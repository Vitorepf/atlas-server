<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Compounding;

/**
 * Pure projection (NOT a new store). Normalizes raw compounding records produced by
 * AtlasCompoundingOutcomeEvaluator + AtlasCompoundingRuntimeService into canonical Self-Construction
 * outcome FACTS, grouped by organ × task_class × cycle_id × evidence_hash.
 *
 * Output (per record):
 *   {organ, task_class, cycle_id, evidence_hash, outcome, raw_fact}
 *
 * Group output:
 *   {schema_version, groups:list<{key, organ, task_class, cycle_id, evidence_hash, outcomes:list<...>}>}
 *
 * The original record payload is preserved verbatim under raw_fact so the audit trail is intact.
 *
 * 3 outcome enum values:
 *   - passed              : record.outcome ∈ {passed, success, green}
 *   - failed              : record.outcome ∈ {failed, regression, red}
 *   - learning_required   : record.outcome ∈ {learning_required, amber, partial, unknown}
 */
final class AtlasSelfConstructionCompoundingOutcomeProjection
{
    public const SCHEMA = 'atlas.self_construction.compounding_outcome_projection.v1';

    public const OUTCOME_PASSED = 'passed';

    public const OUTCOME_FAILED = 'failed';

    public const OUTCOME_LEARNING = 'learning_required';

    private const PASSED_NEEDLES = ['passed', 'success', 'green'];

    private const FAILED_NEEDLES = ['failed', 'regression', 'red'];

    /**
     * @param  list<array<string,mixed>>  $records  raw compounding records
     * @return array<string,mixed>
     */
    public function project(array $records): array
    {
        $bucketed = [];

        foreach ($records as $rec) {
            if (! is_array($rec)) {
                continue;
            }
            $organ = (string) ($rec['organ'] ?? 'unknown_organ');
            $taskClass = (string) ($rec['task_class'] ?? 'unknown_class');
            $cycleId = (string) ($rec['cycle_id'] ?? 'unknown_cycle');
            $evidenceHash = (string) ($rec['evidence_hash'] ?? '');
            $rawOutcome = strtolower((string) ($rec['outcome'] ?? ''));

            $outcome = in_array($rawOutcome, self::PASSED_NEEDLES, true)
                ? self::OUTCOME_PASSED
                : (in_array($rawOutcome, self::FAILED_NEEDLES, true)
                    ? self::OUTCOME_FAILED
                    : self::OUTCOME_LEARNING);

            $key = $organ.'|'.$taskClass.'|'.$cycleId.'|'.$evidenceHash;
            $bucketed[$key] ??= [
                'key' => $key,
                'organ' => $organ,
                'task_class' => $taskClass,
                'cycle_id' => $cycleId,
                'evidence_hash' => $evidenceHash,
                'outcomes' => [],
            ];
            $bucketed[$key]['outcomes'][] = [
                'outcome' => $outcome,
                'raw_outcome' => $rawOutcome,
                'raw_fact' => $rec,
            ];
        }

        $groups = array_values($bucketed);
        usort($groups, static fn (array $a, array $b): int => strcmp($a['key'], $b['key']));

        return [
            'schema_version' => self::SCHEMA,
            'groups' => $groups,
            'summary' => $this->computeSummary($groups),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $groups
     * @return array{trend:string, confidence:string, total_outcome_count:int, rates:array<string,float>}
     */
    private function computeSummary(array $groups): array
    {
        $passed = 0;
        $failed = 0;
        $giveBack = 0;
        $learning = 0;
        $total = 0;

        foreach ($groups as $g) {
            foreach ($g['outcomes'] as $o) {
                $total++;
                if (($o['raw_outcome'] ?? '') === 'give_back') {
                    $giveBack++;
                } elseif ($o['outcome'] === self::OUTCOME_PASSED) {
                    $passed++;
                } elseif ($o['outcome'] === self::OUTCOME_FAILED) {
                    $failed++;
                } else {
                    $learning++;
                }
            }
        }

        $passedRate   = $total > 0 ? $passed / $total : 0.0;
        $failedRate   = $total > 0 ? $failed / $total : 0.0;
        $giveBackRate = $total > 0 ? $giveBack / $total : 0.0;

        if ($total === 0) {
            $trend = 'uncertain';
        } elseif ($giveBackRate >= 0.3) {
            $trend = 'give_back_drag';
        } elseif ($failedRate >= 0.3) {
            $trend = 'quality_decay';
        } elseif ($passedRate >= 0.75) {
            $trend = 'compounding';
        } elseif ($passedRate >= 0.4) {
            $trend = 'flat_volume';
        } else {
            $trend = 'uncertain';
        }

        $confidence = match (true) {
            $total >= 10 => 'high',
            $total >= 3  => 'medium',
            default      => 'low',
        };

        return [
            'trend'               => $trend,
            'confidence'          => $confidence,
            'total_outcome_count' => $total,
            'rates'               => [
                'passed_rate'    => round($passedRate, 4),
                'failed_rate'    => round($failedRate, 4),
                'give_back_rate' => round($giveBackRate, 4),
                'learning_rate'  => $total > 0 ? round($learning / $total, 4) : 0.0,
            ],
        ];
    }
}
