<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\SymbolicAnchoring\PerPhase;

use App\Services\Ai\AutonomousEvolution\SymbolicAnchoring\PerPhase\AtlasLoopAnchorGatePerPhaseRegistry;
use App\Services\Ai\AutonomousEvolution\SymbolicAnchoring\PerPhase\PhaseAnchorRequirement;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasLoopAnchorGatePerPhaseRegistryTest extends TestCase
{
    public function test_registry_returns_distinct_profile_for_every_canonical_phase(): void
    {
        $registry = new AtlasLoopAnchorGatePerPhaseRegistry();
        foreach (AtlasLoopAnchorGatePerPhaseRegistry::PHASES as $phase) {
            $profile = $registry->profileFor($phase);
            self::assertInstanceOf(PhaseAnchorRequirement::class, $profile);
            self::assertSame($phase, $profile->phase);
            self::assertGreaterThan(0.0, $profile->anchoredSymbolsPerKchar);
            self::assertGreaterThan(0, $profile->distinctAnchorFloor);
            self::assertNotEmpty($profile->mustAnchorKinds);
        }
        self::assertCount(8, $registry->all());
    }

    public function test_unknown_phase_throws_invalid_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AtlasLoopAnchorGatePerPhaseRegistry)->profileFor('bogus');
    }

    public function test_decide_phase_floor_and_must_anchor_kinds(): void
    {
        $profile = (new AtlasLoopAnchorGatePerPhaseRegistry)->profileFor('decide');

        self::assertGreaterThan(0.0, $profile->anchoredSymbolsPerKchar);
        $intersect = array_intersect(['class', 'method', 'file'], $profile->mustAnchorKinds);
        self::assertNotEmpty($intersect, 'decide phase must_anchor_kinds must include at least one of class/method/file');
    }

    public function test_disabled_registry_ignores_overrides_and_serves_frozen_defaults(): void
    {
        $defaults = (new AtlasLoopAnchorGatePerPhaseRegistry)->all();
        $overrides = ['decide' => ['anchored_symbols_per_kchar' => 99.9, 'distinct_anchor_floor' => 100, 'must_anchor_kinds' => ['cheat']]];
        $registryOff = new AtlasLoopAnchorGatePerPhaseRegistry($overrides, enabled: false);

        foreach (AtlasLoopAnchorGatePerPhaseRegistry::PHASES as $phase) {
            self::assertEquals($defaults[$phase], $registryOff->profileFor($phase));
        }
    }

    public function test_enabled_registry_applies_per_phase_override(): void
    {
        $registry = new AtlasLoopAnchorGatePerPhaseRegistry([
            'decide' => ['anchored_symbols_per_kchar' => 10.0, 'distinct_anchor_floor' => 12, 'must_anchor_kinds' => ['method']],
        ], enabled: true);
        $decide = $registry->profileFor('decide');

        self::assertSame(10.0, $decide->anchoredSymbolsPerKchar);
        self::assertSame(12, $decide->distinctAnchorFloor);
        self::assertSame(['method'], $decide->mustAnchorKinds);
    }
}
