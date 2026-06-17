<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Obra;

use App\Services\Ai\Obra\AtlasLoopNodeFirstPassReorder;
use PHPUnit\Framework\TestCase;

/**
 * ACDE F7 — DAG-safe step reorder by historical first-pass rate. The load-bearing safety property (never
 * place a node before a node it depends on) and the no-op-until-prior-fills property (empty rates == seq
 * order) are pinned here without a database.
 */
final class AtlasLoopNodeFirstPassReorderTest extends TestCase
{
    private function svc(): AtlasLoopNodeFirstPassReorder
    {
        return new AtlasLoopNodeFirstPassReorder;
    }

    private function node(string $id, int $seq, string $request, array $deps = [], string $target = 'x.php'): array
    {
        return ['id' => $id, 'seq' => $seq, 'request' => $request, 'depends_on' => $deps, 'target_area' => $target];
    }

    public function test_shape_key_is_verb_plus_extension(): void
    {
        $svc = $this->svc();
        $this->assertSame('create:php', $svc->shapeKey(['request' => 'create step 1', 'target_area' => 'step1.php']));
        $this->assertSame('extract:php', $svc->shapeKey(['request' => 'Extract the helper', 'target_area' => 'app/Foo.php']));
        $this->assertSame('step', $svc->shapeKey(['request' => '', 'target_area' => '']));
        $this->assertSame('redirect', $svc->shapeKey(['request' => 'redirect caller', 'target_area' => 'noext']));
    }

    public function test_aggregate_rates_is_done_over_terminal(): void
    {
        $rows = [
            ['shape' => 'create:php', 'status' => 'done'],
            ['shape' => 'create:php', 'status' => 'done'],
            ['shape' => 'create:php', 'status' => 'failed'],
            ['shape' => 'redirect:php', 'status' => 'failed'],
            ['shape' => 'redirect:php', 'status' => 'failed'],
            ['shape' => 'create:php', 'status' => 'skipped'], // non-terminal => ignored
        ];
        $rates = $this->svc()->aggregateRates($rows);
        $this->assertEqualsWithDelta(0.6667, $rates['create:php'], 0.001);
        $this->assertSame(0.0, $rates['redirect:php']);
        $this->assertArrayNotHasKey('unseen', $rates);
    }

    public function test_empty_rates_preserve_seq_order_byte_identical(): void
    {
        $nodes = [
            $this->node('n0', 0, 'create a'),
            $this->node('n1', 1, 'redirect b'),
            $this->node('n2', 2, 'extract c'),
        ];
        $out = $this->svc()->reorder($nodes, []); // no prior => no-op
        $this->assertSame(['n0', 'n1', 'n2'], array_column($out, 'id'), 'empty prior => seq order unchanged');
        $this->assertSame([0, 1, 2], array_column($out, 'seq'));
    }

    public function test_independent_nodes_reorder_by_rate_desc(): void
    {
        // Two INDEPENDENT steps; the historically-easier shape (higher rate) goes first.
        $nodes = [
            $this->node('hard', 0, 'extract thing'),   // shape extract:php
            $this->node('easy', 1, 'create thing'),    // shape create:php
        ];
        $out = $this->svc()->reorder($nodes, ['extract:php' => 0.1, 'create:php' => 0.9]);
        $this->assertSame(['easy', 'hard'], array_column($out, 'id'), 'higher first-pass rate runs first');
        $this->assertSame([0, 1], array_column($out, 'seq'), 'seq reindexed to the new order');
    }

    public function test_dependencies_are_never_violated(): void
    {
        // n2 depends on n0; even though n2's shape has the highest rate, it can NEVER precede n0.
        $nodes = [
            $this->node('n0', 0, 'create base'),               // create:php
            $this->node('n1', 1, 'redirect other'),            // redirect:php
            $this->node('n2', 2, 'extract final', ['n0']),     // extract:php, depends n0
        ];
        $out = $this->svc()->reorder($nodes, ['extract:php' => 0.99, 'create:php' => 0.1, 'redirect:php' => 0.5]);
        $ids = array_column($out, 'id');

        $posN0 = array_search('n0', $ids, true);
        $posN2 = array_search('n2', $ids, true);
        $this->assertLessThan($posN2, $posN0, 'n2 (depends on n0) must come AFTER n0 despite a higher rate');
    }

    public function test_a_dependency_cycle_falls_back_to_seq_order_without_hanging(): void
    {
        // Mutual dependency (malformed) must not deadlock — drain by seq.
        $nodes = [
            $this->node('a', 0, 'create a', ['b']),
            $this->node('b', 1, 'create b', ['a']),
        ];
        $out = $this->svc()->reorder($nodes, ['create:php' => 0.9]);
        $this->assertSame(['a', 'b'], array_column($out, 'id'), 'a cycle drains in seq order, never hangs');
    }

    public function test_single_node_is_returned_unchanged(): void
    {
        $nodes = [$this->node('only', 0, 'create only')];
        $this->assertSame($nodes, $this->svc()->reorder($nodes, ['create:php' => 0.2]));
    }
}
