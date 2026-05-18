<?php

namespace Tests\Feature\Ai\Cyber;

use App\Services\Ai\Cyber\CyberControlPlaneProjection;
use App\Services\Ai\Cyber\CyberEngagementIntakeService;
use Tests\Concerns\CreatesCyberRuntimeTables;
use Tests\TestCase;

class CyberDomainControlPlaneTest extends TestCase
{
    use CreatesCyberRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCyberRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropCyberRuntimeTables();
        parent::tearDown();
    }

    public function test_control_plane_returns_ready_status_with_totals(): void
    {
        app(CyberEngagementIntakeService::class)->intake([
            'title' => 'Sample engagement',
            'engagement_kind' => CyberEngagementIntakeService::KIND_DEFENSIVE_REVIEW,
            'requester' => 'operator',
            'targets' => ['internal'],
            'authorization_present' => true,
            'authorization' => ['doc_url' => 'internal://auth'],
        ]);

        /** @var CyberControlPlaneProjection $svc */
        $svc = app(CyberControlPlaneProjection::class);
        $snap = $svc->snapshot();

        $this->assertSame('atlas.ai.cyber.control_plane.v1', $snap['schema']);
        $this->assertSame('ready', $snap['status']);
        $this->assertGreaterThanOrEqual(1, $snap['totals']['engagements']);
        $this->assertArrayHasKey('recent', $snap['engagements']);
    }

    public function test_control_plane_command_exits_zero(): void
    {
        $exit = $this->artisan('atlas:ai:cyber-domain', [
            '--action' => 'control-plane',
            '--json' => true,
        ])->run();
        $this->assertSame(0, $exit);
    }
}
