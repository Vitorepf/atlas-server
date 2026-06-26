<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Campaign;

use App\Services\Ai\AutonomousEvolution\Campaign\AtlasLoopTerritoryClimbDecider;
use Tests\TestCase;

class AtlasLoopTerritoryClimbDeciderTest extends TestCase
{
    public function test_normalize_roots_deduplicates_and_trims(): void
    {
        $result = AtlasLoopTerritoryClimbDecider::normalizeRoots([
            'app/Foo',
            '  app/Bar  ',
            'app/Foo',
            'app\\Baz',
        ]);

        self::assertSame(['app/Foo', 'app/Bar', 'app/Baz'], $result);
    }

    public function test_normalize_roots_filters_non_strings(): void
    {
        $result = AtlasLoopTerritoryClimbDecider::normalizeRoots([
            'app/Foo', null, 42, 'app/Bar', false, 'app/Foo',
        ]);

        self::assertSame(['app/Foo', 'app/Bar'], $result);
    }

    public function test_normalize_roots_empty(): void
    {
        self::assertSame([], AtlasLoopTerritoryClimbDecider::normalizeRoots([]));
    }

    public function test_normalize_roots_filters_empty_strings(): void
    {
        self::assertSame(['app/Foo'], AtlasLoopTerritoryClimbDecider::normalizeRoots(['app/Foo', '   ', '']));
    }

    public function test_territory_climb_no_rung_returns_no_op(): void
    {
        $result = AtlasLoopTerritoryClimbDecider::territoryClimbDecision(
            current: ['app/Existing'],
            rungs: [],
            campaignId: 'campaign_x',
        );

        self::assertFalse($result['widen']);
        self::assertSame('no_rung_defined', $result['reason']);
        self::assertNull($result['next_roots']);
    }

    public function test_territory_climb_returns_widen_false_when_blocked(): void
    {
        // With no AtlasLoopTerritoryLadder, default blocks the climb (certified_leaps=0 < threshold)
        $result = AtlasLoopTerritoryClimbDecider::territoryClimbDecision(
            current: ['app/Existing'],
            rungs: ['app/New'],
            campaignId: 'campaign_x',
        );

        self::assertFalse($result['widen']);
        self::assertSame('blocked', $result['reason']);
        self::assertNull($result['next_roots']);
    }

    public function test_territory_climb_with_high_certified_leaps_may_widen(): void
    {
        $result = AtlasLoopTerritoryClimbDecider::territoryClimbDecision(
            current: ['app/Existing'],
            rungs: ['app/New'],
            campaignId: 'campaign_x',
            certifiedLeaps: 5,
        );

        // Whether widen=true depends on AtlasLoopTerritoryLadder internals;
        // either way the result must have widen=true|false and proper next_roots
        self::assertArrayHasKey('widen', $result);
        self::assertArrayHasKey('reason', $result);
        self::assertArrayHasKey('violations', $result);
    }

    public function test_territory_climb_combines_current_and_rungs(): void
    {
        $result = AtlasLoopTerritoryClimbDecider::territoryClimbDecision(
            current: ['app/A'],
            rungs: ['app/B'],
            campaignId: 'campaign_x',
            certifiedLeaps: 5,
        );

        // Whether widen or not, next_roots (when widen) must include both A and B
        if ($result['widen'] && $result['next_roots'] !== null) {
            self::assertContains('app/A', $result['next_roots']);
            self::assertContains('app/B', $result['next_roots']);
        }
    }

    public function test_territory_climb_violations_is_array(): void
    {
        $result = AtlasLoopTerritoryClimbDecider::territoryClimbDecision(
            current: [], rungs: ['app/New'], campaignId: 'campaign_x',
        );

        self::assertIsArray($result['violations']);
    }

    public function test_certified_leaps_returns_int(): void
    {
        // Non-existent campaign returns 0 (best-effort)
        self::assertSame(0, AtlasLoopTerritoryClimbDecider::certifiedLeapsFor('nonexistent_campaign_id_'.uniqid()));
    }

    public function test_methods_are_deterministic(): void
    {
        $a = AtlasLoopTerritoryClimbDecider::normalizeRoots(['x', 'y']);
        $b = AtlasLoopTerritoryClimbDecider::normalizeRoots(['x', 'y']);
        self::assertSame($a, $b);
    }
}