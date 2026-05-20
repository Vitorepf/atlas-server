<?php

namespace Tests\Feature\Ai\RuntimeEfficiency;

use App\Services\Ai\RuntimeEfficiency\AtlasRuntimeEfficiencyGovernorCertificationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesRuntimeEfficiencyTables;
use Tests\TestCase;

class AtlasRuntimeEfficiencyGovernorCertificationServiceTest extends TestCase
{
    use CreatesRuntimeEfficiencyTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRuntimeEfficiencyTables();
    }

    protected function tearDown(): void
    {
        $this->dropRuntimeEfficiencyTables();
        parent::tearDown();
    }

    public function test_certification_passes_with_canonical_runtime_artifacts(): void
    {
        $payload = app(AtlasRuntimeEfficiencyGovernorCertificationService::class)->certify();

        $this->assertSame(AtlasRuntimeEfficiencyGovernorCertificationService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(AtlasRuntimeEfficiencyGovernorCertificationService::STATUS_PASSED, $payload['status']);
        $this->assertSame(15, data_get($payload, 'summary.total'));
        $this->assertSame(15, data_get($payload, 'summary.pass'));
        $this->assertSame([], $payload['blockers']);
        $this->assertNotEmpty($payload['certification_hash']);
        $this->assertSame(false, data_get($payload, 'claim_policy.provider_invoked'));
        $this->assertSame(false, data_get($payload, 'claim_policy.external_execution_performed'));
    }

    public function test_certification_smokes_do_not_pollute_runtime_tables(): void
    {
        $payload = app(AtlasRuntimeEfficiencyGovernorCertificationService::class)->certify();

        $this->assertSame(AtlasRuntimeEfficiencyGovernorCertificationService::STATUS_PASSED, $payload['status']);
        $this->assertSame(0, DB::table('atlas_runtime_efficiency_decisions')->count());
        $this->assertSame(0, DB::table('atlas_runtime_efficiency_outcomes')->count());
        $this->assertSame(0, DB::table('atlas_runtime_efficiency_policies')->count());
        $this->assertSame(0, DB::table('atlas_runtime_efficiency_replays')->count());
    }

    public function test_command_outputs_json_and_strict_passes(): void
    {
        $exitCode = Artisan::call('atlas:runtime-efficiency:certify', [
            '--json' => true,
            '--strict' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"schema_version": "atlas.runtime_efficiency_governor.certification.v1"', $output);
        $this->assertStringContainsString('"status": "passed"', $output);
    }

    public function test_runtime_command_can_govern_and_emit_control_plane(): void
    {
        $governExit = Artisan::call('atlas:runtime-efficiency', [
            'action' => 'govern',
            '--prompt' => 'Corrija um bug pequeno com teste.',
            '--domain' => 'programming',
            '--json' => true,
        ]);
        $this->assertSame(0, $governExit);

        $controlExit = Artisan::call('atlas:runtime-efficiency', [
            'action' => 'control-plane',
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $controlExit);
        $this->assertStringContainsString('"schema_version": "atlas.runtime_efficiency.control_plane.v1"', $output);
        $this->assertStringContainsString('"decisions_total": 1', $output);
    }

    public function test_runtime_command_can_compile_policy_and_replay(): void
    {
        $policyExit = Artisan::call('atlas:runtime-efficiency', [
            'action' => 'compile-policy',
            '--flow-id' => 'atlas_dev',
            '--domain' => 'programming',
            '--min-samples' => 1,
            '--json' => true,
        ]);
        $policyOutput = Artisan::output();

        $this->assertSame(0, $policyExit);
        $this->assertStringContainsString('"schema_version": "atlas.runtime_efficiency_policy.v1"', $policyOutput);

        $replayExit = Artisan::call('atlas:runtime-efficiency', [
            'action' => 'replay',
            '--prompt' => 'Corrija bug complexo com teste.',
            '--domain' => 'programming',
            '--flow-id' => 'atlas_dev',
            '--json' => true,
        ]);
        $replayOutput = Artisan::output();

        $this->assertSame(0, $replayExit);
        $this->assertStringContainsString('"schema_version": "atlas.runtime_efficiency_counterfactual_replay.v1"', $replayOutput);
    }
}
