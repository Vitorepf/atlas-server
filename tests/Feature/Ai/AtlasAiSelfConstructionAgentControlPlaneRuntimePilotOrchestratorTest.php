<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneChainIntegrityAuditService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseSimulator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneCostImportDryRun;
use App\Services\Ai\SelfConstruction\AgentControlPlaneDeterministicChainReplayService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\AgentControlPlaneMultiAgentParallelismPlanner;
use App\Services\Ai\SelfConstruction\AgentControlPlaneRuntimePilotOrchestrator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneScopeLockPlanner;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneWorkProductManifestPlanner;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneRuntimePilotOrchestratorTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $cached = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->cached = null;
    }

    public function test_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_control_plane_runtime_pilot_orchestrator.v1', AgentControlPlaneRuntimePilotOrchestrator::SCHEMA_VERSION);
        $this->assertSame('read_only_agent_control_plane_runtime_pilot_orchestrator', AgentControlPlaneRuntimePilotOrchestrator::MODE);
    }

    public function test_full_pilot_available(): void
    {
        $pilot = $this->pilot();
        $this->assertSame('available', $pilot['status']);
        $this->assertSame(0, $pilot['blocker_count']);
        $this->assertNotEmpty($pilot['pilot_hash']);
        $this->assertNotEmpty($pilot['pilot_id']);
    }

    public function test_blockers_on_invalid_task(): void
    {
        $readiness = app(AtlasSelfConstructionReadinessService::class);
        $svc = $this->buildOrchestrator($readiness);
        $pilot = $svc->run(['task_packet' => ['objective' => '', 'allowed_files' => []]]);
        $this->assertSame('blocked', $pilot['status']);
        $this->assertGreaterThanOrEqual(1, $pilot['blocker_count']);
    }

    public function test_all_subcomponents_present(): void
    {
        $pilot = $this->pilot();
        foreach ([
            'task_packet',
            'claim_lease_simulation',
            'scope_lock_plan',
            'evidence_ledger_dry_run',
            'continuation_summary',
            'work_product_manifest_plan',
            'cost_import_dry_run',
            'multi_agent_parallelism_plan',
        ] as $component) {
            $this->assertArrayHasKey($component, $pilot, "Missing $component");
            $this->assertNotEmpty($pilot[$component]);
        }
    }

    public function test_chain_integrity_summary_included(): void
    {
        $pilot = $this->pilot();
        $this->assertArrayHasKey('chain_integrity_summary', $pilot);
        $this->assertTrue((bool) data_get($pilot, 'chain_integrity_summary.runtime_safety_all_false'));
        $this->assertGreaterThan(0, (int) data_get($pilot, 'chain_integrity_summary.chain_length'));
    }

    public function test_replay_summary_included(): void
    {
        $pilot = $this->pilot();
        $this->assertTrue((bool) data_get($pilot, 'replay_summary.invariants_all_true'));
        $this->assertNotEmpty(data_get($pilot, 'replay_summary.replay_hash'));
        $this->assertNotEmpty(data_get($pilot, 'replay_summary.deterministic_replay_hash'));
    }

    public function test_all_runtime_flags_false(): void
    {
        $pilot = $this->pilot();
        $this->assertTrue($pilot['runtime_disabled']);
        $this->assertFalse($pilot['runtime_execution_allowed']);
        $this->assertFalse($pilot['dispatch_allowed']);
        $this->assertFalse($pilot['provider_call_allowed']);
        $this->assertFalse($pilot['token_spend_allowed']);
        $this->assertFalse($pilot['self_programming_allowed']);
        $this->assertFalse($pilot['ledger_write_allowed']);
        $this->assertFalse($pilot['completion_claim_allowed']);
    }

    public function test_pilot_hash_stable(): void
    {
        $readiness = app(AtlasSelfConstructionReadinessService::class);
        $svc = $this->buildOrchestrator($readiness);
        $input = ['task_packet' => $this->defaultInput()];
        $a = $svc->run($input);
        $b = $svc->run($input);
        $this->assertSame($a['pilot_hash'], $b['pilot_hash']);
        $this->assertNotSame($a['pilot_id'], $b['pilot_id']);
    }

    public function test_observatory_summary_present(): void
    {
        $pilot = $this->pilot();
        $this->assertSame('certification', data_get($pilot, 'certification_observatory_summary.workbench_layer'));
        $this->assertContains('chain_integrity_certification', data_get($pilot, 'certification_observatory_summary.observatory_layer_includes'));
        $this->assertFalse((bool) data_get($pilot, 'certification_observatory_summary.runtime_pilot_simulator_runtime_enabled'));
    }

    public function test_cli_status_returns_payload(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-runtime-pilot-orchestrator-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.self_construction_agent_control_plane_runtime_pilot_orchestrator_status.v1', $payload['schema_version']);
        $this->assertSame('available', data_get($payload, 'agent_control_plane_runtime_pilot_orchestrator_status.status'));
        $this->assertNotEmpty(data_get($payload, 'agent_control_plane_runtime_pilot_orchestrator_status.pilot_hash'));
    }

    public function test_cli_quartet_works(): void
    {
        foreach (['contract', 'preflight', 'implementation-packet'] as $stage) {
            Artisan::call('atlas:ai:self-construction', [
                "--agent-control-plane-runtime-pilot-orchestrator-{$stage}" => true,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $stageKey = str_replace('-', '_', $stage);
            $this->assertSame(
                "atlas.self_construction_agent_control_plane_runtime_pilot_orchestrator_{$stageKey}.v1",
                $payload['schema_version'],
            );
        }
    }

    public function test_does_not_advance_pointer(): void
    {
        $before = $this->pointer();
        $this->pilot();
        $after = $this->pointer();
        $this->assertSame($before, $after);
    }

    /**
     * @return array<string, mixed>
     */
    private function pilot(): array
    {
        if ($this->cached === null) {
            $readiness = app(AtlasSelfConstructionReadinessService::class);
            $svc = $this->buildOrchestrator($readiness);
            $this->cached = $svc->run(['task_packet' => $this->defaultInput(), 'additional_task_packets' => [$this->secondaryInput()]]);
        }

        return $this->cached;
    }

    private function buildOrchestrator(AtlasSelfConstructionReadinessService $readiness): AgentControlPlaneRuntimePilotOrchestrator
    {
        $audit = new AgentControlPlaneChainIntegrityAuditService($readiness);
        $replay = new AgentControlPlaneDeterministicChainReplayService($audit, $readiness);

        return new AgentControlPlaneRuntimePilotOrchestrator(
            new AgentControlPlaneTaskPacketBuilder,
            new AgentControlPlaneClaimLeaseSimulator,
            new AgentControlPlaneScopeLockPlanner,
            new AgentControlPlaneEvidenceLedgerDryRun,
            new AgentControlPlaneContinuationSummaryBuilder,
            new AgentControlPlaneWorkProductManifestPlanner,
            new AgentControlPlaneCostImportDryRun,
            new AgentControlPlaneMultiAgentParallelismPlanner,
            $audit,
            $replay,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultInput(): array
    {
        return [
            'objective' => 'orchestrator test pilot',
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentControlPlaneTaskPacketBuilder.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/AgentControlPlaneTaskPacketBuilder.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['x'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function secondaryInput(): array
    {
        return [
            'objective' => 'orchestrator test secondary pilot',
            'operator_id' => 'tester2',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/AgentControlPlaneEvidenceLedgerDryRun.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/AgentControlPlaneEvidenceLedgerDryRun.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['x'],
        ];
    }

    public function test_full_guarantee_set(): void
    {
        $pilot = $this->pilot();
        foreach ([
            'runtime_pilot_orchestrator_does_not_start_codex',
            'runtime_pilot_orchestrator_does_not_call_codex_cli_or_app',
            'runtime_pilot_orchestrator_does_not_spawn_subprocess',
            'runtime_pilot_orchestrator_does_not_invoke_adapter',
            'runtime_pilot_orchestrator_does_not_call_provider',
            'runtime_pilot_orchestrator_does_not_dispatch_work',
            'runtime_pilot_orchestrator_does_not_spend_tokens',
            'runtime_pilot_orchestrator_does_not_enable_self_programming',
            'runtime_pilot_orchestrator_does_not_write_ledger',
            'runtime_pilot_orchestrator_does_not_mutate_pointer',
            'runtime_pilot_orchestrator_does_not_promote_completion_claim',
        ] as $expected) {
            $this->assertContains($expected, $pilot['non_execution_guarantees']);
        }
        $this->assertNotEmpty($pilot['task_packet']['task_packet_hash']);
        $this->assertNotEmpty($pilot['scope_lock_plan']['scope_lock_plan_hash']);
        $this->assertNotEmpty($pilot['evidence_ledger_dry_run']['evidence_hash']);
        $this->assertNotEmpty($pilot['continuation_summary']['continuation_hash']);
        $this->assertNotEmpty($pilot['work_product_manifest_plan']['work_product_manifest_hash']);
        $this->assertNotEmpty($pilot['cost_import_dry_run']['cost_import_plan_hash']);
        $this->assertNotEmpty($pilot['multi_agent_parallelism_plan']['parallelism_hash']);
    }

    public function test_payload_fully_shaped(): void
    {
        $pilot = $this->pilot();
        foreach ([
            'schema_version', 'mode', 'pilot_id', 'generated_at', 'status',
            'task_packet', 'claim_lease_simulation', 'scope_lock_plan', 'evidence_ledger_dry_run',
            'continuation_summary', 'work_product_manifest_plan', 'cost_import_dry_run',
            'multi_agent_parallelism_plan', 'chain_integrity_summary', 'replay_summary',
            'certification_observatory_summary', 'blockers', 'blocker_count', 'warnings',
            'read_only', 'runtime_disabled', 'runtime_execution_allowed', 'dispatch_allowed',
            'provider_call_allowed', 'token_spend_allowed', 'self_programming_allowed',
            'ledger_write_allowed', 'completion_claim_allowed', 'non_execution_guarantees',
            'human_summary', 'pilot_hash',
        ] as $key) {
            $this->assertArrayHasKey($key, $pilot, "Missing $key");
        }
        $this->assertContains('runtime_pilot_orchestrator_does_not_start_codex', $pilot['non_execution_guarantees']);
        $this->assertContains('runtime_pilot_orchestrator_does_not_call_codex_cli_or_app', $pilot['non_execution_guarantees']);
        $this->assertContains('runtime_pilot_orchestrator_does_not_dispatch_work', $pilot['non_execution_guarantees']);
        $this->assertContains('runtime_pilot_orchestrator_does_not_spend_tokens', $pilot['non_execution_guarantees']);
        $this->assertContains('runtime_pilot_orchestrator_does_not_enable_self_programming', $pilot['non_execution_guarantees']);
        $this->assertContains('runtime_pilot_orchestrator_does_not_write_ledger', $pilot['non_execution_guarantees']);
        $this->assertContains('runtime_pilot_orchestrator_does_not_promote_completion_claim', $pilot['non_execution_guarantees']);
        $this->assertContains('runtime_pilot_orchestrator_does_not_mutate_pointer', $pilot['non_execution_guarantees']);
        foreach (['chain_integrity_certification', 'deterministic_chain_replay', 'replay_snapshot_store', 'replay_diff', 'macro_sprint_promotion_gate', 'certification_baseline', 'certification_scenario_simulator', 'release_dossier', 'certification_mutation_guard', 'certification_evidence_query', 'certification_scenario_corpus', 'certification_fuzz_harness', 'multi_snapshot_comparison', 'release_dossier_exporter', 'certification_coverage_report', 'certification_status_batch'] as $layer) {
            $this->assertContains($layer, $pilot['certification_observatory_summary']['observatory_layer_includes']);
        }
    }

    private function pointer(): string
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        return (string) data_get($payload, 'control_plane.persistent_runtime.next_required_slice');
    }

    // ── evaluatePromotion ────────────────────────────────────────────────────────

    private function promotionOrchestrator(): AgentControlPlaneRuntimePilotOrchestrator
    {
        $readiness = app(AtlasSelfConstructionReadinessService::class);

        return $this->buildOrchestrator($readiness);
    }

    private function healthyEvidence(array $overrides = []): array
    {
        return array_merge([
            'current_state' => 'dry_run',
            'queue_health_score' => 0.9,
            'queue_health_baseline' => 0.9,
            'worker_fit_known' => true,
            'give_back_risk' => 0.1,
            'give_back_risk_baseline' => 0.1,
        ], $overrides);
    }

    public function test_healthy_evidence_promotes_dry_run_to_shadow(): void
    {
        $result = $this->promotionOrchestrator()->evaluatePromotion($this->healthyEvidence());

        $this->assertSame('promote', $result['promotion_decision']);
        $this->assertSame('shadow', $result['state']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame([], $result['missing_evidence']);
    }

    public function test_promotion_advances_through_all_states_in_order(): void
    {
        $orchestrator = $this->promotionOrchestrator();

        $shadow = $orchestrator->evaluatePromotion($this->healthyEvidence(['current_state' => 'shadow']));
        $this->assertSame('canary', $shadow['state']);

        $canary = $orchestrator->evaluatePromotion($this->healthyEvidence(['current_state' => 'canary']));
        $this->assertSame('live', $canary['state']);

        $live = $orchestrator->evaluatePromotion($this->healthyEvidence(['current_state' => 'live']));
        $this->assertSame('live', $live['state']);
    }

    public function test_worsened_queue_health_blocks_promotion(): void
    {
        $result = $this->promotionOrchestrator()->evaluatePromotion($this->healthyEvidence([
            'queue_health_score' => 0.5,
            'queue_health_baseline' => 0.9,
        ]));

        $this->assertSame('hold', $result['promotion_decision']);
        $this->assertSame('dry_run', $result['state']);
        $this->assertContains('queue_health_worsened', $result['blockers']);
    }

    public function test_unknown_worker_fit_blocks_promotion(): void
    {
        $result = $this->promotionOrchestrator()->evaluatePromotion($this->healthyEvidence(['worker_fit_known' => false]));

        $this->assertSame('hold', $result['promotion_decision']);
        $this->assertContains('worker_fit_unknown', $result['blockers']);
    }

    public function test_increased_give_back_risk_blocks_promotion(): void
    {
        $result = $this->promotionOrchestrator()->evaluatePromotion($this->healthyEvidence([
            'give_back_risk' => 0.5,
            'give_back_risk_baseline' => 0.1,
        ]));

        $this->assertSame('hold', $result['promotion_decision']);
        $this->assertContains('give_back_risk_increased', $result['blockers']);
    }

    public function test_missing_evidence_blocks_promotion_and_is_reported(): void
    {
        $result = $this->promotionOrchestrator()->evaluatePromotion(['current_state' => 'dry_run']);

        $this->assertSame('hold', $result['promotion_decision']);
        $this->assertSame('dry_run', $result['state']);
        $this->assertContains('queue_health_score', $result['missing_evidence']);
        $this->assertContains('queue_health_baseline', $result['missing_evidence']);
        $this->assertContains('give_back_risk', $result['missing_evidence']);
        $this->assertContains('give_back_risk_baseline', $result['missing_evidence']);
    }

    public function test_invalid_current_state_defaults_to_dry_run(): void
    {
        $result = $this->promotionOrchestrator()->evaluatePromotion($this->healthyEvidence(['current_state' => 'nonsense']));

        $this->assertSame('shadow', $result['state']);
    }
}
