<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopClarificationRequest;
use App\Services\Ai\AutonomousEvolution\AtlasLoopClarificationRouter;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ACDE U7 — deterministic clarification ROUTING. The router maps (reason + family + recurrence) to a
 * surface + priority; the enqueue stamps it when armed. Default OFF => surface/priority NULL => byte-
 * identical queue. A recurring delivery-blocking abstention climbs to high/operator_urgent.
 */
final class AtlasLoopClarificationRoutingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_clarification_requests')) {
            (require base_path('database/migrations/2026_06_17_000200_create_atlas_loop_clarification_requests_table.php'))->up();
        }
        // The additive routing columns.
        if (! Schema::hasColumn('atlas_loop_clarification_requests', 'surface')) {
            (require base_path('database/migrations/2026_06_17_000300_add_routing_to_atlas_loop_clarification_requests.php'))->up();
        }
    }

    public function test_router_is_a_pure_deterministic_mapping(): void
    {
        $router = new AtlasLoopClarificationRouter;

        // vague + refactor + first sighting => the floor: low / operator_inbox.
        $low = $router->route('vague_goal_no_anchor', 'refactor', 1);
        $this->assertSame(['surface' => 'operator_inbox', 'priority' => 'low', 'score' => 1], $low);

        // plan_not_ready (3) + feature (+1) + first sighting => normal, still inbox.
        $normal = $router->route('plan_not_ready', 'feature', 1);
        $this->assertSame(['surface' => 'operator_inbox', 'priority' => 'normal', 'score' => 4], $normal);

        // plan_not_ready (3) + feature (+1) + 3rd sighting (+2) => high / urgent.
        $high = $router->route('plan_not_ready', 'feature', 3);
        $this->assertSame(['surface' => 'operator_urgent', 'priority' => 'high', 'score' => 6], $high);

        // recurrence is capped at +3 (a stuck clarification cannot run away): vague(1)+refactor(0)+cap(3)=4.
        $this->assertSame(4, $router->route('vague_goal_no_anchor', 'refactor', 99)['score']);
        // unknown reason floors to severity 1.
        $this->assertSame(1, $router->route('mystery_reason', 'refactor', 1)['score']);
    }

    public function test_off_does_not_stamp_a_route_byte_identical(): void
    {
        config(['atlas.loop.clarification_routing_enabled' => false]);

        $row = AtlasLoopClarificationRequest::enqueue(
            ['reason' => 'plan_not_ready', 'family' => 'feature', 'objective_kind' => 'feature_x'],
            'ship the new export feature',
        );

        $this->assertNull($row->surface, 'OFF => no routing stamp');
        $this->assertNull($row->priority);
    }

    public function test_armed_stamps_the_router_decision(): void
    {
        config(['atlas.loop.clarification_routing_enabled' => true]);

        $row = AtlasLoopClarificationRequest::enqueue(
            ['reason' => 'plan_not_ready', 'family' => 'feature', 'objective_kind' => 'feature_x'],
            'ship the new export feature',
        );

        $this->assertSame('operator_inbox', $row->surface);
        $this->assertSame('normal', $row->priority, 'plan_not_ready+feature, first sighting => normal');
    }

    public function test_a_recurring_blocking_abstention_climbs_to_urgent(): void
    {
        config(['atlas.loop.clarification_routing_enabled' => true]);
        $receipt = ['reason' => 'plan_not_ready', 'family' => 'feature', 'objective_kind' => 'feature_x'];
        $goal = 'ship the new export feature';

        AtlasLoopClarificationRequest::enqueue($receipt, $goal); // times_seen=1 => normal
        AtlasLoopClarificationRequest::enqueue($receipt, $goal); // times_seen=2 => score 5 => high
        $row = AtlasLoopClarificationRequest::enqueue($receipt, $goal); // times_seen=3 => high

        $this->assertSame(3, $row->times_seen);
        $this->assertSame('high', $row->priority, 'a recurring delivery-blocking abstention climbs');
        $this->assertSame('operator_urgent', $row->surface);
    }
}
