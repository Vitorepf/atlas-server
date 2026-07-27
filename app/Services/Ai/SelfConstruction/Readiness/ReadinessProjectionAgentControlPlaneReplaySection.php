<?php

namespace App\Services\Ai\SelfConstruction\Readiness;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Models\AtlasSelfConstructionAgentHeartbeat;
use App\Models\AtlasSelfConstructionAgentCostEvent;
use App\Models\AtlasSelfConstructionAgentWorkProduct;
use App\Models\AtlasSelfConstructionAgentWakeupItem;
use App\Models\AtlasSelfConstructionAgentDispatchReceipt;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneChainIntegrityAuditService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneDeterministicChainReplayService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneReplaySnapshotStore;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneReplayDiffService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneMacroSprintPromotionGate;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneCertificationBaselineService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneBaselineCaptureReadinessService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneCertificationScenarioSimulator;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneCertificationMutationGuard;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneReleaseDossierService;

/**
 * GOD-DEBULK extracted stateful agent-control-plane replay/chain/certification status family from AtlasSelfConstructionReadinessService (schema preflight, chain integrity, deterministic replay, snapshot store status+capture, replay diff, macro-sprint promotion gate, certification baseline, release dossier).
 * Bound via setMother(); undefined method calls bridge through __call and undefined
 * property reads bridge through __get (ReflectionMethod / ReflectionProperty on the mother)
 * so the moved bodies stay byte-identical to the god service originals.
 */
final class ReadinessProjectionAgentControlPlaneReplaySection
{
    private ?AtlasSelfConstructionReadinessService $mother = null;

    public function setMother(AtlasSelfConstructionReadinessService $mother): self
    {
        $this->mother = $mother;

        return $this;
    }

    public function __call(string $name, array $arguments): mixed
    {
        if ($this->mother === null) {
            throw new \RuntimeException('ReadinessProjectionAgentControlPlaneReplaySection mother not bound for '.$name);
        }
        $method = new \ReflectionMethod($this->mother, $name);

        return $method->invokeArgs($this->mother, $arguments);
    }

    public function __get(string $name): mixed
    {
        $property = new \ReflectionProperty($this->mother, $name);

        return $property->getValue($this->mother);
    }


    public function agentControlPlaneRuntimeSchemaPreflight(array $options = []): array
    {
        $requiredMigration = $this->agentControlPlaneRuntimeSchemaMigration();
        $tables = $this->agentControlPlaneRuntimeTables();
        $missingTables = array_values(array_keys(array_filter(
            $tables,
            fn (bool $ready): bool => ! $ready,
        )));
        $schemaReady = $missingTables === [];

        $preflight = [
            'status' => $schemaReady ? 'schema_ready' : 'schema_missing',
            'required_migration' => $requiredMigration,
            'required_activation_command' => 'php artisan migrate',
            'migration_file_exists' => is_file(base_path($requiredMigration)),
            'tables' => $tables,
            'counts' => [
                'required_tables' => count($tables),
                'ready_tables' => count($tables) - count($missingTables),
                'missing_tables' => count($missingTables),
            ],
            'missing_tables' => $missingTables,
            'models' => [
                AtlasSelfConstructionAgentRun::class => class_exists(AtlasSelfConstructionAgentRun::class),
                AtlasSelfConstructionAgentHeartbeat::class => class_exists(AtlasSelfConstructionAgentHeartbeat::class),
                AtlasSelfConstructionAgentCostEvent::class => class_exists(AtlasSelfConstructionAgentCostEvent::class),
                AtlasSelfConstructionAgentWorkProduct::class => class_exists(AtlasSelfConstructionAgentWorkProduct::class),
                AtlasSelfConstructionAgentWakeupItem::class => class_exists(AtlasSelfConstructionAgentWakeupItem::class),
                AtlasSelfConstructionAgentDispatchReceipt::class => class_exists(AtlasSelfConstructionAgentDispatchReceipt::class),
            ],
            'contracts_available' => [
                'persistent_control_plane_schema_contract',
                'signed_dispatch_receipt_writer_contract',
                'dispatch_receipt_use_writer_contract',
                'provider_adapter_registry_contract',
                'adapter_invocation_boundary_contract',
            ],
            'activation_policy' => [
                'preflight_is_read_only' => true,
                'does_not_run_migrations' => true,
                'does_not_start_providers' => true,
                'does_not_dispatch_work' => true,
                'does_not_enable_self_programming' => true,
                'requires_operator_or_deployment_migration_step' => true,
            ],
            'next_required_slice' => $schemaReady
                ? 'activate_provider_adapter_invocation_runtime_policy'
                : 'apply_agent_control_plane_runtime_schema_migration',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_control_plane_runtime_schema_preflight.v1',
            'status' => 'agent_control_plane_runtime_schema_preflight_ready',
            'mode' => 'read_only_agent_control_plane_runtime_schema_preflight',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'dispatch_allowed' => false,
            'claim_persisted' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'migration_execution_allowed' => false,
            'runtime_schema_preflight' => $preflight,
            'runtime_schema_preflight_hash' => $this->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_control_plane_runtime_schema_preflight_does_not_run_migrations',
                'agent_control_plane_runtime_schema_preflight_does_not_start_providers',
                'agent_control_plane_runtime_schema_preflight_does_not_dispatch_work',
                'agent_control_plane_runtime_schema_preflight_does_not_write_ledger',
                'agent_control_plane_runtime_schema_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $schemaReady
                ? 'Agent Control Plane runtime schema preflight is ready: runtime tables exist; next slice is governed provider adapter invocation runtime policy.'
                : 'Agent Control Plane runtime schema preflight is ready: migration contract exists, but runtime tables are missing; apply the migration before activating runtime writers.',
        ];
    }

