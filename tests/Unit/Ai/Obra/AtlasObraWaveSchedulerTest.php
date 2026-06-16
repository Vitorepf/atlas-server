<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Obra;

use App\Services\Ai\Obra\AtlasObraWaveScheduler;
use PHPUnit\Framework\TestCase;

/**
 * ACDE Leap 4 — the antichain wave scheduler is PURE + deterministic: levels are computed from the
 * machine-declared depends_on DAG (Kahn layering), a cyclic/malformed DAG degrades to a single serial
 * level (byte-identical strict-serial), and a same-level write-scope collision marks the level for serial
 * fallback (never last-writer-wins). Parallelism changes only WHEN nodes run, never WHICH order certifies.
 */
final class AtlasObraWaveSchedulerTest extends TestCase
{
    private function sched(): AtlasObraWaveScheduler
    {
        return new AtlasObraWaveScheduler;
    }

    private function node(string $id, int $seq, array $deps = [], string $target = ''): array
    {
        return ['id' => $id, 'seq' => $seq, 'depends_on' => $deps, 'target_area' => $target ?: ('src/'.$id.'.php')];
    }

    public function test_one_root_three_independent_leaves_form_a_parallel_wave(): void
    {
        $nodes = [
            $this->node('root', 0),
            $this->node('a', 1, ['root']),
            $this->node('b', 2, ['root']),
            $this->node('c', 3, ['root']),
        ];

        $s = $this->sched()->schedule($nodes);

        $this->assertTrue($s['acyclic']);
        $this->assertSame([['root'], ['a', 'b', 'c']], $s['levels'], 'root alone, then the 3 leaves as one antichain level');
        $this->assertSame(3, $s['width']);
        $this->assertTrue($s['parallelizable']);
        $this->assertSame([false, false], $s['level_has_scope_collision'], 'distinct target files => no collision');
    }

    public function test_a_dependency_chain_stays_serial_width_one(): void
    {
        $nodes = [
            $this->node('a', 0),
            $this->node('b', 1, ['a']),
            $this->node('c', 2, ['b']),
        ];

        $s = $this->sched()->schedule($nodes);

        $this->assertTrue($s['acyclic']);
        $this->assertSame([['a'], ['b'], ['c']], $s['levels']);
        $this->assertSame(1, $s['width']);
        $this->assertFalse($s['parallelizable'], 'a deep chain has antichain width 1 — nothing to parallelize');
    }

    public function test_a_cycle_degrades_to_a_single_serial_level(): void
    {
        $nodes = [
            $this->node('a', 0, ['b']),
            $this->node('b', 1, ['a']),
        ];

        $s = $this->sched()->schedule($nodes);

        $this->assertFalse($s['acyclic'], 'a cyclic DAG is not schedulable into antichain levels');
        $this->assertFalse($s['parallelizable']);
        $this->assertSame(['a', 'b'], $s['serial_order'], 'serial fallback order is seq-ascending');
    }

    public function test_same_level_scope_collision_marks_the_level_for_serial_fallback(): void
    {
        // a + b are independent (both depend on root) BUT both write src/Shared.php => they collide.
        $nodes = [
            $this->node('root', 0),
            $this->node('a', 1, ['root'], 'src/Shared.php'),
            $this->node('b', 2, ['root'], 'src/Shared.php'),
        ];

        $s = $this->sched()->schedule($nodes);

        $this->assertTrue($s['acyclic']);
        $this->assertSame([['root'], ['a', 'b']], $s['levels']);
        $this->assertSame([false, true], $s['level_has_scope_collision'], 'the colliding level must serialize');
        $this->assertFalse($s['parallelizable'], 'the only multi-node level collides => not parallelizable');
    }

    public function test_collision_via_allowed_files_overlap_is_detected(): void
    {
        $nodes = [
            $this->node('root', 0),
            ['id' => 'a', 'seq' => 1, 'depends_on' => ['root'], 'allowed_files' => ['src/A.php', 'src/Common.php']],
            ['id' => 'b', 'seq' => 2, 'depends_on' => ['root'], 'allowed_files' => ['src/B.php', 'src/Common.php']],
        ];

        $s = $this->sched()->schedule($nodes);

        $this->assertSame([false, true], $s['level_has_scope_collision'], 'shared allowed_files entry => collision');
    }

    public function test_serial_order_is_deterministic_by_seq_then_id(): void
    {
        // Unordered input; same shape => same schedule regardless of input order.
        $a = $this->sched()->schedule([$this->node('c', 2), $this->node('a', 0), $this->node('b', 1)]);
        $b = $this->sched()->schedule([$this->node('a', 0), $this->node('b', 1), $this->node('c', 2)]);

        $this->assertSame(['a', 'b', 'c'], $a['serial_order']);
        $this->assertSame($a, $b, 'the schedule is input-order-independent (deterministic)');
    }

    public function test_diamond_dag_layers_correctly(): void
    {
        // root -> {a,b} -> sink. sink depends on both a and b => lands a level below them.
        $nodes = [
            $this->node('root', 0),
            $this->node('a', 1, ['root']),
            $this->node('b', 2, ['root']),
            $this->node('sink', 3, ['a', 'b']),
        ];

        $s = $this->sched()->schedule($nodes);

        $this->assertSame([['root'], ['a', 'b'], ['sink']], $s['levels']);
        $this->assertSame(2, $s['width']);
        $this->assertTrue($s['parallelizable']);
    }
}
