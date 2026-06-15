<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopBlastRadiusAnalyzer;
use PHPUnit\Framework\TestCase;

/**
 * Absurd-leap 2 — blast-radius brain. Pure BFS over the reverse-dependency graph: a leaf is low risk, a hub
 * is high, depth + node caps bound the walk, cycles are safe.
 */
final class AtlasLoopBlastRadiusAnalyzerTest extends TestCase
{
    /** @param array<string,list<string>> $graph */
    private function consumersFrom(array $graph): callable
    {
        return static fn (string $node): array => array_map(
            static fn (string $c): array => ['to' => $c],
            $graph[$node] ?? [],
        );
    }

    public function test_leaf_with_no_consumers_is_low_risk(): void
    {
        $r = (new AtlasLoopBlastRadiusAnalyzer)->analyze('Leaf.php', $this->consumersFrom([]));
        $this->assertSame([], $r['blast_radius']);
        $this->assertSame(0, $r['consumer_count']);
        $this->assertSame('low', $r['risk']);
    }

    public function test_transitive_blast_radius_is_collected(): void
    {
        $graph = [
            'Core.php' => ['A.php', 'B.php'],
            'A.php' => ['A1.php'],
            'B.php' => [],
            'A1.php' => [],
        ];
        $r = (new AtlasLoopBlastRadiusAnalyzer)->analyze('Core.php', $this->consumersFrom($graph), 3, 200);
        sort($r['blast_radius']);
        $this->assertSame(['A.php', 'A1.php', 'B.php'], $r['blast_radius']);
        $this->assertSame(3, $r['consumer_count']);
        $this->assertSame(2, $r['max_depth_reached']);
    }

    public function test_depth_cap_limits_the_walk(): void
    {
        $graph = ['n0' => ['n1'], 'n1' => ['n2'], 'n2' => ['n3'], 'n3' => ['n4']];
        $r = (new AtlasLoopBlastRadiusAnalyzer)->analyze('n0', $this->consumersFrom($graph), 2, 200);
        sort($r['blast_radius']);
        $this->assertSame(['n1', 'n2'], $r['blast_radius'], 'depth 2 stops before n3');
    }

    public function test_node_cap_truncates_and_flags_critical(): void
    {
        $consumers = [];
        for ($i = 0; $i < 50; $i++) {
            $consumers[] = 'c'.$i;
        }
        $graph = ['hub' => $consumers];
        $r = (new AtlasLoopBlastRadiusAnalyzer)->analyze('hub', $this->consumersFrom($graph), 3, 10);
        $this->assertTrue($r['truncated']);
        $this->assertSame('critical', $r['risk'], 'a hub that blows the node cap is critical blast radius');
        $this->assertLessThanOrEqual(10, $r['consumer_count']);
    }

    public function test_cycle_is_safe(): void
    {
        $graph = ['a' => ['b'], 'b' => ['a']]; // a<->b cycle
        $r = (new AtlasLoopBlastRadiusAnalyzer)->analyze('a', $this->consumersFrom($graph), 5, 200);
        $this->assertSame(['b'], $r['blast_radius'], 'visited-set prevents infinite cycle walk');
    }
}