    public function agentControlPlaneChainIntegrityCertificationStatus(array $options = []): array
    {
        $auditService = new AgentControlPlaneChainIntegrityAuditService($this->mother);
        $audit = $auditService->audit($options);

        $status = [
            'status' => (string) data_get($audit, 'status', 'unknown'),
            'audit_schema_version' => (string) data_get($audit, 'schema_version', ''),
            'generated_at' => (string) data_get($audit, 'generated_at', ''),
            'checked_slice_count' => (int) data_get($audit, 'checked_slice_count', 0),
            'chain_length' => (int) data_get($audit, 'chain_length', 0),
            'violation_count' => count((array) data_get($audit, 'violations', [])),
            'warning_count' => count((array) data_get($audit, 'warnings', [])),
            'invariants_all_true' => (bool) data_get($audit, 'invariants_all_true', false),
            'runtime_safety_all_false' => (bool) data_get($audit, 'runtime_safety.runtime_safety_all_false', false),
            'current_next_required_slice' => (string) data_get($audit, 'current_next_required_slice', ''),
            'expected_next_required_slice' => (string) data_get($audit, 'expected_next_required_slice', ''),
            'next_action' => (string) data_get($audit, 'next_action', 'verify_alignment'),
            'audit_hash' => (string) data_get($audit, 'agent_control_plane_chain_integrity_certification_hash', ''),
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_control_plane_chain_integrity_certification_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_control_plane_chain_integrity_certification_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'agent_control_plane_chain_integrity_certification_status' => $status,
            'agent_control_plane_chain_integrity_certification' => $audit,
            'agent_control_plane_chain_integrity_certification_status_hash' => $this->stableHash($status),
            'non_execution_guarantees' => [
                'agent_control_plane_chain_integrity_certification_status_does_not_start_codex',
                'agent_control_plane_chain_integrity_certification_status_does_not_advance_pointer',
                'agent_control_plane_chain_integrity_certification_status_does_not_dispatch_work',
                'agent_control_plane_chain_integrity_certification_status_does_not_execute_adapter',
                'agent_control_plane_chain_integrity_certification_status_does_not_enable_self_programming',
            ],
            'human_summary' => match ($status['status']) {
                'available' => 'Agent Control Plane Chain Integrity Certification status is available and aligned with the current horizon.',
                'degraded' => 'Agent Control Plane Chain Integrity Certification status is degraded; review violations before advancing.',
                'blocked' => 'Agent Control Plane Chain Integrity Certification status is blocked; audit service is not callable yet.',
                'missing_artifacts' => 'Agent Control Plane Chain Integrity Certification status is missing artifacts; canonical chain cannot be certified.',
                default => 'Agent Control Plane Chain Integrity Certification status is unknown.',
            },
        ];
    }

