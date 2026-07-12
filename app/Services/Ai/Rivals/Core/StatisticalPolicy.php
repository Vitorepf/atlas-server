<?php

namespace App\Services\Ai\Rivals\Core;

/** Deterministic sample-adequacy gates; report rendering reuses these metrics. */
final class StatisticalPolicy
{
    /**
     * @param  list<RunReceipt>  $receipts
     * @return array{adequate: bool, blockers: list<string>, segments: list<array<string, mixed>>}
     */
    public function evaluate(RunPlan $plan, array $receipts, array $comparisons = []): array
    {
        try {
            $preregistration = Preregistration::load($plan->runId());
        } catch (\Throwable) {
            return [
                'adequate' => false,
                'blockers' => ['preregistration_missing'],
                'segments' => [],
            ];
        }
        if (($plan->data['preregistration_hash'] ?? null) !== $preregistration->hash()) {
            return [
                'adequate' => false,
                'blockers' => ['preregistration_plan_hash_mismatch'],
                'segments' => [],
            ];
        }
        foreach ([
            'suite_id' => $plan->data['suite_id'],
            'case_ids' => $plan->data['case_ids'],
            'arm_ids' => array_column($plan->data['arms'], 'arm_id'),
            'comparisons' => array_values((array) ($plan->data['comparisons'] ?? [])),
            'repetitions' => $plan->data['repetitions'],
        ] as $field => $expected) {
            if (($preregistration->data[$field] ?? null) !== $expected) {
                return [
                    'adequate' => false,
                    'blockers' => ["preregistration_scope_mismatch:{$field}"],
                    'segments' => [],
                ];
            }
        }

        $blockers = [];
        $plannedComparisons = array_values((array) ($preregistration->data['comparisons'] ?? []));
        if ($comparisons === []) {
            $comparisons = $plannedComparisons;
        } elseif (json_encode($comparisons) !== json_encode($plannedComparisons)) {
            $blockers[] = 'comparisons_not_preregistered';
        }
        $targetPower = (float) ($preregistration->data['target_power'] ?? 0.0);
        if ($targetPower < 0.90) {
            $blockers[] = 'preregistered_power_below_90_percent';
        }
        if (($preregistration->data['multiplicity']['method'] ?? null) !== 'holm') {
            $blockers[] = 'multiplicity_policy_not_holm';
        }
        $distinctCases = count(array_unique(array_map(
            fn (RunReceipt $receipt): string => (string) $receipt->data['case_id'],
            $receipts,
        )));
        $minimumCases = (int) $preregistration->data['min_distinct_cases'];
        if ($distinctCases < $minimumCases) {
            $blockers[] = "sample_distinct_cases_below_min:{$distinctCases}<{$minimumCases}";
        }

        $groups = [];
        foreach ($receipts as $receipt) {
            $key = $receipt->data['task_type'].'|'.$receipt->data['arm_id'];
            $groups[$key][] = $receipt;
        }
        $segments = [];
        foreach ($groups as $key => $items) {
            [$taskType, $armId] = explode('|', $key, 2);
            $n = count($items);
            $unitKeys = array_map(
                static fn (RunReceipt $receipt): string => $receipt->data['case_id'].'|'.$receipt->data['repetition'],
                $items,
            );
            if (count($unitKeys) !== count(array_unique($unitKeys))) {
                $blockers[] = 'pseudoreplication_duplicate_unit:'.$taskType.'|'.$armId;
            }
            $expectedAttempts = count($plan->data['case_ids']) * (int) $plan->data['repetitions'];
            if (count(array_unique($unitKeys)) !== $expectedAttempts) {
                $blockers[] = 'itt_denominator_incomplete:'.$taskType.'|'.$armId
                    .'|'.count(array_unique($unitKeys)).'<'.$expectedAttempts;
            }
            $successes = count(array_filter(
                $items,
                fn (RunReceipt $receipt): bool => $receipt->data['status'] === 'success',
            ));
            $interval = self::wilson($successes, $n);
            $environmentFailures = count(array_filter(
                $items,
                fn (RunReceipt $receipt): bool => $receipt->data['failure_class'] === FailureClass::ENVIRONMENT,
            ));
            $environmentRate = $n > 0 ? $environmentFailures / $n : 0.0;
            if ($interval['width'] > (float) $preregistration->data['max_ci_width']) {
                $blockers[] = 'sample_ci_too_wide:'.$taskType.'|'.$armId.'|'
                    .round($interval['width'], 4).'>'.$preregistration->data['max_ci_width'];
            }
            $maxEnvironmentRate = (float) config(
                'atlas_rivals.claim.max_environment_failure_rate',
                0.05,
            );
            if ($environmentRate > $maxEnvironmentRate) {
                $blockers[] = 'environment_failure_rate_exceeded:'.$taskType.'|'.$armId.'|'
                    .round($environmentRate, 4).'>'.$maxEnvironmentRate;
            }
            $segments[] = [
                'task_type' => $taskType,
                'arm_id' => $armId,
                'n' => $n,
                'successes' => $successes,
                'success_rate' => $n > 0 ? $successes / $n : 0.0,
                'wilson_95' => $interval,
                'environment_failure_rate' => $environmentRate,
            ];
        }

        $adjustedComparisons = self::holm($comparisons, (float) $preregistration->data['alpha']);
        foreach ($adjustedComparisons as $comparison) {
            if (($comparison['raw_p_value'] ?? 1.0) < (float) $preregistration->data['alpha']
                && ($comparison['significant'] ?? false) !== true) {
                $blockers[] = 'multiplicity_not_significant_after_holm:'.$comparison['id'];
            }
        }

        return [
            'adequate' => $blockers === [],
            'blockers' => array_values(array_unique($blockers)),
            'segments' => $segments,
            'preregistration' => [
                'target_power' => $targetPower,
                'analysis_population' => $preregistration->data['analysis_population'],
            ],
            'multiplicity' => $preregistration->data['multiplicity'],
            'comparisons' => $adjustedComparisons,
        ];
    }

