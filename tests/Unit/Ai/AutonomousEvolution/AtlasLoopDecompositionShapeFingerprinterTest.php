<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopDecompositionShapeFingerprinter;
use PHPUnit\Framework\TestCase;

/**
 * ACDE Leap 5 — the structural plan fingerprint is a PURE, id-independent reduction of the DAG shape.
 * Same plan => same hash; renamed node ids (same structure) => same hash; an extra node => different hash.
 */
final class AtlasLoopDecompositionShapeFingerprinterTest extends TestCase
{
    private function fp(): AtlasLoopDecompositionShapeFingerprinter
    {
        return new AtlasLoopDecompositionShapeFingerprinter;
    }

    private function plan(array $nodes): array
    {
        return ['plan_id' => 'p', 'nodes' => $nodes];
    }

    public function test_same_plan_produces_the_same_hash(): void
    {
        $plan = $this->plan([
            ['id' => 'a', 'seq' => 0, 'target_area' => 'app/A.php', 'depends_on' => []],
            ['id' => 'b', 'seq' => 1, 'target_area' => 'app/B.php', 'depends_on' => ['a']],
        ]);

        $this->assertSame($this->fp()->fingerprint($plan)['hash'], $this->fp()->fingerprint($plan)['hash']);
        $this->assertSame(24, strlen($this->fp()->fingerprint($plan)['hash']));
    }

    public function test_renamed_node_ids_yield_the_same_hash(): void
    {
        $a = $this->plan([
            ['id' => 'a', 'seq' => 0, 'target_area' => 'app/A.php', 'depends_on' => []],
            ['id' => 'b', 'seq' => 1, 'target_area' => 'app/B.php', 'depends_on' => ['a']],
        ]);
        // Identical STRUCTURE (one root, one dependent), only the node ids differ.
        $b = $this->plan([
            ['id' => 'zzz', 'seq' => 0, 'target_area' => 'app/A.php', 'depends_on' => []],
            ['id' => 'qqq', 'seq' => 1, 'target_area' => 'app/B.php', 'depends_on' => ['zzz']],
        ]);

        $this->assertSame($this->fp()->fingerprint($a)['hash'], $this->fp()->fingerprint($b)['hash']);
    }

    public function test_an_extra_node_changes_the_hash(): void
    {
        $two = $this->plan([
            ['id' => 'a', 'seq' => 0, 'target_area' => 'app/A.php', 'depends_on' => []],
            ['id' => 'b', 'seq' => 1, 'target_area' => 'app/B.php', 'depends_on' => ['a']],
        ]);
        $three = $this->plan([
            ['id' => 'a', 'seq' => 0, 'target_area' => 'app/A.php', 'depends_on' => []],
            ['id' => 'b', 'seq' => 1, 'target_area' => 'app/B.php', 'depends_on' => ['a']],
            ['id' => 'c', 'seq' => 2, 'target_area' => 'app/C.php', 'depends_on' => ['b']],
        ]);

        $this->assertNotSame($this->fp()->fingerprint($two)['hash'], $this->fp()->fingerprint($three)['hash']);
        $this->assertSame(2, $this->fp()->fingerprint($two)['node_count']);
        $this->assertSame(3, $this->fp()->fingerprint($three)['node_count']);
    }

    public function test_a_different_dependency_structure_changes_the_hash(): void
    {
        // Same node_count + same targets, but a CHAIN vs a FAN-OUT — a genuinely different shape.
        $chain = $this->plan([
            ['id' => 'a', 'seq' => 0, 'target_area' => 'app/A.php', 'depends_on' => []],
            ['id' => 'b', 'seq' => 1, 'target_area' => 'app/B.php', 'depends_on' => ['a']],
            ['id' => 'c', 'seq' => 2, 'target_area' => 'app/C.php', 'depends_on' => ['b']],
        ]);
        $fanout = $this->plan([
            ['id' => 'a', 'seq' => 0, 'target_area' => 'app/A.php', 'depends_on' => []],
            ['id' => 'b', 'seq' => 1, 'target_area' => 'app/B.php', 'depends_on' => ['a']],
            ['id' => 'c', 'seq' => 2, 'target_area' => 'app/C.php', 'depends_on' => ['a']],
        ]);

        $this->assertNotSame($this->fp()->fingerprint($chain)['hash'], $this->fp()->fingerprint($fanout)['hash']);
    }

    public function test_empty_plan_is_stable(): void
    {
        $this->assertSame($this->fp()->fingerprint(['nodes' => []])['hash'], $this->fp()->fingerprint([])['hash']);
        $this->assertSame(0, $this->fp()->fingerprint([])['node_count']);
    }
}