    public function agentControlPlaneDeterministicChainReplayStatus(array $options = []): array
    {
        $auditService = new AgentControlPlaneChainIntegrityAuditService($this->mother);
        $replayService = new AgentControlPlaneDeterministicChainReplayService($auditService, $this->mother);
        $replay = $replayService->replay($options);

        $status = [
            'status' => (string) data_get($replay, 'status', 'unknown'),
            'replay_schema_version' => (string) data_get($replay, 'schema_version', ''),
            'replay_id' => (string) data_get($replay, 'replay_id', ''),
            'generated_at' => (string) data_get($replay, 'generated_at', ''),
            'replay_hash' => (string) data_get($replay, 'replay_hash', ''),
            'deterministic_replay_hash' => (string) data_get($replay, 'deterministic_replay_hash', ''),
            'proof_bundle_hash' => (string) data_get($replay, 'proof_bundle_hash', ''),
            'replayed_slice_count' => (int) data_get($replay, 'replayed_slice_count', 0),
            'replayed_edge_count' => (int) data_get($replay, 'replayed_edge_count', 0),
            'violation_count' => count((array) data_get($replay, 'violations', [])),
            'warning_count' => count((array) data_get($replay, 'warnings', [])),
            'invariants_all_true' => (bool) data_get($replay, 'invariants_all_true', false),
            'runtime_safety_all_false' => (bool) data_get($replay, 'runtime_safety.runtime_safety_all_false', false),
            'current_pointer' => (string) data_get($replay, 'current_pointer', ''),
            'expected_pointer' => (string) data_get($replay, 'expected_pointer', ''),
            'next_safe_macro_batch' => (string) data_get($replay, 'next_safe_macro_batch', ''),
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_control_plane_deterministic_chain_replay_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_control_plane_deterministic_chain_replay_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'agent_control_plane_deterministic_chain_replay_status' => $status,
            'agent_control_plane_deterministic_chain_replay' => $replay,
            'agent_control_plane_deterministic_chain_replay_status_hash' => $this->stableHash($status),
            'non_execution_guarantees' => [
                'agent_control_plane_deterministic_chain_replay_status_does_not_start_codex',
                'agent_control_plane_deterministic_chain_replay_status_does_not_advance_pointer',
                'agent_control_plane_deterministic_chain_replay_status_does_not_dispatch_work',
                'agent_control_plane_deterministic_chain_replay_status_does_not_execute_adapter',
                'agent_control_plane_deterministic_chain_replay_status_does_not_enable_self_programming',
            ],
            'human_summary' => match ($status['status']) {
                'available' => 'Agent Control Plane Deterministic Chain Replay status is available and the proof bundle is deterministic.',
                'degraded' => 'Agent Control Plane Deterministic Chain Replay status is degraded; review violations before relying on the proof bundle.',
                'blocked' => 'Agent Control Plane Deterministic Chain Replay status is blocked; replay service is not callable yet.',
                default => 'Agent Control Plane Deterministic Chain Replay status is unknown.',
            },
        ];
    }

