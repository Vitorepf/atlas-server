<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Replenishment;

/**
 * ITEM8 — the cohesive completion-audit interpretation concern the auto-replenishment service uses
 * to read and classify the completion-audit payload that the operator / real-provider surfaces
 * produce, so the service can decide whether to claim a packet or escalate to operator handoff.
 *
 * Six methods migrated verbatim from
 * {@see \App\Services\Ai\SelfConstruction\AgentControlPlaneTaskAutoReplenishmentService}:
 *  - {@see self::completionAuditPayload}: walk five known paths (root, `current_completion_audit`,
 *    `completion_audit`, `operator_handoff_packet.completion_audit`, fallback to the input) and
 *    return the first non-empty nested array.
 *  - {@see self::completionAuditFailedCriteria}: aggregate failed-criterion IDs from four schema
 *    variants (`failed_criteria`, `failed_criteria_detailed[].id`, the four nested blocker paths),
 *    de-duplicate, and return as a sorted-stable list.
 *  - {@see self::completionAuditFailedCriterionDetails}: project failed-criterion details from
 *    three nested paths (`failed_criteria_detailed[]`, `blockers[]`, `operator_handoff_packet.blockers[]`)
 *    keyed by criterion id, with later sources NOT clobbering earlier ones (idempotent merge).
 *  - {@see self::completionAuditCriterionRequiresOperator}: true for the three operator-only
 *    completion blockers (runtime-gap / human-signed / real-provider-smoke); false otherwise.
 *  - {@see self::completionAuditOperatorHandoffReason}: human-readable reason string per
 *    operator-only criterion (empty for non-operator criteria).
 *  - {@see self::operatorHandoffNextAction}: the canonical "what the operator must do next" line
 *    per operator-only criterion (generic fallback for non-operator criteria).
 *
 * Pure / stateless / zero Laravel surface — extracted so the auto-replenishment service can split
 * cohesive completion-audit interpretation logic out of its public signature without changing ANY
 * caller-visible byte.
 */
class AgentControlPlaneCompletionAuditReader
{
    /**
     * The three operator-only completion criteria: a claimable packet blocked by any of these
     * requires an operator handoff (the auto-replenishment lane refuses to claim such packets).
     */
    public const OPERATOR_ONLY_CRITERIA = [
        'runtime_gap_matrix_all_runtime_y',
        'human_signed_os_complete_receipt_present',
        'end_to_end_real_provider_smoke_green',
    ];

    /**
     * @param  array<string, mixed>  $completionAudit
     * @return array<string, mixed>
     */
    public function completionAuditPayload(array $completionAudit): array
    {
        foreach ([
            'agent_control_plane_atlas_self_construction_os_completion_audit',
            'agent_control_plane_atlas_self_construction_os_completion_audit_status',
            'current_completion_audit',
            'completion_audit',
            'operator_handoff_packet.completion_audit',
        ] as $path) {
            $candidate = data_get($completionAudit, $path);
            if (is_array($candidate) && $candidate !== []) {
                return (array) $candidate;
            }
        }

        return $completionAudit;
    }

    /**
     * @param  array<string, mixed>  $completionAudit
     * @return list<string>
     */
    public function completionAuditFailedCriteria(array $completionAudit): array
    {
        $criteria = array_values(array_filter(array_map('strval', (array) data_get($completionAudit, 'failed_criteria', []))));

        foreach ((array) data_get($completionAudit, 'failed_criteria_detailed', []) as $entry) {
            $id = (string) data_get($entry, 'id', '');
            if ($id !== '') {
                $criteria[] = $id;
            }
        }

        foreach ([
            'current_blocks_completion_criteria',
            'operator_handoff_packet.current_blocks_completion_criteria',
            'blocker_classification.human_blockers',
            'blocker_classification.real_provider_blockers',
            'blocker_classification.technical_blockers',
        ] as $path) {
            foreach ((array) data_get($completionAudit, $path, []) as $id) {
                $id = (string) $id;
                if ($id !== '') {
                    $criteria[] = $id;
                }
            }
        }

        return array_values(array_unique($criteria));
    }

