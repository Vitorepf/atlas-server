<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

/**
 * Read-only certification that the worker-facing queue does not contain
 * operator/provider-only completion blockers.
 */
final class AgentControlPlaneWorkerTaskEligibilityCertificationService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_worker_task_eligibility_certification.v1';

    public const MODE = 'read_only_worker_task_eligibility_certification';

    private const OPERATOR_ONLY_COMPLETION_CRITERIA = [
        'runtime_gap_matrix_all_runtime_y',
        'human_signed_os_complete_receipt_present',
        'end_to_end_real_provider_smoke_green',
    ];

    private const WORKER_CANDIDATE_STATUSES = [
        'queued',
        'claimable',
        'claimed',
        'lease_expired',
    ];

    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $readiness,
        private readonly AgentControlPlaneTaskPacketQueueRepository $queue,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function certify(array $options = []): array
    {
        $queueTags = $this->stringList((array) ($options['queue_tags'] ?? []));
        $autoReplenishmentOptions = array_merge($options, [
            'target_min_claimable_tasks' => max(1, (int) ($options['target_min_claimable_tasks'] ?? 1)),
            'max_new_tasks' => 0,
            'queue_tags' => $queueTags,
            'reason' => 'worker_task_eligibility_certification_read_only_probe',
        ]);
        if (array_key_exists('completion_audit', $options) && is_array($options['completion_audit'])) {
            $autoReplenishmentOptions['completion_audit'] = (array) $options['completion_audit'];
        }

        $autoReplenishment = $this->readiness->agentControlPlaneTaskAutoReplenishmentStatus($autoReplenishmentOptions);
        $records = $this->recordsForTags($queueTags);
        $claimableRecords = array_values(array_filter(
            $records,
            static fn (array $record): bool => (string) ($record['status'] ?? '') === 'claimable',
        ));
        $activeWorkerRecords = array_values(array_filter(
            $records,
            static fn (array $record): bool => in_array((string) ($record['status'] ?? ''), self::WORKER_CANDIDATE_STATUSES, true),
        ));

        $violations = [];
        foreach ($activeWorkerRecords as $record) {
            $recordViolations = $this->recordViolations($record);
            foreach ($recordViolations as $violation) {
                $violations[] = $violation;
            }
        }

        $completionAuditFailedCriteria = array_values(array_map(
            'strval',
            (array) data_get($autoReplenishment, 'agent_control_plane_task_auto_replenishment_status.completion_audit_context_failed_criteria', []),
        ));
        $operatorOnlyFailedCriteria = array_values(array_intersect($completionAuditFailedCriteria, self::OPERATOR_ONLY_COMPLETION_CRITERIA));
        $operatorHandoffReferences = array_values(array_map(
            static fn (array $task): string => (string) ($task['reference'] ?? ''),
            (array) data_get($autoReplenishment, 'agent_control_plane_task_auto_replenishment_status.operator_handoff_tasks', []),
        ));
        $missingOperatorHandoffs = array_values(array_diff($operatorOnlyFailedCriteria, $operatorHandoffReferences));
        foreach ($missingOperatorHandoffs as $criterion) {
            $violations[] = [
                'code' => 'operator_only_completion_blocker_missing_from_handoff',
                'criterion' => $criterion,
            ];
        }

        $claimableRows = array_map(fn (array $record): array => $this->recordSummary($record), $claimableRecords);
        $activeWorkerRows = array_map(fn (array $record): array => $this->recordSummary($record), $activeWorkerRecords);
        $operatorHandoffRows = (array) data_get($autoReplenishment, 'agent_control_plane_task_auto_replenishment_status.operator_handoff_tasks', []);
        $checks = [
            'claimable_tasks_are_worker_executable' => ! $this->anyViolationWithCode($violations, 'claimable_or_claimed_task_not_worker_executable'),
            'claimable_tasks_do_not_require_operator_handoff' => ! $this->anyViolationWithCode($violations, 'claimable_or_claimed_task_requires_operator_handoff'),
            'claimable_tasks_do_not_reference_operator_only_completion_blockers' => ! $this->anyViolationWithCode($violations, 'claimable_or_claimed_task_references_operator_only_completion_blocker'),
            'claimable_task_runtime_flags_false' => ! $this->anyViolationWithCode($violations, 'claimable_or_claimed_task_runtime_flag_true'),
            'operator_only_completion_blockers_surface_as_handoff' => $missingOperatorHandoffs === [],
            'auto_replenishment_did_not_create_tasks_during_certification' => (int) data_get($autoReplenishment, 'agent_control_plane_task_auto_replenishment_status.generated_task_count', 0) === 0,
        ];
        $failedCheckIds = array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed));

        $violationSummaryByCode = $this->violationSummaryByCode($violations);
        $operatorHandoffSeedCount = (int) data_get($autoReplenishment, 'agent_control_plane_task_auto_replenishment_status.operator_handoff_seed_count', 0);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $failedCheckIds === [] ? 'available' : 'blocked',
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'queue_tags' => $queueTags,
            'checked_record_count' => count($records),
            'worker_candidate_statuses' => self::WORKER_CANDIDATE_STATUSES,
            'claimable_task_count' => count($claimableRecords),
            'active_worker_task_count' => count($activeWorkerRecords),
            'active_worker_tasks' => $activeWorkerRows,
            'violation_summary_by_code' => $violationSummaryByCode,
            'worker_candidate_summary' => [
                'active_worker_task_count' => count($activeWorkerRecords),
                'claimable_task_count' => count($claimableRecords),
                'operator_handoff_seed_count' => $operatorHandoffSeedCount,
            ],
            'operator_handoff_seed_count' => $operatorHandoffSeedCount,
            'operator_handoff_references' => $operatorHandoffReferences,
            'operator_handoff_tasks' => $operatorHandoffRows,
            'completion_audit_context_status' => (string) data_get($autoReplenishment, 'agent_control_plane_task_auto_replenishment_status.completion_audit_context_status', ''),
            'completion_audit_context_failed_criteria' => $completionAuditFailedCriteria,
            'operator_only_failed_criteria' => $operatorOnlyFailedCriteria,
            'missing_operator_handoff_criteria' => $missingOperatorHandoffs,
            'claimable_tasks' => $claimableRows,
            'checks' => $checks,
            'failed_check_ids' => $failedCheckIds,
            'checks_all_true' => $failedCheckIds === [],
            'violations' => $violations,
            'violation_count' => count($violations),
            'auto_replenishment_probe_hash' => (string) data_get($autoReplenishment, 'agent_control_plane_task_auto_replenishment_status.replenishment_plan_hash', ''),
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'completion_real_allowed' => false,
            'non_execution_guarantees' => [
                'worker_task_eligibility_certification_does_not_claim_tasks',
                'worker_task_eligibility_certification_does_not_create_tasks',
                'worker_task_eligibility_certification_does_not_complete_tasks',
                'worker_task_eligibility_certification_does_not_call_provider',
                'worker_task_eligibility_certification_does_not_spend_tokens',
                'worker_task_eligibility_certification_does_not_dispatch_work',
                'worker_task_eligibility_certification_does_not_promote_completion',
            ],
        ];
        $payload['certification_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /**
     * @param  list<string>  $queueTags
     * @return list<array<string, mixed>>
     */
    private function recordsForTags(array $queueTags): array
    {
        if ($queueTags === []) {
            return $this->queue->list();
        }

        $records = [];
        $seen = [];
        foreach ($queueTags as $tag) {
            foreach ($this->queue->list(['tag' => $tag]) as $record) {
                $taskPacketId = (string) ($record['task_packet_id'] ?? '');
                if ($taskPacketId !== '' && isset($seen[$taskPacketId])) {
                    continue;
                }
                if ($taskPacketId !== '') {
                    $seen[$taskPacketId] = true;
                }
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<array<string, mixed>>
     */
    private function recordViolations(array $record): array
    {
        $violations = [];
        $taskPacketId = (string) ($record['task_packet_id'] ?? '');
        $reference = (string) data_get($record, 'task_packet.continuation_context.auto_replenishment_reference', '');
        if ((bool) data_get($record, 'task_packet.continuation_context.worker_executable', true) === false) {
            $violations[] = ['code' => 'claimable_or_claimed_task_not_worker_executable', 'task_packet_id' => $taskPacketId];
        }
        if ((bool) data_get($record, 'task_packet.continuation_context.operator_handoff_required', false)) {
            $violations[] = ['code' => 'claimable_or_claimed_task_requires_operator_handoff', 'task_packet_id' => $taskPacketId];
        }
        if (in_array($reference, self::OPERATOR_ONLY_COMPLETION_CRITERIA, true)) {
            $violations[] = [
                'code' => 'claimable_or_claimed_task_references_operator_only_completion_blocker',
                'task_packet_id' => $taskPacketId,
                'reference' => $reference,
            ];
        }
        foreach ([
            'dispatch_allowed',
            'provider_call_allowed',
            'token_spend_allowed',
            'self_programming_allowed',
            'ledger_write_allowed',
            'runtime_execution_allowed',
            'completion_real_allowed',
        ] as $flag) {
            if ((bool) data_get($record, $flag, false)) {
                $violations[] = [
                    'code' => 'claimable_or_claimed_task_runtime_flag_true',
                    'task_packet_id' => $taskPacketId,
                    'flag' => $flag,
                ];
            }
        }

        return $violations;
    }

    /** @param array<string, mixed> $record */
    private function recordSummary(array $record): array
    {
        return [
            'task_packet_id' => (string) ($record['task_packet_id'] ?? ''),
            'status' => (string) ($record['status'] ?? ''),
            'tags' => (array) ($record['tags'] ?? []),
            'auto_replenishment_seed_key' => (string) data_get($record, 'task_packet.continuation_context.auto_replenishment_seed_key', ''),
            'auto_replenishment_reference' => (string) data_get($record, 'task_packet.continuation_context.auto_replenishment_reference', ''),
            'worker_executable' => (bool) data_get($record, 'task_packet.continuation_context.worker_executable', true),
            'operator_handoff_required' => (bool) data_get($record, 'task_packet.continuation_context.operator_handoff_required', false),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $violations
     * @return array<string, array{count: int, task_packet_ids: list<string>}>
     */
    private function violationSummaryByCode(array $violations): array
    {
        $summary = [];
        foreach ($violations as $violation) {
            $code = (string) ($violation['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $taskPacketId = (string) ($violation['task_packet_id'] ?? '');
            if (! isset($summary[$code])) {
                $summary[$code] = ['count' => 0, 'task_packet_ids' => []];
            }
            $summary[$code]['count']++;
            if ($taskPacketId !== '' && ! in_array($taskPacketId, $summary[$code]['task_packet_ids'], true)) {
                $summary[$code]['task_packet_ids'][] = $taskPacketId;
            }
        }
        foreach ($summary as $code => $entry) {
            sort($summary[$code]['task_packet_ids']);
        }
        ksort($summary);

        return $summary;
    }

    /** @param list<array<string, mixed>> $violations */
    private function anyViolationWithCode(array $violations, string $code): bool
    {
        foreach ($violations as $violation) {
            if ((string) ($violation['code'] ?? '') === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values,
        ), static fn (string $value): bool => $value !== ''));
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['generated_at'], $payload['certification_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param mixed $value */
    private function ksortRecursive($value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $entry) {
            $value[$key] = $this->ksortRecursive($entry);
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }

        return $value;
    }
}