    public function agentControlPlaneReplaySnapshotStoreStatus(array $options = []): array
    {
        $store = new AgentControlPlaneReplaySnapshotStore;
        $latest = $store->latest();
        $registry = $store->registry();

        $status = [
            'status' => $latest === null ? 'no_snapshot' : 'available',
            'entry_count' => (int) data_get($registry, 'entry_count', 0),
            'storage_prefix' => AgentControlPlaneReplaySnapshotStore::STORAGE_PREFIX,
            'registry_path' => AgentControlPlaneReplaySnapshotStore::REGISTRY_PATH,
            'keep_default' => AgentControlPlaneReplaySnapshotStore::DEFAULT_KEEP,
            'latest_snapshot_id' => (string) data_get($latest, 'snapshot_id', ''),
            'latest_created_at' => (string) data_get($latest, 'created_at', ''),
            'latest_label' => (string) data_get($latest, 'label', ''),
            'latest_deterministic_replay_hash' => (string) data_get($latest, 'deterministic_replay_hash', ''),
            'latest_replay_hash' => (string) data_get($latest, 'replay_hash', ''),
            'latest_proof_bundle_hash' => (string) data_get($latest, 'proof_bundle_hash', ''),
            'latest_current_pointer' => (string) data_get($latest, 'current_pointer', ''),
            'latest_violation_count' => (int) data_get($latest, 'violation_count', 0),
            'latest_warning_count' => (int) data_get($latest, 'warning_count', 0),
            'latest_runtime_safety_all_false' => (bool) data_get($latest, 'runtime_safety_all_false', false),
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_control_plane_replay_snapshot_store_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_control_plane_replay_snapshot_store_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'agent_control_plane_replay_snapshot_store_status' => $status,
            'agent_control_plane_replay_snapshot_store_registry' => $registry,
            'agent_control_plane_replay_snapshot_store_status_hash' => $this->stableHash($status),
            'non_execution_guarantees' => [
                'agent_control_plane_replay_snapshot_store_status_does_not_start_codex',
                'agent_control_plane_replay_snapshot_store_status_does_not_advance_pointer',
                'agent_control_plane_replay_snapshot_store_status_does_not_dispatch_work',
                'agent_control_plane_replay_snapshot_store_status_does_not_execute_adapter',
                'agent_control_plane_replay_snapshot_store_status_does_not_enable_self_programming',
            ],
            'human_summary' => match ($status['status']) {
                'available' => 'Agent Control Plane Replay Snapshot Store has at least one persisted snapshot.',
                'no_snapshot' => 'Agent Control Plane Replay Snapshot Store is empty; no snapshots have been persisted yet.',
                default => 'Agent Control Plane Replay Snapshot Store status is unknown.',
            },
        ];
    }

    public function agentControlPlaneReplaySnapshotStoreCapture(array $options = []): array
    {
        $store = new AgentControlPlaneReplaySnapshotStore;
        $auditService = new AgentControlPlaneChainIntegrityAuditService($this->mother);
        $replayService = new AgentControlPlaneDeterministicChainReplayService($auditService, $this->mother);
        $diffService = new AgentControlPlaneReplayDiffService($store, $replayService);
        $gate = new AgentControlPlaneMacroSprintPromotionGate($diffService, $auditService, $replayService);
        $baselineService = new AgentControlPlaneCertificationBaselineService(
            $this->mother,
            $auditService,
            $replayService,
            $store,
            $diffService,
            $gate,
        );

        $baseline = $baselineService->build($options);
        $replay = $replayService->replay($options);
        $diff = $diffService->diff();
        $gatePayload = $gate->evaluate($options);
        $readiness = (new AgentControlPlaneBaselineCaptureReadinessService($store))->assess($baseline, $replay, $diff, $gatePayload);
        $registryBefore = $store->registry();

        $snapshotWritePerformed = false;
        $snapshotResult = null;
        if ((bool) data_get($readiness, 'can_capture_snapshot', false)
            && (bool) data_get($readiness, 'snapshot_capture_required', false)) {
            $snapshotResult = $store->put($replay, [
                'label' => (string) data_get($readiness, 'capture_plan.recommended_label', 'completion-baseline'),
                'keep' => (int) data_get($readiness, 'capture_plan.recommended_keep', AgentControlPlaneReplaySnapshotStore::DEFAULT_KEEP),
            ]);
            $snapshotWritePerformed = true;
        }

        $registryAfter = $store->registry();
        $latest = $store->latest();
        $status = match (true) {
            (string) data_get($readiness, 'status') === 'blocked' => 'blocked',
            $snapshotWritePerformed => 'captured',
            (string) data_get($readiness, 'snapshot_state') === 'current' => 'already_current',
            default => 'not_captured',
        };

        $payload = [
            'schema_version' => 'atlas.self_construction_agent_control_plane_replay_snapshot_store_capture.v1',
            'status' => $status,
            'mode' => 'explicit_agent_control_plane_replay_snapshot_store_capture',
            'read_only' => false,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'external_provider_call' => false,
            'token_spend' => false,
            'process_started' => false,
            'provider_call_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'completion_claim_allowed' => false,
            'snapshot_write_allowed' => true,
            'snapshot_write_performed' => $snapshotWritePerformed,
            'snapshot_id' => (string) data_get($snapshotResult, 'snapshot_id', data_get($latest, 'snapshot_id', '')),
            'snapshot_path' => (string) data_get($snapshotResult, 'path', ''),
            'registry_entry_count_before' => (int) data_get($registryBefore, 'entry_count', 0),
            'registry_entry_count_after' => (int) data_get($registryAfter, 'entry_count', 0),
            'latest_snapshot_id' => (string) data_get($latest, 'snapshot_id', ''),
            'latest_deterministic_replay_hash' => (string) data_get($latest, 'deterministic_replay_hash', ''),
            'baseline_capture_readiness_status' => (string) data_get($readiness, 'status'),
            'baseline_snapshot_state_before_capture' => (string) data_get($readiness, 'snapshot_state'),
            'baseline_capture_readiness_hash' => (string) data_get($readiness, 'baseline_capture_readiness_hash'),
            'current_deterministic_replay_hash' => (string) data_get($replay, 'deterministic_replay_hash', ''),
            'non_execution_guarantees' => [
                'snapshot_capture_does_not_start_codex',
                'snapshot_capture_does_not_call_codex_cli_or_app',
                'snapshot_capture_does_not_spawn_subprocess',
                'snapshot_capture_does_not_invoke_adapter',
                'snapshot_capture_does_not_execute_adapter',
                'snapshot_capture_does_not_call_provider',
                'snapshot_capture_does_not_dispatch_work',
                'snapshot_capture_does_not_spend_tokens',
                'snapshot_capture_does_not_write_ledger',
                'snapshot_capture_does_not_mutate_pointer',
                'snapshot_capture_does_not_promote_completion_claim',
            ],
            'human_summary' => match ($status) {
                'captured' => 'Replay snapshot baseline captured explicitly; rerun replay diff and promotion gate.',
                'already_current' => 'Replay snapshot baseline is already current; no new snapshot was written.',
                'blocked' => 'Replay snapshot baseline capture is blocked by readiness checks.',
                default => 'Replay snapshot baseline was not captured; inspect capture readiness.',
            },
        ];
        $payload['agent_control_plane_replay_snapshot_store_capture_hash'] = $this->stableHash($payload);

        return $payload;
    }

