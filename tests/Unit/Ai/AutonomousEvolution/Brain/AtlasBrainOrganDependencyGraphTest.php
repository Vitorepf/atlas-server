<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainCompoundingVelocity;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainNextCycleProjector;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainOrganDependencyGraph;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPathSignalAggregator;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPathYieldMomentum;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPlanAdviserRedTeam;
use Tests\TestCase;

final class AtlasBrainOrganDependencyGraphTest extends TestCase
{
    public function test_depends_on_next_cycle_projector(): void
    {
        $deps = (new AtlasBrainOrganDependencyGraph)->dependsOn(AtlasBrainNextCycleProjector::class);
        self::assertContains(AtlasBrainPlanAdviserRedTeam::class, $deps);
        self::assertContains(AtlasBrainPathYieldMomentum::class, $deps);
    }

    public function test_consumers_of_momentum_include_signal_aggregator(): void
    {
        $consumers = (new AtlasBrainOrganDependencyGraph)->consumersOf(AtlasBrainPathYieldMomentum::class);
        self::assertContains(AtlasBrainPathSignalAggregator::class, $consumers);
        self::assertContains(AtlasBrainNextCycleProjector::class, $consumers);
    }

    public function test_velocity_has_signal_aggregator_consumer(): void
    {
        $consumers = (new AtlasBrainOrganDependencyGraph)->consumersOf(AtlasBrainCompoundingVelocity::class);
        self::assertContains(AtlasBrainPathSignalAggregator::class, $consumers);
    }

    public function test_unknown_organ_returns_empty(): void
    {
        self::assertSame([], (new AtlasBrainOrganDependencyGraph)->dependsOn('NoSuchOrgan'));
        self::assertSame([], (new AtlasBrainOrganDependencyGraph)->consumersOf('NoSuchOrgan'));
    }

    public function test_graph_classes_exist(): void
    {
        foreach ((new AtlasBrainOrganDependencyGraph)->graph() as $consumer => $deps) {
            self::assertTrue(class_exists($consumer));
            foreach ($deps as $dep) {
                self::assertTrue(class_exists($dep), "$dep declared but missing");
            }
        }
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainOrganDependencyGraph.php',
                true
            )
        );
    }
}
