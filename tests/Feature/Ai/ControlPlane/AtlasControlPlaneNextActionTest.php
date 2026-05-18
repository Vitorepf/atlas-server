<?php

namespace Tests\Feature\Ai\ControlPlane;

use App\Services\Ai\ControlPlane\AtlasControlPlaneNextActionService;
use App\Services\Ai\Evidence\BlockerService;
use App\Services\Ai\Evidence\EvidencePackService;
use Tests\Concerns\CreatesEvidenceRuntimeTables;
use Tests\Concerns\CreatesMissionFoundationTables;
use Tests\TestCase;

class AtlasControlPlaneNextActionTest extends TestCase
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

    public function test_next_actions_derive_setup_repair_items_from_readiness(): void
    {
        /** @var AtlasControlPlaneNextActionService $svc */
        $svc = app(AtlasControlPlaneNextActionService::class);
        $payload = $svc->actions();

        $this->assertSame('atlas.ai.control_plane.next_action.v1', $payload['schema']);
        $this->assertIsArray($payload['items']);
        $this->assertGreaterThanOrEqual(1, $payload['count']);

        $kinds = array_map(static fn (array $i): string => (string) $i['kind'], $payload['items']);
        // Without Domain/Policy/Tool/Router tables bootstrapped, we expect
        // at least one setup or repair action surface.
        $hasOperational = (bool) array_intersect($kinds, [
            'setup_runtime',
            'repair_runtime',
            'resolve_blocker',
            'review_pending_approvals',
            'idle',
        ]);
        $this->assertTrue($hasOperational, 'expected at least one canonical next-action kind.');
    }

    public function test_next_actions_promote_critical_blocker_to_resolve(): void
    {
        /** @var BlockerService $blockers */
        $blockers = app(BlockerService::class);
        $blockers->open([
            'target_type' => EvidencePackService::TARGET_MISSION,
            'target_id' => 'mission-critical',
            'blocker_type' => BlockerService::KIND_LEGAL_OR_POLICY,
            'severity' => BlockerService::SEVERITY_CRITICAL,
            'reason' => 'awaiting legal sign-off',
        ]);

        /** @var AtlasControlPlaneNextActionService $svc */
        $svc = app(AtlasControlPlaneNextActionService::class);
        $payload = $svc->actions();

        $kinds = array_map(static fn (array $i): string => (string) $i['kind'], $payload['items']);
        $this->assertContains('resolve_blocker', $kinds);
    }

    public function test_next_actions_command_exits_zero(): void
    {
        $exit = $this->artisan('atlas:ai:control-plane', [
            '--action' => 'next-actions',
            '--json' => true,
        ])->run();

        $this->assertSame(0, $exit);
    }
}
