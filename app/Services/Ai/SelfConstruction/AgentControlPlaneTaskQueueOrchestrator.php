<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Orchestrates the first persistent runtime layer of the Agent Control
 * Plane: Task Packet Builder → Scope Lock Runtime Validator → Task Packet
 * Queue Repository → Claim/Lease Repository → Evidence Ledger Dry-Run →
 * Continuation Summary Builder. Every step still writes only to local
 * storage; no provider call, no dispatch, no real completion.
 *
 * Runtime-safe: never starts processes, never calls Codex CLI/app, never
 * spawns subprocesses, never invokes adapters, never dispatches work,
 * never spends tokens, never advances the next required slice, never
 * enables self-programming and never writes the evidence ledger.
 */
final class AgentControlPlaneTaskQueueOrchestrator
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_task_queue_orchestrator.v1';

    public const MODE = 'persistent_local_agent_control_plane_task_queue_orchestrator';

    public function __construct(
        private readonly AgentControlPlaneTaskPacketBuilder $builder,
        private readonly AgentControlPlaneScopeLockRuntimeValidator $validator,
        private readonly AgentControlPlaneTaskPacketQueueRepository $queue,
        private readonly AgentControlPlaneClaimLeaseRepository $leases,
        private readonly AgentControlPlaneEvidenceLedgerDryRun $evidence,
        private readonly AgentControlPlaneContinuationSummaryBuilder $continuation,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function prepareAndEnqueue(array $input): array
    {
        $packetInput = (array) ($input['task_packet'] ?? $input);
        $queueOptions = (array) ($input['queue'] ?? []);
        $validatorOptions = (array) ($input['validator'] ?? []);

        $packet = $this->builder->build($packetInput);
        $validation = $this->validator->validate($packet, $validatorOptions);

        if ((string) ($packet['status'] ?? '') !== 'planned' || (string) ($validation['status'] ?? '') !== 'valid') {
            return $this->envelope('prepare_blocked', [
                'task_packet' => $packet,
                'validation' => $validation,
                'queue_entry' => null,
                'evidence_plan' => null,
                'continuation_summary' => null,
                'reason' => 'task_packet_or_validation_blocked',
            ]);
        }

        $enqueueResult = $this->queue->enqueue($packet, [
            'metadata' => [
                'scope_lock_hash' => (string) $validation['scope_lock_hash'],
                'validation_hash' => (string) $validation['validation_hash'],
            ],
            'priority' => (int) ($queueOptions['priority'] ?? 5),
            'tags' => (array) ($queueOptions['tags'] ?? []),
        ]);

        $evidencePlan = $this->evidence->plan($packet, [
            'write_set' => (array) $validation['normalized_scope_lock']['write_set'],
            'read_set' => (array) $validation['normalized_scope_lock']['read_set'],
            'scope_lock_plan_hash' => (string) $validation['scope_lock_hash'],
            'blocking_reasons' => [],
        ]);
        $continuation = $this->continuation->build($packet, $evidencePlan);

        // Attach planning receipts to the queue record.
        $taskPacketId = (string) data_get($enqueueResult, 'task_packet_id', '');
        if ($taskPacketId !== '' && (string) $enqueueResult['status'] === 'ok') {
            $this->queue->appendReceipt($taskPacketId, [
                'receipt_kind' => 'scope_lock_runtime_validated',
                'scope_lock_hash' => (string) $validation['scope_lock_hash'],
                'validation_hash' => (string) $validation['validation_hash'],
            ]);
            $this->queue->appendReceipt($taskPacketId, [
                'receipt_kind' => 'evidence_plan_prepared',
                'evidence_hash' => (string) $evidencePlan['evidence_hash'],
                'evidence_plan_hash' => (string) $evidencePlan['evidence_plan_hash'],
            ]);
            $this->queue->appendReceipt($taskPacketId, [
                'receipt_kind' => 'continuation_summary_prepared',
                'continuation_hash' => (string) $continuation['continuation_hash'],
            ]);
        }

        return $this->envelope('prepared_and_enqueued', [
            'task_packet' => $packet,
            'validation' => $validation,
            'queue_entry' => $enqueueResult,
            'evidence_plan' => $evidencePlan,
            'continuation_summary' => $continuation,
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function claimNext(string $agentId, array $filters = []): array
    {
        if ($agentId === '') {
            return $this->envelope('claim_blocked', ['reason' => 'agent_id_missing']);
        }

        // A3/MF-05: reclaim stranded leases BEFORE listing, so a task stranded by an expired lease, an orphaned
        // claim, OR a client give-back (status `released`) is visible (and serveable) again this very call —
        // R1/R2 recovery on the hot path, not just by the scheduled reaper. Best-effort: a hiccup in recovery
        // never blocks a claim.
        $this->reapExpiredBeforeListing();

        $candidates = $this->queue->list(array_merge(['status' => 'claimable'], $filters));
        foreach ($candidates as $candidate) {
            if (! $this->candidateCanBeClaimedByWorker($candidate)) {
                continue;
            }

            $taskPacketId = (string) $candidate['task_packet_id'];
            $scopeLock = [
                'write_set' => (array) data_get($candidate, 'task_packet.normalized_scope.allowed_files', []),
                'read_set' => (array) data_get($candidate, 'task_packet.normalized_scope.scope_in', []),
                'scope_lock_plan_hash' => (string) data_get($candidate, 'metadata.scope_lock_hash', ''),
            ];
            $claim = $this->leases->claim($taskPacketId, $agentId, $scopeLock, [
                'ttl_seconds' => (int) ($filters['ttl_seconds'] ?? 1800),
            ]);
            if ((string) $claim['status'] === 'ok') {
                // A2/MF-16: the lease (A1) already serialized the winner; the ATOMIC compare-and-swap
                // claimable->claimed guarantees the queue record can never be double-flipped by a stale
                // selection. If the record moved under us, release the lease we just took and try the next.
                $swap = $this->queue->compareAndSwapStatus($taskPacketId, 'claimable', 'claimed', [
                    'lease_id' => (string) $claim['lease_id'],
                    'agent_id' => $agentId,
                ]);
                if (($swap['swapped'] ?? false) !== true) {
                    $this->leases->release((string) $claim['lease_id'], $agentId, ['reason' => 'queue_status_moved']);

                    continue;
                }
                $this->queue->appendReceipt($taskPacketId, [
                    'receipt_kind' => 'claim_acquired_by_orchestrator',
                    'lease_id' => (string) $claim['lease_id'],
                    'agent_id' => $agentId,
                ]);

                return $this->envelope('claimed', [
                    'queue_entry' => $candidate,
                    'lease' => $claim['lease'] ?? null,
                    'lease_id' => (string) $claim['lease_id'],
                    'task_packet_id' => $taskPacketId,
                    'agent_id' => $agentId,
                ]);
            }
            // Conflict: try next candidate.
        }

        return $this->envelope('no_claimable_task', [
            'agent_id' => $agentId,
            'candidate_count' => count($candidates),
        ]);
    }

    /**
     * A3/MF-05 — return any stranded task to `claimable` before the claim scan, reusing this orchestrator's OWN
     * queue + lease repos (same disk/lock config). Best-effort + fail-open: recovery never throws into the
     * claim path. Equivalent to the scheduled reaper, on the hot path. Three strands:
     *   - EXPIRED leases (dead client past TTL) — `recoverExpiredLeases`.
     *   - ORPHANED claims (queue stuck `claimed` with a missing/non-active lease) — `recoverOrphanedClaims`.
     *   - RELEASED tasks (a client reported give_back/failed) — `recoverReleasedTasks`. WITHOUT this, a
     *     give-back stranded the task in `released` forever (never re-listed), silently draining the queue
     *     (R1) and failing to re-serve recoverable work (R2). The recovery itself SKIPS released-with-blocker
     *     reasons (operator-investigation), so only transient give-backs are re-admitted.
     */
    private function reapExpiredBeforeListing(): void
    {
        try {
            $recovery = new AgentControlPlaneTaskLeaseRecoveryService($this->queue, $this->leases);
            $recovery->recoverExpiredLeases(['actor' => 'claim_next_presweep']);
            $recovery->recoverOrphanedClaims(['actor' => 'claim_next_presweep']);
            $recovery->recoverReleasedTasks(['actor' => 'claim_next_presweep']);
        } catch (Throwable) {
            // Pre-sweep is best-effort; a recovery hiccup must never block serving a claim.
        }
    }

    /** @param array<string, mixed> $candidate */
    private function candidateCanBeClaimedByWorker(array $candidate): bool
    {
        if ((bool) data_get($candidate, 'task_packet.continuation_context.worker_executable', true) === false) {
            return false;
        }
        if ((bool) data_get($candidate, 'task_packet.continuation_context.operator_handoff_required', false)) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function renewLease(string $leaseId, string $agentId, int $ttlSeconds): array
    {
        $renewal = $this->leases->renew($leaseId, $agentId, $ttlSeconds);

        return $this->envelope('lease_renewal', ['renewal' => $renewal]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function releaseLease(string $leaseId, string $agentId, array $options = []): array
    {
        $release = $this->leases->release($leaseId, $agentId, $options);
        $taskPacketId = (string) data_get($release, 'task_packet_id', '');
        if ($taskPacketId !== '' && (string) $release['status'] === 'ok') {
            $this->queue->updateStatus($taskPacketId, 'released', [
                'lease_id' => $leaseId,
                'release_reason' => (string) ($options['reason'] ?? 'released_by_owner'),
            ]);
            $this->queue->appendReceipt($taskPacketId, [
                'receipt_kind' => 'lease_released_by_orchestrator',
                'lease_id' => $leaseId,
                'agent_id' => $agentId,
            ]);
        }

        return $this->envelope('lease_release', ['release' => $release]);
    }

    /**
     * Axis 8 — QUARANTINE a claimed packet that is NOT self-sufficient: a cold client could never implement or
     * prove it (e.g. no acceptance criteria / no required evidence / a bare-dir write scope). Release the lease
     * and move the queue record `claimed → blocked` so it is never re-offered to a client until an operator
     * fixes it (recovery never auto-reopens `blocked`). The deficiencies are recorded on the receipt. This is
     * how the serving path guarantees a client only ever receives an implementable task.
     *
     * @param  list<string>  $deficiencies
     * @return array<string, mixed>
     */
    public function quarantineClaimed(string $taskPacketId, string $leaseId, string $agentId, array $deficiencies = []): array
    {
        // Release the lease (registry only) so no active lease lingers; the queue record stays `claimed`.
        $this->leases->release($leaseId, $agentId, ['reason' => 'packet_not_self_sufficient']);

        $transition = $this->queue->updateStatus($taskPacketId, 'blocked', [
            'lease_id' => $leaseId,
            'agent_id' => $agentId,
            'reason' => 'packet_not_self_sufficient',
            'blocking_deficiencies' => array_values($deficiencies),
        ]);
        $this->queue->appendReceipt($taskPacketId, [
            'receipt_kind' => 'packet_quarantined_not_self_sufficient',
            'lease_id' => $leaseId,
            'agent_id' => $agentId,
            'blocking_deficiencies' => array_values($deficiencies),
        ]);

        return $this->envelope('packet_quarantined', [
            'task_packet_id' => $taskPacketId,
            'lease_id' => $leaseId,
            'blocking_deficiencies' => array_values($deficiencies),
            'queue_transition' => (string) ($transition['status'] ?? ''),
        ]);
    }

    /**
     * Finalises the dry-run cycle: the lease is released and the queue
     * record moves to `completed_dry_run`. Real completion remains forbidden.
     *
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    public function completeDryRun(string $taskPacketId, string $leaseId, array $evidence = []): array
    {
        $lease = $this->leases->get($leaseId);
        if ($lease === null) {
            return $this->envelope('complete_dry_run_blocked', ['reason' => 'lease_not_found']);
        }
        if ((string) $lease['task_packet_id'] !== $taskPacketId) {
            return $this->envelope('complete_dry_run_blocked', [
                'reason' => 'task_packet_lease_mismatch',
                'lease_task_packet_id' => (string) $lease['task_packet_id'],
                'requested_task_packet_id' => $taskPacketId,
            ]);
        }
        if ((string) $lease['lease_status'] !== AgentControlPlaneClaimLeaseRepository::LEASE_STATUS_ACTIVE) {
            return $this->envelope('complete_dry_run_blocked', [
                'reason' => 'lease_not_active',
                'lease_status' => (string) $lease['lease_status'],
            ]);
        }
        $queueRecord = $this->queue->get($taskPacketId);
        if ($queueRecord === null) {
            return $this->envelope('complete_dry_run_blocked', [
                'reason' => 'task_packet_not_found',
                'task_packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
            ]);
        }
        $agentId = (string) $lease['agent_id'];
        $queueStatus = (string) ($queueRecord['status'] ?? '');
        $queueLeaseId = (string) data_get($queueRecord, 'metadata.lease_id', '');
        $queueAgentId = (string) data_get($queueRecord, 'metadata.agent_id', '');
        if ($queueStatus !== 'claimed') {
            return $this->envelope('complete_dry_run_blocked', [
                'reason' => 'task_packet_not_claimed_for_completion',
                'task_packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
                'queue_status' => $queueStatus,
                'completion_real_allowed' => false,
            ]);
        }
        if ($queueLeaseId === '' || $queueLeaseId !== $leaseId) {
            return $this->envelope('complete_dry_run_blocked', [
                'reason' => 'queue_lease_id_mismatch',
                'task_packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
                'queue_lease_id' => $queueLeaseId,
                'completion_real_allowed' => false,
            ]);
        }
        if ($queueAgentId === '' || $queueAgentId !== $agentId) {
            return $this->envelope('complete_dry_run_blocked', [
                'reason' => 'queue_agent_id_mismatch',
                'task_packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
                'agent_id' => $agentId,
                'queue_agent_id' => $queueAgentId,
                'completion_real_allowed' => false,
            ]);
        }
        $completionEvidence = $this->completionEvidence($evidence);
        $evidenceValidation = $this->validateCompletionEvidence($completionEvidence, [
            'task_packet_id' => $taskPacketId,
            'lease_id' => $leaseId,
            'agent_id' => $agentId,
            'allowed_files' => $this->allowedFilesForRecord($queueRecord),
        ]);
        if ($evidenceValidation['blockers'] !== []) {
            return $this->envelope('complete_dry_run_blocked', [
                'reason' => (string) $evidenceValidation['blockers'][0],
                'task_packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
                'evidence_validation' => $evidenceValidation,
                'completion_real_allowed' => false,
            ]);
        }

        $release = $this->leases->release($leaseId, $agentId, ['reason' => 'completed_dry_run']);

        $this->queue->appendReceipt($taskPacketId, [
            'receipt_kind' => 'dry_run_completion_recorded',
            'lease_id' => $leaseId,
            'agent_id' => $agentId,
            'evidence_hash' => (string) $evidenceValidation['evidence_hash'],
            'evidence_validation_status' => (string) $evidenceValidation['status'],
            'evidence_validation_hash' => (string) $evidenceValidation['evidence_validation_hash'],
            'evidence_validation_blockers' => (array) $evidenceValidation['blockers'],
            'structured_completion_evidence_required' => true,
            'structured_completion_evidence_valid' => (bool) $evidenceValidation['structured_completion_evidence_valid'],
            'queue_claim_binding_verified' => true,
            'queue_status_at_completion' => $queueStatus,
            'queue_lease_id' => $queueLeaseId,
            'queue_agent_id' => $queueAgentId,
            'files_changed_within_allowed_scope' => (bool) $evidenceValidation['files_changed_within_allowed_scope'],
            'files_changed_outside_allowed_scope' => (array) $evidenceValidation['files_changed_outside_allowed_scope'],
            'evidence_keys' => array_keys($completionEvidence),
            'evidence_digest' => hash('sha256', (string) json_encode($completionEvidence, JSON_THROW_ON_ERROR)),
        ]);
        $update = $this->queue->updateStatus($taskPacketId, 'completed_dry_run', [
            'lease_id' => $leaseId,
            'agent_id' => $agentId,
        ]);

        return $this->envelope('completed_dry_run', [
            'task_packet_id' => $taskPacketId,
            'lease_id' => $leaseId,
            'release' => $release,
            'queue_update' => $update,
            'evidence_validation' => $evidenceValidation,
            'queue_claim_binding_verified' => true,
            'queue_status_at_completion' => $queueStatus,
            'queue_lease_id' => $queueLeaseId,
            'queue_agent_id' => $queueAgentId,
            'completion_real_allowed' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function completionEvidence(array $evidence): array
    {
        $nested = $evidence['completion_evidence'] ?? null;
        if (is_array($nested)) {
            return array_merge($nested, [
                'evidence_hash' => $evidence['evidence_hash'] ?? $evidence['operator_supplied_evidence_hash'] ?? $nested['evidence_hash'] ?? null,
            ]);
        }

        return $evidence;
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function validateCompletionEvidence(array $evidence, array $expectedBinding): array
    {
        $evidenceHash = strtolower(trim((string) ($evidence['evidence_hash'] ?? $evidence['operator_supplied_evidence_hash'] ?? '')));
        $computedEvidenceHash = self::canonicalCompletionEvidenceHash($evidence);
        $expectedTaskPacketId = (string) ($expectedBinding['task_packet_id'] ?? '');
        $expectedLeaseId = (string) ($expectedBinding['lease_id'] ?? '');
        $expectedAgentId = (string) ($expectedBinding['agent_id'] ?? '');
        $allowedFiles = $this->stringList((array) ($expectedBinding['allowed_files'] ?? []));
        $evidenceTaskPacketId = trim((string) ($evidence['packet_id'] ?? $evidence['task_packet_id'] ?? ''));
        $evidenceLeaseId = trim((string) ($evidence['lease_id'] ?? ''));
        $evidenceActor = trim((string) ($evidence['actor'] ?? $evidence['agent_id'] ?? ''));
        $blockers = [];
        $requiredFields = [
            'packet_id',
            'lease_id',
            'evidence_hash',
            'files_changed',
            'commands_run',
            'tests_or_gates_result',
            'git_status_short',
            'git_diff_check_result',
        ];
        $providedFields = array_values(array_intersect($requiredFields, array_keys($evidence)));
        $missingFields = [];
        if ($evidenceHash === '') {
            $blockers[] = 'evidence_hash_missing';
            $missingFields[] = 'evidence_hash';
        } elseif (preg_match('/^[a-f0-9]{64}$/', $evidenceHash) !== 1) {
            $blockers[] = 'evidence_hash_invalid';
        } elseif (! hash_equals($computedEvidenceHash, $evidenceHash)) {
            $blockers[] = 'evidence_hash_mismatch';
        }
        if ($evidenceTaskPacketId === '') {
            $blockers[] = 'packet_id_missing';
            $missingFields[] = 'packet_id';
        } elseif ($expectedTaskPacketId !== '' && $evidenceTaskPacketId !== $expectedTaskPacketId) {
            $blockers[] = 'packet_id_mismatch';
        }
        if ($evidenceLeaseId === '') {
            $blockers[] = 'lease_id_missing';
            $missingFields[] = 'lease_id';
        } elseif ($expectedLeaseId !== '' && $evidenceLeaseId !== $expectedLeaseId) {
            $blockers[] = 'lease_id_mismatch';
        }
        if ($evidenceActor !== '' && $expectedAgentId !== '' && $evidenceActor !== $expectedAgentId) {
            $blockers[] = 'actor_mismatch';
        }

        $testsOrGates = strtolower(trim((string) ($evidence['tests_or_gates_result'] ?? '')));
        if ($testsOrGates !== '' && ! in_array($testsOrGates, ['pass', 'passed', 'green'], true)) {
            $blockers[] = 'tests_or_gates_result_not_passing';
        }
        if ($testsOrGates === '') {
            $blockers[] = 'tests_or_gates_result_missing';
            $missingFields[] = 'tests_or_gates_result';
        }

        $filesChanged = $this->stringList((array) ($evidence['files_changed'] ?? []));
        if ($filesChanged === []) {
            $blockers[] = 'files_changed_missing';
            $missingFields[] = 'files_changed';
        }
        $filesChangedOutsideAllowedScope = $allowedFiles === []
            ? []
            : array_values(array_diff($filesChanged, $allowedFiles));
        if ($filesChanged !== [] && $allowedFiles === []) {
            $blockers[] = 'allowed_files_missing_for_completion_scope_check';
        } elseif ($filesChangedOutsideAllowedScope !== []) {
            $blockers[] = 'files_changed_outside_allowed_scope';
        }
        $commandsRun = $this->stringList((array) ($evidence['commands_run'] ?? []));
        if ($commandsRun === []) {
            $blockers[] = 'commands_run_missing';
            $missingFields[] = 'commands_run';
        }
        $gitStatusShort = trim((string) ($evidence['git_status_short'] ?? ''));
        if ($gitStatusShort === '') {
            $blockers[] = 'git_status_short_missing';
            $missingFields[] = 'git_status_short';
        }
        $diffCheckResult = strtolower(trim((string) ($evidence['git_diff_check_result'] ?? '')));
        if ($diffCheckResult === '') {
            $blockers[] = 'git_diff_check_result_missing';
            $missingFields[] = 'git_diff_check_result';
        } elseif (! in_array($diffCheckResult, ['clean', 'passed', 'pass', 'ok'], true)) {
            $blockers[] = 'git_diff_check_result_not_clean';
        }

        $missingFields = array_values(array_unique($missingFields));
        $blockers = array_values(array_unique($blockers));
        $structuredCompletionEvidenceValid = $missingFields === []
            && $evidenceHash !== ''
            && preg_match('/^[a-f0-9]{64}$/', $evidenceHash) === 1
            && hash_equals($computedEvidenceHash, $evidenceHash)
            && $evidenceTaskPacketId !== ''
            && ($expectedTaskPacketId === '' || $evidenceTaskPacketId === $expectedTaskPacketId)
            && $evidenceLeaseId !== ''
            && ($expectedLeaseId === '' || $evidenceLeaseId === $expectedLeaseId)
            && ($evidenceActor === '' || $expectedAgentId === '' || $evidenceActor === $expectedAgentId)
            && in_array($testsOrGates, ['pass', 'passed', 'green'], true)
            && $filesChanged !== []
            && $allowedFiles !== []
            && $filesChangedOutsideAllowedScope === []
            && $commandsRun !== []
            && $gitStatusShort !== ''
            && in_array($diffCheckResult, ['clean', 'passed', 'pass', 'ok'], true);

        $validation = [
            'schema_version' => 'atlas.self_construction.agent_control_plane_task_queue_completion_evidence_validation.v1',
            'status' => $blockers === [] ? 'valid' : 'blocked',
            'structured_completion_evidence_required' => true,
            'structured_completion_evidence_valid' => $structuredCompletionEvidenceValid,
            'required_fields' => $requiredFields,
            'provided_fields' => $providedFields,
            'missing_fields' => $missingFields,
            'missing_field_count' => count($missingFields),
            'expected_binding' => [
                'task_packet_id' => $expectedTaskPacketId,
                'lease_id' => $expectedLeaseId,
                'agent_id' => $expectedAgentId,
                'allowed_files' => $allowedFiles,
            ],
            'evidence_binding' => [
                'task_packet_id' => $evidenceTaskPacketId,
                'lease_id' => $evidenceLeaseId,
                'actor' => $evidenceActor,
            ],
            'packet_id_matches' => $evidenceTaskPacketId !== '' && ($expectedTaskPacketId === '' || $evidenceTaskPacketId === $expectedTaskPacketId),
            'lease_id_matches' => $evidenceLeaseId !== '' && ($expectedLeaseId === '' || $evidenceLeaseId === $expectedLeaseId),
            'actor_matches' => $evidenceActor === '' || $expectedAgentId === '' || $evidenceActor === $expectedAgentId,
            'evidence_hash' => $evidenceHash,
            'computed_evidence_hash' => $computedEvidenceHash,
            'evidence_hash_present' => $evidenceHash !== '',
            'evidence_hash_valid' => $evidenceHash !== '' && preg_match('/^[a-f0-9]{64}$/', $evidenceHash) === 1,
            'evidence_hash_matches_payload' => $evidenceHash !== ''
                && preg_match('/^[a-f0-9]{64}$/', $evidenceHash) === 1
                && hash_equals($computedEvidenceHash, $evidenceHash),
            'evidence_hash_algorithm' => 'sha256(canonical_json(completion_evidence_without_evidence_hash_fields))',
            'files_changed_count' => count($filesChanged),
            'files_changed' => $filesChanged,
            'allowed_files_count' => count($allowedFiles),
            'files_changed_within_allowed_scope' => $filesChanged !== [] && $allowedFiles !== [] && $filesChangedOutsideAllowedScope === [],
            'files_changed_outside_allowed_scope' => $filesChangedOutsideAllowedScope,
            'commands_run_count' => count($commandsRun),
            'tests_or_gates_result' => $testsOrGates,
            'tests_or_gates_passing' => in_array($testsOrGates, ['pass', 'passed', 'green'], true),
            'git_status_short_present' => $gitStatusShort !== '',
            'git_diff_check_result' => $diffCheckResult,
            'git_diff_check_clean' => in_array($diffCheckResult, ['clean', 'passed', 'pass', 'ok'], true),
            'blocker_count' => count($blockers),
            'blockers' => $blockers,
            'completion_real_allowed' => false,
        ];
        $validation['evidence_validation_hash'] = hash('sha256', (string) json_encode($validation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $validation;
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    public static function canonicalCompletionEvidenceHash(array $evidence): string
    {
        $normalized = self::normalizeEvidenceForHash($evidence);

        return hash('sha256', (string) json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function normalizeEvidenceForHash(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        unset($value['evidence_hash'], $value['operator_supplied_evidence_hash']);

        foreach ($value as $key => $nested) {
            $value[$key] = self::normalizeEvidenceForHash($nested);
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<string>
     */
    private function allowedFilesForRecord(array $record): array
    {
        $allowed = $this->stringList((array) data_get($record, 'task_packet.normalized_scope.allowed_files', []));
        if ($allowed !== []) {
            return $allowed;
        }

        return $this->stringList((array) data_get($record, 'task_packet.allowed_files', []));
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values,
        ), static fn (string $value): bool => $value !== ''));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function envelope(string $event, array $payload): array
    {
        return array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'event' => $event,
            'orchestration_id' => (string) Str::uuid(),
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'completion_real_allowed' => false,
            'non_execution_guarantees' => [
                'task_queue_orchestrator_does_not_start_codex',
                'task_queue_orchestrator_does_not_call_codex_cli_or_app',
                'task_queue_orchestrator_does_not_spawn_subprocess',
                'task_queue_orchestrator_does_not_invoke_adapter',
                'task_queue_orchestrator_does_not_call_provider',
                'task_queue_orchestrator_does_not_dispatch_work',
                'task_queue_orchestrator_does_not_spend_tokens',
                'task_queue_orchestrator_does_not_enable_self_programming',
                'task_queue_orchestrator_does_not_write_ledger',
                'task_queue_orchestrator_does_not_mutate_pointer',
                'task_queue_orchestrator_does_not_mark_real_completion',
            ],
        ], $payload);
    }
}
