<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TerminalWorkerBootstrap;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\Support\HashesPayloadCanonically;

/**
 * ITEM8 — the cohesive worker-eligibility validation concern the terminal-worker bootstrap service
 * uses to assert every claimable record satisfies the "one-terminal-runs-one-packet-at-a-time"
 * contract (worker-executable / not operator-only / no runtime-allowed flags / etc.).
 *
 * One method migrated verbatim from
 * {@see \App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTerminalWorkerBootstrapService}:
 *  - {@see self::workerEligibilityGuard}: read the current claimable records from the queue
 *    repository, scan each for the seven violation codes (`claimable_task_not_worker_executable`,
 *    `claimable_task_requires_operator_handoff`,
 *    `claimable_task_references_operator_only_completion_blocker`, and the seven
 *    `claimable_task_runtime_flag_true` variants for the runtime-allowed flags), and emit the
 *    guard envelope (status + eligible count + violations + stable hash + non-execution guarantees).
 *
 * The queue repository is constructor-injected so the guard reads the canonical claimable records
 * directly (no need to thread them through the bootstrap service). The `OPERATOR_ONLY_COMPLETION_CRITERIA`
 * constant mirrors the bootstrap service's constant verbatim so the byte-identical contract survives
 * the split. Pure / zero Laravel surface / stableHash is local to keep cross-class coupling minimal.
 */
class AgentControlPlaneWorkerEligibilityGuard
{
    use HashesPayloadCanonically;

    /**
     * The references that, when present in `continuation_context.auto_replenishment_reference`,
     * make a claimable task ineligible for direct worker execution (the operator must sign the
     * completion offline).
     */
    private const OPERATOR_ONLY_COMPLETION_CRITERIA = [
        'runtime_gap_matrix_all_runtime_y',
        'human_signed_os_complete_receipt_present',
        'end_to_end_real_provider_smoke_green',
    ];

    /**
     * The seven runtime-allowed flags on a task packet that, when true, disqualify it from direct
     * worker execution (the worker lane is read-only; any of these set on a claimable packet is
     * a forged-bytes / wrong-queue signal).
     */
    private const RUNTIME_FLAG_NAMES = [
        'dispatch_allowed',
        'provider_call_allowed',
        'token_spend_allowed',
        'self_programming_allowed',
        'ledger_write_allowed',
        'runtime_execution_allowed',
        'completion_real_allowed',
    ];

    /**
     * Risk-tier ordering: higher index = higher risk.
     */
    private const RISK_TIERS = ['low', 'medium', 'high'];

    public function __construct(
        private readonly AgentControlPlaneTaskPacketQueueRepository $queue,
    ) {}

