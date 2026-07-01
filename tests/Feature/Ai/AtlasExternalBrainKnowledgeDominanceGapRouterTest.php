<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainKnowledgeDominanceGapRouter;
use Tests\TestCase;

final class AtlasExternalBrainKnowledgeDominanceGapRouterTest extends TestCase
{
    public function test_blocked_dependencies_route_to_maestro_unblock_plan_before_everything_else(): void
    {
        $result = (new AtlasExternalBrainKnowledgeDominanceGapRouter)->route([
            [
                'area_id' => 'area-blocked',
                'blocked_dependencies' => ['dep-a'],
                'stale' => true,
                'risk_level' => 'high',
                'sprawl' => true,
            ],
        ]);

        $this->assertSame(
            AtlasExternalBrainKnowledgeDominanceGapRouter::ACTION_MAESTRO_UNBLOCK,
            $result['routes'][0]['action'],
        );
    }

    public function test_stale_high_risk_area_routes_to_refresh_context(): void
    {
        $result = (new AtlasExternalBrainKnowledgeDominanceGapRouter)->route([
            ['area_id' => 'area-stale', 'stale' => true, 'risk_level' => 'high'],
        ]);

        $this->assertSame(
            AtlasExternalBrainKnowledgeDominanceGapRouter::ACTION_REFRESH_CONTEXT,
            $result['routes'][0]['action'],
        );
    }

    public function test_sprawl_or_overlapping_ownership_routes_to_simplify_or_consolidate(): void
    {
        $result = (new AtlasExternalBrainKnowledgeDominanceGapRouter)->route([
            ['area_id' => 'area-sprawl', 'sprawl' => true],
            ['area_id' => 'area-overlap', 'overlapping_ownership' => true],
        ]);

        foreach ($result['routes'] as $route) {
            $this->assertSame(AtlasExternalBrainKnowledgeDominanceGapRouter::ACTION_SIMPLIFY_OR_CONSOLIDATE, $route['action']);
        }
    }

    public function test_low_maturity_clear_ownership_and_evidence_gap_routes_to_create_task_chain(): void
    {
        $result = (new AtlasExternalBrainKnowledgeDominanceGapRouter)->route([
            [
                'area_id' => 'area-new',
                'maturity' => 0.10,
                'owner_clear' => true,
                'evidence_coverage' => 0.10,
            ],
        ]);

        $this->assertSame(
            AtlasExternalBrainKnowledgeDominanceGapRouter::ACTION_CREATE_TASK_CHAIN,
            $result['routes'][0]['action'],
        );
        $this->assertNull($result['routes'][0]['refusal_reason']);
    }

    public function test_routes_are_ordered_by_urgency_then_area_id(): void
    {
        $result = (new AtlasExternalBrainKnowledgeDominanceGapRouter)->route([
            ['area_id' => 'z-monitor', 'maturity' => 1.0, 'owner_clear' => true, 'evidence_coverage' => 1.0],
            ['area_id' => 'b-blocked', 'blocked_dependencies' => ['dep']],
            ['area_id' => 'a-blocked', 'blocked_dependencies' => ['dep']],
        ]);

        $actions = array_column($result['routes'], 'action');
        $ids = array_column($result['routes'], 'area_id');

        $this->assertSame(
            [
                AtlasExternalBrainKnowledgeDominanceGapRouter::ACTION_MAESTRO_UNBLOCK,
                AtlasExternalBrainKnowledgeDominanceGapRouter::ACTION_MAESTRO_UNBLOCK,
                AtlasExternalBrainKnowledgeDominanceGapRouter::ACTION_NO_ACTION,
            ],
            $actions,
        );
        $this->assertSame(['a-blocked', 'b-blocked', 'z-monitor'], $ids);
    }

    public function test_unsafe_task_chain_creation_emits_refusal_reason(): void
    {
        $result = (new AtlasExternalBrainKnowledgeDominanceGapRouter)->route([
            [
                'area_id' => 'area-unclear-owner-evidence-gap',
                'maturity' => 0.10,
                'owner_clear' => false,
                'evidence_coverage' => 0.10,
            ],
        ]);

        $this->assertNotSame(
            AtlasExternalBrainKnowledgeDominanceGapRouter::ACTION_CREATE_TASK_CHAIN,
            $result['routes'][0]['action'],
        );
        $this->assertNotNull($result['routes'][0]['refusal_reason']);
    }
}