    /** @param list<array{id?: string, p_value: float|int}> $comparisons */
    public static function holm(array $comparisons, float $alpha = 0.05): array
    {
        $ordered = [];
        foreach ($comparisons as $index => $comparison) {
            $pValue = (float) ($comparison['p_value'] ?? -1.0);
            if ($pValue < 0.0 || $pValue > 1.0) {
                $pValue = 1.0;
            }
            $ordered[] = [
                'index' => $index,
                'id' => (string) ($comparison['id'] ?? "comparison_{$index}"),
                'raw_p_value' => $pValue,
            ];
        }
        usort($ordered, static fn (array $a, array $b): int => $a['raw_p_value'] <=> $b['raw_p_value']);
        $previous = 0.0;
        foreach ($ordered as $rank => &$comparison) {
            $adjusted = min(1.0, max($previous, $comparison['raw_p_value'] * (count($ordered) - $rank)));
            $comparison['adjusted_p_value'] = round($adjusted, 6);
            $comparison['significant'] = $adjusted <= $alpha;
            $previous = $adjusted;
        }
        unset($comparison);
        usort($ordered, static fn (array $a, array $b): int => $a['index'] <=> $b['index']);

        return array_map(static function (array $comparison): array {
            unset($comparison['index']);

            return $comparison;
        }, $ordered);
    }

    /** @return array{low: float, high: float, width: float} */
    public static function wilson(int $successes, int $n, float $z = 1.959963984540054): array
    {
        if ($n <= 0) {
            return ['low' => 0.0, 'high' => 1.0, 'width' => 1.0];
        }
        $p = $successes / $n;
        $z2 = $z ** 2;
        $denominator = 1 + ($z2 / $n);
        $center = ($p + ($z2 / (2 * $n))) / $denominator;
        $margin = ($z / $denominator) * sqrt(
            (($p * (1 - $p)) / $n) + ($z2 / (4 * ($n ** 2)))
        );
        $low = max(0.0, $center - $margin);
        $high = min(1.0, $center + $margin);

        return [
            'low' => round($low, 6),
            'high' => round($high, 6),
            'width' => round($high - $low, 6),
        ];
    }
}