    public function agentControlPlaneReplayDiffStatus(array $options = []): array
    {
        $store = new AgentControlPlaneReplaySnapshotStore;
        $auditService = new AgentControlPlaneChainIntegrityAuditService($this->mother);
        $replayService = new AgentControlPlaneDeterministicChainReplayService($auditService, $this->mother);
        $diffService = new AgentControlPlaneReplayDiffService($store, $replayService);

        $before = isset($options['before_snapshot_id']) && is_string($options['before_snapshot_id']) && $options['before_snapshot_id'] !== ''
            ? $options['before_snapshot_id']
            : null;
        $after = isset($options['after_snapshot_id']) && is_string($options['after_snapshot_id']) && $options['after_snapshot_id'] !== ''
            ? $options['after_snapshot_id']
            : null;

        $diff = $diffService->diff($before, $after, $options);

        $status = [
            'status' => (string) data_get($diff, 'status'),
            'diff_id' => (string) data_get($diff, 'diff_id'),
            'generated_at' => (string) data_get($diff, 'generated_at'),
            'before_snapshot_id' => (string) data_get($diff, 'before_snapshot_id'),
            'after_snapshot_id' => (string) data_get($diff, 'after_snapshot_id'),
            'changed' => (bool) data_get($diff, 'changed', false),
            'regression_count' => (int) data_get($diff, 'regression_count', 0),
            'improvement_count' => (int) data_get($diff, 'improvement_count', 0),
            'before_deterministic_replay_hash' => (string) data_get($diff, 'before_deterministic_replay_hash'),
            'after_deterministic_replay_hash' => (string) data_get($diff, 'after_deterministic_replay_hash'),
            'diff_hash' => (string) data_get($diff, 'diff_hash'),
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_control_plane_replay_diff_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_control_plane_replay_diff_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'agent_control_plane_replay_diff_status' => $status,
            'agent_control_plane_replay_diff' => $diff,
            'agent_control_plane_replay_diff_status_hash' => $this->stableHash($status),
            'non_execution_guarantees' => [
                'agent_control_plane_replay_diff_status_does_not_start_codex',
                'agent_control_plane_replay_diff_status_does_not_advance_pointer',
                'agent_control_plane_replay_diff_status_does_not_dispatch_work',
                'agent_control_plane_replay_diff_status_does_not_execute_adapter',
                'agent_control_plane_replay_diff_status_does_not_enable_self_programming',
            ],
            'human_summary' => match ($status['status']) {
                'unchanged' => 'Agent Control Plane Replay Diff is unchanged between baseline and current replay.',
                'improved' => 'Agent Control Plane Replay Diff shows the chain improved without regressions.',
                'regressed' => 'Agent Control Plane Replay Diff shows a regression; resolve before promoting.',
                'changed_with_warnings' => 'Agent Control Plane Replay Diff changed and warnings increased; review before promoting.',
                'no_baseline' => 'Agent Control Plane Replay Diff has no baseline snapshot yet.',
                'no_target' => 'Agent Control Plane Replay Diff target snapshot could not be resolved.',
                default => 'Agent Control Plane Replay Diff status is unknown.',
            },
        ];
    }

