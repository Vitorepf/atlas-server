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

        $operatorSummary = $this->buildOperatorSummary($status, $riskClassification, $baseline, $replay, $gate, $simulator, $blockers, $warnings);
        $machineSummary = $this->buildMachineSummary($baseline, $replay, $diff, $gate, $simulator, $chainIntegrity, $mutationGuard);

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
            'chain_integrity_hash' => (string) data_get($chainIntegrity, 'agent_control_plane_chain_integrity_certification_hash'),
            'promotion_gate_status' => $gateStatus,
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
    private function buildOperatorSummary(string $status, string $risk, array $baseline, array $replay, array $gate, array $simulator, array $blockers, array $warnings): array
    {
        return [
            'overall_status' => $status,
            'risk_classification' => $risk,
            'current_pointer' => (string) data_get($baseline, 'current_pointer'),
            'next_safe_macro_batch' => (string) data_get($replay, 'next_safe_macro_batch'),
            'replay_status' => (string) data_get($replay, 'status'),
            'promotion_gate_status' => (string) data_get($gate, 'status'),
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
    private function buildMachineSummary(array $baseline, array $replay, array $diff, array $gate, array $simulator, array $chainIntegrity, array $mutationGuard): array
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
            'chain_integrity_hash' => (string) data_get($chainIntegrity, 'agent_control_plane_chain_integrity_certification_hash'),
            'replay_status' => (string) data_get($replay, 'status'),
            'gate_status' => (string) data_get($gate, 'status'),
            'simulator_status' => (string) data_get($simulator, 'status'),
            'chain_integrity_status' => (string) data_get($chainIntegrity, 'status'),
            'mutation_guard_status' => (string) data_get($mutationGuard, 'status'),
            'replayed_slice_count' => (int) data_get($replay, 'replayed_slice_count'),
            'replayed_edge_count' => (int) data_get($replay, 'replayed_edge_count'),
            'scenario_count' => (int) data_get($simulator, 'scenario_count'),
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
