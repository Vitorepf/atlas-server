<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainKnowledgeDominanceGapRouter;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainKnowledgeDominanceGapRouterTest extends TestCase
{
    private function router(): AtlasExternalBrainKnowledgeDominanceGapRouter
    {
        return new AtlasExternalBrainKnowledgeDominanceGapRouter;
    }

    private function area(string $id, array $overrides = []): array
    {
        return array_merge([
            'area_id' => $id,
            'maturity' => 0.8,
            'stale' => false,
            'risk_level' => 'low',
            'owner_clear' => true,
            'evidence_coverage' => 0.9,
            'blocked_dependencies' => [],
            'sprawl' => false,
            'overlapping_ownership' => false,
        ], $overrides);
    }

    // ── AC: stale high-risk → refresh_context before task creation ──────────

    public function test_stale_high_risk_area_routes_to_refresh_context(): void
    {
        $r = $this->router()->route([$this->area('a1', ['stale' => true, 'risk_level' => 'high'])]);

        $this->assertSame(AtlasExternalBrainKnowledgeDominanceGapRouter::ACTION_REFRESH_CONTEXT, $r['routes'][0]['action']);
    }

    public function test_stale_high_risk_beats_task_creation_signal_too(): void
    {
        // Also has low maturity + clear owner + evidence gap (would qualify for create_task_chain),
        // but stale+high-risk must win.
        $r = $this->router()->route([$this->area('a1', [
            'stale' => true,
            'risk_level' => 'high',
            'maturity' => 0.1,
            'owner_clear' => true,
            'evidence_coverage' => 0.1,
        ])]);

        $this->assertSame(AtlasExternalBrainKnowledgeDominanceGapRouter::ACTION_REFRESH_CONTEXT, $r['routes'][0]['action']);
    }

    // ── AC: low maturity + clear owner + evidence gap → create_task_chain ────

    public function test_low_maturity_clear_owner_evidence_gap_routes_to_create_task_chain(): void
    {
        $r = $this->router()->route([$this->area('a1', [
            'maturity' => 0.2,
            'owner_clear' => true,
            'evidence_coverage' => 0.1,
        ])]);

        $this->assertSame(AtlasExternalBrainKnowledgeDominanceGapRouter::ACTION_CREATE_TASK_CHAIN, $r['routes'][0]['action']);
    }

    // ── AC: high sprawl or overlapping ownership → simplify_or_consolidate ───

    public function test_sprawl_routes_to_simplify_or_consolidate(): void
    {
        $r = $this->router()->route([$this->area('a1', ['sprawl' => true])]);
        $this->assertSame(AtlasExternalBrainKnowledgeDominanceGapRouter::ACTION_SIMPLIFY_OR_CONSOLIDATE, $r['routes'][0]['action']);
    }

    public function test_overlapping_ownership_routes_to_simplify_or_consolidate(): void
    {
        $r = $this->router()->route([$this->area('a1', ['overlapping_ownership' => true])]);
        $this->assertSame(AtlasExternalBrainKnowledgeDominanceGapRouter::ACTION_SIMPLIFY_OR_CONSOLIDATE, $r['routes'][0]['action']);
    }

    // ── AC: blocked dependency signals → maestro_unblock_plan with names preserved ──

    public function test_blocked_dependencies_route_to_maestro_unblock_plan_with_names_preserved(): void
    {
        $r = $this->router()->route([$this->area('a1', ['blocked_dependencies' => ['dep-x', 'dep-y']])]);

        $route = $r['routes'][0];
        $this->assertSame(AtlasExternalBrainKnowledgeDominanceGapRouter::ACTION_MAESTRO_UNBLOCK, $route['action']);
        $this->assertStringContainsString('dep-x', $route['reason']);
        $this->assertStringContainsString('dep-y', $route['reason']);
    }

    public function test_blocked_dependencies_beat_every_other_signal(): void
    {
        $r = $this->router()->route([$this->area('a1', [
            'blocked_dependencies' => ['dep-z'],
            'stale' => true,
            'risk_level' => 'high',
            'sprawl' => true,
        ])]);

        $this->assertSame(AtlasExternalBrainKnowledgeDominanceGapRouter::ACTION_MAESTRO_UNBLOCK, $r['routes'][0]['action']);
    }

    // ── Additional actions: research grounding + retire stale ────────────────

    public function test_evidence_gap_with_unclear_owner_routes_to_research_grounding(): void
    {
        $r = $this->router()->route([$this->area('a1', [
            'owner_clear' => false,
            'evidence_coverage' => 0.1,
            'maturity' => 0.9, // high maturity so it does not qualify for create_task_chain
        ])]);

        $this->assertSame(AtlasExternalBrainKnowledgeDominanceGapRouter::ACTION_RUN_RESEARCH_GROUNDING, $r['routes'][0]['action']);
    }

    public function test_stale_unclear_owner_low_risk_routes_to_retire_stale_work(): void
    {
        $r = $this->router()->route([$this->area('a1', [
            'stale' => true,
            'owner_clear' => false,
            'risk_level' => 'low',
            'evidence_coverage' => 0.9, // no evidence gap, so research_grounding does not match
        ])]);

        $this->assertSame(AtlasExternalBrainKnowledgeDominanceGapRouter::ACTION_RETIRE_STALE_WORK, $r['routes'][0]['action']);
    }

    public function test_clean_area_routes_to_no_action(): void
    {
        $r = $this->router()->route([$this->area('a1')]);
        $this->assertSame(AtlasExternalBrainKnowledgeDominanceGapRouter::ACTION_NO_ACTION, $r['routes'][0]['action']);
    }

    // ── AC: ordered by urgency, includes action/reason/required_evidence ─────

    public function test_routes_ordered_by_urgency(): void
    {
        $r = $this->router()->route([
            $this->area('low-urgency'), // no_action
            $this->area('mid-urgency', ['sprawl' => true]), // simplify_or_consolidate
            $this->area('high-urgency', ['blocked_dependencies' => ['dep-1']]), // maestro_unblock_plan
        ]);

        $actions = array_column($r['routes'], 'action');
        $this->assertSame([
            AtlasExternalBrainKnowledgeDominanceGapRouter::ACTION_MAESTRO_UNBLOCK,
            AtlasExternalBrainKnowledgeDominanceGapRouter::ACTION_SIMPLIFY_OR_CONSOLIDATE,
            AtlasExternalBrainKnowledgeDominanceGapRouter::ACTION_NO_ACTION,
        ], $actions);
    }

    public function test_same_action_areas_sorted_by_area_id(): void
    {
        $r = $this->router()->route([
            $this->area('zeta', ['sprawl' => true]),
            $this->area('alpha', ['sprawl' => true]),
        ]);

        $this->assertSame(['alpha', 'zeta'], array_column($r['routes'], 'area_id'));
    }

    public function test_each_route_has_action_reason_and_required_evidence(): void
    {
        $r = $this->router()->route([$this->area('a1', ['blocked_dependencies' => ['dep-1']])]);
        $route = $r['routes'][0];

        $this->assertArrayHasKey('action', $route);
        $this->assertArrayHasKey('reason', $route);
        $this->assertArrayHasKey('required_evidence', $route);
        $this->assertNotEmpty($route['reason']);
        $this->assertNotEmpty($route['required_evidence']);
    }

    public function test_no_action_has_empty_required_evidence(): void
    {
        $r = $this->router()->route([$this->area('a1')]);
        $this->assertSame([], $r['routes'][0]['required_evidence']);
    }

    public function test_route_is_deterministic(): void
    {
        $areas = [
            $this->area('a1', ['blocked_dependencies' => ['dep-1']]),
            $this->area('a2', ['sprawl' => true]),
        ];

        $a = $this->router()->route($areas);
        $b = $this->router()->route($areas);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_empty_areas_returns_empty_routes(): void
    {
        $r = $this->router()->route([]);
        $this->assertSame([], $r['routes']);
    }

    public function test_area_without_area_id_is_skipped(): void
    {
        $r = $this->router()->route([['maturity' => 0.5]]);
        $this->assertSame([], $r['routes']);
    }
}
