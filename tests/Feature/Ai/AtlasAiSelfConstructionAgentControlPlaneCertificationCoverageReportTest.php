<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneCertificationCoverageReportService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneCertificationFuzzHarness;
use App\Services\Ai\SelfConstruction\AgentControlPlaneCertificationScenarioSimulator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneChainIntegrityAuditService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneDeterministicChainReplayService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneMacroSprintPromotionGate;
use App\Services\Ai\SelfConstruction\AgentControlPlaneReplayDiffService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneReplaySnapshotStore;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneCertificationCoverageReportTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $cached = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->cached = null;
    }

    public function test_report_returns_schema_v1(): void
    {
        $payload = $this->coverageResult();
        $this->assertSame(AgentControlPlaneCertificationCoverageReportService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(AgentControlPlaneCertificationCoverageReportService::MODE, $payload['mode']);
    }

    public function test_coverage_score_numeric(): void
    {
        $payload = $this->coverageResult();
        $this->assertIsFloat($payload['coverage_score']);
        $this->assertGreaterThanOrEqual(0.0, $payload['coverage_score']);
        $this->assertLessThanOrEqual(1.0, $payload['coverage_score']);
    }

    public function test_coverage_grade_present(): void
    {
        $payload = $this->coverageResult();
        $this->assertContains($payload['coverage_grade'], ['A', 'B', 'C', 'D', 'F']);
    }

    public function test_slice_coverage_present(): void
    {
        $payload = $this->coverageResult();
        $this->assertArrayHasKey('slice_coverage', $payload['coverage_blocks']);
    }

    public function test_edge_coverage_present(): void
    {
        $payload = $this->coverageResult();
        $this->assertArrayHasKey('edge_coverage', $payload['coverage_blocks']);
    }

    public function test_cli_coverage_present(): void
    {
        $payload = $this->coverageResult();
        $this->assertArrayHasKey('cli_coverage', $payload['coverage_blocks']);
    }

    public function test_readiness_coverage_present(): void
    {
        $payload = $this->coverageResult();
        $this->assertArrayHasKey('readiness_coverage', $payload['coverage_blocks']);
    }

    public function test_invoker_coverage_present(): void
    {
        $payload = $this->coverageResult();
        $this->assertArrayHasKey('invoker_coverage', $payload['coverage_blocks']);
    }

    public function test_doc_coverage_present(): void
    {
        $payload = $this->coverageResult();
        $this->assertArrayHasKey('doc_coverage', $payload['coverage_blocks']);
    }

    public function test_runtime_flag_coverage_present(): void
    {
        $payload = $this->coverageResult();
        $this->assertArrayHasKey('runtime_flag_coverage', $payload['coverage_blocks']);
    }

    public function test_scenario_coverage_present(): void
    {
        $payload = $this->coverageResult();
        $this->assertArrayHasKey('scenario_coverage', $payload['coverage_blocks']);
    }

    public function test_fuzz_coverage_present(): void
    {
        $payload = $this->coverageResult();
        $this->assertArrayHasKey('fuzz_coverage', $payload['coverage_blocks']);
    }

    public function test_command_status_coverage_present(): void
    {
        $payload = $this->coverageResult();
        $this->assertArrayHasKey('command_status_coverage', $payload['coverage_blocks']);
    }

    public function test_proof_bundle_coverage_present(): void
    {
        $payload = $this->coverageResult();
        $this->assertArrayHasKey('proof_bundle_coverage', $payload['coverage_blocks']);
    }

    public function test_missing_coverage_honest(): void
    {
        $payload = $this->coverageResult();
        $this->assertIsArray($payload['missing_coverage']);
        foreach ($payload['missing_coverage'] as $missing) {
            $this->assertArrayHasKey('block', $missing);
            $this->assertArrayHasKey('value', $missing);
            $this->assertArrayHasKey('gap', $missing);
            $this->assertLessThan(1.0, $missing['value']);
        }
    }

    public function test_coverage_hash_stable(): void
    {
        $svc = $this->newService();
        $a = $svc->report(['skip_simulator' => true, 'skip_fuzz' => true]);
        $b = $svc->report(['skip_simulator' => true, 'skip_fuzz' => true]);
        $this->assertSame($a['coverage_hash'], $b['coverage_hash']);
        $this->assertNotSame($a['report_id'], $b['report_id']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $a['coverage_hash']);
    }

    public function test_coverage_metrics_present(): void
    {
        $payload = $this->coverageResult();
        $this->assertIsArray($payload['metrics']);
        $this->assertArrayHasKey('slice_count', $payload['metrics']);
        $this->assertArrayHasKey('edge_count', $payload['metrics']);
        $this->assertArrayHasKey('proof_bundle_kinds_present', $payload['metrics']);
    }

    public function test_coverage_block_count(): void
    {
        $payload = $this->coverageResult();
        $this->assertSame(11, (int) $payload['block_count']);
    }

    public function test_coverage_is_read_only(): void
    {
        $payload = $this->coverageResult();
        $this->assertTrue((bool) $payload['read_only']);
        $this->assertFalse((bool) $payload['execution_allowed']);
        $this->assertFalse((bool) $payload['dispatch_allowed']);
        $this->assertFalse((bool) $payload['ledger_write_allowed']);
        $this->assertFalse((bool) $payload['runtime_write_allowed']);
        $this->assertFalse((bool) $payload['external_provider_call']);
        $this->assertFalse((bool) $payload['token_spend']);
        $this->assertFalse((bool) $payload['process_started']);
        $this->assertFalse((bool) $payload['self_programming_allowed']);
    }

    public function test_coverage_does_not_advance_pointer(): void
    {
        $beforePointer = $this->controlPlanePointer();
        $this->newService()->report(['skip_simulator' => true, 'skip_fuzz' => true]);
        $afterPointer = $this->controlPlanePointer();

        $this->assertSame($beforePointer, $afterPointer);
    }

    public function test_coverage_status_cli_works(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-certification-coverage-report-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(
            'atlas.self_construction_agent_control_plane_certification_coverage_report_status.v1',
            $payload['schema_version'],
        );
        $this->assertSame('available', data_get($payload, 'agent_control_plane_certification_coverage_report_status.status'));
        $this->assertContains(data_get($payload, 'agent_control_plane_certification_coverage_report_status.coverage_grade'), ['A', 'B', 'C', 'D', 'F']);
    }

    public function test_coverage_quartet_cli_works(): void
    {
        foreach (['contract', 'preflight', 'implementation-packet'] as $stage) {
            Artisan::call('atlas:ai:self-construction', [
                "--agent-control-plane-certification-coverage-report-{$stage}" => true,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $stageKey = str_replace('-', '_', $stage);
            $this->assertSame(
                "atlas.self_construction_agent_control_plane_certification_coverage_report_{$stageKey}.v1",
                $payload['schema_version'],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function coverageResult(): array
    {
        if ($this->cached === null) {
            $this->cached = $this->newService()->report(['skip_simulator' => true, 'skip_fuzz' => true]);
        }

        return $this->cached;
    }

    private function newService(): AgentControlPlaneCertificationCoverageReportService
    {
        $readiness = app(AtlasSelfConstructionReadinessService::class);
        $audit = new AgentControlPlaneChainIntegrityAuditService($readiness);
        $replay = new AgentControlPlaneDeterministicChainReplayService($audit, $readiness);
        $store = new AgentControlPlaneReplaySnapshotStore('local');
        $diff = new AgentControlPlaneReplayDiffService($store, $replay);
        $gate = new AgentControlPlaneMacroSprintPromotionGate($diff, $audit, $replay);
        $simulator = new AgentControlPlaneCertificationScenarioSimulator($readiness, $audit, $replay, $diff, $store, $gate);
        $fuzz = new AgentControlPlaneCertificationFuzzHarness($audit, $replay, $diff);

        return new AgentControlPlaneCertificationCoverageReportService($audit, $replay, $simulator, $fuzz);
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
}
