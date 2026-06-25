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
        ];
    }
}