    public function agentControlPlaneMacroSprintPromotionGateStatus(array $options = []): array
    {
        $store = new AgentControlPlaneReplaySnapshotStore;
        $auditService = new AgentControlPlaneChainIntegrityAuditService($this->mother);
        $replayService = new AgentControlPlaneDeterministicChainReplayService($auditService, $this->mother);
        $diffService = new AgentControlPlaneReplayDiffService($store, $replayService);
        $gate = new AgentControlPlaneMacroSprintPromotionGate($diffService, $auditService, $replayService);

        $gatePayload = $gate->evaluate($options);

        $status = [
            'status' => (string) data_get($gatePayload, 'status'),
            'gate_id' => (string) data_get($gatePayload, 'gate_id'),
            'generated_at' => (string) data_get($gatePayload, 'generated_at'),
            'promotion_allowed' => (bool) data_get($gatePayload, 'promotion_allowed', false),
            'blocker_count' => (int) data_get($gatePayload, 'blocker_count', 0),
            'warning_count' => (int) data_get($gatePayload, 'warning_count', 0),
            'gate_hash' => (string) data_get($gatePayload, 'gate_hash'),
            'completion_claim_allowed' => (bool) data_get($gatePayload, 'completion_claim_allowed', false),
            'runtime_execution_allowed' => (bool) data_get($gatePayload, 'runtime_execution_allowed', false),
            'provider_call_allowed' => (bool) data_get($gatePayload, 'provider_call_allowed', false),
            'dispatch_allowed' => (bool) data_get($gatePayload, 'dispatch_allowed', false),
            'self_programming_allowed' => (bool) data_get($gatePayload, 'self_programming_allowed', false),
            'next_action' => (string) data_get($gatePayload, 'next_action'),
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_control_plane_macro_sprint_promotion_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_control_plane_macro_sprint_promotion_gate_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'agent_control_plane_macro_sprint_promotion_gate_status' => $status,
            'agent_control_plane_macro_sprint_promotion_gate' => $gatePayload,
            'agent_control_plane_macro_sprint_promotion_gate_status_hash' => $this->stableHash($status),
            'non_execution_guarantees' => [
                'agent_control_plane_macro_sprint_promotion_gate_status_does_not_start_codex',
                'agent_control_plane_macro_sprint_promotion_gate_status_does_not_advance_pointer',
                'agent_control_plane_macro_sprint_promotion_gate_status_does_not_dispatch_work',
                'agent_control_plane_macro_sprint_promotion_gate_status_does_not_execute_adapter',
                'agent_control_plane_macro_sprint_promotion_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => match ($status['status']) {
                'passed' => 'Agent Control Plane Macro-Sprint Promotion Gate is passed; macro-sprint may be recorded as certified.',
                'warning' => 'Agent Control Plane Macro-Sprint Promotion Gate is passed with warnings; review warnings before recording.',
                'blocked' => 'Agent Control Plane Macro-Sprint Promotion Gate is blocked; resolve blockers before recording.',
                'no_baseline' => 'Agent Control Plane Macro-Sprint Promotion Gate has no baseline snapshot; record one before promotion.',
                default => 'Agent Control Plane Macro-Sprint Promotion Gate status is unknown.',
            },
        ];
    }

