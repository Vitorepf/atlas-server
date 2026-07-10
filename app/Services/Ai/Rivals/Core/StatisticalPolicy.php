<?php

namespace App\Services\Ai\Rivals\Core;

/** Deterministic sample-adequacy gates; report rendering reuses these metrics. */
final class StatisticalPolicy
{
    /**
     * @param  list<RunReceipt>  $receipts
     * @return array{adequate: bool, blockers: list<string>, segments: list<array<string, mixed>>}
     */
    public function evaluate(RunPlan $plan, array $receipts): array
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

        return [
            'adequate' => $blockers === [],
            'blockers' => array_values(array_unique($blockers)),
            'segments' => $segments,
        ];
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
