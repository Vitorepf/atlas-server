<?php

namespace Tests\Feature\Ai\ControlPlane;

use App\Services\Ai\ControlPlane\AtlasControlPlaneSnapshotService;
use App\Services\Ai\ControlPlane\AtlasControlPlaneStatus;
use Tests\Concerns\CreatesEvidenceRuntimeTables;
use Tests\Concerns\CreatesMissionFoundationTables;
use Tests\TestCase;

class AtlasControlPlaneSnapshotTest extends TestCase
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

    public function test_snapshot_returns_required_top_level_keys(): void
    {
        /** @var AtlasControlPlaneSnapshotService $svc */
        $svc = app(AtlasControlPlaneSnapshotService::class);
        $snap = $svc->snapshot();

        foreach ([
            'schema',
            'generated_at',
            'status',
            'readiness',
            'missions_summary',
            'domains_summary',
            'policies_summary',
            'evidence_summary',
            'tools_summary',
            'router_summary',
            'approvals_summary',
            'blockers_summary',
            'certifications_summary',
            'recent_events',
            'next_actions',
        ] as $key) {
            $this->assertArrayHasKey($key, $snap, "snapshot must expose [{$key}]");
        }
        $this->assertSame('atlas.ai.control_plane.snapshot.v1', $snap['schema']);
        $this->assertContains($snap['status'], AtlasControlPlaneStatus::ALLOWED);
    }

    public function test_snapshot_per_runtime_summary_carries_component_status(): void
    {
        /** @var AtlasControlPlaneSnapshotService $svc */
        $svc = app(AtlasControlPlaneSnapshotService::class);
        $snap = $svc->snapshot();

        foreach (['missions_summary', 'domains_summary', 'policies_summary', 'evidence_summary', 'tools_summary', 'router_summary'] as $key) {
            $this->assertArrayHasKey('status', $snap[$key]);
            $this->assertContains($snap[$key]['status'], AtlasControlPlaneStatus::ALLOWED);
        }
    }

    public function test_snapshot_command_exits_zero(): void
    {
        $exit = $this->artisan('atlas:ai:control-plane', [
            '--action' => 'snapshot',
            '--json' => true,
        ])->run();

        $this->assertSame(0, $exit);
    }
}
