<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopHypothesisTreeProducer as P;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopIdeaTreeAccessor as Tree;
use PHPUnit\Framework\TestCase;

/**
 * ARBOR-GRAFT TIER 0.1 — the tree-producer's pure child-spec logic (competing siblings under a parent).
 */
final class AtlasLoopHypothesisTreeProducerTest extends TestCase
{
    public function test_k_distinct_hypotheses_become_k_sibling_specs(): void
    {
        $specs = P::childSpecs(0, ['use a verifier-guided beam', 'invert the one-shot retrieval assumption', 'add a fact ledger']);
        $this->assertCount(3, $specs);
        foreach ($specs as $s) {
            $this->assertSame(1, $s['depth']);                       // children of a depth-0 root
            $this->assertSame(Tree::KIND_DIRECTION, $s['node_kind']); // depth 1 = direction
            $this->assertSame(Tree::STATUS_PENDING, $s['tree_status']);
            $this->assertStringStartsWith('#h', $s['key_suffix']);
        }
        // distinct hypotheses => distinct content-addressed keys
        $keys = array_map(fn ($s) => $s['key_suffix'], $specs);
        $this->assertSame($keys, array_values(array_unique($keys)));
    }

    public function test_duplicate_hypotheses_collapse(): void
    {
        $specs = P::childSpecs(0, ['same idea', 'same idea', '  same   idea  ', 'different idea']);
        $this->assertCount(2, $specs); // the 3 rewordings of "same idea" collapse to one
    }

    public function test_blank_hypotheses_are_skipped(): void
    {
        $this->assertSame([], P::childSpecs(0, ['', '   ', "\n"]));
    }

    public function test_depth_two_children_are_implementation_kind(): void
    {
        $specs = P::childSpecs(1, ['concrete approach A']); // parent at depth 1 => child depth 2
        $this->assertSame(2, $specs[0]['depth']);
        $this->assertSame(Tree::KIND_IMPLEMENTATION, $specs[0]['node_kind']);
    }

    public function test_specs_are_deterministic(): void
    {
        $a = P::childSpecs(0, ['x', 'y']);
        $b = P::childSpecs(0, ['x', 'y']);
        $this->assertSame($a, $b);
    }
}
