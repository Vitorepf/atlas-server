<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure, deterministic queue-repair planner. Turns live packet_quality deficiency signals into
 * concrete respec actions BEFORE a muscle wastes a lease claiming a doomed packet — the same
 * deficiency vocabulary the task-serving contract already emits (e.g. `hidden_poison:...`,
 * `scope_incoherent`, `missing_scope`, `weak_evidence`) is classified into one of six canonical
 * defects, each bound to the exact packet field a respec must change.
 *
 * CANONICAL DEFECTS → field to respec:
 *   blocked                  → allowed_files   (unblock: drop or replace the blocking dependency)
 *   poison                   → objective       (poison risk requires re-scoping the objective itself)
 *   test_only                → allowed_files   (add a real non-test implementation target)
 *   missing_scope            → allowed_files   (declare concrete, non-empty allowed_files)
 *   contradictory_acceptance → acceptance_criteria (reconcile/remove the contradicting criterion)
 *   weak_evidence            → required_evidence   (name a runnable gate, not a prose promise)
 *
 * A single raw deficiency string may match MORE THAN ONE canonical defect (e.g.
 * `hidden_poison:contradictory_acceptance` maps to BOTH poison and contradictory_acceptance) —
 * every match is classified and repaired independently.
 *
 * QUARANTINE: a packet whose declared deficiencies contain NONE of the six recognized patterns has
 * no actionable field-level repair — it is marked quarantine_recommended instead of being re-served
 * blindly on a generic respec guess. A packet with zero declared deficiencies is healthy and is
 * omitted from the matrix entirely.
 *
 * INPUT: list<{packet_id:string, deficiencies:list<string>}>
 * OUTPUT: {schema, queue_repair_matrix:list<{packet_id, verdict:'respec_recommended'|'quarantine_recommended',
 *   defects:list<string>, respec_actions:list<{defect,field,respec_action}>, reason:?string}>}
 *
 * Pure: no I/O, no provider calls, no queue mutation — this planner only produces facts and
 * recommended respec actions for a downstream mutator to apply.
 */
final class AtlasExternalBrainQueueGapRepairPlanner
{
    public const SCHEMA = 'atlas.external_brain.queue_gap_repair_planner.v1';

    public const VERDICT_RESPEC_RECOMMENDED = 'respec_recommended';

    public const VERDICT_QUARANTINE_RECOMMENDED = 'quarantine_recommended';

    private const DEFECT_FIELD_MAP = [
        'blocked' => 'allowed_files',
        'poison' => 'objective',
        'test_only' => 'allowed_files',
        'missing_scope' => 'allowed_files',
        'contradictory_acceptance' => 'acceptance_criteria',
        'weak_evidence' => 'required_evidence',
    ];

    /**
     * @param  list<array{packet_id?:string, deficiencies?:list<string>}>  $packets
     * @return array{schema:string, queue_repair_matrix:list<array<string,mixed>>}
     */
    public function plan(array $packets): array
    {
        $matrix = [];

        foreach ($packets as $packet) {
            if (! is_array($packet) || ! isset($packet['packet_id'])) {
                continue;
            }

            $packetId = (string) $packet['packet_id'];
            $deficiencies = array_values(array_map('strval', (array) ($packet['deficiencies'] ?? [])));

            if ($deficiencies === []) {
                continue; // healthy packet — nothing to repair, not part of the matrix
            }

            $defects = [];
            foreach ($deficiencies as $deficiency) {
                foreach ($this->classify($deficiency) as $defect) {
                    $defects[$defect] = true;
                }
            }

            if ($defects === []) {
                $matrix[] = [
                    'packet_id' => $packetId,
                    'verdict' => self::VERDICT_QUARANTINE_RECOMMENDED,
                    'defects' => [],
                    'respec_actions' => [],
                    'reason' => 'no_actionable_repair_mapping_for_declared_deficiencies',
                ];

                continue;
            }

            $defectNames = array_keys($defects);
            sort($defectNames, SORT_STRING);

            $respecActions = [];
            foreach ($defectNames as $defect) {
                $field = self::DEFECT_FIELD_MAP[$defect];
                $respecActions[] = [
                    'defect' => $defect,
                    'field' => $field,
                    'respec_action' => sprintf('respec_%s_to_resolve_%s', $field, $defect),
                ];
            }

            $matrix[] = [
                'packet_id' => $packetId,
                'verdict' => self::VERDICT_RESPEC_RECOMMENDED,
                'defects' => $defectNames,
                'respec_actions' => $respecActions,
                'reason' => null,
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'queue_repair_matrix' => $matrix,
        ];
    }

    /** @return list<string> */
    private function classify(string $deficiency): array
    {
        $d = strtolower($deficiency);
        $found = [];

        if (str_contains($d, 'poison')) {
            $found[] = 'poison';
        }
        if (str_contains($d, 'blocked')) {
            $found[] = 'blocked';
        }
        if (str_contains($d, 'test_only')) {
            $found[] = 'test_only';
        }
        if (str_contains($d, 'contradictory')) {
            $found[] = 'contradictory_acceptance';
        }
        if (str_contains($d, 'missing_scope') || str_contains($d, 'scope_incoherent') || str_contains($d, 'scope_uncovered')) {
            $found[] = 'missing_scope';
        }
        if (str_contains($d, 'weak_evidence') || str_contains($d, 'not_runnable') || str_contains($d, 'evidence_thin')) {
            $found[] = 'weak_evidence';
        }

        return $found;
    }
}
