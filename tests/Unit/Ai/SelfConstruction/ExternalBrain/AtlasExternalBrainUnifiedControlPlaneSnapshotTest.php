<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainUnifiedControlPlaneSnapshot;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainUnifiedControlPlaneSnapshotTest extends TestCase
{
    private AtlasExternalBrainUnifiedControlPlaneSnapshot $snapshot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshot = new AtlasExternalBrainUnifiedControlPlaneSnapshot();
    }

    // AC: stale evidence routes to refresh_evidence before generic create_more_tasks
    public function test_stale_evidence_routes_to_refresh(): void
    {
        $result = $this->snapshot->snapshot([
            'stale_evidence_count' => 3,
            'stop_go_verdict' => 'green',
        ]);

        $this->assertSame('refresh_evidence', $result['recommended_next_decision']);
    }

    // AC: provider_independence=failing routes to close_provider_dependency with red
    public function test_provider_failing_routes_to_close_dependency(): void
    {
        $result = $this->snapshot->snapshot([
            'provider_independence' => 'failing',
            'stop_go_verdict' => 'green',
        ]);

        $this->assertSame('close_provider_dependency', $result['recommended_next_decision']);
        $this->assertSame('red', $result['stop_go_verdict']);
    }

    // AC: maturity_gap_count with no red/yellow → compile_gap_chain
    public function test_maturity_gaps_no_blockers_routes_to_compile(): void
    {
        $result = $this->snapshot->snapshot([
            'maturity_gap_count' => 5,
            'red_blocker_count' => 0,
            'yellow_blocker_count' => 0,
        ]);

        $this->assertSame('compile_gap_chain', $result['recommended_next_decision']);
    }

    public function test_no_blockers_routes_to_create_more_tasks(): void
    {
        $result = $this->snapshot->snapshot([
            'stale_evidence_count' => 0,
            'provider_independence' => 'passing',
            'maturity_gap_count' => 0,
            'red_blocker_count' => 0,
            'yellow_blocker_count' => 0,
        ]);

        $this->assertSame('create_more_tasks', $result['recommended_next_decision']);
    }

    public function test_yellow_blockers_route_to_harden_task_fabric(): void
    {
        $result = $this->snapshot->snapshot([
            'yellow_blocker_count' => 2,
            'red_blocker_count' => 0,
        ]);

        $this->assertSame('harden_task_fabric', $result['recommended_next_decision']);
    }
}
