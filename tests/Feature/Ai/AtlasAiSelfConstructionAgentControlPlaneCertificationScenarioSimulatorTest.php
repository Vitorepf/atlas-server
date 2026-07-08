<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneCertificationScenarioSimulator;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneChainIntegrityAuditService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneDeterministicChainReplayService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneMacroSprintPromotionGate;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneReplayDiffService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneReplaySnapshotStore;
use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneCertificationScenarioSimulatorTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $cachedResult = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->cachedResult = null;
    }

    public function test_simulator_returns_schema_v1(): void
    {
        $result = $this->simulationResult();
        $this->assertSame(
            'atlas.self_construction.agent_control_plane_certification_scenario_simulator.v1',
            $result['schema_version'],
        );
        $this->assertSame('read_only_agent_control_plane_certification_scenario_simulator', $result['mode']);
    }

    public function test_simulator_scenario_count_at_least_30(): void
    {
        $result = $this->simulationResult();
        $this->assertGreaterThanOrEqual(30, (int) $result['scenario_count']);
    }

    public function test_simulator_all_expected_faults_detected(): void
    {
        $result = $this->simulationResult();
        $this->assertTrue((bool) $result['all_expected_faults_detected']);
    }

    public function test_simulator_detection_rate_is_one(): void
    {
        $result = $this->simulationResult();
        $this->assertSame(1.0, (float) $result['detection_rate']);
    }

    public function test_missing_capability_detected(): void
    {
        $this->assertScenarioDetected('missing_capability');
    }

    public function test_duplicate_capability_detected(): void
    {
        $this->assertScenarioDetected('duplicate_capability');
    }

    public function test_missing_cli_option_detected(): void
    {
        $this->assertScenarioDetected('missing_cli_option');
    }

    public function test_missing_readiness_method_detected(): void
    {
        $this->assertScenarioDetected('missing_readiness_method');
    }

    public function test_missing_invoker_detected(): void
    {
        $this->assertScenarioDetected('missing_invoker');
    }

    public function test_missing_doc_bullet_detected(): void
    {
        $this->assertScenarioDetected('missing_doc_bullet');
    }

    public function test_duplicate_doc_bullet_detected(): void
    {
        $this->assertScenarioDetected('duplicate_doc_bullet');
    }

    public function test_broken_edge_detected(): void
    {
        $this->assertScenarioDetected('broken_edge');
    }

    public function test_pointer_regression_detected(): void
    {
        $this->assertScenarioDetected('pointer_regression');
    }

    public function test_pointer_unexpected_reentry_detected(): void
    {
        $this->assertScenarioDetected('pointer_unexpected_reentry');
    }

    public function test_not_yet_runtime_capable_misalignment_detected(): void
    {
        $this->assertScenarioDetected('not_yet_runtime_capable_misalignment');
    }

    public function test_next_build_slices_misalignment_detected(): void
    {
        $this->assertScenarioDetected('next_build_slices_misalignment');
    }

    public function test_runtime_flag_actual_process_start_detected(): void
    {
        $this->assertScenarioDetected('runtime_flag_actual_process_start_true');
    }

    public function test_runtime_flag_provider_call_detected(): void
    {
        $this->assertScenarioDetected('runtime_flag_provider_call_true');
    }

    public function test_runtime_flag_adapter_invocation_detected(): void
    {
        $this->assertScenarioDetected('runtime_flag_adapter_invocation_true');
    }

    public function test_runtime_flag_adapter_execution_detected(): void
    {
        $this->assertScenarioDetected('runtime_flag_adapter_execution_true');
    }

    public function test_runtime_flag_dispatch_detected(): void
    {
        $this->assertScenarioDetected('runtime_flag_dispatch_true');
    }

    public function test_runtime_flag_token_spend_detected(): void
    {
        $this->assertScenarioDetected('runtime_flag_token_spend_true');
    }

    public function test_runtime_flag_self_programming_detected(): void
    {
        $this->assertScenarioDetected('runtime_flag_self_programming_true');
    }

    public function test_replay_hash_drift_detected(): void
    {
        $this->assertScenarioDetected('deterministic_replay_hash_drift');
    }

    public function test_proof_bundle_drift_detected(): void
    {
        $this->assertScenarioDetected('proof_bundle_hash_drift');
    }

    public function test_no_baseline_detected(): void
    {
        $this->assertScenarioDetected('snapshot_missing_baseline');
    }

    public function test_diff_regression_detected(): void
    {
        $this->assertScenarioDetected('diff_regression');
    }

    public function test_promotion_regression_detected(): void
    {
        $this->assertScenarioDetected('promotion_gate_regression');
    }

    public function test_cycle_unintentional_detected(): void
    {
        $this->assertScenarioDetected('cycle_unintentional');
    }

    public function test_intentional_reentry_wrong_target_detected(): void
    {
        $this->assertScenarioDetected('intentional_reentry_wrong_target');
    }

    public function test_terminal_horizon_missing_detected(): void
    {
        $this->assertScenarioDetected('terminal_horizon_missing');
    }

    public function test_provider_runtime_matrix_gap_detected(): void
    {
        $this->assertScenarioDetected('provider_runtime_matrix_gap');
    }

    public function test_evidence_corridor_gap_detected(): void
    {
        $this->assertScenarioDetected('evidence_corridor_gap');
    }

    public function test_implementation_corridor_gap_detected(): void
    {
        $this->assertScenarioDetected('implementation_corridor_gap');
    }

    public function test_scenario_matrix_hash_stable(): void
    {
        $service = $this->newService();
        $a = $service->simulate();
        $b = $service->simulate();
        $this->assertSame($a['scenario_matrix_hash'], $b['scenario_matrix_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $a['scenario_matrix_hash']);
    }

    public function test_simulator_is_read_only(): void
    {
        $result = $this->simulationResult();
        $this->assertTrue((bool) $result['read_only']);
        $this->assertFalse((bool) $result['execution_allowed']);
        $this->assertFalse((bool) $result['dispatch_allowed']);
        $this->assertFalse((bool) $result['ledger_write_allowed']);
        $this->assertFalse((bool) $result['runtime_write_allowed']);
        $this->assertFalse((bool) $result['external_provider_call']);
        $this->assertFalse((bool) $result['token_spend']);
        $this->assertFalse((bool) $result['process_started']);
        $this->assertFalse((bool) $result['self_programming_allowed']);
    }

    public function test_simulator_does_not_advance_pointer(): void
    {
        $beforePointer = $this->controlPlanePointer();
        $this->newService()->simulate();
        $afterPointer = $this->controlPlanePointer();

        $this->assertSame($beforePointer, $afterPointer);
    }

    public function test_simulator_status_cli_works(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-certification-scenario-simulator-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(
            'atlas.self_construction_agent_control_plane_certification_scenario_simulator_status.v1',
            $payload['schema_version'],
        );
        $this->assertSame('passed', data_get($payload, 'agent_control_plane_certification_scenario_simulator_status.status'));
        $this->assertSame(30, (int) data_get($payload, 'agent_control_plane_certification_scenario_simulator_status.scenario_count'));
    }

    public function test_simulator_quartet_cli_works(): void
    {
        foreach (['contract', 'preflight', 'implementation-packet'] as $stage) {
            Artisan::call('atlas:ai:self-construction', [
                "--agent-control-plane-certification-scenario-simulator-{$stage}" => true,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $stageKey = str_replace('-', '_', $stage);
            $this->assertSame(
                "atlas.self_construction_agent_control_plane_certification_scenario_simulator_{$stageKey}.v1",
                $payload['schema_version'],
            );
        }
    }

    private function assertScenarioDetected(string $id): void
    {
        $simResult = $this->simulationResult();
        $scenarios = (array) $simResult['scenarios'];
        $matched = array_values(array_filter($scenarios, static fn ($s) => $s['scenario_id'] === $id));
        $this->assertNotEmpty($matched, "Scenario '$id' missing from simulator output");
        $this->assertTrue((bool) $matched[0]['detected'], "Scenario '$id' not detected by simulator");
    }

    /**
     * @return array<string, mixed>
     */
    private function simulationResult(): array
    {
        if ($this->cachedResult === null) {
            $this->cachedResult = $this->newService()->simulate();
        }

        return $this->cachedResult;
    }

    private function newService(): AgentControlPlaneCertificationScenarioSimulator
    {
        $readiness = app(AtlasSelfConstructionReadinessService::class);
        $audit = new AgentControlPlaneChainIntegrityAuditService($readiness);
        $replay = new AgentControlPlaneDeterministicChainReplayService($audit, $readiness);
        $store = new AgentControlPlaneReplaySnapshotStore('local');
        $diff = new AgentControlPlaneReplayDiffService($store, $replay);
        $gate = new AgentControlPlaneMacroSprintPromotionGate($diff, $audit, $replay);

        return new AgentControlPlaneCertificationScenarioSimulator($readiness, $audit, $replay, $diff, $store, $gate);
    }

    private function controlPlanePointer(): string
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        return (string) data_get($payload, 'control_plane.persistent_runtime.next_required_slice');
    }

    // ── simulateTaskServingScenarios() ──────────────────────────────────────────

    public function test_stale_lease_match(): void
    {
        $result = $this->newService()->simulateTaskServingScenarios(['scenarios' => [
            ['scenario_id' => 's1', 'kind' => 'stale_lease', 'lease_expires_at_is_past' => true, 'observed_decision' => 'give_back'],
        ]]);

        $this->assertSame('give_back', $result['results'][0]['expected_decision']);
        $this->assertSame(AgentControlPlaneCertificationScenarioSimulator::REGRESSION_MATCH, $result['results'][0]['regression_status']);
        $this->assertSame(1, $result['match_count']);
    }

    public function test_duplicate_target_regression_when_observed_wrong(): void
    {
        $result = $this->newService()->simulateTaskServingScenarios(['scenarios' => [
            ['scenario_id' => 's2', 'kind' => 'duplicate_target', 'target_in_existing_queue' => true, 'observed_decision' => 'accept'],
        ]]);

        $this->assertSame('give_back', $result['results'][0]['expected_decision']);
        $this->assertSame(AgentControlPlaneCertificationScenarioSimulator::REGRESSION_REGRESSION, $result['results'][0]['regression_status']);
        $this->assertSame(1, $result['regression_count']);
    }

    public function test_missing_proof_match(): void
    {
        $result = $this->newService()->simulateTaskServingScenarios(['scenarios' => [
            ['scenario_id' => 's3', 'kind' => 'missing_proof', 'required_evidence_present' => false, 'observed_decision' => 'reject'],
        ]]);

        $this->assertSame('reject', $result['results'][0]['expected_decision']);
        $this->assertSame(AgentControlPlaneCertificationScenarioSimulator::REGRESSION_MATCH, $result['results'][0]['regression_status']);
    }

    public function test_worker_mismatch_match(): void
    {
        $result = $this->newService()->simulateTaskServingScenarios(['scenarios' => [
            ['scenario_id' => 's4', 'kind' => 'worker_mismatch', 'required_capabilities' => ['security_audit'], 'worker_capabilities' => ['code_edit'], 'observed_decision' => 'reject'],
        ]]);

        $this->assertSame('reject', $result['results'][0]['expected_decision']);
        $this->assertSame(AgentControlPlaneCertificationScenarioSimulator::REGRESSION_MATCH, $result['results'][0]['regression_status']);
    }

    public function test_clean_happy_path_match(): void
    {
        $result = $this->newService()->simulateTaskServingScenarios(['scenarios' => [
            [
                'scenario_id' => 's5', 'kind' => 'clean_happy_path',
                'lease_expires_at_is_past' => false, 'target_in_existing_queue' => false, 'required_evidence_present' => true,
                'observed_decision' => 'accept',
            ],
        ]]);

        $this->assertSame('accept', $result['results'][0]['expected_decision']);
        $this->assertSame(AgentControlPlaneCertificationScenarioSimulator::REGRESSION_MATCH, $result['results'][0]['regression_status']);
    }

    public function test_incomplete_evidence_fails_closed(): void
    {
        $result = $this->newService()->simulateTaskServingScenarios(['scenarios' => [
            ['scenario_id' => 's6', 'kind' => 'stale_lease', 'observed_decision' => 'accept'],
        ]]);

        $this->assertNull($result['results'][0]['expected_decision']);
        $this->assertSame(AgentControlPlaneCertificationScenarioSimulator::REGRESSION_INCOMPLETE_EVIDENCE_FAIL_CLOSED, $result['results'][0]['regression_status']);
        $this->assertSame(1, $result['fail_closed_count']);
        $this->assertSame(0, $result['match_count']);
        $this->assertSame(0, $result['regression_count']);
    }

    public function test_all_five_scenario_kinds_covered_in_one_run(): void
    {
        $result = $this->newService()->simulateTaskServingScenarios(['scenarios' => [
            ['scenario_id' => 's1', 'kind' => 'stale_lease', 'lease_expires_at_is_past' => false, 'observed_decision' => 'accept'],
            ['scenario_id' => 's2', 'kind' => 'duplicate_target', 'target_in_existing_queue' => false, 'observed_decision' => 'accept'],
            ['scenario_id' => 's3', 'kind' => 'missing_proof', 'required_evidence_present' => true, 'observed_decision' => 'accept'],
            ['scenario_id' => 's4', 'kind' => 'worker_mismatch', 'required_capabilities' => [], 'worker_capabilities' => [], 'observed_decision' => 'accept'],
            ['scenario_id' => 's5', 'kind' => 'clean_happy_path', 'lease_expires_at_is_past' => false, 'target_in_existing_queue' => false, 'required_evidence_present' => true, 'observed_decision' => 'accept'],
        ]]);

        $this->assertCount(5, $result['results']);
        $this->assertSame(5, $result['match_count']);
    }
}
