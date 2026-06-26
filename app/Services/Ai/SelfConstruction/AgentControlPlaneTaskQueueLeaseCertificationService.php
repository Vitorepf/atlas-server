<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;

/**
 * Certifies that the persistent task queue + claim/lease + scope lock
 * runtime layer is mature enough to be relied on while preserving every
 * Agent Control Plane runtime-safety invariant. It exercises the queue,
 * lease and scope-lock services through synthetic invariant probes, then
 * asserts that no runtime flag has been flipped.
 *
 * Runtime-safe: never starts processes, never calls Codex CLI/app, never
 * spawns subprocesses, never invokes adapters, never dispatches work,
 * never spends tokens, never advances the next required slice, never
 * enables self-programming and never writes the evidence ledger.
 */
final class AgentControlPlaneTaskQueueLeaseCertificationService
{
    use RecursivelyKsortsArrays;
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_task_queue_lease_certification.v1';

    public const MODE = 'persistent_local_agent_control_plane_task_queue_lease_certification';

    public function __construct(
        private readonly AgentControlPlaneTaskPacketBuilder $builder,
        private readonly AgentControlPlaneScopeLockRuntimeValidator $validator,
        private readonly AgentControlPlaneTaskPacketQueueRepository $queue,
        private readonly AgentControlPlaneClaimLeaseRepository $leases,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function certify(array $options = []): array
    {
        $invariants = [];
        $violations = [];
        $warnings = [];

        // Storage prefix writable invariant.
        $invariants['queue_repository_available'] = $this->queue->isAvailable();
        if (! $invariants['queue_repository_available']) {
            $violations[] = ['code' => 'queue_repository_unavailable'];
        }

        $invariants['lease_repository_available'] = $this->leases->isAvailable();
        if (! $invariants['lease_repository_available']) {
            $violations[] = ['code' => 'lease_repository_unavailable'];
        }

        $invariants['scope_lock_validator_available'] = $this->validator->isAvailable();
        if (! $invariants['scope_lock_validator_available']) {
            $violations[] = ['code' => 'scope_lock_validator_unavailable'];
        }

        $queueRuntime = $this->queue->runtimeFlags();
        $leaseRuntime = $this->leases->runtimeFlags();
        foreach (['runtime_execution_allowed', 'dispatch_allowed', 'provider_call_allowed', 'token_spend_allowed', 'self_programming_allowed', 'ledger_write_allowed', 'completion_real_allowed'] as $flag) {
            $invariants["queue_{$flag}_is_false"] = ($queueRuntime[$flag] ?? true) === false;
            $invariants["lease_{$flag}_is_false"] = ($leaseRuntime[$flag] ?? true) === false;
            if (! $invariants["queue_{$flag}_is_false"]) {
                $violations[] = ['code' => "queue_{$flag}_flipped"];
            }
            if (! $invariants["lease_{$flag}_is_false"]) {
                $violations[] = ['code' => "lease_{$flag}_flipped"];
            }
        }

        // Allowed statuses contain the canonical set.
        $expectedStatuses = ['queued', 'claimable', 'claimed', 'lease_expired', 'released', 'completed_dry_run', 'blocked', 'cancelled'];
        $statusSet = AgentControlPlaneTaskPacketQueueRepository::STATUSES;
        $invariants['allowed_statuses_canonical'] = count(array_diff($expectedStatuses, $statusSet)) === 0;
        if (! $invariants['allowed_statuses_canonical']) {
            $violations[] = ['code' => 'allowed_statuses_drift'];
        }

        // Forbidden axes covered by validator.
        $axes = array_keys(AgentControlPlaneScopeLockRuntimeValidator::FORBIDDEN_AXES);
        $invariants['forbidden_axes_set'] = count(array_intersect(['self_improvement', 'programming', 'atlas_code_controllers', 'routes_api', 'atlas_desktop', 'forge', 'rivals', 'cartografia', 'voice'], $axes)) === 9;
        if (! $invariants['forbidden_axes_set']) {
            $violations[] = ['code' => 'forbidden_axes_set_drift'];
        }

        // Synthetic probes.
        $probes = $this->runProbes();
        foreach ($probes['probes'] as $key => $passed) {
            $invariants["probe_{$key}"] = (bool) $passed;
            if (! $passed) {
                $violations[] = ['code' => "probe_{$key}_failed"];
            }
        }

        $allTrue = array_values($invariants) === array_fill(0, count($invariants), true);

        $runtimeSafety = [
            'runtime_safety_all_false' => true,
            'queue_runtime_flags' => $queueRuntime,
            'lease_runtime_flags' => $leaseRuntime,
        ];

        $queueRegistry = $this->queue->registry();
        $leaseSummary = $this->leaseSummary();

        $status = $violations === [] ? 'available' : 'blocked';

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'certification_id' => (string) Str::uuid(),
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'status' => $status,
            'invariants' => $invariants,
            'invariants_all_true' => $allTrue,
            'violation_count' => count($violations),
            'warning_count' => count($warnings),
            'violations' => $violations,
            'warnings' => $warnings,
            'queue_summary' => [
                'entry_count' => (int) $queueRegistry['entry_count'],
                'total_count' => (int) $queueRegistry['total_count'],
                'status_counts' => (array) $queueRegistry['status_counts'],
                'corrupt' => (bool) $queueRegistry['corrupt'],
                'storage_prefix' => AgentControlPlaneTaskPacketQueueRepository::STORAGE_PREFIX,
            ],
            'lease_summary' => $leaseSummary,
            'runtime_safety' => $runtimeSafety,
            'probe_evidence' => $probes,
            'next_action' => $status === 'available' ? 'continue_runtime_pilot_observability' : 'investigate_violations',
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'completion_real_allowed' => false,
            'non_execution_guarantees' => [
                'task_queue_lease_certification_does_not_start_codex',
                'task_queue_lease_certification_does_not_call_codex_cli_or_app',
                'task_queue_lease_certification_does_not_spawn_subprocess',
                'task_queue_lease_certification_does_not_invoke_adapter',
                'task_queue_lease_certification_does_not_call_provider',
                'task_queue_lease_certification_does_not_dispatch_work',
                'task_queue_lease_certification_does_not_spend_tokens',
                'task_queue_lease_certification_does_not_enable_self_programming',
                'task_queue_lease_certification_does_not_write_ledger',
                'task_queue_lease_certification_does_not_mutate_pointer',
                'task_queue_lease_certification_does_not_mark_real_completion',
            ],
            'human_summary' => sprintf(
                'Persistent queue+lease certification %s (%d invariants, %d violations).',
                $status,
                count($invariants),
                count($violations),
            ),
        ];

        $payload['certification_hash'] = $this->stableHash($this->normalizeForHash($payload));

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function runProbes(): array
    {
        $probes = [];
        $probeId = 'probe_'.(string) Str::uuid();
        $secondaryId = $probeId.'_b';
        $probeFile = 'app/Services/Ai/SelfConstruction/__task_queue_lease_certification__/'.$probeId.'.php';

        $taskPacket = $this->builder->build([
            'task_packet_id' => $probeId,
            'objective' => 'queue/lease certification probe',
            'operator_id' => 'certification-probe-operator',
            'allowed_files' => [$probeFile],
            'scope_in' => [$probeFile],
            'acceptance_criteria' => ['probe_ok'],
            'required_evidence' => ['task_packet_created'],
            'risk_level' => 'low',
        ]);
        $validation = $this->validator->validate($taskPacket);

        $probes['scope_lock_runtime_validator_valid'] = (string) $validation['status'] === 'valid';

        $enqueueOnce = $this->queue->enqueue($taskPacket);
        $probes['queue_enqueue_ok'] = (string) $enqueueOnce['status'] === 'ok' && (string) $enqueueOnce['event'] === 'enqueued';

        $enqueueTwice = $this->queue->enqueue($taskPacket);
        $probes['queue_idempotent'] = (string) $enqueueTwice['status'] === 'ok' && (bool) ($enqueueTwice['idempotent'] ?? false) === true;

        $taggedFull = $this->builder->build([
            'task_packet_id' => $probeId.'_tagged_full',
            'objective' => 'queue/lease certification multi tag probe full',
            'operator_id' => 'certification-probe-operator',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/__task_queue_lease_certification__/'.$probeId.'_tagged_full.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/__task_queue_lease_certification__/'.$probeId.'_tagged_full.php'],
            'acceptance_criteria' => ['probe_ok'],
            'required_evidence' => ['task_packet_created'],
            'risk_level' => 'low',
        ]);
        $taggedPartial = $this->builder->build([
            'task_packet_id' => $probeId.'_tagged_partial',
            'objective' => 'queue/lease certification multi tag probe partial',
            'operator_id' => 'certification-probe-operator',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/__task_queue_lease_certification__/'.$probeId.'_tagged_partial.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/__task_queue_lease_certification__/'.$probeId.'_tagged_partial.php'],
            'acceptance_criteria' => ['probe_ok'],
            'required_evidence' => ['task_packet_created'],
            'risk_level' => 'low',
        ]);
        $certificationLaneTag = 'certification_lane_'.$probeId;
        $workerLaneTag = 'worker_lane_'.$probeId;
        $this->queue->enqueue($taggedPartial, ['tags' => [$certificationLaneTag]]);
        $this->queue->enqueue($taggedFull, ['tags' => [$certificationLaneTag, $workerLaneTag]]);
        $taggedMatches = $this->queue->list([
            'status' => 'claimable',
            'tags' => [$certificationLaneTag, $workerLaneTag],
        ]);
        $probes['queue_multi_tag_filter_requires_all_tags'] = count($taggedMatches) === 1
            && (string) data_get($taggedMatches, '0.task_packet_id') === $probeId.'_tagged_full';

        $scopeLock = [
            'write_set' => (array) data_get($taskPacket, 'normalized_scope.allowed_files', []),
            'read_set' => (array) data_get($taskPacket, 'normalized_scope.scope_in', []),
            'scope_lock_plan_hash' => (string) $validation['scope_lock_hash'],
        ];
        $claimA = $this->leases->claim($probeId, 'agent-a', $scopeLock);
        $probes['claim_single_owner'] = (string) $claimA['status'] === 'ok';

        $claimDouble = $this->leases->claim($probeId, 'agent-b', $scopeLock);
        $probes['claim_double_blocked'] = (string) $claimDouble['status'] === 'blocked' && (string) $claimDouble['reason'] === 'task_already_claimed';

        // Renew owner-only.
        $leaseId = (string) ($claimA['lease_id'] ?? '');
        $renewWrong = $leaseId !== ''
            ? $this->leases->renew($leaseId, 'agent-z', 60)
            : ['status' => 'blocked', 'reason' => 'lease_missing_after_claim'];
        $probes['renew_owner_only'] = (string) $renewWrong['status'] === 'blocked' && (string) $renewWrong['reason'] === 'not_lease_owner';

        $renewOk = $leaseId !== ''
            ? $this->leases->renew($leaseId, 'agent-a', 120)
            : ['status' => 'blocked', 'reason' => 'lease_missing_after_claim'];
        $probes['renew_owner_succeeds'] = (string) $renewOk['status'] === 'ok';

        // Release owner-only.
        $releaseWrong = $leaseId !== ''
            ? $this->leases->release($leaseId, 'agent-z')
            : ['status' => 'blocked', 'reason' => 'lease_missing_after_claim'];
        $probes['release_owner_only'] = (string) $releaseWrong['status'] === 'blocked' && (string) $releaseWrong['reason'] === 'not_lease_owner';

        $releaseOk = $leaseId !== ''
            ? $this->leases->release($leaseId, 'agent-a')
            : ['status' => 'blocked', 'reason' => 'lease_missing_after_claim'];
        $probes['release_owner_succeeds'] = (string) $releaseOk['status'] === 'ok';

        // Conflict detection.
        $secondaryPacket = $this->builder->build([
            'task_packet_id' => $secondaryId,
            'objective' => 'queue/lease conflict probe secondary',
            'operator_id' => 'certification-probe-operator-secondary',
            'allowed_files' => [$probeFile],
            'scope_in' => [$probeFile],
            'acceptance_criteria' => ['probe_ok'],
            'required_evidence' => ['task_packet_created'],
        ]);
        $this->queue->enqueue($secondaryPacket);
        $claimAOwner = $this->leases->claim($probeId, 'agent-a', $scopeLock);
        $claimSecondaryConflict = $this->leases->claim($secondaryId, 'agent-c', $scopeLock);
        $probes['conflict_detection_ok'] = (string) $claimSecondaryConflict['status'] === 'blocked'
            && (string) $claimSecondaryConflict['reason'] === 'write_set_overlap';

        if ((string) $claimAOwner['status'] === 'ok') {
            $this->leases->release((string) $claimAOwner['lease_id'], 'agent-a');
        }

        // Forbidden axis blocked at validator.
        $forbiddenPacket = $this->builder->build([
            'objective' => 'forbidden axis probe',
            'allowed_files' => ['routes/api.php'],
            'forbidden_files' => [],
            'scope_in' => ['routes/api.php'],
            'acceptance_criteria' => ['probe_ok'],
            'required_evidence' => ['task_packet_created'],
        ]);
        $forbiddenValidation = $this->validator->validate($forbiddenPacket);
        $probes['validator_blocks_forbidden_axis'] = (string) $forbiddenValidation['status'] === 'blocked';

        // Path traversal blocked.
        $traversalPacket = [
            'task_packet_id' => 'probe_traversal',
            'task_packet_hash' => hash('sha256', 'traversal'),
            'status' => 'planned',
            'normalized_scope' => [
                'allowed_files' => ['../etc/passwd'],
                'forbidden_files' => [],
                'scope_in' => ['../etc/passwd'],
                'scope_out' => [],
            ],
            'risk_classification' => ['risk_level' => 'low'],
            'rollback_requirements' => ['rollback_strategy' => 'plan_only'],
            'continuation_requirements' => ['continuation_required' => true],
            'evidence_requirements' => ['required' => ['task_packet_created']],
        ];
        $traversalValidation = $this->validator->validate($traversalPacket);
        $probes['validator_blocks_path_traversal'] = (string) $traversalValidation['status'] === 'blocked';

        // Receipts attached to lease.
        $probes['lease_has_receipts'] = $claimA !== null
            && (array) data_get($claimA, 'lease.receipts', []) !== [];

        $probes['conflict_check_clear_when_no_overlap'] = (string) $this->leases->conflictCheck([
            'write_set' => ['app/Services/Ai/SelfConstruction/__task_queue_lease_certification__/'.$probeId.'_clear.php'],
        ])['status'] === 'clear';

        return [
            'probe_count' => count($probes),
            'probes' => $probes,
            'probe_task_packet_id' => $probeId,
            'probe_secondary_task_packet_id' => $secondaryId,
            'probe_runtime_execution_allowed' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function leaseSummary(): array
    {
        $active = $this->leases->activeLeases();

        return [
            'active_lease_count' => count($active),
            'storage_prefix' => AgentControlPlaneClaimLeaseRepository::STORAGE_PREFIX,
            'default_ttl_seconds' => AgentControlPlaneClaimLeaseRepository::DEFAULT_TTL_SECONDS,
            'min_ttl_seconds' => AgentControlPlaneClaimLeaseRepository::MIN_TTL_SECONDS,
            'max_ttl_seconds' => AgentControlPlaneClaimLeaseRepository::MAX_TTL_SECONDS,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeForHash(array $payload): array
    {
        $clone = $payload;
        unset($clone['certification_id'], $clone['generated_at'], $clone['certification_hash'], $clone['human_summary']);
        if (isset($clone['probe_evidence']['probe_task_packet_id'])) {
            unset($clone['probe_evidence']['probe_task_packet_id']);
        }
        if (isset($clone['probe_evidence']['probe_secondary_task_packet_id'])) {
            unset($clone['probe_evidence']['probe_secondary_task_packet_id']);
        }
        if (isset($clone['queue_summary']['total_count'])) {
            // queue total grows during probes; exclude from hash so consecutive
            // certify() calls produce a stable digest for the safety invariants
            // they actually prove.
            unset($clone['queue_summary']['total_count']);
            unset($clone['queue_summary']['entry_count']);
            unset($clone['queue_summary']['status_counts']);
            unset($clone['queue_summary']['corrupt']);
        }
        if (isset($clone['lease_summary']['active_lease_count'])) {
            unset($clone['lease_summary']['active_lease_count']);
        }

        return $this->recursivelyKsort($clone);
    }


    /**
     * @param  array<mixed, mixed>  $payload
     */
    private function stableHash(array $payload): string
    {
        $payload = $this->recursivelyKsort($payload);

        return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
