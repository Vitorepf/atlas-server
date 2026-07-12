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

    public function test_final_witness_requires_complete_independent_bundle_and_is_read_only(): void
    {
        $service = app(AtlasAgenticWorkcellCertificationService::class);
        $blocked = $service->certifyFinalWitness([
            'write_authority' => 'worktree_write',
            'changed_files' => ['app/Example.php'],
            'evidence_bundle' => ['frozen_spec' => ['hash' => 'spec']],
        ]);
        self::assertSame('blocked', $blocked['status']);
        self::assertContains('final_certifier_write_authority_forbidden', $blocked['blockers']);
        self::assertContains('independent_evidence_bundle_incomplete', $blocked['blockers']);

        $passed = $service->certifyFinalWitness([
            'write_authority' => 'read_only_no_merge',
            'changed_files' => [],
            'evidence_bundle' => [
                'frozen_spec' => ['hash' => 'spec'],
                'candidate_artifact' => ['hash' => 'artifact'],
                'independent_evidence' => ['hash' => 'evidence', 'independent' => true],
                'acceptance' => ['hash' => 'acceptance'],
            ],
        ]);
        self::assertSame('passed', $passed['status']);
        self::assertTrue($passed['certified']);
        self::assertTrue($passed['final_certifier_policy']['read_only']);
        self::assertFalse($passed['final_certifier_policy']['can_edit_code']);
        self::assertFalse($passed['final_certifier_policy']['can_merge']);
    }
}
