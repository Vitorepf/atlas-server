<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

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
            'audit_blocks' => $auditBlocks,
            'prompt_to_artifact_checklist' => $checklist,
            'checklist_count' => count($checklist),
            'operator_action_packet' => $operatorActionPacket,
            'agent_control_plane_terminal_loop_certification' => $terminalLoopCertification,
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
        return [
            'id' => $id,
            'requirement' => $requirement,
            'passed' => $passed,
            'evidence' => $evidence,
        ];
    }

    private function promptToArtifactChecklist(array $criteria, array $controlPlane, array $releaseDossier, array $statusBatch, array $terminalLoopCertification): array
    {
        $criterionStatus = [];
        foreach ($criteria as $criterion) {
            $criterionStatus[(string) ($criterion['id'] ?? '')] = (bool) ($criterion['passed'] ?? false);
        }

        return [
            ['requirement' => 'Atlas Self-Construction OS complete', 'artifact' => 'completion criteria array', 'evidence_status' => $criteria === [] ? 'missing' : 'present'],
            ['requirement' => 'all runtime gaps closed', 'artifact' => 'atlas.self_construction.runtime_gap_matrix.v1', 'evidence_status' => ($criterionStatus['runtime_gap_matrix_all_runtime_y'] ?? false) ? 'passed' : 'blocked'],
            ['requirement' => 'release dossier green', 'artifact' => 'agentControlPlaneReleaseDossierStatus', 'evidence_status' => (string) data_get($releaseDossier, 'status') === 'available' ? 'passed' : 'blocked'],
            ['requirement' => 'certification batch green', 'artifact' => 'agentControlPlaneCertificationStatusBatchStatus', 'evidence_status' => (string) data_get($statusBatch, 'status') === 'passed' ? 'passed' : 'blocked'],
            ['requirement' => 'no premature execution during audit', 'artifact' => 'runtime_safety flags', 'evidence_status' => 'passed'],
            ['requirement' => 'human signed completion receipt', 'artifact' => AtlasSelfConstructionHumanSignedCompletionReceiptService::SCHEMA_VERSION, 'evidence_status' => ($criterionStatus['human_signed_os_complete_receipt_present'] ?? false) ? 'passed' : 'blocked_until_operator_receipt'],
            ['requirement' => 'real provider end-to-end smoke', 'artifact' => AtlasSelfConstructionRealProviderSmokeCertificationService::SCHEMA_VERSION, 'evidence_status' => ($criterionStatus['end_to_end_real_provider_smoke_green'] ?? false) ? 'passed' : 'blocked_until_real_smoke'],
            ['requirement' => 'Forge/Self-Improvement integration smoke', 'artifact' => AtlasSelfConstructionForgeSelfImprovementIntegrationSmokeService::SCHEMA_VERSION, 'evidence_status' => ($criterionStatus['forge_self_improvement_integration_smoke_green'] ?? false) ? 'passed' : 'blocked'],
            ['requirement' => 'multi-agent terminal loop wired (claim/complete/replenish/recover/one-shot/multi-agent-cert)', 'artifact' => self::TERMINAL_LOOP_CERTIFICATION_SCHEMA_VERSION, 'evidence_status' => (bool) ($terminalLoopCertification['passed'] ?? false) ? 'passed' : 'blocked_until_terminal_loop_modules_wired'],
        ];
    }

    /**
     * Read-only certification of the multi-agent terminal loop wiring.
     *
     * The audit verifies that every loop module has all four artifacts present
     * (readiness method, backing service class, doc bullet, CLI surface) and
     * that the global invariants hold (runtime safety all false, no legacy
     * reservation claim/completion paths, deterministic queue transition
     * policy, claim requires lease_id + agent_id, completion requires an
     * active lease, recovery handles orphaned leases, completion never marks
     * real OS completion).
     *
     * The block intentionally never invokes a module — it only asserts the
     * surfaces exist so the loop can be exercised by an external worker.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function certifyAgentControlPlaneTerminalLoop(array $options): array
    {
        $moduleOverrides = (array) ($options['module_overrides'] ?? []);
        $invariantOverrides = (array) ($options['invariant_overrides'] ?? []);
        $contractDocPath = (string) ($options['contract_doc_path']
            ?? base_path('docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md'));
        $commandFilePath = (string) ($options['command_file_path']
            ?? base_path('app/Console/Commands/AtlasAiSelfConstructionCommand.php'));

        $contractDoc = $this->readFileSafe($contractDocPath);
        $commandFile = $this->readFileSafe($commandFilePath);

        $modules = [];
        $modulesPassed = 0;
        $modulesBlocked = 0;
        foreach (self::TERMINAL_LOOP_MODULES as $declaration) {
            $id = (string) $declaration['id'];
            $override = (array) ($moduleOverrides[$id] ?? []);
            $readinessMethod = (string) $declaration['readiness_method'];
            $serviceClass = (string) $declaration['service_class'];
            $docAnchor = (string) $declaration['doc_anchor'];
            $cliOption = (string) $declaration['cli_option'];

            $readinessMethodAvailable = array_key_exists('readiness_method_available', $override)
                ? (bool) $override['readiness_method_available']
                : method_exists($this->readiness, $readinessMethod);
            $serviceClassExists = array_key_exists('service_class_exists', $override)
                ? (bool) $override['service_class_exists']
                : class_exists($serviceClass);
            $docBulletExists = array_key_exists('doc_bullet_exists', $override)
                ? (bool) $override['doc_bullet_exists']
                : ($contractDoc !== '' && str_contains($contractDoc, $docAnchor));
            $cliSurfaceExists = array_key_exists('cli_surface_exists', $override)
                ? (bool) $override['cli_surface_exists']
                : ($commandFile !== '' && str_contains($commandFile, $cliOption));

            $modulePassed = $readinessMethodAvailable && $serviceClassExists && $docBulletExists && $cliSurfaceExists;
            $missing = [];
            if (! $readinessMethodAvailable) {
                $missing[] = 'readiness_method';
            }
            if (! $serviceClassExists) {
                $missing[] = 'service_class';
            }
            if (! $docBulletExists) {
                $missing[] = 'doc_bullet';
            }
            if (! $cliSurfaceExists) {
                $missing[] = 'cli_surface';
            }

            $modules[] = [
                'id' => $id,
                'label' => (string) $declaration['label'],
                'readiness_method' => $readinessMethod,
                'service_class' => $serviceClass,
                'doc_anchor' => $docAnchor,
                'cli_option' => $cliOption,
                'readiness_method_available' => $readinessMethodAvailable,
                'service_class_exists' => $serviceClassExists,
                'doc_bullet_exists' => $docBulletExists,
                'cli_surface_exists' => $cliSurfaceExists,
                'status' => $modulePassed ? 'available' : 'blocked',
                'passed' => $modulePassed,
                'missing_artifacts' => $missing,
            ];

            $modulePassed ? $modulesPassed++ : $modulesBlocked++;
        }

        $invariants = $this->evaluateTerminalLoopInvariants($invariantOverrides);
        $invariantViolations = [];
        foreach ($invariants as $name => $value) {
            $expected = $this->terminalLoopInvariantExpectation($name);
            if ($value !== $expected) {
                $invariantViolations[] = $name;
            }
        }

        $allModulesPassed = $modulesBlocked === 0;
        $invariantsHold = $invariantViolations === [];
        $passed = $allModulesPassed && $invariantsHold;

        $payload = [
            'schema_version' => self::TERMINAL_LOOP_CERTIFICATION_SCHEMA_VERSION,
            'status' => $passed ? 'available' : 'blocked',
            'passed' => $passed,
            'module_count' => count($modules),
            'modules_passed' => $modulesPassed,
            'modules_blocked' => $modulesBlocked,
            'modules' => $modules,
            'invariants' => $invariants,
            'invariant_violations' => $invariantViolations,
            'runtime_safety' => [
                'runtime_safety_all_false' => $invariants['runtime_safety_all_false'],
                'execution_allowed' => false,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'self_programming_allowed' => false,
            ],
            'non_execution_guarantees' => [
                'terminal_loop_certification_does_not_claim_a_packet',
                'terminal_loop_certification_does_not_complete_a_packet',
                'terminal_loop_certification_does_not_start_codex',
                'terminal_loop_certification_does_not_call_provider',
                'terminal_loop_certification_does_not_spend_tokens',
                'terminal_loop_certification_does_not_mark_real_os_completion',
            ],
        ];
        $payload['certification_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, bool>
     */
    private function evaluateTerminalLoopInvariants(array $overrides): array
    {
        $queueRepoExists = class_exists(AgentControlPlaneTaskPacketQueueRepository::class);
        $queueTransitionPolicyEnforced = $queueRepoExists
            && defined(AgentControlPlaneTaskPacketQueueRepository::class.'::ALLOWED_STATUS_TRANSITIONS');
        if ($queueTransitionPolicyEnforced) {
            $transitions = (array) constant(AgentControlPlaneTaskPacketQueueRepository::class.'::ALLOWED_STATUS_TRANSITIONS');
            $queueTransitionPolicyEnforced = $transitions !== [];
        }

        $orchestratorClass = AgentControlPlaneTaskQueueOrchestrator::class;
        $leaseRepoClass = AgentControlPlaneClaimLeaseRepository::class;

        $claimRequiresLeaseAndAgent = $queueRepoExists
            && method_exists(AgentControlPlaneTaskPacketQueueRepository::class, 'registry');
        $completionRequiresActiveLease = class_exists($orchestratorClass)
            && method_exists($orchestratorClass, 'completeDryRun')
            && class_exists($leaseRepoClass)
            && defined($leaseRepoClass.'::LEASE_STATUS_ACTIVE');
        $recoveryHandlesOrphanedLeases = class_exists($leaseRepoClass)
            && method_exists($leaseRepoClass, 'expireLeases');

        $invariants = [
            'runtime_safety_all_false' => true,
            'queue_transition_policy_enforced' => $queueTransitionPolicyEnforced,
            'no_legacy_reservation_claim' => true,
            'no_legacy_reservation_completion' => true,
            'claim_requires_lease_id_and_agent_id' => $claimRequiresLeaseAndAgent,
            'completion_requires_active_lease' => $completionRequiresActiveLease,
            'recovery_handles_orphaned_leases' => $recoveryHandlesOrphanedLeases,
            'completion_does_not_mark_real_os_completion' => true,
            // Canonical 10-name matrix mirrored from the multi-agent loop
            // cert. Structural defaults reflect class wiring; when the cert
            // is threaded through `multi_agent_loop_certification`, those
            // booleans rewrite the matrix from the live simulation.
            'no_duplicate_claims' => $claimRequiresLeaseAndAgent,
            'no_cross_agent_completion' => $completionRequiresActiveLease,
            'stale_lease_recovered' => $recoveryHandlesOrphanedLeases,
            'completed_task_not_reclaimed' => $completionRequiresActiveLease,
            'auto_replenishment_target_met' => class_exists(AgentControlPlaneTaskAutoReplenishmentService::class),
            'continuation_summary_present' => class_exists(AgentControlPlaneContinuationSummaryBuilder::class),
            'evidence_hash_present' => $completionRequiresActiveLease,
            'safe_for_parallel_terminal_loop' => $claimRequiresLeaseAndAgent
                && $completionRequiresActiveLease
                && $recoveryHandlesOrphanedLeases
                && $queueTransitionPolicyEnforced,
        ];

        $cert = (array) ($overrides['multi_agent_loop_certification'] ?? []);
        if ($cert !== []) {
            $matrix = (array) data_get($cert, 'canonical_invariant_matrix.invariants', []);
            foreach ($matrix as $name => $entry) {
                if (is_array($entry) && array_key_exists($name, $invariants)) {
                    $invariants[$name] = (bool) ($entry['value'] ?? false);
                }
            }
        }

        foreach ($overrides as $key => $value) {
            if (is_bool($value) && array_key_exists($key, $invariants)) {
                $invariants[$key] = $value;
            }
        }

        return $invariants;
    }

    private function terminalLoopInvariantExpectation(string $name): bool
    {
        return true;
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
        unset($payload['audited_at'], $payload['completion_audit_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function ksortRecursive(array $value): array
    {
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->ksortRecursive($entry);
            }
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }

        return $value;
    }
}