    /**
     * Validate a worker profile against a specific packet, returning a deterministic
     * eligibility verdict with matched_tags, missing_tags, blockers, and next_unblock_action.
     *
     * @param  array<string, mixed>  $workerProfile
     *         tags              : list<string>  worker capability tags
     *         allowed_scope     : list<string>  file paths the worker can edit
     *         can_run_tests     : bool          whether worker can run test gates
     *         can_report_evidence: bool          whether worker can produce evidence
     *         risk_tolerance    : string        max risk level the worker accepts
     * @param  array<string, mixed>  $packet
     * @return array<string, mixed>
     *         eligible            : bool
     *         blockers            : list<string>  human-readable reasons
     *         matched_tags        : list<string>  intersection of packet.queue_tags and worker.tags
     *         missing_tags        : list<string>  packet.queue_tags not in worker.tags
     *         next_unblock_action : string        one of: none, add_tags, grant_scope, enable_gates, accept_risk
     */
    public function workerEligibilityGuardForPacket(array $workerProfile, array $packet): array
    {
        $blockers = [];
        $nextUnblockAction = 'none';

        // Normalize inputs
        $workerTags = array_values(array_filter(
            array_map('strval', (array) ($workerProfile['tags'] ?? [])),
            static fn (string $t): bool => $t !== '',
        ));
        $workerScope = array_values(array_filter(
            array_map('strval', (array) ($workerProfile['allowed_scope'] ?? [])),
            static fn (string $p): bool => $p !== '',
        ));
        $canRunTests = (bool) ($workerProfile['can_run_tests'] ?? false);
        $canReportEvidence = (bool) ($workerProfile['can_report_evidence'] ?? false);
        $riskTolerance = (string) ($workerProfile['risk_tolerance'] ?? 'low');

        $packetTags = array_values(array_filter(
            array_map('strval', (array) data_get($packet, 'queue_tags', data_get($packet, 'tags', []))),
            static fn (string $t): bool => $t !== '',
        ));
        $allowedFiles = array_values(array_filter(
            array_map('strval', (array) data_get($packet, 'normalized_scope.allowed_files', data_get($packet, 'allowed_files', []))),
            static fn (string $p): bool => $p !== '',
        ));
        $packetRiskLevel = (string) data_get($packet, 'risk_classification.risk_level', data_get($packet, 'risk_level', 'low'));
        $acceptanceCriteria = (array) data_get($packet, 'acceptance_criteria', []);
        $requiredEvidence = (array) data_get($packet, 'evidence_requirements.required', data_get($packet, 'required_evidence', []));

        // ── Tag matching ────────────────────────────────────────────────
        $workerTagSet = array_flip($workerTags);
        $matchedTags = array_values(array_filter(
            $packetTags,
            static fn (string $t): bool => isset($workerTagSet[$t]),
        ));
        $missingTags = array_values(array_filter(
            $packetTags,
            static fn (string $t): bool => ! isset($workerTagSet[$t]),
        ));
        if ($missingTags !== []) {
            $blockers[] = 'worker_missing_required_queue_tags: '.implode(', ', $missingTags);
            $nextUnblockAction = 'add_tags';
        }

        // ── Scope matching ───────────────────────────────────────────────
        if ($allowedFiles !== [] && $workerScope !== []) {
            $outOfScope = [];
            foreach ($allowedFiles as $p) {
                $inScope = false;
                foreach ($workerScope as $scopePrefix) {
                    $scopePrefix = rtrim($scopePrefix, '/');
                    // Support prefix matching: scope prefix `app/Services` matches `app/Services/Foo.php`
                    if ($p === $scopePrefix || str_starts_with($p, $scopePrefix.'/')) {
                        $inScope = true;
                        break;
                    }
                }
                if (! $inScope) {
                    $outOfScope[] = $p;
                }
            }
            if ($outOfScope !== []) {
                $blockers[] = 'packet_allowed_files_outside_worker_scope: '.implode(', ', $outOfScope);
                if ($nextUnblockAction === 'none') {
                    $nextUnblockAction = 'grant_scope';
                }
            }
        }

        // ── Test gate capability ─────────────────────────────────────────
        if ($acceptanceCriteria !== []) {
            $hasTestGate = false;
            foreach ($acceptanceCriteria as $ac) {
                $acLower = strtolower((string) $ac);
                if (str_contains($acLower, 'phpunit') || str_contains($acLower, 'artisan test') || str_contains($acLower, 'pest')) {
                    $hasTestGate = true;
                    break;
                }
            }
            if ($hasTestGate && ! $canRunTests) {
                $blockers[] = 'packet_requires_test_gate_but_worker_cannot_run_tests';
                if ($nextUnblockAction === 'none' || $nextUnblockAction === 'add_tags') {
                    $nextUnblockAction = 'enable_gates';
                }
            }
        }

        // ── Evidence reporting capability ────────────────────────────────
        if ($requiredEvidence !== [] && ! $canReportEvidence) {
            $blockers[] = 'packet_requires_evidence_reporting_but_worker_cannot_report_evidence';
            if ($nextUnblockAction === 'none' || $nextUnblockAction === 'add_tags') {
                $nextUnblockAction = 'enable_gates';
            }
        }

        // ── Risk tolerance ───────────────────────────────────────────────
        $workerTierIndex = array_search($riskTolerance, self::RISK_TIERS, true);
        $packetTierIndex = array_search($packetRiskLevel, self::RISK_TIERS, true);
        if ($workerTierIndex !== false && $packetTierIndex !== false && $packetTierIndex > $workerTierIndex) {
            $blockers[] = "packet_risk_level_{$packetRiskLevel}_exceeds_worker_tolerance_{$riskTolerance}";
            if ($nextUnblockAction === 'none') {
                $nextUnblockAction = 'accept_risk';
            }
        }

        $eligible = $blockers === [];

        return [
            'eligible' => $eligible,
            'blockers' => $blockers,
            'matched_tags' => $matchedTags,
            'missing_tags' => $missingTags,
            'next_unblock_action' => $nextUnblockAction,
            'worker_tags' => $workerTags,
            'packet_tags' => $packetTags,
        ];
    }

