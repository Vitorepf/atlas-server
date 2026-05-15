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
        $statusBatch = $this->safeStatus('certification_status_batch', 'agentControlPlaneCertificationStatusBatchStatus', [
            'only_keys' => [
                'chain_integrity_certification',
                'deterministic_chain_replay',
                'replay_snapshot_store',
                'replay_diff',
                'macro_sprint_promotion_gate',
                'certification_baseline',
                'certification_mutation_guard',
                'runtime_evidence_journal',
                'execution_workspace_runtime',
                'governance_approval_runtime',
                'automatic_cost_import_runtime',
                'automatic_work_product_collection_runtime',
                'adapter_execution_runtime_boundary',
                'dispatch_planner_runtime',
                'validation_gate_runtime',
                'merge_review_runtime',
            ],
        ]);
        $runtimeGapMatrix = (new AtlasSelfConstructionRuntimeGapMatrixService($this->readiness))->matrix();

        $notYetRuntimeCapable = (array) data_get($controlPlane, 'control_plane.not_yet_runtime_capable', []);
        $completionReceipt = (new AtlasSelfConstructionHumanCompletionReceiptVerifierService)->verify((array) ($options['completion_receipt'] ?? []));
        $realProviderSmoke = (new AtlasSelfConstructionRealProviderSmokeCertificationService)->certify((array) ($options['real_provider_smoke'] ?? []));
        $forgeSmoke = (new AtlasSelfConstructionForgeSelfImprovementIntegrationSmokeService)->certify((array) ($options['forge_self_improvement_smoke'] ?? []));
        $operatorActionPacket = (new AtlasSelfConstructionCompletionOperatorActionPacketService($this->readiness))->build($runtimeGapMatrix, $completionReceipt, $realProviderSmoke);

        $criteria = [
            $this->criterion(
                'runtime_gap_matrix_all_runtime_y',
                (bool) data_get($runtimeGapMatrix, 'all_runtime_y', false) === true,
                'Every runtime-readiness gap matrix row must be runtime=Y with cited evidence.',
                [
                    'status' => (string) data_get($runtimeGapMatrix, 'status'),
                    'runtime_gap_matrix_hash' => (string) data_get($runtimeGapMatrix, 'runtime_gap_matrix_hash'),
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
                ['status' => (string) data_get($statusBatch, 'status'), 'checked_count' => (int) data_get($statusBatch, 'agent_control_plane_certification_status_batch_status.checked_count'), 'failed_count' => (int) data_get($statusBatch, 'agent_control_plane_certification_status_batch_status.failed_count', 1)],
            ),
        ];

        $failed = array_values(array_filter($criteria, static fn (array $criterion): bool => $criterion['passed'] === false));
        $checklist = $this->promptToArtifactChecklist($criteria, $controlPlane, $releaseDossier, $statusBatch);
        $status = $failed === [] ? 'complete' : 'incomplete';

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
            'prompt_to_artifact_checklist' => $checklist,
            'checklist_count' => count($checklist),
            'operator_action_packet' => $operatorActionPacket,
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

    private function promptToArtifactChecklist(array $criteria, array $controlPlane, array $releaseDossier, array $statusBatch): array
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
        ];
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
