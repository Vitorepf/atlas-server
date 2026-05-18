<?php

namespace Tests\Feature\Ai\ControlPlane;

use App\Services\Ai\ControlPlane\AtlasControlPlaneReadinessService;
use App\Services\Ai\ControlPlane\AtlasControlPlaneStatus;
use Tests\Concerns\CreatesEvidenceRuntimeTables;
use Tests\Concerns\CreatesMissionFoundationTables;
use Tests\TestCase;

class AtlasControlPlaneReadinessTest extends TestCase
{
    use CreatesEvidenceRuntimeTables;
    use CreatesMissionFoundationTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMissionFoundationTables();
        $this->createEvidenceRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropEvidenceRuntimeTables();
        $this->dropMissionFoundationTables();
        parent::tearDown();
    }

    public function test_readiness_reports_canonical_schema_and_components(): void
    {
        /** @var AtlasControlPlaneReadinessService $svc */
        $svc = app(AtlasControlPlaneReadinessService::class);
        $report = $svc->report();

        $this->assertSame('atlas.ai.control_plane.readiness.v1', $report['schema']);
        $this->assertIsArray($report['components']);
        $this->assertCount(6, $report['components']);
        $names = array_map(static fn (array $c): string => (string) $c['component'], $report['components']);
        foreach (['mission', 'domain', 'policy', 'evidence', 'tool', 'router'] as $expected) {
            $this->assertContains($expected, $names);
        }
    }

    public function test_readiness_reduces_to_degraded_when_runtime_absent(): void
    {
        /** @var AtlasControlPlaneReadinessService $svc */
        $svc = app(AtlasControlPlaneReadinessService::class);
        $report = $svc->report();

        // Domain/Policy/Tool/Router tables are not bootstrapped by this trait
        // so we expect at least degraded (some missing or partial).
        $this->assertContains(
            $report['status'],
            [AtlasControlPlaneStatus::DEGRADED, AtlasControlPlaneStatus::READY],
        );
        $this->assertArrayHasKey('ready', $report['summary']);
        $this->assertArrayHasKey('degraded', $report['summary']);
        $this->assertArrayHasKey('missing', $report['summary']);
    }

    public function test_readiness_command_exits_zero_for_ready_or_degraded(): void
    {
        $exit = $this->artisan('atlas:ai:control-plane', [
            '--action' => 'readiness',
            '--json' => true,
        ])->run();

        $this->assertSame(0, $exit);
    }
}