    /**
     * @param  list<string>  $queueTags
     * @return array<string, mixed>
     */
    public function workerEligibilityGuard(array $queueTags): array
    {
        $records = $this->readClaimableRecords($queueTags);
        $violations = [];
        $ineligibleTaskIds = [];

        foreach ($records as $record) {
            $taskPacketId = (string) ($record['task_packet_id'] ?? '');
            $recordViolationCountBefore = count($violations);
            $reference = (string) data_get($record, 'task_packet.continuation_context.auto_replenishment_reference', '');
            if ((bool) data_get($record, 'task_packet.continuation_context.worker_executable', true) === false) {
                $violations[] = ['code' => 'claimable_task_not_worker_executable', 'task_packet_id' => $taskPacketId];
            }
            if ((bool) data_get($record, 'task_packet.continuation_context.operator_handoff_required', false)) {
                $violations[] = ['code' => 'claimable_task_requires_operator_handoff', 'task_packet_id' => $taskPacketId];
            }
            if (in_array($reference, self::OPERATOR_ONLY_COMPLETION_CRITERIA, true)) {
                $violations[] = [
                    'code' => 'claimable_task_references_operator_only_completion_blocker',
                    'task_packet_id' => $taskPacketId,
                    'reference' => $reference,
                ];
            }
            foreach (self::RUNTIME_FLAG_NAMES as $flag) {
                if ((bool) data_get($record, $flag, false)) {
                    $violations[] = [
                        'code' => 'claimable_task_runtime_flag_true',
                        'task_packet_id' => $taskPacketId,
                        'flag' => $flag,
                    ];
                }
                if ((bool) data_get($record, 'task_packet.continuation_context.'.$flag, false)) {
                    $violations[] = [
                        'code' => 'claimable_task_runtime_flag_true',
                        'task_packet_id' => $taskPacketId,
                        'flag' => $flag,
                    ];
                }
            }
            if (count($violations) > $recordViolationCountBefore && $taskPacketId !== '') {
                $ineligibleTaskIds[$taskPacketId] = true;
            }
        }
        $ineligibleIds = array_keys($ineligibleTaskIds);
        sort($ineligibleIds);
        $eligibleClaimableCount = count(array_values(array_filter(
            $records,
            static fn (array $record): bool => ! isset($ineligibleTaskIds[(string) ($record['task_packet_id'] ?? '')]),
        )));
        $status = $eligibleClaimableCount > 0
            ? ($violations === [] ? 'available' : 'available_with_non_worker_candidates')
            : ($violations === [] ? 'available' : 'blocked');

        $guard = [
            'schema_version' => 'atlas.self_construction.agent_control_plane_terminal_worker_bootstrap_worker_task_eligibility_guard.v1',
            'status' => $status,
            'queue_tags' => $queueTags,
            'checked_claimable_task_count' => count($records),
            'eligible_claimable_task_count' => $eligibleClaimableCount,
            'blocked_reasons' => array_values(array_unique(array_map(
                static fn (array $violation): string => (string) ($violation['code'] ?? ''),
                $violations,
            ))),
            'violations' => $violations,
            'violation_count' => count($violations),
            'ineligible_task_ids' => $ineligibleIds,
            'can_claim_after_guard' => $eligibleClaimableCount > 0,
            'non_execution_guarantees' => [
                'worker_task_eligibility_guard_does_not_claim_tasks',
                'worker_task_eligibility_guard_does_not_create_or_renew_leases',
                'worker_task_eligibility_guard_does_not_call_provider',
                'worker_task_eligibility_guard_does_not_spend_tokens',
                'worker_task_eligibility_guard_does_not_dispatch_work',
            ],
        ];
        $guard['worker_task_eligibility_guard_hash'] = $this->stableHash($guard);

        return $guard;
    }

    /**
     * @param  list<string>  $queueTags
     * @return list<array<string, mixed>>
     */
    private function claimableRecords(array $queueTags, int $limit = 0): array
    {
        $filters = [
            'status' => 'claimable',
        ];
        if ($queueTags !== []) {
            $filters['tag'] = $queueTags[0];
            $filters['tags'] = $queueTags;
        }
        if ($limit > 0) {
            $filters['limit'] = $limit;
        }

        return $this->queue->list($filters);
    }

    /**
     * Read-only seam for tests: subclasses can override this hook to inject pre-canned claimable
     * records without touching the {@see AgentControlPlaneTaskPacketQueueRepository} instance. The
     * default delegates to the queue repo's `list()` exactly as the bootstrap service's original
     * `claimableRecords()` did, so production behaviour is byte-identical.
     *
     * @param  list<string>  $queueTags
     * @return list<array<string, mixed>>
     */
    protected function readClaimableRecords(array $queueTags, int $limit = 0): array
    {
        return $this->claimableRecords($queueTags, $limit);
    }

}
