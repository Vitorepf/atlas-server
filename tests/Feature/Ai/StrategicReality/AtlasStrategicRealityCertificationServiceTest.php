<?php

namespace Tests\Feature\Ai\StrategicReality;

use App\Services\Ai\StrategicReality\AtlasStrategicRealityCertificationService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesStrategicRealityTables;
use Tests\TestCase;

class AtlasStrategicRealityCertificationServiceTest extends TestCase
{
    use CreatesStrategicRealityTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createStrategicRealityTables();
    }

    protected function tearDown(): void
    {
        $this->dropStrategicRealityTables();
        parent::tearDown();
    }

    public function test_certification_passes_with_canonical_runtime_artifacts(): void
    {
        $payload = app(AtlasStrategicRealityCertificationService::class)->certify();

        $this->assertSame(AtlasStrategicRealityCertificationService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(AtlasStrategicRealityCertificationService::STATUS_PASSED, $payload['status']);
        $this->assertSame(11, data_get($payload, 'summary.total'));
        $this->assertSame(11, data_get($payload, 'summary.pass'));
        $this->assertSame([], $payload['blockers']);
        $this->assertNotEmpty($payload['certification_hash']);
        $this->assertSame(false, data_get($payload, 'claim_policy.provider_invoked'));
        $this->assertSame(false, data_get($payload, 'claim_policy.external_execution_performed'));
    }

    public function test_certification_checks_include_safety_and_macro_naming(): void
    {
        $payload = app(AtlasStrategicRealityCertificationService::class)->certify();
        $checkIds = collect($payload['checks'])->pluck('id')->all();

        $this->assertContains('macro_naming_contract', $checkIds);
        $this->assertContains('freshness_gate_smoke', $checkIds);
        $this->assertContains('governed_external_action_smoke', $checkIds);
        $this->assertContains('control_plane_sanitization_smoke', $checkIds);
        $this->assertContains('integration_wiring', $checkIds);
        $this->assertContains('claim_policy', $checkIds);
    }

    public function test_certification_smoke_does_not_pollute_strategic_reality_tables(): void
    {
        $payload = app(AtlasStrategicRealityCertificationService::class)->certify();

        $this->assertSame(AtlasStrategicRealityCertificationService::STATUS_PASSED, $payload['status']);
        $this->assertSame(0, DB::table('atlas_strategic_decisions')->count());
        $this->assertSame(0, DB::table('atlas_executive_briefings')->count());
        $this->assertSame(0, DB::table('atlas_risk_signals')->count());
        $this->assertSame(0, DB::table('atlas_opportunity_signals')->count());
    }
}