    public function agentControlPlaneCertificationBaselineStatus(array $options = []): array
    {
        $auditService = new AgentControlPlaneChainIntegrityAuditService($this->mother);
        $replayService = new AgentControlPlaneDeterministicChainReplayService($auditService, $this->mother);
        $store = new AgentControlPlaneReplaySnapshotStore;
        $diffService = new AgentControlPlaneReplayDiffService($store, $replayService);
        $gate = new AgentControlPlaneMacroSprintPromotionGate($diffService, $auditService, $replayService);
        $baselineService = new AgentControlPlaneCertificationBaselineService(
            $this->mother,
            $auditService,
            $replayService,
            $store,
            $diffService,
            $gate,
        );
        $baseline = $baselineService->build($options);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'certification_baseline',
            label: 'Certification Baseline',
            payload: $baseline,
            statusKey: 'status',
            extraStatusFields: [
                'baseline_id' => (string) data_get($baseline, 'baseline_id'),
                'baseline_hash' => (string) data_get($baseline, 'baseline_hash'),
                'baseline_fingerprint' => (string) data_get($baseline, 'baseline_fingerprint'),
                'current_pointer' => (string) data_get($baseline, 'current_pointer'),
                'invariants_all_true' => (bool) data_get($baseline, 'invariants_all_true', false),
                'chain_integrity_hash' => (string) data_get($baseline, 'chain_integrity_hash'),
                'deterministic_replay_hash' => (string) data_get($baseline, 'deterministic_replay_hash'),
                'docs_hash' => (string) data_get($baseline, 'docs_hash'),
                'capability_surface_hash' => (string) data_get($baseline, 'capability_surface_hash'),
            ],
        );
    }

    public function agentControlPlaneReleaseDossierStatus(array $options = []): array
    {
        $auditService = new AgentControlPlaneChainIntegrityAuditService($this->mother);
        $replayService = new AgentControlPlaneDeterministicChainReplayService($auditService, $this->mother);
        $store = new AgentControlPlaneReplaySnapshotStore;
        $diffService = new AgentControlPlaneReplayDiffService($store, $replayService);
        $gate = new AgentControlPlaneMacroSprintPromotionGate($diffService, $auditService, $replayService);
        $simulator = new AgentControlPlaneCertificationScenarioSimulator(
            $this->mother,
            $auditService,
            $replayService,
            $diffService,
            $store,
            $gate,
        );
        $baselineService = new AgentControlPlaneCertificationBaselineService(
            $this->mother,
            $auditService,
            $replayService,
            $store,
            $diffService,
            $gate,
        );
        $mutationGuard = new AgentControlPlaneCertificationMutationGuard($this->mother, $replayService, $store);
        $dossier = new AgentControlPlaneReleaseDossierService(
            $baselineService,
            $replayService,
            $store,
            $diffService,
            $gate,
            $simulator,
            $auditService,
            $mutationGuard,
        );
        $result = $dossier->build($options);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'release_dossier',
            label: 'Release Dossier',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'dossier_id' => (string) data_get($result, 'dossier_id'),
                'release_dossier_hash' => (string) data_get($result, 'release_dossier_hash'),
                'baseline_hash' => (string) data_get($result, 'baseline_hash'),
                'baseline_capture_readiness_hash' => (string) data_get($result, 'baseline_capture_readiness_hash'),
                'baseline_capture_readiness_status' => (string) data_get($result, 'baseline_capture_readiness_status'),
                'baseline_snapshot_state' => (string) data_get($result, 'baseline_snapshot_state'),
                'baseline_snapshot_capture_required' => (bool) data_get($result, 'baseline_snapshot_capture_required'),
                'baseline_snapshot_can_capture' => (bool) data_get($result, 'baseline_snapshot_can_capture'),
                'risk_classification' => (string) data_get($result, 'risk_classification'),
                'promotion_gate_status' => (string) data_get($result, 'promotion_gate_status'),
                'scenario_detection_rate' => (float) data_get($result, 'scenario_detection_rate'),
                'chain_integrity_status' => (string) data_get($result, 'chain_integrity_status'),
                'runtime_safety_all_false' => (bool) data_get($result, 'runtime_safety_all_false'),
                'mutation_guard_passed' => (bool) data_get($result, 'mutation_guard_passed'),
                'blocker_count' => (int) data_get($result, 'blocker_count'),
                'warning_count' => (int) data_get($result, 'warning_count'),
            ],
        );
    }

}
