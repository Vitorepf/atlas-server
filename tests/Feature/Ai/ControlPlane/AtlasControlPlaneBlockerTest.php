<?php

namespace Tests\Feature\Ai\ControlPlane;

use App\Services\Ai\ControlPlane\AtlasControlPlaneBlockerService;
use App\Services\Ai\Evidence\BlockerService;
use App\Services\Ai\Evidence\EvidencePackService;
use Tests\Concerns\CreatesEvidenceRuntimeTables;
use Tests\Concerns\CreatesMissionFoundationTables;
use Tests\TestCase;

class AtlasControlPlaneBlockerTest extends TestCase
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

    public function test_blockers_snapshot_returns_zero_total_when_empty(): void
    {
        /** @var AtlasControlPlaneBlockerService $svc */
        $svc = app(AtlasControlPlaneBlockerService::class);
        $snap = $svc->snapshot();

        $this->assertSame('atlas.ai.control_plane.blocker.v1', $snap['schema']);
        $this->assertSame(0, $snap['total']);
        $this->assertSame(0, $snap['critical']);
        $this->assertIsArray($snap['recent']);
    }

    public function test_blockers_snapshot_aggregates_evidence_blocker(): void
    {
        /** @var BlockerService $blockers */
        $blockers = app(BlockerService::class);
        $blockers->open([
            'target_type' => EvidencePackService::TARGET_MISSION,
            'target_id' => 'mission-cp',
            'blocker_type' => BlockerService::KIND_MISSING_EVIDENCE,
            'severity' => BlockerService::SEVERITY_CRITICAL,
            'reason' => 'no evidence yet',
        ]);

        /** @var AtlasControlPlaneBlockerService $svc */
        $svc = app(AtlasControlPlaneBlockerService::class);
        $snap = $svc->snapshot();

        $this->assertGreaterThanOrEqual(1, $snap['total']);
        $this->assertGreaterThanOrEqual(1, $snap['critical']);
        $this->assertContains('evidence', array_keys($snap['by_source']));
        $this->assertNotEmpty($snap['recent']);
        $this->assertSame('evidence', $snap['recent'][0]['source']);
    }

    public function test_blockers_command_exits_zero(): void
    {
        $exit = $this->artisan('atlas:ai:control-plane', [
            '--action' => 'blockers',
            '--json' => true,
        ])->run();

        $this->assertSame(0, $exit);
    }
}
