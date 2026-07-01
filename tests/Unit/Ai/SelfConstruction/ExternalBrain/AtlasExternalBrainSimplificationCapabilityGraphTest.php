<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSimplificationCapabilityGraph;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainSimplificationCapabilityGraphTest extends TestCase
{
    private function graph(): AtlasExternalBrainSimplificationCapabilityGraph
    {
        return new AtlasExternalBrainSimplificationCapabilityGraph;
    }

    public function test_safe_order_case_dependent_precedes_dependency(): void
    {
        $r = $this->graph()->build([
            'capabilities' => [
                ['id' => 'support_organ', 'owner' => 'atlas-dev'],
                ['id' => 'consumer_organ', 'owner' => 'atlas-dev', 'depends_on' => ['support_organ']],
            ],
        ]);

        $this->assertSame('ready', $r['decision']);
        $this->assertSame([], $r['blockers']);
        $supportPos = array_search('support_organ', $r['safe_order'], true);
        $consumerPos = array_search('consumer_organ', $r['safe_order'], true);
        $this->assertLessThan($supportPos, $consumerPos, 'dependent must be ordered before what it depends on');
    }

    public function test_cycle_or_missing_owner_hold_case_cycle(): void
    {
        $r = $this->graph()->build([
            'capabilities' => [
                ['id' => 'a', 'owner' => 'atlas-dev', 'depends_on' => ['b']],
                ['id' => 'b', 'owner' => 'atlas-dev', 'depends_on' => ['a']],
            ],
        ]);

        $this->assertSame('hold', $r['decision']);
        $this->assertSame([], $r['safe_order']);
        $blockerString = implode('|', $r['blockers']);
        $this->assertStringContainsString('cycle_involving', $blockerString);
        $this->assertStringContainsString('a', $blockerString);
        $this->assertStringContainsString('b', $blockerString);
    }

    public function test_missing_owner_hold_case(): void
    {
        $r = $this->graph()->build([
            'capabilities' => [
                ['id' => 'orphan', 'depends_on' => []],
            ],
        ]);

        $this->assertSame('hold', $r['decision']);
        $this->assertContains('missing_owner:orphan', $r['blockers']);
    }

    public function test_nodes_and_edges_reflect_input(): void
    {
        $r = $this->graph()->build([
            'capabilities' => [
                ['id' => 'x', 'owner' => 'atlas-dev', 'depends_on' => ['y']],
                ['id' => 'y', 'owner' => 'atlas-dev'],
            ],
        ]);

        $this->assertSame(['x', 'y'], $r['nodes']);
        $this->assertSame([['from' => 'x', 'to' => 'y']], $r['edges']);
    }

    public function test_diamond_dependency_produces_valid_safe_order(): void
    {
        $r = $this->graph()->build([
            'capabilities' => [
                ['id' => 'top', 'owner' => 'atlas-dev', 'depends_on' => ['left', 'right']],
                ['id' => 'left', 'owner' => 'atlas-dev', 'depends_on' => ['bottom']],
                ['id' => 'right', 'owner' => 'atlas-dev', 'depends_on' => ['bottom']],
                ['id' => 'bottom', 'owner' => 'atlas-dev'],
            ],
        ]);

        $this->assertSame('ready', $r['decision']);
        $pos = array_flip($r['safe_order']);
        $this->assertLessThan($pos['left'], $pos['top']);
        $this->assertLessThan($pos['right'], $pos['top']);
        $this->assertLessThan($pos['bottom'], $pos['left']);
        $this->assertLessThan($pos['bottom'], $pos['right']);
    }

    public function test_empty_capabilities_is_ready_with_empty_graph(): void
    {
        $r = $this->graph()->build([]);

        $this->assertSame('ready', $r['decision']);
        $this->assertSame([], $r['nodes']);
        $this->assertSame([], $r['safe_order']);
    }

    public function test_schema_present(): void
    {
        $r = $this->graph()->build([]);

        $this->assertSame(AtlasExternalBrainSimplificationCapabilityGraph::SCHEMA, $r['schema']);
    }
}
