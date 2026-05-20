<?php

namespace Tests\Feature\Ai\AgenticWorkcell;

use App\Services\Ai\AgenticWorkcell\AtlasAgenticWorkcellCertificationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAgenticWorkcellTables;
use Tests\Concerns\CreatesRuntimeEfficiencyTables;
use Tests\TestCase;

class AtlasAgenticWorkcellCertificationServiceTest extends TestCase
{
    use CreatesAgenticWorkcellTables;
    use CreatesRuntimeEfficiencyTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRuntimeEfficiencyTables();
        $this->createAgenticWorkcellTables();
    }

    protected function tearDown(): void
    {
        $this->dropAgenticWorkcellTables();
        $this->dropRuntimeEfficiencyTables();
        parent::tearDown();
    }

    public function test_certification_passes_with_all_canonical_artifacts(): void
    {
        $payload = app(AtlasAgenticWorkcellCertificationService::class)->certify();

        $this->assertSame(AtlasAgenticWorkcellCertificationService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('passed', $payload['status']);
        $this->assertSame(14, data_get($payload, 'summary.total'));
        $this->assertSame(14, data_get($payload, 'summary.pass'));
        $this->assertSame([], $payload['blockers']);
        $this->assertFalse(data_get($payload, 'claim_policy.provider_invoked'));
        $this->assertFalse(data_get($payload, 'claim_policy.agents_spawned'));
    }

    public function test_certification_smokes_do_not_pollute_tables(): void
    {
        $payload = app(AtlasAgenticWorkcellCertificationService::class)->certify();

        $this->assertSame('passed', $payload['status']);
        $this->assertSame(0, DB::table('atlas_agentic_workcells')->count());
        $this->assertSame(0, DB::table('atlas_agentic_workcell_outcomes')->count());
        $this->assertSame(0, DB::table('atlas_agentic_workcell_org_patterns')->count());
    }

    public function test_commands_design_control_plane_and_certify_json(): void
    {
        $designExit = Artisan::call('atlas:agentic-workcell', [
            'action' => 'design',
            '--objective' => 'Faça pesquisa profunda com fontes contrárias.',
            '--domain' => 'research',
            '--evidence' => ['source:seed'],
            '--json' => true,
        ]);
        $designOutput = Artisan::output();

        $this->assertSame(0, $designExit);
        $this->assertStringContainsString('"schema_version": "atlas.agentic_workcell.v1"', $designOutput);

        $controlExit = Artisan::call('atlas:agentic-workcell', [
            'action' => 'control-plane',
            '--json' => true,
        ]);
        $controlOutput = Artisan::output();

        $this->assertSame(0, $controlExit);
        $this->assertStringContainsString('"schema_version": "atlas.agentic_workcell.control_plane.v1"', $controlOutput);

        $certExit = Artisan::call('atlas:agentic-workcell:certify', [
            '--json' => true,
            '--strict' => true,
        ]);
        $certOutput = Artisan::output();

        $this->assertSame(0, $certExit);
        $this->assertStringContainsString('"schema_version": "atlas.agentic_workcell.certification.v1"', $certOutput);
        $this->assertStringContainsString('"status": "passed"', $certOutput);
    }
}
