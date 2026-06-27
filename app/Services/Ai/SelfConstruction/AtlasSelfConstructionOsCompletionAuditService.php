<?php

namespace App\Services\Ai\SelfConstruction;


use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionCompletionCriterionReporter;
use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionTerminalLoopCertifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * Canonical completion audit for Atlas Self-Construction OS. It intentionally
 * refuses proxy completion and requires concrete evidence for every OQ-10
 * condition before any completion claim can be promoted.
 */
final class AtlasSelfConstructionOsCompletionAuditService
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    public const SCHEMA_VERSION = 'atlas.self_construction.os_completion_audit.v1';

    public const MODE = 'read_only_atlas_self_construction_os_completion_audit';

    public const TERMINAL_LOOP_CERTIFICATION_SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_terminal_loop_certification.v1';

    private const CANONICAL_TERMINAL_LOOP_OPERATIONAL_PROOF_BINDING_PATH = 'atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json';

    /**
     * Canonical declaration of the multi-agent terminal-loop modules the
     * Atlas Self-Construction OS must wire before completion can be claimed.
     *
     * Each module is verified through four independent artifacts:
     *   - readiness method available on AtlasSelfConstructionReadinessService
     *   - backing service class loadable in the application classpath
     *   - canonical bullet present in the Agent Control Plane contract doc
     *   - CLI surface exposed through AtlasAiSelfConstructionCommand
     *
     * The audit never invokes any module; it only verifies the four artifacts
     * exist so the loop is wired end-to-end.
     */
    public const TERMINAL_LOOP_MODULES = [
        [
            'id' => 'task_auto_replenishment_status',
            'label' => 'Task Auto-Replenishment Status',
            'readiness_method' => 'agentControlPlaneTaskAutoReplenishmentStatus',
            'service_class' => AgentControlPlaneTaskAutoReplenishmentService::class,
            'doc_anchor' => 'agent_control_plane_task_auto_replenishment',
            'cli_option' => 'agent-control-plane-task-auto-replenishment-status',
        ],
        [
            'id' => 'worker_task_eligibility_certification_status',
            'label' => 'Worker Task Eligibility Certification Status',
            'readiness_method' => 'agentControlPlaneWorkerTaskEligibilityCertificationStatus',
            'service_class' => AgentControlPlaneWorkerTaskEligibilityCertificationService::class,
            'doc_anchor' => 'agent_control_plane_worker_task_eligibility_certification',
            'cli_option' => 'agent-control-plane-worker-task-eligibility-certification-status',
        ],
        [
            'id' => 'task_queue_claim_next_status',
            'label' => 'Task Queue Claim Next Status',
            'readiness_method' => 'agentControlPlaneTaskQueueClaimNextStatus',
            'service_class' => AgentControlPlaneTaskQueueOrchestrator::class,
            'doc_anchor' => 'agent_control_plane_task_queue_claim_next_status',
            'cli_option' => 'agent-control-plane-task-queue-claim-next-status',
        ],
        [
            'id' => 'task_queue_complete_dry_run_status',
            'label' => 'Task Queue Complete Dry-Run Status',
            'readiness_method' => 'agentControlPlaneTaskQueueCompleteDryRunStatus',
            'service_class' => AgentControlPlaneTaskQueueOrchestrator::class,
            'doc_anchor' => 'agent_control_plane_task_queue_complete_dry_run_status',
            'cli_option' => 'agent-control-plane-task-queue-complete-dry-run-status',
        ],
        [
            'id' => 'one_shot_worker_packet_status',
            'label' => 'One-Shot Worker Packet Status',
            'readiness_method' => 'agentControlPlaneOneShotWorkerPacketStatus',
            'service_class' => AgentControlPlaneOneShotWorkerPacketService::class,
            'doc_anchor' => 'agent_control_plane_one_shot_worker_packet',
            'cli_option' => 'agent-control-plane-one-shot-worker-packet-status',
        ],
        [
            'id' => 'terminal_worker_bootstrap_status',
            'label' => 'Terminal Worker Bootstrap Status',
            'readiness_method' => 'agentControlPlaneTerminalWorkerBootstrapStatus',
            'service_class' => AgentControlPlaneTerminalWorkerBootstrapService::class,
            'doc_anchor' => 'agent_control_plane_terminal_worker_bootstrap',
            'cli_option' => 'agent-control-plane-terminal-worker-bootstrap-status',
        ],
        [
            'id' => 'terminal_loop_health_digest_status',
            'label' => 'Terminal Loop Health Digest Status',
            'readiness_method' => 'agentControlPlaneTerminalLoopHealthDigestStatus',
            'service_class' => AgentControlPlaneTerminalLoopHealthDigestService::class,
            'doc_anchor' => 'agent_control_plane_terminal_loop_health_digest',
            'cli_option' => 'agent-control-plane-terminal-loop-health-digest-status',
        ],
        [
            'id' => 'task_lease_recovery_status',
            'label' => 'Task Lease Recovery Status',
            'readiness_method' => 'agentControlPlaneTaskLeaseRecoveryStatus',
            'service_class' => AgentControlPlaneTaskLeaseRecoveryService::class,
            'doc_anchor' => 'agent_control_plane_task_lease_recovery',
            'cli_option' => 'agent-control-plane-task-lease-recovery-status',
        ],
        [
            'id' => 'multi_agent_loop_certification_status',
            'label' => 'Multi-Agent Loop Certification Status',
            'readiness_method' => 'agentControlPlaneMultiAgentLoopCertificationStatus',
            'service_class' => AgentControlPlaneMultiAgentLoopCertificationService::class,
            'doc_anchor' => 'agent_control_plane_multi_agent_loop_certification',
            'cli_option' => 'agent-control-plane-multi-agent-loop-certification-status',
        ],
    ];

    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $readiness,
    ) {}

    /** @return array<string, mixed> */
    public function audit(array $options = []): array
    {
        $controlPlane = $this->readiness->agentControlPlane();
        $releaseDossier = $this->safeStatus('release_dossier', 'agentControlPlaneReleaseDossierStatus', ['skip_simulator' => true]);
        $replayDiff = $this->safeStatus('replay_diff', 'agentControlPlaneReplayDiffStatus');
        $promotionGate = $this->safeStatus('promotion_gate', 'agentControlPlaneMacroSprintPromotionGateStatus');
        $mutationGuard = $this->safeStatus('mutation_guard', 'agentControlPlaneCertificationMutationGuardStatus');
        $statusBatch = $this->safeStatus('certification_status_batch', 'agentControlPlaneCertificationStatusBatchStatus');
        $runtimeGapMatrix = (new AtlasSelfConstructionRuntimeGapMatrixService($this->readiness))->matrix();

        $notYetRuntimeCapable = (array) data_get($controlPlane, 'control_plane.not_yet_runtime_capable', []);
        $completionReceipt = (new AtlasSelfConstructionHumanCompletionReceiptVerifierService)->verify((array) ($options['completion_receipt'] ?? []));
        $realProviderSmoke = (new AtlasSelfConstructionRealProviderSmokeCertificationService)->certify((array) ($options['real_provider_smoke'] ?? []));
        $forgeSmoke = (new AtlasSelfConstructionForgeSelfImprovementIntegrationSmokeService)->certify((array) ($options['forge_self_improvement_smoke'] ?? []));
        $operatorActionPacket = (new AtlasSelfConstructionCompletionOperatorActionPacketService($this->readiness))->build(
            $runtimeGapMatrix,
            $completionReceipt,
            $realProviderSmoke,
            [
                'release_dossier' => $releaseDossier,
                'replay_diff' => $replayDiff,
                'certification_status_batch' => $statusBatch,
            ],
        );

        $terminalLoopCertification = $this->certifyAgentControlPlaneTerminalLoop(
            (array) ($options['agent_control_plane_terminal_loop_certification'] ?? []),
        );
        $terminalLoopOperationalProofInput = (array) ($options['agent_control_plane_terminal_loop_operational_proof'] ?? []);
        if ($terminalLoopOperationalProofInput === []) {
            $terminalLoopOperationalProofInput = $this->loadCanonicalTerminalLoopOperationalProofBinding();
        }
        $terminalLoopOperationalProof = $this->terminalLoopOperationalProofEvidence($terminalLoopOperationalProofInput);

        $criteria = [
            $this->criterion(
                'runtime_gap_matrix_all_runtime_y',
                (bool) data_get($runtimeGapMatrix, 'all_runtime_y', false) === true,
                'Every runtime-readiness gap matrix row must be runtime=Y with cited evidence.',
                [
                    'status' => (string) data_get($runtimeGapMatrix, 'status'),
                    'runtime_gap_matrix_hash' => (string) data_get($runtimeGapMatrix, 'runtime_gap_matrix_hash'),
                    'expected_runtime_gap_matrix_hash_for_promotion_receipt' => (string) data_get($runtimeGapMatrix, 'expected_runtime_gap_matrix_hash_for_promotion_receipt'),
                    'runtime_promotion_basis_hash' => (string) data_get($runtimeGapMatrix, 'runtime_promotion_basis_hash'),
                    'runtime_promotion_closure_basis_hash' => (string) data_get($runtimeGapMatrix, 'runtime_promotion_closure_basis_hash'),
                    'runtime_promotion_receipt_hash' => (string) data_get($runtimeGapMatrix, 'runtime_promotion_receipt.receipt_hash'),
                    'runtime_promotion_receipt_status' => (string) data_get($runtimeGapMatrix, 'runtime_promotion_receipt.status'),
                    'runtime_gap_count' => (int) data_get($runtimeGapMatrix, 'runtime_gap_count'),
                    'blocked_gap_ids' => (array) data_get($runtimeGapMatrix, 'blocked_gap_ids', []),
                    'not_yet_runtime_capable' => $notYetRuntimeCapable,
                    'execution_allowed' => (bool) data_get($controlPlane, 'execution_allowed', false),
                ],
            ),
            $this->criterion(
                'release_dossier_green',
                (string) data_get($releaseDossier, 'status') === 'available',
                'Release dossier must be green/available.',
                [
                    'status' => (string) data_get($releaseDossier, 'status'),
                    'hash' => (string) data_get($releaseDossier, 'agent_control_plane_release_dossier_status.release_dossier_hash', data_get($releaseDossier, 'agent_control_plane_release_dossier.release_dossier_hash', '')),
                    'baseline_capture_readiness_status' => (string) data_get($releaseDossier, 'agent_control_plane_release_dossier_status.baseline_capture_readiness_status', ''),
                    'baseline_snapshot_capture_required' => (bool) data_get($releaseDossier, 'agent_control_plane_release_dossier_status.baseline_snapshot_capture_required', false),
                    'closure_status' => (string) data_get($releaseDossier, 'agent_control_plane_release_dossier.release_dossier_closure_runbook.closure_status', ''),
                    'closure_next_action' => (string) data_get($releaseDossier, 'agent_control_plane_release_dossier.release_dossier_closure_runbook.next_action', ''),
                    'closure_exact_next_command' => (string) data_get($releaseDossier, 'agent_control_plane_release_dossier.release_dossier_closure_runbook.exact_next_command', ''),
                    'closure_runbook_hash' => (string) data_get($releaseDossier, 'agent_control_plane_release_dossier.release_dossier_closure_runbook.runbook_hash', ''),
                    'latest_snapshot_id' => (string) data_get($releaseDossier, 'agent_control_plane_release_dossier.release_dossier_closure_runbook.latest_snapshot_id', ''),
                    'latest_snapshot_deterministic_replay_hash' => (string) data_get($releaseDossier, 'agent_control_plane_release_dossier.release_dossier_closure_runbook.latest_snapshot_deterministic_replay_hash', ''),
                ],
            ),
            $this->criterion(
                'replay_diff_against_completion_snapshot_green',
                in_array((string) data_get($replayDiff, 'status'), ['unchanged', 'improved', 'changed'], true)
                    && (string) data_get($replayDiff, 'agent_control_plane_replay_diff_status.before_snapshot_id', '') !== '',
                'Replay diff must compare against a stored completion snapshot.',
                [
                    'status' => (string) data_get($replayDiff, 'status'),
                    'before_snapshot_id' => (string) data_get($replayDiff, 'agent_control_plane_replay_diff_status.before_snapshot_id', ''),
                    'diff_hash' => (string) data_get($replayDiff, 'agent_control_plane_replay_diff_status.diff_hash', ''),
                ],
            ),
            $this->criterion(
                'promotion_gate_green',
                in_array((string) data_get($promotionGate, 'status'), ['available', 'passed'], true)
                    && (bool) data_get($promotionGate, 'agent_control_plane_macro_sprint_promotion_gate.promotion_allowed', false) === true,
                'Promotion gate must explicitly allow promotion.',
                ['status' => (string) data_get($promotionGate, 'status'), 'promotion_allowed' => (bool) data_get($promotionGate, 'agent_control_plane_macro_sprint_promotion_gate.promotion_allowed', false)],
            ),
            $this->criterion(
                'mutation_guard_green',
                in_array((string) data_get($mutationGuard, 'status'), ['available', 'passed'], true)
                    && (bool) data_get($mutationGuard, 'agent_control_plane_certification_mutation_guard.guard_passed', data_get($mutationGuard, 'agent_control_plane_certification_mutation_guard_status.guard_passed', false)) === true,
                'Mutation guard must pass.',
                ['status' => (string) data_get($mutationGuard, 'status'), 'guard_passed' => (bool) data_get($mutationGuard, 'agent_control_plane_certification_mutation_guard.guard_passed', data_get($mutationGuard, 'agent_control_plane_certification_mutation_guard_status.guard_passed', false))],
            ),
            $this->criterion(
                'human_signed_os_complete_receipt_present',
                (string) data_get($completionReceipt, 'status') === 'passed',
                'A human-signed OS-complete receipt must be present.',
                [
                    'status' => (string) data_get($completionReceipt, 'status'),
                    'receipt_id' => (string) data_get($completionReceipt, 'receipt_id'),
                    'receipt_hash' => (string) data_get($completionReceipt, 'receipt_hash'),
                    'receipt_verification_hash' => (string) data_get($completionReceipt, 'receipt_verification_hash'),
                    'violation_count' => (int) data_get($completionReceipt, 'violation_count', 0),
                ],
            ),
            $this->criterion(
                'end_to_end_real_provider_smoke_green',
                (string) data_get($realProviderSmoke, 'status') === 'passed',
                'One packet must run claim -> completion through a real provider with evidence.',
                [
                    'status' => (string) data_get($realProviderSmoke, 'status'),
                    'smoke_hash' => (string) data_get($realProviderSmoke, 'smoke_hash'),
                    'certification_hash' => (string) data_get($realProviderSmoke, 'certification_hash'),
                    'violation_count' => (int) data_get($realProviderSmoke, 'violation_count', 0),
                ],
            ),
            $this->criterion(
                'forge_self_improvement_integration_smoke_green',
                (string) data_get($forgeSmoke, 'status') === 'passed',
                'Forge and Self-Improvement integration smoke must prove no activation collision.',
                [
                    'status' => (string) data_get($forgeSmoke, 'status'),
                    'smoke_hash' => (string) data_get($forgeSmoke, 'smoke_hash'),
                    'violation_count' => (int) data_get($forgeSmoke, 'violation_count', 0),
                    'dry_run_status' => (string) data_get($forgeSmoke, 'dry_run_status'),
                ],
            ),
            $this->criterion(
                'certification_status_batch_green',
                (string) data_get($statusBatch, 'status') === 'passed' && (int) data_get($statusBatch, 'agent_control_plane_certification_status_batch_status.failed_count', 1) === 0,
                'Certification status batch must be green with zero failed checks.',
                [
                    'status' => (string) data_get($statusBatch, 'status'),
                    'hash' => (string) data_get($statusBatch, 'agent_control_plane_certification_status_batch_status.batch_hash', data_get($statusBatch, 'agent_control_plane_certification_status_batch.batch_hash', '')),
                    'checked_count' => (int) data_get($statusBatch, 'agent_control_plane_certification_status_batch_status.checked_count'),
                    'failed_count' => (int) data_get($statusBatch, 'agent_control_plane_certification_status_batch_status.failed_count', 1),
                    'full_batch_required' => true,
                ],
            ),
            $this->criterion(
                'agent_control_plane_terminal_loop_certification_green',
                (bool) $terminalLoopCertification['passed'],
                'The multi-agent terminal loop must be wired end-to-end (auto-replenishment, claim-next, complete-dry-run, one-shot worker packet, lease recovery and multi-agent loop certification) with read-only runtime safety preserved.',
                [
                    'status' => (string) $terminalLoopCertification['status'],
                    'module_count' => (int) $terminalLoopCertification['module_count'],
                    'modules_passed' => (int) $terminalLoopCertification['modules_passed'],
                    'modules_blocked' => (int) $terminalLoopCertification['modules_blocked'],
                    'invariant_violations' => (array) $terminalLoopCertification['invariant_violations'],
                    'certification_hash' => (string) $terminalLoopCertification['certification_hash'],
                    'operational_proof_status' => (string) $terminalLoopOperationalProof['status'],
                    'operational_proof_supplied' => (bool) $terminalLoopOperationalProof['supplied'],
                    'operational_proof_passed' => (bool) $terminalLoopOperationalProof['passed'],
                    'operational_proof_hash' => (string) $terminalLoopOperationalProof['proof_hash'],
                    'operational_proof_expected_command' => (string) $terminalLoopOperationalProof['expected_command'],
                    'operational_proof_note' => (string) $terminalLoopOperationalProof['note'],
                ],
            ),
        ];

        $failed = array_values(array_filter($criteria, static fn (array $criterion): bool => $criterion['passed'] === false));
        $passed = array_values(array_filter($criteria, static fn (array $criterion): bool => $criterion['passed'] === true));
        $checklist = $this->promptToArtifactChecklist($criteria, $controlPlane, $releaseDossier, $statusBatch, $terminalLoopCertification);
        $status = $failed === [] ? 'complete' : 'incomplete';

        $failedCriteriaDetailed = array_values(array_map(
            fn (array $criterion): array => $this->enrichFailedCriterion($criterion),
            $failed,
        ));
        $passedCriteriaDetailed = array_values(array_map(
            fn (array $criterion): array => $this->summarisePassedCriterion($criterion),
            $passed,
        ));
        $blockerClassification = $this->classifyBlockers($failedCriteriaDetailed);
        $auditBlocks = $this->buildAuditBlocks(
            $criteria,
            $releaseDossier,
            $replayDiff,
            $promotionGate,
            $mutationGuard,
            $statusBatch,
            $runtimeGapMatrix,
            $terminalLoopCertification,
            $completionReceipt,
            $realProviderSmoke,
            $forgeSmoke,
        );
        $completionClaimAuthorityVerdict = $this->completionClaimAuthorityVerdict(
            status: $status,
            failedCriteriaDetailed: $failedCriteriaDetailed,
            blockerClassification: $blockerClassification,
        );

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'audit_id' => 'ATLAS-SELF-CONSTRUCTION-OS-COMPLETION-AUDIT-0001',
            'audited_at' => CarbonImmutable::now()->toIso8601String(),
            'status' => $status,
            'completion_allowed' => $status === 'complete',
            'completion_claim_allowed' => $status === 'complete',
            'objective' => 'Implement Atlas Self-Construction OS completely and make it ready for the next stage.',
            'success_criteria_source' => 'docs/engineering-knowledge-base/self-construction/audits/atlas-self-construction-os-open-questions-v1.md#OQ-10',
            'criteria' => $criteria,
            'criteria_count' => count($criteria),
            'passed_count' => count($criteria) - count($failed),
            'failed_count' => count($failed),
            'failed_criteria' => array_column($failed, 'id'),
            'failed_criteria_detailed' => $failedCriteriaDetailed,
            'passed_criteria_detailed' => $passedCriteriaDetailed,
            'blocker_classification' => $blockerClassification,
            'completion_claim_authority_verdict' => $completionClaimAuthorityVerdict,
            'audit_blocks' => $auditBlocks,
            'prompt_to_artifact_checklist' => $checklist,
            'checklist_count' => count($checklist),
            'operator_action_packet' => $operatorActionPacket,
            'agent_control_plane_terminal_loop_certification' => $terminalLoopCertification,
            'agent_control_plane_terminal_loop_operational_proof_evidence' => $terminalLoopOperationalProof,
            'current_pointer' => (string) data_get($controlPlane, 'control_plane.persistent_runtime.next_required_slice'),
            'not_yet_runtime_capable' => $notYetRuntimeCapable,
            'runtime_safety' => [
                'runtime_safety_all_false' => true,
                'execution_allowed' => false,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'adapter_execution_allowed' => false,
                'self_programming_allowed' => false,
            ],
            'next_action' => $status === 'complete'
                ? 'operator_may_review_os_complete_receipt_and_promote_next_stage'
                : 'continue_implementation_until_failed_completion_criteria_have_real_evidence',
            'non_execution_guarantees' => [
                'completion_audit_does_not_start_codex',
                'completion_audit_does_not_call_codex_cli_or_app',
                'completion_audit_does_not_spawn_subprocess',
                'completion_audit_does_not_call_provider',
                'completion_audit_does_not_dispatch_work',
                'completion_audit_does_not_spend_tokens',
                'completion_audit_does_not_enable_self_programming',
                'completion_audit_does_not_promote_completion_without_all_criteria',
                'completion_audit_does_not_accept_external_completion_claims',
            ],
        ];
        $payload['completion_audit_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /**
     * Thin delegator to AtlasSelfConstructionCompletionCriterionReporter;
     * the canonical implementation lives there so the god-class stays thin.
     *
     * @return array<string, array<string, string>>
     */
    private function criterionMetadata(): array
    {
        return AtlasSelfConstructionCompletionCriterionReporter::criterionMetadata();
    }

    /**
     * Thin delegator to AtlasSelfConstructionCompletionCriterionReporter.
     *
     * @param  array<string, mixed>  $criterion
     * @return array<string, mixed>
     */
    private function enrichFailedCriterion(array $criterion): array
    {
        return AtlasSelfConstructionCompletionCriterionReporter::enrichFailedCriterion($criterion);
    }

    /**
     * Thin delegator to AtlasSelfConstructionCompletionCriterionReporter.
     *
     * @param  array<string, mixed>  $criterion
     * @return array<string, mixed>
     */
    private function summarisePassedCriterion(array $criterion): array
    {
        return AtlasSelfConstructionCompletionCriterionReporter::summarisePassedCriterion($criterion);
    }

    /**
     * Thin delegator to AtlasSelfConstructionCompletionCriterionReporter.
     *
     * @param  list<array<string, mixed>>  $failedDetailed
     * @return array<string, mixed>
     */
    private function classifyBlockers(array $failedDetailed): array
    {
        return AtlasSelfConstructionCompletionCriterionReporter::classifyBlockers($failedDetailed);
    }

    /**
     * Thin delegator to AtlasSelfConstructionCompletionCriterionReporter.
     *
     * @param  list<array<string, mixed>>  $failedCriteriaDetailed
     * @param  array<string, mixed>  $blockerClassification
     * @return array<string, mixed>
     */
    private function completionClaimAuthorityVerdict(string $status, array $failedCriteriaDetailed, array $blockerClassification): array
    {
        return AtlasSelfConstructionCompletionCriterionReporter::completionClaimAuthorityVerdict(
            $status,
            $failedCriteriaDetailed,
            $blockerClassification,
        );
    }

    /**
     * Thin delegator to AtlasSelfConstructionCompletionCriterionReporter;
     * the canonical implementation lives there so the god-class stays thin.
     *
     * @param  list<array<string, mixed>>  $criteria
     * @return array<string, mixed>
     */
    private function buildAuditBlocks(
        array $criteria,
        array $releaseDossier,
        array $replayDiff,
        array $promotionGate,
        array $mutationGuard,
        array $statusBatch,
        array $runtimeGapMatrix,
        array $terminalLoopCertification,
        array $completionReceipt,
        array $realProviderSmoke,
        array $forgeSmoke,
    ): array {
        return AtlasSelfConstructionCompletionCriterionReporter::buildAuditBlocks(
            $criteria,
            $releaseDossier,
            $replayDiff,
            $promotionGate,
            $mutationGuard,
            $statusBatch,
            $runtimeGapMatrix,
            $terminalLoopCertification,
            $completionReceipt,
            $realProviderSmoke,
            $forgeSmoke,
        );
    }

    /**
     * The completion audit must stay read-only, so it never runs the operational
     * proof itself. Instead, the audit binds the proof hash/status to the
     * terminal-loop criterion as stronger evidence that the wired loop was
     * actually exercised.
     *
     * @param  array<string, mixed>  $proof
     * @return array<string, mixed>
     */
    private function terminalLoopOperationalProofEvidence(array $proof): array
    {
        return AtlasSelfConstructionTerminalLoopCertifier::terminalLoopOperationalProofEvidence($proof);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadCanonicalTerminalLoopOperationalProofBinding(): array
    {
        try {
            if (! Storage::disk('local')->exists(self::CANONICAL_TERMINAL_LOOP_OPERATIONAL_PROOF_BINDING_PATH)) {
                return [];
            }

            $content = Storage::disk('local')->get(self::CANONICAL_TERMINAL_LOOP_OPERATIONAL_PROOF_BINDING_PATH);
            $decoded = json_decode($content, true);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function safeStatus(string $key, string $method, array $options = []): array
    {
        if (! method_exists($this->readiness, $method)) {
            return ['key' => $key, 'status' => 'method_missing'];
        }

        try {
            return $this->readiness->{$method}($options);
        } catch (\Throwable $e) {
            return ['key' => $key, 'status' => 'exception', 'error' => $e->getMessage()];
        }
    }

    private function criterion(string $id, bool $passed, string $requirement, array $evidence): array
    {
        $meta = $this->criterionMetadata()[$id] ?? [
            'blocker_type' => 'technical',
            'doc_anchor' => '',
            'remediation_command' => '',
            'expected_receipt_schema' => '',
            'why_blocking' => '',
        ];

        return [
            'id' => $id,
            'requirement' => $requirement,
            'passed' => $passed,
            'blocker_type' => $passed ? 'none' : $meta['blocker_type'],
            'why_blocking' => $passed ? '' : $meta['why_blocking'],
            'doc_anchor' => $meta['doc_anchor'],
            'remediation_command' => $passed ? '' : $meta['remediation_command'],
            'expected_receipt_schema' => $meta['expected_receipt_schema'],
            'evidence' => $evidence,
        ];
    }

    /**
     * Thin delegator to AtlasSelfConstructionCompletionCriterionReporter.
     */
    private function promptToArtifactChecklist(array $criteria, array $controlPlane, array $releaseDossier, array $statusBatch, array $terminalLoopCertification): array
    {
        return AtlasSelfConstructionCompletionCriterionReporter::promptToArtifactChecklist(
            $criteria,
            $controlPlane,
            $releaseDossier,
            $statusBatch,
            $terminalLoopCertification,
        );
    }

    /**
     * Read-only certification of the multi-agent terminal loop wiring.
     *
     * Thin delegator to AtlasSelfConstructionTerminalLoopCertifier; the
     * canonical implementation lives there so the god-class stays thin.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function certifyAgentControlPlaneTerminalLoop(array $options): array
    {
        return AtlasSelfConstructionTerminalLoopCertifier::certifyAgentControlPlaneTerminalLoop(
            $options,
            self::TERMINAL_LOOP_MODULES,
            $this->readiness,
            self::TERMINAL_LOOP_CERTIFICATION_SCHEMA_VERSION,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, bool>
     */
    private function evaluateTerminalLoopInvariants(array $overrides): array
    {
        return AtlasSelfConstructionTerminalLoopCertifier::evaluateTerminalLoopInvariants($overrides);
    }

    private function terminalLoopInvariantExpectation(string $name): bool
    {
        return AtlasSelfConstructionTerminalLoopCertifier::terminalLoopInvariantExpectation($name);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function terminalLoopContractDoc(array $options): string
    {
        return AtlasSelfConstructionTerminalLoopCertifier::terminalLoopContractDoc($options);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function terminalLoopCommandSurface(array $options): string
    {
        return AtlasSelfConstructionTerminalLoopCertifier::terminalLoopCommandSurface($options);
    }

    /**
     * @param  list<string>  $paths
     */
    private function readFilesSafe(array $paths): string
    {
        return implode("\n", array_map(fn (string $path): string => $this->readFileSafe($path), $paths));
    }

    private function readFileSafe(string $path): string
    {
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return '';
        }
        $contents = @file_get_contents($path);

        return $contents === false ? '' : $contents;
    }

    private function stableHash(array $payload): string
    {
        return Completion\AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::stableHash($payload);
    }

}
