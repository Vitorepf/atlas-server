<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionCircuitBoundaryExtractor;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionCircuitBoundaryExtractorTest extends TestCase
{
    private function fullGraph(): array
    {
        return [
            'entrypoints' => ['AtlasFooCommand::handle'],
            'outputs' => ['payload.status'],
            'ledgers' => ['atlas_foo_ledger'],
            'consumers' => ['AtlasBarService'],
            'allowed_files' => ['app/Services/Ai/Foo.php', 'app/Services/Ai/Bar.php'],
            'crossing_edges' => [
                ['target' => 'app/Services/Ai/Bar.php'],
            ],
        ];
    }

    public function test_extracts_entrypoints_outputs_ledgers_consumers_and_crossing_edges(): void
    {
        $result = (new AtlasSelfConstructionCircuitBoundaryExtractor)->extract($this->fullGraph());

        $this->assertSame(['AtlasFooCommand::handle'], $result['entrypoints']);
        $this->assertSame(['payload.status'], $result['outputs']);
        $this->assertSame(['atlas_foo_ledger'], $result['ledgers']);
        $this->assertSame(['AtlasBarService'], $result['consumers']);
        $this->assertSame([['target' => 'app/Services/Ai/Bar.php']], $result['crossing_edges']);
        $this->assertTrue($result['boundary_confident']);
        $this->assertSame([], $result['consolidation_blockers']);
    }

    public function test_boundary_confident_false_when_entrypoints_unknown(): void
    {
        $graph = $this->fullGraph();
        unset($graph['entrypoints']);

        $result = (new AtlasSelfConstructionCircuitBoundaryExtractor)->extract($graph);

        $this->assertFalse($result['boundary_confident']);
        $this->assertContains('entrypoints', $result['unknown_facts']);
    }

    public function test_boundary_confident_false_when_outputs_unknown(): void
    {
        $graph = $this->fullGraph();
        unset($graph['outputs']);

        $result = (new AtlasSelfConstructionCircuitBoundaryExtractor)->extract($graph);

        $this->assertFalse($result['boundary_confident']);
        $this->assertContains('outputs', $result['unknown_facts']);
    }

    public function test_boundary_confident_false_when_ledgers_unknown(): void
    {
        $graph = $this->fullGraph();
        unset($graph['ledgers']);

        $result = (new AtlasSelfConstructionCircuitBoundaryExtractor)->extract($graph);

        $this->assertFalse($result['boundary_confident']);
        $this->assertContains('ledgers', $result['unknown_facts']);
    }

    public function test_empty_ledgers_array_is_still_confident(): void
    {
        $graph = $this->fullGraph();
        $graph['ledgers'] = [];

        $result = (new AtlasSelfConstructionCircuitBoundaryExtractor)->extract($graph);

        $this->assertTrue($result['boundary_confident'], 'explicit empty array means known-no-ledgers, not unknown');
    }

    public function test_crossing_edge_outside_allowed_files_is_consolidation_blocker(): void
    {
        $graph = $this->fullGraph();
        $graph['crossing_edges'] = [
            ['target' => 'app/Services/Ai/OutsideCircuit.php'],
        ];

        $result = (new AtlasSelfConstructionCircuitBoundaryExtractor)->extract($graph);

        $this->assertSame(
            ['crossing_edge_outside_allowed_files:app/Services/Ai/OutsideCircuit.php'],
            $result['consolidation_blockers'],
        );
    }
}
