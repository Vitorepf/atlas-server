<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Trinity;

use App\Services\Ai\AutonomousEvolution\Trinity\ThreeWay\AtlasLoopTrinityFactStreamMerger;
use App\Services\Ai\AutonomousEvolution\Trinity\ThreeWay\TrinityLineageBrokenException;
use PHPUnit\Framework\TestCase;

/**
 * Proves the Trinity 3-way fact merger: deterministic factId, fail-closed lineage (Cortex⇒Loop parent,
 * Maestro⇒Cortex parent), and preserved Loop→Cortex→Maestro emission order.
 */
final class AtlasLoopTrinityFactStreamMergerTest extends TestCase
{
    private function merger(): AtlasLoopTrinityFactStreamMerger
    {
        return new AtlasLoopTrinityFactStreamMerger;
    }

    public function test_merges_a_well_formed_lineage_in_order(): void
    {
        $loopId = AtlasLoopTrinityFactStreamMerger::computeFactId(['x' => 1], 'c1', 'loop');
        $cortexId = AtlasLoopTrinityFactStreamMerger::computeFactId(['y' => 2], 'c1', 'cortex');

        $stream = $this->merger()->merge(
            [['cycleId' => 'c1', 'payload' => ['x' => 1]]],
            [['cycleId' => 'c1', 'payload' => ['y' => 2], 'parentFactIds' => [$loopId]]],
            [['cycleId' => 'c1', 'payload' => ['z' => 3], 'parentFactIds' => [$cortexId]]],
        )->stream();

        $facts = is_array($stream) ? $stream : iterator_to_array($stream);
        $this->assertSame(['loop', 'cortex', 'maestro'], array_column($facts, 'source'), 'Loop before Cortex before Maestro');
        $this->assertSame($loopId, $facts[0]['factId']);
        $this->assertSame([$loopId], $facts[1]['parentFactIds']);
    }

    public function test_rejects_cortex_fact_without_loop_parent(): void
    {
        $this->expectException(TrinityLineageBrokenException::class);

        $this->merger()->merge(
            [['cycleId' => 'c1', 'payload' => ['x' => 1]]],
            [['cycleId' => 'c1', 'payload' => ['y' => 2], 'parentFactIds' => []]], // no Loop parent
            [],
        );
    }

    public function test_rejects_maestro_fact_without_cortex_parent(): void
    {
        $loopId = AtlasLoopTrinityFactStreamMerger::computeFactId(['x' => 1], 'c1', 'loop');

        $this->expectException(TrinityLineageBrokenException::class);

        $this->merger()->merge(
            [['cycleId' => 'c1', 'payload' => ['x' => 1]]],
            [['cycleId' => 'c1', 'payload' => ['y' => 2], 'parentFactIds' => [$loopId]]],
            [['cycleId' => 'c1', 'payload' => ['z' => 3], 'parentFactIds' => [$loopId]]], // references Loop, not Cortex
        );
    }

    public function test_fact_id_is_deterministic(): void
    {
        $a = AtlasLoopTrinityFactStreamMerger::computeFactId(['x' => 1], 'c1', 'loop');
        $b = AtlasLoopTrinityFactStreamMerger::computeFactId(['x' => 1], 'c1', 'loop');
        $c = AtlasLoopTrinityFactStreamMerger::computeFactId(['x' => 2], 'c1', 'loop');

        $this->assertSame($a, $b, 'identical input ⇒ identical factId');
        $this->assertNotSame($a, $c, 'differing payload ⇒ differing factId');
    }
}
