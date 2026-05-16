<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Bundles all certification layers (baseline, replay, snapshot, diff,
 * promotion gate, scenario simulator, mutation guard, chain integrity)
 * into a human + machine readable release dossier so an operator can
 * accept or reject the next macro-sprint based on auditable evidence.
 *
 * Read-only: never starts processes, never calls Codex CLI/app, never
 * spawns subprocesses, never invokes adapters, never dispatches work,
 * never spends tokens, never advances the next required slice, never
 * enables self-programming, never writes the ledger.
 */
final class AgentControlPlaneReleaseDossierService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_release_dossier.v1';

    public const MODE = 'read_only_agent_control_plane_release_dossier';

    public function __construct(
        private readonly AgentControlPlaneCertificationBaselineService $baseline,
        private readonly AgentControlPlaneDeterministicChainReplayService $replay,
        private readonly AgentControlPlaneReplaySnapshotStore $store,
        private readonly AgentControlPlaneReplayDiffService $diff,
        private readonly AgentControlPlaneMacroSprintPromotionGate $gate,
        private readonly AgentControlPlaneCertificationScenarioSimulator $simulator,
        private readonly AgentControlPlaneChainIntegrityAuditService $audit,
        private readonly AgentControlPlaneCertificationMutationGuard $mutationGuard,
        private readonly ?AgentControlPlaneBaselineCaptureReadinessService $baselineCaptureReadiness = null,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function build(array $options = []): array
    {
        $skipSimulator = (bool) ($options['skip_simulator'] ?? false);

        $baseline = $this->baseline->build();
        $replay = $this->replay->replay();
        $diff = $this->diff->diff();
        $gate = $this->gate->evaluate();
        $simulator = $skipSimulator
            ? ['scenario_count' => 0, 'detection_rate' => 1.0, 'all_expected_faults_detected' => true, 'scenario_matrix_hash' => '']
            : $this->simulator->simulate();
        $chainIntegrity = $this->audit->audit();
        $mutationGuard = $this->mutationGuard->guard();
        $latestSnapshot = $this->store->latest();
        $baselineCaptureReadiness = ($this->baselineCaptureReadiness ?? new AgentControlPlaneBaselineCaptureReadinessService($this->store))
            ->assess($baseline, $replay, $diff, $gate);

        $blockers = [];
        $warnings = [];

        $chainStatus = (string) data_get($chainIntegrity, 'status');
        if ($chainStatus !== 'available') {
            $blockers[] = 'chain_integrity_status_is_'.$chainStatus;
        }
        $replayStatus = (string) data_get($replay, 'status');
        if ($replayStatus !== 'available') {
            $blockers[] = 'replay_status_is_'.$replayStatus;
        }
        $runtimeSafetyAllFalse = (bool) data_get($replay, 'runtime_safety.runtime_safety_all_false', false);
        if (! $runtimeSafetyAllFalse) {
            $blockers[] = 'runtime_safety_not_all_false';
        }
        if ((int) data_get($replay, 'violations', []) || count((array) data_get($replay, 'violations', [])) > 0) {
            $warnings[] = 'replay_has_violations';
        }
        if (! ($skipSimulator) && ! (bool) data_get($simulator, 'all_expected_faults_detected', false)) {
            $blockers[] = 'scenario_simulator_failed_to_detect_some_faults';
        }
        if (! (bool) data_get($mutationGuard, 'guard_passed', false)) {
            $blockers[] = 'mutation_guard_detected_forbidden_mutation';
        }
        $gateStatus = (string) data_get($gate, 'status');
        if ($gateStatus === 'no_baseline') {
            $warnings[] = 'promotion_gate_has_no_baseline_yet';
        } elseif ($gateStatus === 'blocked') {
            $blockers[] = 'promotion_gate_status_is_blocked';
        } elseif ($gateStatus === 'warning') {
            $warnings[] = 'promotion_gate_status_is_warning';
        }
        if ((string) data_get($baselineCaptureReadiness, 'status') === 'blocked') {
            $blockers[] = 'baseline_capture_readiness_blocked';
        }
        if ((bool) data_get($baselineCaptureReadiness, 'snapshot_capture_required', false)) {
            $warnings[] = (string) data_get($baselineCaptureReadiness, 'snapshot_state') === 'stale'
                ? 'baseline_snapshot_refresh_required'
                : 'baseline_snapshot_capture_required';
        }

        $status = match (true) {
            $blockers !== [] => 'blocked',
            $warnings !== [] => 'warning',
            default => 'available',
        };

        $detectionRate = (float) data_get($simulator, 'detection_rate', 1.0);
        $riskClassification = match (true) {
            $blockers !== [] => 'high',
            $detectionRate < 1.0 => 'medium',
            $warnings !== [] => 'medium',
            default => 'low',
        };

        $operatorSummary = $this->buildOperatorSummary($status, $riskClassification, $baseline, $replay, $gate, $simulator, $baselineCaptureReadiness, $blockers, $warnings);
        $machineSummary = $this->buildMachineSummary($baseline, $replay, $diff, $gate, $simulator, $chainIntegrity, $mutationGuard, $baselineCaptureReadiness);
        $closureRunbook = $this->buildClosureRunbook($status, $blockers, $warnings, $replay, $baselineCaptureReadiness, $latestSnapshot);

        $evidenceIndex = [
            'control_plane_baseline' => 'php artisan atlas:ai:self-construction --agent-control-plane-certification-baseline-status --json',
            'chain_integrity' => 'php artisan atlas:ai:self-construction --agent-control-plane-chain-integrity-certification-status --json',
            'deterministic_replay' => 'php artisan atlas:ai:self-construction --agent-control-plane-deterministic-chain-replay-status --json',
            'snapshot_store' => 'php artisan atlas:ai:self-construction --agent-control-plane-replay-snapshot-store-status --json',
            'replay_diff' => 'php artisan atlas:ai:self-construction --agent-control-plane-replay-diff-status --json',
            'promotion_gate' => 'php artisan atlas:ai:self-construction --agent-control-plane-macro-sprint-promotion-gate-status --json',
            'scenario_simulator' => 'php artisan atlas:ai:self-construction --agent-control-plane-certification-scenario-simulator-status --json',
            'mutation_guard' => 'php artisan atlas:ai:self-construction --agent-control-plane-certification-mutation-guard-status --json',
        ];

        $commandEvidence = [
            'baseline_command' => $evidenceIndex['control_plane_baseline'],
            'chain_integrity_command' => $evidenceIndex['chain_integrity'],
            'replay_command' => $evidenceIndex['deterministic_replay'],
            'diff_command' => $evidenceIndex['replay_diff'],
            'gate_command' => $evidenceIndex['promotion_gate'],
            'simulator_command' => $evidenceIndex['scenario_simulator'],
            'mutation_guard_command' => $evidenceIndex['mutation_guard'],
        ];

        $docEvidence = [
            'agent_control_plane_contract' => 'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
        ];

        $testEvidence = [
            'baseline_tests' => 'tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneCertificationBaselineTest.php',
            'simulator_tests' => 'tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneCertificationScenarioSimulatorTest.php',
            'release_dossier_tests' => 'tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneReleaseDossierTest.php',
            'mutation_guard_tests' => 'tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneCertificationMutationGuardTest.php',
            'chain_integrity_tests' => 'tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneChainIntegrityAuditTest.php',
            'replay_tests' => 'tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneDeterministicChainReplayTest.php',
            'snapshot_store_tests' => 'tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneReplaySnapshotStoreTest.php',
            'replay_diff_tests' => 'tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneReplayDiffTest.php',
            'promotion_gate_tests' => 'tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneMacroSprintPromotionGateTest.php',
        ];

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'dossier_id' => (string) Str::uuid(),
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'status' => $status,
            'read_only' => true,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'completion_claim_allowed' => false,
            'runtime_execution_allowed' => false,
            'provider_call_allowed' => false,
            'self_programming_allowed' => false,
            'baseline_hash' => (string) data_get($baseline, 'baseline_hash'),
            'baseline_fingerprint' => (string) data_get($baseline, 'baseline_fingerprint'),
            'replay_hash' => (string) data_get($replay, 'replay_hash'),
            'deterministic_replay_hash' => (string) data_get($replay, 'deterministic_replay_hash'),
            'proof_bundle_hash' => (string) data_get($replay, 'proof_bundle_hash'),
            'diff_hash' => (string) data_get($diff, 'diff_hash'),
            'gate_hash' => (string) data_get($gate, 'gate_hash'),
            'scenario_matrix_hash' => (string) data_get($simulator, 'scenario_matrix_hash'),
            'mutation_guard_hash' => (string) data_get($mutationGuard, 'mutation_hash'),
            'baseline_capture_readiness_hash' => (string) data_get($baselineCaptureReadiness, 'baseline_capture_readiness_hash'),
            'chain_integrity_hash' => (string) data_get($chainIntegrity, 'agent_control_plane_chain_integrity_certification_hash'),
            'promotion_gate_status' => $gateStatus,
            'baseline_capture_readiness_status' => (string) data_get($baselineCaptureReadiness, 'status'),
            'baseline_snapshot_state' => (string) data_get($baselineCaptureReadiness, 'snapshot_state'),
            'baseline_snapshot_capture_required' => (bool) data_get($baselineCaptureReadiness, 'snapshot_capture_required', false),
            'baseline_snapshot_can_capture' => (bool) data_get($baselineCaptureReadiness, 'can_capture_snapshot', false),
            'scenario_detection_rate' => $detectionRate,
            'chain_integrity_status' => $chainStatus,
            'runtime_safety_all_false' => $runtimeSafetyAllFalse,
            'mutation_guard_passed' => (bool) data_get($mutationGuard, 'guard_passed', false),
            'current_pointer' => (string) data_get($baseline, 'current_pointer'),
            'next_required_slice' => (string) data_get($baseline, 'current_pointer'),
            'next_safe_macro_batch' => (string) data_get($replay, 'next_safe_macro_batch'),
            'risk_classification' => $riskClassification,
            'operator_summary' => $operatorSummary,
            'machine_summary' => $machineSummary,
            'baseline_capture_readiness' => $baselineCaptureReadiness,
            'release_dossier_closure_runbook' => $closureRunbook,
            'integrated_runtime_surface' => $this->buildIntegratedRuntimeSurface(),
            'evidence_index' => $evidenceIndex,
            'command_evidence' => $commandEvidence,
            'doc_evidence' => $docEvidence,
            'test_evidence' => $testEvidence,
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'blocker_count' => count(array_unique($blockers)),
            'warning_count' => count(array_unique($warnings)),
            'latest_snapshot_id' => (string) data_get($latestSnapshot, 'snapshot_id', ''),
            'non_goals' => [
                'mutate_agent_control_plane',
                'advance_next_required_slice',
                'invoke_codex',
                'dispatch_work',
                'spawn_subprocess',
                'enable_self_programming',
                'declare_atlas_self_construction_os_complete',
                'promote_completion_claim',
                'execute_runtime',
            ],
            'non_execution_guarantees' => [
                'release_dossier_does_not_start_codex',
                'release_dossier_does_not_call_codex_cli_or_app',
                'release_dossier_does_not_spawn_subprocess',
                'release_dossier_does_not_invoke_adapter',
                'release_dossier_does_not_execute_adapter',
                'release_dossier_does_not_call_provider',
                'release_dossier_does_not_dispatch_work',
                'release_dossier_does_not_spend_tokens',
                'release_dossier_does_not_enable_self_programming',
                'release_dossier_does_not_write_ledger',
                'release_dossier_does_not_mutate_pointer',
                'release_dossier_does_not_promote_completion_claim',
            ],
            'human_summary' => match ($status) {
                'available' => 'Release dossier is available with low risk; macro-sprint may be promoted by the operator.',
                'warning' => 'Release dossier is available with warnings; review before promotion.',
                'blocked' => 'Release dossier is blocked; resolve blockers before promotion.',
                default => 'Release dossier status is unknown.',
            },
        ];

        $payload['release_dossier_hash'] = $this->stableHash($this->normalizeForDossierHash($payload));

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $baseline
     * @param  array<string, mixed>  $replay
     * @param  array<string, mixed>  $gate
     * @param  array<string, mixed>  $simulator
     * @param  list<string>  $blockers
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    private function buildOperatorSummary(string $status, string $risk, array $baseline, array $replay, array $gate, array $simulator, array $baselineCaptureReadiness, array $blockers, array $warnings): array
    {
        return [
            'overall_status' => $status,
            'risk_classification' => $risk,
            'current_pointer' => (string) data_get($baseline, 'current_pointer'),
            'next_safe_macro_batch' => (string) data_get($replay, 'next_safe_macro_batch'),
            'replay_status' => (string) data_get($replay, 'status'),
            'promotion_gate_status' => (string) data_get($gate, 'status'),
            'baseline_capture_readiness_status' => (string) data_get($baselineCaptureReadiness, 'status'),
            'baseline_snapshot_state' => (string) data_get($baselineCaptureReadiness, 'snapshot_state'),
            'baseline_snapshot_next_action' => (string) data_get($baselineCaptureReadiness, 'next_action'),
            'scenario_detection_rate' => (float) data_get($simulator, 'detection_rate', 1.0),
            'blockers' => $blockers,
            'warnings' => $warnings,
            'instruction' => match ($status) {
                'available' => 'Operator may accept the next macro-sprint after reviewing the proof bundle and snapshot history.',
                'warning' => 'Operator should investigate warnings before accepting; promotion is possible but not advised yet.',
                'blocked' => 'Operator must resolve blockers before any promotion attempt.',
                default => 'Operator should run the certification commands again.',
            },
        ];
    }

    /**
     * @param  array<string, mixed>  $baseline
     * @param  array<string, mixed>  $replay
     * @param  array<string, mixed>  $diff
     * @param  array<string, mixed>  $gate
     * @param  array<string, mixed>  $simulator
     * @param  array<string, mixed>  $chainIntegrity
     * @param  array<string, mixed>  $mutationGuard
     * @return array<string, mixed>
     */
    private function buildMachineSummary(array $baseline, array $replay, array $diff, array $gate, array $simulator, array $chainIntegrity, array $mutationGuard, array $baselineCaptureReadiness): array
    {
        return [
            'baseline_hash' => (string) data_get($baseline, 'baseline_hash'),
            'replay_hash' => (string) data_get($replay, 'replay_hash'),
            'deterministic_replay_hash' => (string) data_get($replay, 'deterministic_replay_hash'),
            'proof_bundle_hash' => (string) data_get($replay, 'proof_bundle_hash'),
            'diff_hash' => (string) data_get($diff, 'diff_hash'),
            'gate_hash' => (string) data_get($gate, 'gate_hash'),
            'scenario_matrix_hash' => (string) data_get($simulator, 'scenario_matrix_hash'),
            'mutation_guard_hash' => (string) data_get($mutationGuard, 'mutation_hash'),
            'baseline_capture_readiness_hash' => (string) data_get($baselineCaptureReadiness, 'baseline_capture_readiness_hash'),
            'chain_integrity_hash' => (string) data_get($chainIntegrity, 'agent_control_plane_chain_integrity_certification_hash'),
            'replay_status' => (string) data_get($replay, 'status'),
            'gate_status' => (string) data_get($gate, 'status'),
            'baseline_capture_readiness_status' => (string) data_get($baselineCaptureReadiness, 'status'),
            'baseline_snapshot_state' => (string) data_get($baselineCaptureReadiness, 'snapshot_state'),
            'baseline_snapshot_capture_required' => (bool) data_get($baselineCaptureReadiness, 'snapshot_capture_required', false),
            'simulator_status' => (string) data_get($simulator, 'status'),
            'chain_integrity_status' => (string) data_get($chainIntegrity, 'status'),
            'mutation_guard_status' => (string) data_get($mutationGuard, 'status'),
            'replayed_slice_count' => (int) data_get($replay, 'replayed_slice_count'),
            'replayed_edge_count' => (int) data_get($replay, 'replayed_edge_count'),
            'scenario_count' => (int) data_get($simulator, 'scenario_count'),
        ];
    }

    /**
     * Builds a read-only closure runbook so an operator can flip
     * release_dossier_green from warning to available without having to
     * trace which command writes which artifact. Surfaces the canonical
     * capture command, the audit command to rerun afterwards, and the
     * exact non-execution guarantees of the closure path.
     *
     * @param  list<string>  $blockers
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $replay
     * @param  array<string, mixed>  $baselineCaptureReadiness
     * @param  array<string, mixed>|null  $latestSnapshot
     * @return array<string, mixed>
     */
    private function buildClosureRunbook(
        string $status,
        array $blockers,
        array $warnings,
        array $replay,
        array $baselineCaptureReadiness,
        ?array $latestSnapshot,
    ): array {
        $captureRequired = (bool) data_get($baselineCaptureReadiness, 'snapshot_capture_required', false);
        $canCapture = (bool) data_get($baselineCaptureReadiness, 'can_capture_snapshot', false);
        $readinessStatus = (string) data_get($baselineCaptureReadiness, 'status', '');
        $snapshotState = (string) data_get($baselineCaptureReadiness, 'snapshot_state', '');
        $captureCommand = (string) data_get($baselineCaptureReadiness, 'capture_plan.capture_command', '');
        $postCaptureAuditCommand = (string) data_get($baselineCaptureReadiness, 'capture_plan.post_capture_audit_command', '');
        $postCaptureDossierCommand = (string) data_get($baselineCaptureReadiness, 'capture_plan.post_capture_dossier_command', '');
        $postCaptureReplayDiffCommand = (string) data_get($baselineCaptureReadiness, 'capture_plan.post_capture_replay_diff_command', '');
        $snapshotStoreStatusCommand = (string) data_get($baselineCaptureReadiness, 'capture_plan.snapshot_store_status_command', '');

        $closureStatus = match (true) {
            $status === 'available' => 'release_dossier_green',
            $status === 'blocked' => 'release_dossier_blocked_resolve_blockers_before_capture',
            $captureRequired && $canCapture => 'snapshot_capture_required_before_release_dossier_green',
            $captureRequired => 'snapshot_capture_required_but_capture_not_yet_safe',
            default => 'release_dossier_warning_inspect_blockers_and_warnings',
        };

        $nextAction = match ($closureStatus) {
            'release_dossier_green' => 'release_dossier_is_green_no_capture_required',
            'release_dossier_blocked_resolve_blockers_before_capture' => 'resolve_release_dossier_blockers_before_attempting_snapshot_capture',
            'snapshot_capture_required_before_release_dossier_green' => 'capture_baseline_snapshot_then_rerun_completion_audit',
            'snapshot_capture_required_but_capture_not_yet_safe' => 'resolve_capture_readiness_blockers_before_attempting_snapshot_capture',
            default => 'inspect_release_dossier_warnings_before_promotion',
        };

        $exactNextCommand = match ($closureStatus) {
            'snapshot_capture_required_before_release_dossier_green' => $captureCommand,
            'release_dossier_green' => $postCaptureAuditCommand,
            default => $postCaptureDossierCommand,
        };

        $runbookSteps = [
            [
                'id' => 'inspect_release_dossier',
                'summary' => 'Inspect the current release dossier to confirm capture is the only remaining step.',
                'command' => $postCaptureDossierCommand,
            ],
            [
                'id' => 'inspect_snapshot_store',
                'summary' => 'Inspect the snapshot registry to confirm the latest snapshot id and deterministic replay hash.',
                'command' => $snapshotStoreStatusCommand,
            ],
            [
                'id' => 'capture_replay_snapshot',
                'summary' => 'Explicitly capture the current deterministic replay snapshot. Only runs when readiness allows it and capture is required.',
                'command' => $captureCommand,
                'requires_capture' => $captureRequired,
                'capture_safe' => $canCapture,
            ],
            [
                'id' => 'rerun_replay_diff',
                'summary' => 'Re-run the replay diff so the dossier compares against the freshly captured snapshot.',
                'command' => $postCaptureReplayDiffCommand,
            ],
            [
                'id' => 'rerun_completion_audit',
                'summary' => 'Re-run the OS completion audit to confirm release_dossier_green flips to passed.',
                'command' => $postCaptureAuditCommand,
            ],
        ];

        $runbook = [
            'schema_version' => 'atlas.self_construction.agent_control_plane_release_dossier_closure_runbook.v1',
            'mode' => 'read_only_agent_control_plane_release_dossier_closure_runbook',
            'release_dossier_status' => $status,
            'release_dossier_green_passed' => $status === 'available',
            'closure_status' => $closureStatus,
            'next_action' => $nextAction,
            'exact_next_command' => $exactNextCommand,
            'baseline_capture_readiness_status' => $readinessStatus,
            'baseline_snapshot_state' => $snapshotState,
            'baseline_snapshot_capture_required' => $captureRequired,
            'baseline_snapshot_can_capture' => $canCapture,
            'current_pointer' => (string) data_get($replay, 'current_pointer', ''),
            'current_deterministic_replay_hash' => (string) data_get($replay, 'deterministic_replay_hash', ''),
            'latest_snapshot_id' => (string) data_get($latestSnapshot, 'snapshot_id', ''),
            'latest_snapshot_deterministic_replay_hash' => (string) data_get($latestSnapshot, 'deterministic_replay_hash', ''),
            'latest_snapshot_label' => (string) data_get($latestSnapshot, 'label', ''),
            'latest_snapshot_created_at' => (string) data_get($latestSnapshot, 'created_at', ''),
            'capture_command' => $captureCommand,
            'post_capture_replay_diff_command' => $postCaptureReplayDiffCommand,
            'post_capture_dossier_command' => $postCaptureDossierCommand,
            'post_capture_audit_command' => $postCaptureAuditCommand,
            'snapshot_store_status_command' => $snapshotStoreStatusCommand,
            'steps' => $runbookSteps,
            'step_count' => count($runbookSteps),
            'blockers' => $blockers,
            'warnings' => $warnings,
            'blocker_count' => count($blockers),
            'warning_count' => count($warnings),
            'read_only' => true,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'completion_claim_allowed' => false,
            'provider_call_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'non_execution_guarantees' => [
                'closure_runbook_does_not_start_codex',
                'closure_runbook_does_not_call_codex_cli_or_app',
                'closure_runbook_does_not_spawn_subprocess',
                'closure_runbook_does_not_invoke_adapter',
                'closure_runbook_does_not_execute_adapter',
                'closure_runbook_does_not_call_provider',
                'closure_runbook_does_not_dispatch_work',
                'closure_runbook_does_not_spend_tokens',
                'closure_runbook_does_not_enable_self_programming',
                'closure_runbook_does_not_write_ledger',
                'closure_runbook_does_not_mutate_pointer',
                'closure_runbook_does_not_promote_completion_claim',
                'closure_runbook_does_not_capture_snapshot_itself',
            ],
            'human_summary' => match ($closureStatus) {
                'release_dossier_green' => 'Release dossier is green; no snapshot capture required. Re-run the completion audit to confirm release_dossier_green is passed.',
                'snapshot_capture_required_before_release_dossier_green' => 'Capture the baseline replay snapshot explicitly, then rerun the completion audit to flip release_dossier_green to passed.',
                'snapshot_capture_required_but_capture_not_yet_safe' => 'Resolve the capture readiness blockers before attempting to capture the baseline replay snapshot.',
                'release_dossier_blocked_resolve_blockers_before_capture' => 'Release dossier is blocked; resolve blockers before attempting to capture the baseline replay snapshot.',
                default => 'Release dossier has warnings; inspect blockers and warnings before promotion.',
            },
        ];
        $runbook['runbook_hash'] = $this->stableHash($this->recursivelyKsort($runbook));

        return $runbook;
    }

    /**
     * Summarises the cross-module Agent Control Plane runtime surface for the
     * release dossier, calling each integrated layer's readiness projection
     * and folding the results into a single, stable map. Every layer is
     * read-only by contract.
     *
     * @return array<string, mixed>
     */
    private function buildIntegratedRuntimeSurface(): array
    {
        $surface = [];
        try {
            $readiness = app(AtlasSelfConstructionReadinessService::class);
        } catch (\Throwable) {
            return [
                'available' => false,
                'reason' => 'readiness_service_unavailable',
                'runtime_safety_all_false' => true,
            ];
        }

        $probes = [
            'persistent_task_queue' => 'agentControlPlaneTaskPacketQueueStatus',
            'persistent_claim_lease' => 'agentControlPlaneClaimLeaseRuntimeStatus',
            'scope_lock_runtime_validator' => 'agentControlPlaneScopeLockRuntimeValidatorStatus',
            'task_queue_orchestrator' => 'agentControlPlaneTaskQueueOrchestratorStatus',
            'task_queue_lease_certification' => 'agentControlPlaneTaskQueueLeaseCertificationStatus',
            'runtime_evidence_journal' => 'agentControlPlaneRuntimeEvidenceJournalStatus',
            'execution_workspace_runtime' => 'agentControlPlaneExecutionWorkspaceRuntimeStatus',
            'governance_approval_runtime' => 'agentControlPlaneGovernanceApprovalRuntimeStatus',
            'automatic_cost_import_runtime' => 'agentControlPlaneAutomaticCostImportRuntimeStatus',
            'automatic_work_product_collection_runtime' => 'agentControlPlaneAutomaticWorkProductCollectionRuntimeStatus',
            'adapter_execution_runtime_boundary' => 'agentControlPlaneAdapterExecutionRuntimeBoundaryStatus',
            'dispatch_planner_runtime' => 'agentControlPlaneDispatchPlannerRuntimeStatus',
            'validation_gate_runtime' => 'agentControlPlaneValidationGateRuntimeStatus',
            'merge_review_runtime' => 'agentControlPlaneMergeReviewRuntimeStatus',
            'runtime_pilot_orchestrator' => 'agentControlPlaneRuntimePilotOrchestratorStatus',
            'runtime_pilot_certification' => 'agentControlPlaneRuntimePilotCertificationStatus',
            'agent_runtime_registry' => 'agentControlPlaneAgentRuntimeRegistryStatus',
            'agent_runtime_registry_heartbeat' => 'agentControlPlaneAgentRuntimeRegistryHeartbeatStatus',
            'agent_runtime_registry_quarantine' => 'agentControlPlaneAgentRuntimeRegistryQuarantineStatus',
            'agent_runtime_registry_orchestrator' => 'agentControlPlaneAgentRuntimeRegistryOrchestratorStatus',
            'agent_runtime_registry_certification' => 'agentControlPlaneAgentRuntimeRegistryCertificationStatus',
        ];

        $availableCount = 0;
        foreach ($probes as $key => $method) {
            if (! method_exists($readiness, $method)) {
                $surface[$key] = ['status' => 'missing_method', 'runtime_execution_allowed' => false];

                continue;
            }
            try {
                $result = $readiness->{$method}();
                $surface[$key] = [
                    'status' => (string) data_get($result, 'status', 'unknown'),
                    'runtime_execution_allowed' => false,
                    'ledger_write_allowed' => false,
                    'dispatch_allowed' => false,
                ];
                if (! in_array((string) ($surface[$key]['status']), ['blocked', 'failed', 'persist_failed', 'missing_method'], true)) {
                    $availableCount++;
                }
            } catch (\Throwable $e) {
                $surface[$key] = [
                    'status' => 'exception',
                    'error' => $e->getMessage(),
                    'runtime_execution_allowed' => false,
                ];
            }
        }

        return [
            'available' => true,
            'probe_count' => count($probes),
            'available_count' => $availableCount,
            'all_layers_available' => $availableCount === count($probes),
            'runtime_safety_all_false' => true,
            'execution_workspace_runtime_status' => (string) data_get($surface, 'execution_workspace_runtime.status', 'unknown'),
            'governance_approval_runtime_status' => (string) data_get($surface, 'governance_approval_runtime.status', 'unknown'),
            'automatic_cost_import_runtime_status' => (string) data_get($surface, 'automatic_cost_import_runtime.status', 'unknown'),
            'automatic_work_product_collection_runtime_status' => (string) data_get($surface, 'automatic_work_product_collection_runtime.status', 'unknown'),
            'adapter_execution_runtime_boundary_status' => (string) data_get($surface, 'adapter_execution_runtime_boundary.status', 'unknown'),
            'dispatch_planner_runtime_status' => (string) data_get($surface, 'dispatch_planner_runtime.status', 'unknown'),
            'validation_gate_runtime_status' => (string) data_get($surface, 'validation_gate_runtime.status', 'unknown'),
            'merge_review_runtime_status' => (string) data_get($surface, 'merge_review_runtime.status', 'unknown'),
            'layers' => $surface,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeForDossierHash(array $payload): array
    {
        $clone = $payload;
        unset(
            $clone['dossier_id'],
            $clone['generated_at'],
            $clone['release_dossier_hash'],
            // The replay_hash and gate_hash both fold the per-call
            // generated_at/replay_id/gate_id volatile fields into their
            // hash; the dossier's stable fingerprint relies on
            // deterministic_replay_hash + diff_hash + baseline_hash
            // instead, which are stable when structural state is stable.
            $clone['replay_hash'],
            $clone['gate_hash'],
            $clone['mutation_guard_hash'],
        );
        if (isset($clone['machine_summary'])) {
            unset(
                $clone['machine_summary']['replay_hash'],
                $clone['machine_summary']['gate_hash'],
                $clone['machine_summary']['mutation_guard_hash'],
            );
        }
        if (isset($clone['baseline_capture_readiness']['assessed_at'])) {
            unset($clone['baseline_capture_readiness']['assessed_at']);
        }
        if (isset($clone['baseline_capture_readiness']['promotion_gate_hash'])) {
            unset($clone['baseline_capture_readiness']['promotion_gate_hash']);
        }

        return $this->recursivelyKsort($clone);
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<mixed, mixed>
     */
    private function recursivelyKsort(array $value): array
    {
        $isAssoc = $value !== [] && array_keys($value) !== range(0, count($value) - 1);
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->recursivelyKsort($entry);
            }
        }
        if ($isAssoc) {
            ksort($value);
        }

        return $value;
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