    /**
     * @param  array<string, mixed>  $completionAudit
     * @return array<string, array<string, mixed>>
     */
    public function completionAuditFailedCriterionDetails(array $completionAudit): array
    {
        $details = [];
        foreach ((array) data_get($completionAudit, 'failed_criteria_detailed', []) as $entry) {
            $id = (string) data_get($entry, 'id', '');
            if ($id !== '') {
                $details[$id] = (array) $entry;
            }
        }

        foreach ((array) data_get($completionAudit, 'blockers', []) as $entry) {
            $id = (string) data_get($entry, 'id', '');
            if ($id !== '' && ! isset($details[$id])) {
                $details[$id] = (array) $entry;
            }
        }

        foreach ((array) data_get($completionAudit, 'operator_handoff_packet.blockers', []) as $entry) {
            $id = (string) data_get($entry, 'id', '');
            if ($id !== '' && ! isset($details[$id])) {
                $details[$id] = (array) $entry;
            }
        }

        return $details;
    }

    public function completionAuditCriterionRequiresOperator(string $criterion): bool
    {
        return in_array($criterion, self::OPERATOR_ONLY_CRITERIA, true);
    }

    public function completionAuditOperatorHandoffReason(string $criterion): string
    {
        return match ($criterion) {
            'runtime_gap_matrix_all_runtime_y' => 'requires_operator_signed_runtime_promotion_receipt_before_runtime_gap_can_close',
            'human_signed_os_complete_receipt_present' => 'requires_human_signed_os_completion_receipt_after_runtime_and_real_provider_smoke_are_green',
            'end_to_end_real_provider_smoke_green' => 'requires_operator_run_real_provider_smoke_outside_atlas_and_persist_evidence',
            default => '',
        };
    }

    /**
     * Summarize repeated give_back reasons into poison_family facts for the replenisher.
     * Only give_back records count; success outcomes are ignored.
     * Families with fewer than $minCount occurrences are not reported.
     * Output is deterministically ordered by reason ASC.
     *
     * @param  list<array<string,mixed>>  $records
     * @return array{poison_families:list<array{reason:string,count:int,exemplar_packet_id:string,repair_hint:string}>}
     */
    public function poisonFamilies(array $records, int $minCount = 2): array
    {
        $groups = [];
        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }
            if ((string) ($record['outcome'] ?? '') !== 'give_back') {
                continue;
            }
            $reason = (string) ($record['give_back_reason'] ?? $record['reason'] ?? 'unknown');
            if (! isset($groups[$reason])) {
                $groups[$reason] = ['count' => 0, 'exemplar_packet_id' => (string) ($record['task_packet_id'] ?? '')];
            }
            $groups[$reason]['count']++;
        }

        $families = [];
        foreach ($groups as $reason => $data) {
            if ($data['count'] < $minCount) {
                continue;
            }
            $families[] = [
                'reason' => $reason,
                'count' => $data['count'],
                'exemplar_packet_id' => $data['exemplar_packet_id'],
                'repair_hint' => $this->repairHintFor($reason),
            ];
        }

        usort($families, static fn (array $a, array $b): int => strcmp($a['reason'], $b['reason']));

        return ['poison_families' => array_values($families)];
    }

    private function repairHintFor(string $reason): string
    {
        if (str_contains($reason, 'test_only')) {
            return 'remove_or_rewire_test_only_survivors';
        }
        if (str_contains($reason, 'forbidden')) {
            return 'resolve_forbidden_file_conflict';
        }
        if (str_contains($reason, 'scope')) {
            return 'run_atlas_task_repair_blocked';
        }

        return 'review_and_repair_or_retire_family';
    }

    public function operatorHandoffNextAction(string $criterion): string
    {
        return match ($criterion) {
            'runtime_gap_matrix_all_runtime_y' => 'run_runtime_promotion_endgame_and_persist_operator_signed_runtime_promotion_receipt',
            'human_signed_os_complete_receipt_present' => 'persist_human_completion_receipt_only_after_runtime_promotion_and_real_provider_smoke_are_green',
            'end_to_end_real_provider_smoke_green' => 'run_real_provider_smoke_outside_atlas_then_persist_smoke_certification_evidence',
            default => 'operator_review_required_before_replenishing_worker_claimable_task',
        };
    }
}
