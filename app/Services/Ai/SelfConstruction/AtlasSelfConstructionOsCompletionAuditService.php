<?php

namespace App\Services\Ai\SelfConstruction;

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
     * Operational metadata for every completion-audit criterion: blocker type,
     * doc anchor, remediation command, and the canonical receipt schema the
     * operator must produce to unblock it. The audit is a closure contract;
     * an operator must be able to read the failed_criteria list and know
     * exactly what to do without grepping code.
     *
     * @return array<string, array<string, string>>
     */
    private function criterionMetadata(): array
    {
        return [
            'runtime_gap_matrix_all_runtime_y' => [
                'blocker_type' => 'human',
                'doc_anchor' => 'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md#runtime-gap-matrix-v1',
                'remediation_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-receipt-draft-status --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json',
                'expected_receipt_schema' => AtlasSelfConstructionRuntimePromotionReceiptService::SCHEMA_VERSION,
                'why_blocking' => 'Every Y/N row in the runtime gap matrix must be runtime=Y with graduation hashes; promoting requires an operator-signed runtime promotion receipt.',
            ],
            'release_dossier_green' => [
                'blocker_type' => 'technical',
                'doc_anchor' => 'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md#agent-control-plane-release-dossier-v1',
                'remediation_command' => 'php artisan atlas:ai:self-construction --agent-control-plane-release-dossier-status --json',
                'expected_receipt_schema' => AgentControlPlaneReleaseDossierService::SCHEMA_VERSION,
                'why_blocking' => 'The release dossier must aggregate baseline, replay, diff, gate, simulator, mutation guard, chain integrity and snapshot capture into a single green status.',
            ],
            'replay_diff_against_completion_snapshot_green' => [
                'blocker_type' => 'technical',
                'doc_anchor' => 'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md#agent-control-plane-replay-diff-v1',
                'remediation_command' => 'php artisan atlas:ai:self-construction --agent-control-plane-replay-diff-status --json',
                'expected_receipt_schema' => 'atlas.self_construction.agent_control_plane_replay_diff_status.v1',
                'why_blocking' => 'The replay diff must compare against a stored completion snapshot so OS closure can be replayed deterministically.',
            ],
            'promotion_gate_green' => [
                'blocker_type' => 'technical',
                'doc_anchor' => 'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md#promotion-gate-v1',
                'remediation_command' => 'php artisan atlas:ai:self-construction --agent-control-plane-macro-sprint-promotion-gate-status --json',
                'expected_receipt_schema' => 'atlas.self_construction.agent_control_plane_macro_sprint_promotion_gate.v1',
                'why_blocking' => 'The promotion gate must explicitly allow promotion before any completion claim can be accepted.',
            ],
            'mutation_guard_green' => [
                'blocker_type' => 'technical',
                'doc_anchor' => 'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md#mutation-guard-v1',
                'remediation_command' => 'php artisan atlas:ai:self-construction --agent-control-plane-certification-mutation-guard-status --json',
                'expected_receipt_schema' => 'atlas.self_construction.agent_control_plane_certification_mutation_guard.v1',
                'why_blocking' => 'Mutation guard must pass; forbidden mutations on the certification surface invalidate the audit.',
            ],
            'human_signed_os_complete_receipt_present' => [
                'blocker_type' => 'human',
                'doc_anchor' => 'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md#human-signed-os-complete-receipt',
                'remediation_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-human-completion-receipt-draft-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --real-provider-smoke-json=@/path/to/real-provider-smoke.json --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json',
                'expected_receipt_schema' => AtlasSelfConstructionHumanSignedCompletionReceiptService::SCHEMA_VERSION,
                'why_blocking' => 'Atlas never self-promotes OS-complete. The operator must persist a signed human completion receipt referencing the post-smoke audit hash.',
            ],
            'end_to_end_real_provider_smoke_green' => [
                'blocker_type' => 'real_provider',
                'doc_anchor' => 'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md#real-provider-smoke-v1',
                'remediation_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-draft-status --real-provider-smoke-json=@/path/to/real-provider-smoke-preimage.json --json',
                'expected_receipt_schema' => AtlasSelfConstructionRealProviderSmokeCertificationService::SCHEMA_VERSION,
                'why_blocking' => 'Atlas never starts a provider process and never spends tokens. The operator must run a real provider claim-to-completion smoke outside Atlas and persist evidence.',
            ],
            'forge_self_improvement_integration_smoke_green' => [
                'blocker_type' => 'technical',
                'doc_anchor' => 'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md#forge-self-improvement-integration-smoke',
                'remediation_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-forge-self-improvement-integration-smoke-status --json',
                'expected_receipt_schema' => AtlasSelfConstructionForgeSelfImprovementIntegrationSmokeService::SCHEMA_VERSION,
                'why_blocking' => 'The integration smoke must prove Forge and Self-Improvement do not collide on activation.',
            ],
            'certification_status_batch_green' => [
                'blocker_type' => 'technical',
                'doc_anchor' => 'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md#certification-status-batch',
                'remediation_command' => 'php artisan atlas:ai:self-construction --agent-control-plane-certification-status-batch-status --json',
                'expected_receipt_schema' => 'atlas.self_construction.agent_control_plane_certification_status_batch.v1',
                'why_blocking' => 'Every Agent Control Plane status projection in the batch must be green with zero failed checks.',
            ],
            'agent_control_plane_terminal_loop_certification_green' => [
                'blocker_type' => 'technical',
                'doc_anchor' => 'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md#multi-agent-terminal-loop',
                'remediation_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
                'expected_receipt_schema' => self::TERMINAL_LOOP_CERTIFICATION_SCHEMA_VERSION,
                'why_blocking' => 'The multi-agent terminal loop must be wired end-to-end (auto-replenishment, claim-next, complete-dry-run, one-shot worker packet, lease recovery, multi-agent loop cert).',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $criterion
     * @return array<string, mixed>
     */
    private function enrichFailedCriterion(array $criterion): array
    {
        $id = (string) ($criterion['id'] ?? '');
        $meta = $this->criterionMetadata()[$id] ?? [
            'blocker_type' => 'technical',
            'doc_anchor' => '',
            'remediation_command' => '',
            'expected_receipt_schema' => '',
            'why_blocking' => '',
        ];
        $evidence = (array) ($criterion['evidence'] ?? []);

        return [
            'id' => $id,
            'requirement' => (string) ($criterion['requirement'] ?? ''),
            'blocker_type' => $meta['blocker_type'],
            'why_blocking' => $meta['why_blocking'],
            'observed_status' => (string) ($evidence['status'] ?? ''),
            'doc_anchor' => $meta['doc_anchor'],
            'remediation_command' => $meta['remediation_command'],
            'expected_receipt_schema' => $meta['expected_receipt_schema'],
            'evidence' => $evidence,
        ];
    }

    /**
     * @param  array<string, mixed>  $criterion
     * @return array<string, mixed>
     */
    private function summarisePassedCriterion(array $criterion): array
    {
        $id = (string) ($criterion['id'] ?? '');
        $meta = $this->criterionMetadata()[$id] ?? [
            'doc_anchor' => '',
            'expected_receipt_schema' => '',
        ];
        $evidence = (array) ($criterion['evidence'] ?? []);
        $evidenceHashCandidates = [
            'receipt_hash',
            'receipt_verification_hash',
            'smoke_hash',
            'certification_hash',
            'hash',
            'closure_runbook_hash',
            'runtime_promotion_receipt_hash',
            'runtime_gap_matrix_hash',
            'diff_hash',
            'batch_hash',
        ];
        $evidenceHash = '';
        foreach ($evidenceHashCandidates as $candidate) {
            $value = (string) ($evidence[$candidate] ?? '');
            if ($value !== '') {
                $evidenceHash = $value;
                break;
            }
        }

        return [
            'id' => $id,
            'requirement' => (string) ($criterion['requirement'] ?? ''),
            'observed_status' => (string) ($evidence['status'] ?? 'passed'),
            'evidence_hash' => $evidenceHash,
            'doc_anchor' => $meta['doc_anchor'],
            'expected_receipt_schema' => $meta['expected_receipt_schema'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $failedDetailed
     * @return array<string, mixed>
     */
    private function classifyBlockers(array $failedDetailed): array
    {
        $buckets = [
            'human' => [],
            'real_provider' => [],
            'technical' => [],
        ];
        foreach ($failedDetailed as $entry) {
            $type = (string) ($entry['blocker_type'] ?? 'technical');
            if (! isset($buckets[$type])) {
                $buckets[$type] = [];
            }
            $buckets[$type][] = (string) ($entry['id'] ?? '');
        }

        return [
            'human_blockers' => array_values(array_filter($buckets['human'])),
            'real_provider_blockers' => array_values(array_filter($buckets['real_provider'])),
            'technical_blockers' => array_values(array_filter($buckets['technical'])),
            'human_blocker_count' => count(array_filter($buckets['human'])),
            'real_provider_blocker_count' => count(array_filter($buckets['real_provider'])),
            'technical_blocker_count' => count(array_filter($buckets['technical'])),
            'no_blockers_at_all' => $failedDetailed === [],
            'completion_allowed' => $failedDetailed === [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $failedCriteriaDetailed
     * @param  array<string, mixed>  $blockerClassification
     * @return array<string, mixed>
     */
    private function completionClaimAuthorityVerdict(string $status, array $failedCriteriaDetailed, array $blockerClassification): array
    {
        $completionAllowed = $status === 'complete' && $failedCriteriaDetailed === [];
        $missingEvidence = array_values(array_map(
            static fn (array $entry): array => [
                'criterion_id' => (string) ($entry['id'] ?? ''),
                'blocker_type' => (string) ($entry['blocker_type'] ?? 'technical'),
                'expected_receipt_schema' => (string) ($entry['expected_receipt_schema'] ?? ''),
                'remediation_command' => (string) ($entry['remediation_command'] ?? ''),
                'why_blocking' => (string) ($entry['why_blocking'] ?? ''),
            ],
            $failedCriteriaDetailed,
        ));

        $verdict = [
            'schema_version' => 'atlas.self_construction.completion_claim_authority_verdict.v1',
            'mode' => 'read_only_completion_claim_authority_verdict',
            'status' => $completionAllowed ? 'completion_claim_authorized_by_audit' : 'completion_claim_rejected_by_audit',
            'completion_authority' => 'atlas_self_construction_os_completion_audit',
            'required_completion_predicate' => 'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
            'audit_status' => $status,
            'completion_allowed_by_audit' => $completionAllowed,
            'completion_claim_allowed_by_audit' => $completionAllowed,
            'external_agent_claim_accepted' => false,
            'external_agent_claim_can_override_audit' => false,
            'external_agent_claim_can_mark_os_complete' => false,
            'failed_criterion_count' => count($failedCriteriaDetailed),
            'missing_evidence' => $missingEvidence,
            'missing_evidence_count' => count($missingEvidence),
            'blocker_classification' => $blockerClassification,
            'operator_next_action' => $completionAllowed
                ? 'operator_may_review_completion_claim_and_next_stage'
                : 'resolve_missing_evidence_then_rerun_completion_audit',
            'operator_verification_commands' => [
                'completion_audit' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
                'completion_evidence_status' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json',
                'operator_evidence_submission_readiness' => 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-submission-readiness-status --json',
            ],
            'failure_policy' => [
                'reject_external_completion_claim_until_audit_status_complete',
                'reject_completion_claim_when_failed_criteria_present',
                'reject_completion_claim_without_human_signed_receipt',
                'reject_completion_claim_without_real_provider_smoke',
                'reject_completion_claim_without_runtime_gap_matrix_all_runtime_y',
            ],
            'non_execution_guarantees' => [
                'completion_claim_authority_verdict_does_not_persist_receipts',
                'completion_claim_authority_verdict_does_not_sign_for_operator',
                'completion_claim_authority_verdict_does_not_call_provider',
                'completion_claim_authority_verdict_does_not_spend_tokens',
                'completion_claim_authority_verdict_does_not_dispatch',
                'completion_claim_authority_verdict_does_not_enable_runtime',
                'completion_claim_authority_verdict_does_not_promote_completion',
            ],
        ];
        $verdict['completion_claim_authority_verdict_hash'] = $this->stableHash($verdict);

        return $verdict;
    }

    /**
     * Group criteria into operator-visible blocks so each big-ticket OS
     * closure surface has a single, scannable status block with the exact
     * remediation command, blocker type and expected receipt schema.
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
        $byId = [];
        foreach ($criteria as $criterion) {
            $byId[(string) ($criterion['id'] ?? '')] = $criterion;
        }
        $meta = $this->criterionMetadata();

        $blockFor = function (
            string $label,
            string $criterionId,
            string $observedStatus,
            string $observedHash,
        ) use ($byId, $meta): array {
            $criterion = $byId[$criterionId] ?? null;
            $passed = $criterion !== null ? (bool) ($criterion['passed'] ?? false) : false;
            $metaForCriterion = $meta[$criterionId] ?? [
                'blocker_type' => 'technical',
                'remediation_command' => '',
                'doc_anchor' => '',
                'expected_receipt_schema' => '',
                'why_blocking' => '',
            ];

            return [
                'label' => $label,
                'criterion_id' => $criterionId,
                'status' => $passed ? 'green' : 'blocked',
                'passed' => $passed,
                'blocker_type' => $passed ? 'none' : $metaForCriterion['blocker_type'],
                'observed_status' => $observedStatus,
                'observed_hash' => $observedHash,
                'remediation_command' => $passed ? '' : $metaForCriterion['remediation_command'],
                'doc_anchor' => $metaForCriterion['doc_anchor'],
                'expected_receipt_schema' => $metaForCriterion['expected_receipt_schema'],
                'why_blocking' => $passed ? '' : $metaForCriterion['why_blocking'],
            ];
        };

        $chainIntegrityStatus = (string) data_get($releaseDossier, 'agent_control_plane_release_dossier_status.chain_integrity_status', '');

        return [
            'release_dossier_block' => $blockFor(
                'Release Dossier',
                'release_dossier_green',
                (string) data_get($releaseDossier, 'status', ''),
                (string) data_get($releaseDossier, 'agent_control_plane_release_dossier_status.release_dossier_hash', data_get($releaseDossier, 'agent_control_plane_release_dossier.release_dossier_hash', '')),
            ),
            'replay_diff_block' => $blockFor(
                'Replay Diff',
                'replay_diff_against_completion_snapshot_green',
                (string) data_get($replayDiff, 'status', ''),
                (string) data_get($replayDiff, 'agent_control_plane_replay_diff_status.diff_hash', ''),
            ),
            'promotion_gate_block' => $blockFor(
                'Promotion Gate',
                'promotion_gate_green',
                (string) data_get($promotionGate, 'status', ''),
                (string) data_get($promotionGate, 'agent_control_plane_macro_sprint_promotion_gate.gate_hash', ''),
            ),
            'mutation_guard_block' => $blockFor(
                'Mutation Guard',
                'mutation_guard_green',
                (string) data_get($mutationGuard, 'status', ''),
                (string) data_get($mutationGuard, 'agent_control_plane_certification_mutation_guard.mutation_hash', data_get($mutationGuard, 'agent_control_plane_certification_mutation_guard_status.mutation_hash', '')),
            ),
            'chain_integrity_block' => [
                'label' => 'Chain Integrity',
                'criterion_id' => 'release_dossier_green',
                'status' => $chainIntegrityStatus === 'available' ? 'green' : 'unknown',
                'passed' => $chainIntegrityStatus === 'available',
                'blocker_type' => $chainIntegrityStatus === 'available' ? 'none' : 'technical',
                'observed_status' => $chainIntegrityStatus,
                'observed_hash' => (string) data_get($releaseDossier, 'agent_control_plane_release_dossier.chain_integrity_hash', ''),
                'remediation_command' => 'php artisan atlas:ai:self-construction --agent-control-plane-chain-integrity-certification-status --json',
                'doc_anchor' => 'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md#chain-integrity',
                'expected_receipt_schema' => 'atlas.self_construction.agent_control_plane_chain_integrity_certification.v1',
                'why_blocking' => $chainIntegrityStatus === 'available' ? '' : 'Chain integrity certification must be available before the release dossier can be promoted.',
            ],
            'runtime_gap_block' => $blockFor(
                'Runtime Gap Matrix',
                'runtime_gap_matrix_all_runtime_y',
                (string) data_get($runtimeGapMatrix, 'status', ''),
                (string) data_get($runtimeGapMatrix, 'runtime_gap_matrix_hash', ''),
            ),
            'certification_status_batch_block' => $blockFor(
                'Certification Status Batch',
                'certification_status_batch_green',
                (string) data_get($statusBatch, 'status', ''),
                (string) data_get($statusBatch, 'agent_control_plane_certification_status_batch_status.batch_hash', data_get($statusBatch, 'agent_control_plane_certification_status_batch.batch_hash', '')),
            ),
            'terminal_loop_block' => $blockFor(
                'Multi-Agent Terminal Loop',
                'agent_control_plane_terminal_loop_certification_green',
                (string) ($terminalLoopCertification['status'] ?? ''),
                (string) ($terminalLoopCertification['certification_hash'] ?? ''),
            ),
            'human_signed_receipt_block' => $blockFor(
                'Human-Signed OS-Complete Receipt',
                'human_signed_os_complete_receipt_present',
                (string) data_get($completionReceipt, 'status', ''),
                (string) data_get($completionReceipt, 'receipt_hash', ''),
            ),
            'real_provider_smoke_block' => $blockFor(
                'Real Provider Smoke',
                'end_to_end_real_provider_smoke_green',
                (string) data_get($realProviderSmoke, 'status', ''),
                (string) data_get($realProviderSmoke, 'smoke_hash', ''),
            ),
            'forge_self_improvement_integration_smoke_block' => $blockFor(
                'Forge ↔ Self-Improvement Integration Smoke',
                'forge_self_improvement_integration_smoke_green',
                (string) data_get($forgeSmoke, 'status', ''),
                (string) data_get($forgeSmoke, 'smoke_hash', ''),
            ),
        ];
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

    private function promptToArtifactChecklist(array $criteria, array $controlPlane, array $releaseDossier, array $statusBatch, array $terminalLoopCertification): array
    {
        $criterionStatus = [];
        foreach ($criteria as $criterion) {
            $criterionStatus[(string) ($criterion['id'] ?? '')] = (bool) ($criterion['passed'] ?? false);
        }

        $modulePassed = [];
        foreach ((array) ($terminalLoopCertification['modules'] ?? []) as $module) {
            $modulePassed[(string) ($module['id'] ?? '')] = (bool) ($module['passed'] ?? false);
        }
        $invariants = (array) ($terminalLoopCertification['invariants'] ?? []);
        $terminalLoopPassed = (bool) ($terminalLoopCertification['passed'] ?? false);
        $terminalLoopProofHash = (string) data_get(
            collect($criteria)->firstWhere('id', 'agent_control_plane_terminal_loop_certification_green'),
            'evidence.operational_proof_hash',
            '',
        );

        return [
            ['requirement' => 'Atlas Self-Construction OS complete', 'artifact' => 'completion criteria array', 'evidence_status' => $criteria === [] ? 'missing' : 'present'],
            ['requirement' => 'all runtime gaps closed', 'artifact' => 'atlas.self_construction.runtime_gap_matrix.v1', 'evidence_status' => ($criterionStatus['runtime_gap_matrix_all_runtime_y'] ?? false) ? 'passed' : 'blocked'],
            ['requirement' => 'release dossier green', 'artifact' => 'agentControlPlaneReleaseDossierStatus', 'evidence_status' => (string) data_get($releaseDossier, 'status') === 'available' ? 'passed' : 'blocked'],
            ['requirement' => 'certification batch green', 'artifact' => 'agentControlPlaneCertificationStatusBatchStatus', 'evidence_status' => (string) data_get($statusBatch, 'status') === 'passed' ? 'passed' : 'blocked'],
            ['requirement' => 'no premature execution during audit', 'artifact' => 'runtime_safety flags', 'evidence_status' => 'passed'],
            ['requirement' => 'loop auto-replenishment works', 'artifact' => 'agentControlPlaneTaskAutoReplenishmentStatus + terminal loop operational proof', 'evidence_status' => (($modulePassed['task_auto_replenishment_status'] ?? false) && (bool) ($invariants['auto_replenishment_target_met'] ?? false) && $terminalLoopPassed) ? 'passed' : 'blocked', 'evidence_hash' => $terminalLoopProofHash],
            ['requirement' => 'loop validates worker eligibility and completion evidence', 'artifact' => 'agentControlPlaneWorkerTaskEligibilityCertificationStatus + one-shot worker packet + terminal loop operational proof', 'evidence_status' => (($modulePassed['worker_task_eligibility_certification_status'] ?? false) && ($modulePassed['one_shot_worker_packet_status'] ?? false) && (bool) ($invariants['worker_task_eligibility_blocks_operator_only_completion_blockers'] ?? false) && (bool) ($invariants['structured_completion_evidence_valid'] ?? false) && $terminalLoopPassed) ? 'passed' : 'blocked', 'evidence_hash' => $terminalLoopProofHash],
            ['requirement' => 'loop claim/lease prevents duplicate or cross-agent completion', 'artifact' => 'agentControlPlaneTaskQueueClaimNextStatus + agentControlPlaneTaskQueueCompleteDryRunStatus + lease invariants', 'evidence_status' => (($modulePassed['task_queue_claim_next_status'] ?? false) && ($modulePassed['task_queue_complete_dry_run_status'] ?? false) && (bool) ($invariants['claim_requires_lease_id_and_agent_id'] ?? false) && (bool) ($invariants['completion_requires_active_lease'] ?? false) && (bool) ($invariants['no_duplicate_claims'] ?? false) && (bool) ($invariants['no_cross_agent_completion'] ?? false) && $terminalLoopPassed) ? 'passed' : 'blocked', 'evidence_hash' => $terminalLoopProofHash],
            ['requirement' => 'loop evidence ledger/work product handoff is captured', 'artifact' => 'one-shot worker packet evidence template + terminal loop health digest evidence rollup', 'evidence_status' => (($modulePassed['one_shot_worker_packet_status'] ?? false) && ($modulePassed['terminal_loop_health_digest_status'] ?? false) && (bool) ($invariants['evidence_hash_present'] ?? false) && (bool) ($invariants['terminal_loop_fleet_evidence_rollup_green_path_verified'] ?? false) && $terminalLoopPassed) ? 'passed' : 'blocked', 'evidence_hash' => $terminalLoopProofHash],
            ['requirement' => 'loop resume/retomada recovers interrupted agents and stale leases', 'artifact' => 'agentControlPlaneTaskLeaseRecoveryStatus + terminal worker bootstrap resume contract + health digest resume rollup', 'evidence_status' => (($modulePassed['task_lease_recovery_status'] ?? false) && ($modulePassed['terminal_worker_bootstrap_status'] ?? false) && ($modulePassed['terminal_loop_health_digest_status'] ?? false) && (bool) ($invariants['recovery_handles_orphaned_leases'] ?? false) && (bool) ($invariants['terminal_loop_fleet_resume_recovery_path_verified'] ?? false) && (bool) ($invariants['worker_resumption_contract_present'] ?? false) && $terminalLoopPassed) ? 'passed' : 'blocked', 'evidence_hash' => $terminalLoopProofHash],
            ['requirement' => 'human signed completion receipt', 'artifact' => AtlasSelfConstructionHumanSignedCompletionReceiptService::SCHEMA_VERSION, 'evidence_status' => ($criterionStatus['human_signed_os_complete_receipt_present'] ?? false) ? 'passed' : 'blocked_until_operator_receipt'],
            ['requirement' => 'real provider end-to-end smoke', 'artifact' => AtlasSelfConstructionRealProviderSmokeCertificationService::SCHEMA_VERSION, 'evidence_status' => ($criterionStatus['end_to_end_real_provider_smoke_green'] ?? false) ? 'passed' : 'blocked_until_real_smoke'],
            ['requirement' => 'Forge/Self-Improvement integration smoke', 'artifact' => AtlasSelfConstructionForgeSelfImprovementIntegrationSmokeService::SCHEMA_VERSION, 'evidence_status' => ($criterionStatus['forge_self_improvement_integration_smoke_green'] ?? false) ? 'passed' : 'blocked'],
            ['requirement' => 'multi-agent terminal loop wired (claim/complete/replenish/bootstrap/health-digest/recover/one-shot/multi-agent-cert)', 'artifact' => self::TERMINAL_LOOP_CERTIFICATION_SCHEMA_VERSION, 'evidence_status' => (bool) ($terminalLoopCertification['passed'] ?? false) ? 'passed' : 'blocked_until_terminal_loop_modules_wired'],
        ];
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

    private function ksortRecursive(array $value): array
    {
        return Completion\AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::ksortRecursive($value);
    }
}
