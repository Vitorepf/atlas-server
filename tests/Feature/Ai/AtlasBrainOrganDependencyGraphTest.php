<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainCompoundingSuperposition;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainComposedSelfKnowledgeReport;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainNextCycleProjector;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainOrganDependencyGraph;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPathPriorityRank;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPathSignalAggregator;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPathYieldMomentum;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPerceptionBundle;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainSchemaContractRegistry;
use Tests\TestCase;

final class AtlasBrainOrganDependencyGraphTest extends TestCase
{
    private AtlasBrainOrganDependencyGraph $graph;

    protected function setUp(): void
    {
        parent::setUp();
        $this->graph = new AtlasBrainOrganDependencyGraph;
    }

    // ── AC2: dependsOn returns declared deps for known organs, empty for unknown

    public function test_depends_on_returns_declared_dependencies_for_known_organ(): void
    {
        $deps = $this->graph->dependsOn(AtlasBrainNextCycleProjector::class);

        $this->assertNotEmpty($deps);
        $this->assertContains(AtlasBrainPathYieldMomentum::class, $deps);
    }

    public function test_depends_on_returns_empty_for_unknown_organ(): void
    {
        $this->assertSame([], $this->graph->dependsOn('App\Nonexistent\SomeClass'));
    }

    public function test_depends_on_multi_dependency_organ_returns_all_deps(): void
    {
        $deps = $this->graph->dependsOn(AtlasBrainPathSignalAggregator::class);

        $this->assertGreaterThan(1, count($deps));
        $this->assertContains(AtlasBrainPathYieldMomentum::class, $deps);
    }

    public function test_depends_on_returns_single_dep_for_priority_rank(): void
    {
        $deps = $this->graph->dependsOn(AtlasBrainPathPriorityRank::class);

        $this->assertSame([AtlasBrainPathSignalAggregator::class], $deps);
    }

    // ── AC3: consumersOf returns every consumer of the queried organ, deterministically

    public function test_consumers_of_returns_all_consumers_of_path_yield_momentum(): void
    {
        $consumers = $this->graph->consumersOf(AtlasBrainPathYieldMomentum::class);

        // These four consume AtlasBrainPathYieldMomentum
        $this->assertContains(AtlasBrainNextCycleProjector::class,      $consumers);
        $this->assertContains(AtlasBrainPathSignalAggregator::class,    $consumers);
        $this->assertContains(AtlasBrainPerceptionBundle::class,        $consumers);
        $this->assertContains(AtlasBrainCompoundingSuperposition::class, $consumers);
    }

    public function test_consumers_of_returns_empty_for_leaf_organ(): void
    {
        // AtlasBrainPathYieldMomentum is a dependency of many but depends on nothing → no consumers in the map
        // (or any organ not listed as a dep anywhere)
        $consumers = $this->graph->consumersOf('App\Nonexistent\LeafOrgan');

        $this->assertSame([], $consumers);
    }

    public function test_consumers_of_returns_composed_report_for_dependency_graph_itself(): void
    {
        $consumers = $this->graph->consumersOf(AtlasBrainOrganDependencyGraph::class);

        $this->assertContains(AtlasBrainComposedSelfKnowledgeReport::class, $consumers);
    }

    public function test_consumers_of_is_deterministic(): void
    {
        $this->assertSame(
            $this->graph->consumersOf(AtlasBrainPathSignalAggregator::class),
            $this->graph->consumersOf(AtlasBrainPathSignalAggregator::class),
        );
    }

    public function test_consumers_of_returns_consumers_for_signal_aggregator(): void
    {
        $consumers = $this->graph->consumersOf(AtlasBrainPathSignalAggregator::class);

        // AtlasBrainPathPriorityRank and AtlasBrainPerceptionBundle both depend on it
        $this->assertContains(AtlasBrainPathPriorityRank::class,   $consumers);
        $this->assertContains(AtlasBrainPerceptionBundle::class, $consumers);
    }

    // ── AC4: graph returns the full map, includes empty virtual-edge entries

    public function test_graph_returns_full_dependency_map(): void
    {
        $map = $this->graph->graph();

        $this->assertIsArray($map);
        $this->assertArrayHasKey(AtlasBrainNextCycleProjector::class,      $map);
        $this->assertArrayHasKey(AtlasBrainPerceptionBundle::class,        $map);
        $this->assertArrayHasKey(AtlasBrainComposedSelfKnowledgeReport::class, $map);
    }

    public function test_graph_preserves_empty_virtual_edge_entries(): void
    {
        $map = $this->graph->graph();

        // SchemaContractRegistry has virtual edges noted in a comment; the const still declares []
        $this->assertArrayHasKey(AtlasBrainSchemaContractRegistry::class, $map);
        $this->assertSame([], $map[AtlasBrainSchemaContractRegistry::class]);
    }

    public function test_graph_does_not_mutate_between_calls(): void
    {
        $this->assertSame($this->graph->graph(), $this->graph->graph());
    }
}
