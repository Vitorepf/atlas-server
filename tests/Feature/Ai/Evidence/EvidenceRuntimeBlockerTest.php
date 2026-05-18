<?php

namespace Tests\Feature\Ai\Evidence;

use App\Services\Ai\Evidence\BlockerService;
use App\Services\Ai\Evidence\EvidenceControlPlaneService;
use App\Services\Ai\Evidence\EvidencePackService;
use Tests\Concerns\CreatesEvidenceRuntimeTables;
use Tests\TestCase;

class EvidenceRuntimeBlockerTest extends TestCase
{
    use CreatesEvidenceRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createEvidenceRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropEvidenceRuntimeTables();
        parent::tearDown();
    }

    public function test_open_blocker_appears_in_control_plane_snapshot(): void
    {
        /** @var BlockerService $blockers */
        $blockers = app(BlockerService::class);
        /** @var EvidenceControlPlaneService $controlPlane */
        $controlPlane = app(EvidenceControlPlaneService::class);

        $blocker = $blockers->open([
            'target_type' => EvidencePackService::TARGET_MISSION,
            'target_id' => 'mission-x',
            'blocker_type' => BlockerService::KIND_MISSING_EVIDENCE,
            'severity' => BlockerService::SEVERITY_HIGH,
            'reason' => 'pentest authorization missing',
        ]);

        $snapshot = $controlPlane->snapshot();
        $this->assertSame(EvidenceControlPlaneService::SCHEMA, $snapshot['schema']);
        $this->assertGreaterThanOrEqual(1, $snapshot['open_blockers_count']);
        $ids = collect($snapshot['recent_blockers'])->pluck('id')->all();
        $this->assertContains($blocker->id, $ids);
    }

    public function test_blocker_resolution_updates_status(): void
    {
        /** @var BlockerService $blockers */
        $blockers = app(BlockerService::class);
        $blocker = $blockers->open([
            'target_type' => EvidencePackService::TARGET_TOOL_RUN,
            'target_id' => 'tool-run-1',
            'blocker_type' => BlockerService::KIND_DATA_UNAVAILABLE,
            'severity' => BlockerService::SEVERITY_MEDIUM,
            'reason' => 'staging DB offline',
        ]);

        $resolved = $blockers->resolve($blocker, BlockerService::STATUS_RESOLVED);
        $this->assertSame(BlockerService::STATUS_RESOLVED, $resolved->status);
        $this->assertNotNull($resolved->resolved_at);
    }
}
