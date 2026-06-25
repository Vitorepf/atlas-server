<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\SymbolicAnchoring\PerPhase;

use App\Services\Ai\AutonomousEvolution\SymbolicAnchoring\PerPhase\AtlasLoopAnchorGatePerPhaseEnforcer;
use App\Services\Ai\AutonomousEvolution\SymbolicAnchoring\PerPhase\AtlasLoopAnchorGatePerPhaseRegistry;
use App\Services\Ai\AutonomousEvolution\SymbolicAnchoring\PerPhase\EnforcementVerdict;
use Tests\TestCase;

class AtlasLoopAnchorGatePerPhaseEnforcerTest extends TestCase
{
    public function test_refuses_when_density_below_floor(): void
    {
        $registry = new AtlasLoopAnchorGatePerPhaseRegistry();
        $enforcer = new AtlasLoopAnchorGatePerPhaseEnforcer($registry, enabled: true);

        // Long text with very few anchors → density << floor.
        $payload = [
            'text' => str_repeat('lorem ipsum dolor sit amet, ', 200), // ~5500 chars
            'anchors' => [['kind' => 'class', 'name' => 'X']],
        ];
        $verdict = $enforcer->enforce('decide', $payload);

        self::assertFalse($verdict->allow);
        self::assertSame(EnforcementVerdict::REASON_DENSITY_BELOW_FLOOR, $verdict->reasonCode);
    }

    public function test_allows_when_density_and_kinds_meet_floor(): void
    {
        $registry = new AtlasLoopAnchorGatePerPhaseRegistry();
        $enforcer = new AtlasLoopAnchorGatePerPhaseEnforcer($registry, enabled: true);

        $anchors = [];
        // Compose 12 anchors covering required kinds.
        for ($i = 0; $i < 6; $i++) {
            $anchors[] = ['kind' => 'class', 'name' => 'C'.$i];
            $anchors[] = ['kind' => 'method', 'name' => 'm'.$i];
        }
        $payload = [
            'text' => 'short', // ~5 chars → 1 kchar → density = 12 / 1 = 12
            'anchors' => $anchors,
        ];

        $verdict = $enforcer->enforce('decide', $payload);

        self::assertTrue($verdict->allow);
        self::assertSame(EnforcementVerdict::REASON_ALLOWED, $verdict->reasonCode);
        self::assertSame([], $verdict->missingAnchorKinds);
    }

    public function test_disabled_always_allows_with_disabled_reason_and_zero_writes(): void
    {
        $registry = new AtlasLoopAnchorGatePerPhaseRegistry();
        $enforcer = new AtlasLoopAnchorGatePerPhaseEnforcer($registry, enabled: false);

        $payload = ['text' => str_repeat('x', 10000), 'anchors' => []];
        $verdict = $enforcer->enforce('decide', $payload);

        self::assertTrue($verdict->allow);
        self::assertSame(EnforcementVerdict::REASON_DISABLED, $verdict->reasonCode);
    }

    public function test_refuses_with_missing_required_anchor_kinds(): void
    {
        $registry = new AtlasLoopAnchorGatePerPhaseRegistry([
            'decide' => ['anchored_symbols_per_kchar' => 0.5, 'distinct_anchor_floor' => 1, 'must_anchor_kinds' => ['method']],
        ], enabled: true);
        $enforcer = new AtlasLoopAnchorGatePerPhaseEnforcer($registry, enabled: true);

        $payload = [
            'text' => 'x',
            'anchors' => [['kind' => 'class', 'name' => 'C']],
        ];
        $verdict = $enforcer->enforce('decide', $payload);

        self::assertFalse($verdict->allow);
        self::assertSame(EnforcementVerdict::REASON_MISSING_ANCHOR_KINDS, $verdict->reasonCode);
        self::assertContains('method', $verdict->missingAnchorKinds);
    }
}
