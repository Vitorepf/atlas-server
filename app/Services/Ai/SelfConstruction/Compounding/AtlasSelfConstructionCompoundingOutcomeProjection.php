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

            $key = json_encode([$organ, $taskClass, $cycleId, $evidenceHash], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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

    /** Average evidence_refs count per outcome at or above this is strong proof coverage. */
    private const STRONG_PROOF_AVG_REFS = 1.0;

    /** Ratio of all-failed groups at or above this signals repeated-failure drag, not a blip. */
    private const FAILURE_DRAG_GROUP_RATIO = 0.3;

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

        // AC: capability_delta / simplification_gain are only credited from PASSED outcomes — a
        // failed or learning_required record proves nothing was actually delivered. proof_strength
        // is measured across every outcome (weak proof is itself a signal regardless of verdict).
        $capabilityDeltaSum = 0.0;
        $simplificationGainSum = 0.0;
        $proofRefCounts = [];
        $allFailedGroupCount = 0;

        foreach ($groups as $g) {
            $groupOutcomes = $g['outcomes'];
            $groupAllFailed = $groupOutcomes !== [] && count(array_filter(
                $groupOutcomes,
                static fn (array $o): bool => $o['outcome'] === self::OUTCOME_FAILED,
            )) === count($groupOutcomes);
            if ($groupAllFailed) {
                $allFailedGroupCount++;
            }

            foreach ($groupOutcomes as $o) {
                $total++;
                $raw = (array) ($o['raw_fact'] ?? []);
                $proofRefCounts[] = count((array) ($raw['evidence_refs'] ?? []));

                if (($o['raw_outcome'] ?? '') === 'give_back') {
                    $giveBack++;
                } elseif ($o['outcome'] === self::OUTCOME_PASSED) {
                    $passed++;
                    $capabilityDeltaSum += (float) ($raw['capability_delta'] ?? 0.0);
                    $simplificationGainSum += (float) ($raw['simplification_gain'] ?? 0.0);
                } elseif ($o['outcome'] === self::OUTCOME_FAILED) {
                    $failed++;
                } else {
                    $learning++;
                }
            }
        }

        $totalGroups = count($groups);
        $failureDrag = $totalGroups > 0 ? round($allFailedGroupCount / $totalGroups, 4) : 0.0;
        $avgProofRefs = $proofRefCounts !== [] ? array_sum($proofRefCounts) / count($proofRefCounts) : 0.0;
        $proofStrength = $avgProofRefs >= self::STRONG_PROOF_AVG_REFS ? 'strong' : 'weak';

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

        // AC: distinguish REAL compounding from raw task volume — a high pass rate alone (trend
        // 'compounding') means nothing if no capability actually moved and proof is weak.
        $compoundingStatus = match (true) {
            $total === 0 => 'no_data',
            $failureDrag >= self::FAILURE_DRAG_GROUP_RATIO => 'regressing',
            $capabilityDeltaSum > 0.0 && $proofStrength === 'strong' => 'true_compounding',
            $simplificationGainSum > 0.0 => 'simplifying',
            $trend === 'compounding' && $capabilityDeltaSum <= 0.0 => 'volume_without_compounding',
            default => 'flat',
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
            'capability_delta'    => round($capabilityDeltaSum, 4),
            'failure_drag'        => $failureDrag,
            'simplification_gain' => round($simplificationGainSum, 4),
            'proof_strength'      => $proofStrength,
            'compounding_status'  => $compoundingStatus,
        ];
    }
}
