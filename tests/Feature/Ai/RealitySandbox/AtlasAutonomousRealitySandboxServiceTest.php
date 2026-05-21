<?php

namespace Tests\Feature\Ai\RealitySandbox;

use App\Services\Ai\RealitySandbox\AtlasAutonomousRealitySandboxService;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesAarsTables;
use Tests\TestCase;

class AtlasAutonomousRealitySandboxServiceTest extends TestCase
{
    use CreatesAarsTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAarsTables();
    }

    protected function tearDown(): void
    {
        $this->dropAarsTables();

        parent::tearDown();
    }

    public function test_run_creates_full_reality_sandbox(): void
    {
        $result = app(AtlasAutonomousRealitySandboxService::class)->run([
            'objective' => 'simular uma melhoria segura no Atlas Dev antes de implementar',
            'domain' => 'programming',
            'flow_id' => 'atlas_forge',
            'evidence_refs' => ['test:aars_full_run'],
        ]);

        $this->assertSame('atlas.aars.run.v1', $result['schema_version']);
        $this->assertSame('ready', $result['status']);
        $this->assertNotEmpty($result['run_hash']);
        $this->assertNotEmpty(data_get($result, 'scenario.scenario_id'));
        $this->assertNotEmpty(data_get($result, 'simulation.simulation_id'));
        $this->assertNotEmpty(data_get($result, 'counterfactual.counterfactual_id'));
        $this->assertNotEmpty(data_get($result, 'risk_projection.risk_projection_id'));
        $this->assertNotEmpty(data_get($result, 'certification.certification_id'));
        $this->assertFalse(data_get($result, 'claim_policy.external_execution_performed'));
        $this->assertDatabaseCount('atlas_aars_scenarios', 1);
        $this->assertDatabaseCount('atlas_aars_simulations', 1);
        $this->assertDatabaseCount('atlas_aars_counterfactuals', 1);
        $this->assertDatabaseCount('atlas_aars_risk_projections', 1);
        $this->assertDatabaseCount('atlas_aars_certifications', 1);
    }

    public function test_missing_evidence_keeps_simulation_on_watch_without_claiming_truth(): void
    {
        $result = app(AtlasAutonomousRealitySandboxService::class)->run([
            'objective' => 'simular decisao de produto sem evidencias',
        ]);

        $this->assertSame('watch', data_get($result, 'scenario.status'));
        $this->assertSame('watch', data_get($result, 'simulation.status'));
        $this->assertSame('insufficient', data_get($result, 'simulation.uncertainty.context_sufficiency'));
        $this->assertTrue(data_get($result, 'claim_policy.simulation_is_not_truth'));
    }

    public function test_critical_risk_blocks_release_recommendation(): void
    {
        $result = app(AtlasAutonomousRealitySandboxService::class)->run([
            'objective' => 'simular delete de pagamento em producao',
            'force_critical' => true,
            'evidence_refs' => ['test:aars_critical'],
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('critical', data_get($result, 'risk_projection.risk_level'));
        $this->assertSame('blocked', data_get($result, 'release_recommendation.status'));
        $this->assertFalse(data_get($result, 'release_recommendation.real_execution_allowed'));
    }

    public function test_control_plane_does_not_expose_raw_objective(): void
    {
        app(AtlasAutonomousRealitySandboxService::class)->run([
            'objective' => 'objetivo sensivel que nao deve aparecer no control plane',
            'evidence_refs' => ['test:aars_control_plane'],
        ]);

        $payload = app(AtlasAutonomousRealitySandboxService::class)->controlPlane();
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertSame('atlas.aars.control_plane.v1', $payload['schema_version']);
        $this->assertSame(1, data_get($payload, 'summary.scenarios_total'));
        $this->assertStringNotContainsString('objetivo sensivel', $encoded);
        $this->assertStringContainsString('objective_hash', $encoded);
    }

    public function test_cli_run_and_control_plane_emit_json(): void
    {
        $runExit = Artisan::call('atlas:aars', [
            'action' => 'run',
            '--objective' => 'simular fluxo cli AARS',
            '--evidence' => ['test:aars_cli'],
            '--json' => true,
        ]);
        $runPayload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $runExit);
        $this->assertSame('atlas.aars.run.v1', $runPayload['schema_version']);

        $controlExit = Artisan::call('atlas:aars', [
            'action' => 'control-plane',
            '--json' => true,
        ]);
        $controlPayload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $controlExit);
        $this->assertSame('atlas.aars.control_plane.v1', $controlPayload['schema_version']);
    }
}
