<?php

declare(strict_types=1);

namespace Tests\Unit\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphContextPackAssembler;
use Tests\TestCase;

/**
 * AP-815 · E-3 — Pure (no-DB) unit tests for the context-pack assembler.
 *
 * The assembler is a pure transform; these tests touch no database. They extend the
 * app's base TestCase (matching the sibling CodeGraph tests in this directory) only so
 * the `config()` helper resolves its inline default — they assert packing behaviour,
 * not configuration plumbing.
 */
final class CodeGraphContextPackAssemblerTest extends TestCase
{
    private CodeGraphContextPackAssembler $assembler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assembler = new CodeGraphContextPackAssembler();
    }

    /**
     * @param  array<int,int>  $tokenCosts
     * @return array<int,array{id:string,tokens:int}>
     */
    private function nodes(array $tokenCosts): array
    {
        $nodes = [];
        foreach ($tokenCosts as $i => $cost) {
            $nodes[] = ['id' => 'n'.$i, 'tokens' => $cost];
        }

        return $nodes;
    }

    /**
     * @param  array<int,mixed>  $included
     * @return array<int,string>
     */
    private function ids(array $included): array
    {
        return array_map(static fn (array $n): string => (string) ($n['id'] ?? ''), $included);
    }

    public function test_budget_fits_three_of_five_ranked_nodes_includes_prefix_and_truncates(): void
    {
        // Five nodes of 100 each; budget 300 fits exactly the first three.
        $nodes = $this->nodes([100, 100, 100, 100, 100]);

        $pack = $this->assembler->assemble($nodes, 300);

        $this->assertSame(['n0', 'n1', 'n2'], $this->ids($pack['included']));
        $this->assertSame(['n3', 'n4'], $this->ids($pack['excluded']));
        $this->assertTrue($pack['truncated']);
        $this->assertSame(3, $pack['count']);
        $this->assertSame(300, $pack['estimated_tokens']);
        $this->assertSame(300, $pack['budget']);
        $this->assertLessThanOrEqual($pack['budget'], $pack['estimated_tokens']);
    }

    public function test_budget_fits_all_nodes_is_not_truncated(): void
    {
        $nodes = $this->nodes([100, 50, 25]);

        $pack = $this->assembler->assemble($nodes, 1000);

        $this->assertSame(['n0', 'n1', 'n2'], $this->ids($pack['included']));
        $this->assertSame([], $pack['excluded']);
        $this->assertFalse($pack['truncated']);
        $this->assertSame(3, $pack['count']);
        $this->assertSame(175, $pack['estimated_tokens']);
    }

    public function test_default_mode_stops_at_first_node_that_does_not_fit(): void
    {
        // n0=100 fits (total 100). n1=500 does not fit (budget 250) → stop. Even though
        // n2=50 would fit the remaining 150, default mode excludes it (clean prefix).
        $nodes = $this->nodes([100, 500, 50]);

        $pack = $this->assembler->assemble($nodes, 250);

        $this->assertSame(['n0'], $this->ids($pack['included']));
        $this->assertSame(['n1', 'n2'], $this->ids($pack['excluded']));
        $this->assertTrue($pack['truncated']);
        $this->assertSame(100, $pack['estimated_tokens']);
    }

    public function test_fill_gaps_admits_a_small_low_rank_node_after_a_big_one_is_skipped(): void
    {
        // Same input as the default-mode test, but fill_gaps keeps scanning: n0=100
        // fits, n1=500 is skipped, n2=50 still fits the remaining 150 → admitted.
        $nodes = $this->nodes([100, 500, 50]);

        $pack = $this->assembler->assemble($nodes, 250, ['fill_gaps' => true]);

        $this->assertSame(['n0', 'n2'], $this->ids($pack['included']));
        $this->assertSame(['n1'], $this->ids($pack['excluded']));
        $this->assertTrue($pack['truncated']);
        $this->assertSame(150, $pack['estimated_tokens']);
        $this->assertSame(2, $pack['count']);
    }

    public function test_fill_gaps_only_enabled_by_strict_true(): void
    {
        // A truthy-but-not-true value (1) must NOT enable gap-filling.
        $nodes = $this->nodes([100, 500, 50]);

        $pack = $this->assembler->assemble($nodes, 250, ['fill_gaps' => 1]);

        // Behaves as default stop-and-exclude-rest.
        $this->assertSame(['n0'], $this->ids($pack['included']));
        $this->assertSame(['n1', 'n2'], $this->ids($pack['excluded']));
    }

    public function test_zero_budget_includes_nothing(): void
    {
        $nodes = $this->nodes([10, 20, 30]);

        $pack = $this->assembler->assemble($nodes, 0);

        $this->assertSame([], $pack['included']);
        $this->assertSame(['n0', 'n1', 'n2'], $this->ids($pack['excluded']));
        $this->assertTrue($pack['truncated']);
        $this->assertSame(0, $pack['count']);
        $this->assertSame(0, $pack['estimated_tokens']);
        $this->assertSame(0, $pack['budget']);
    }

    public function test_negative_budget_includes_nothing_and_clamps_reported_budget(): void
    {
        $nodes = $this->nodes([10, 20]);

        $pack = $this->assembler->assemble($nodes, -500);

        $this->assertSame([], $pack['included']);
        $this->assertSame(0, $pack['estimated_tokens']);
        $this->assertSame(0, $pack['budget']);
        $this->assertTrue($pack['truncated']);
    }

    public function test_empty_input_is_not_truncated(): void
    {
        $pack = $this->assembler->assemble([], 1000);

        $this->assertSame([], $pack['included']);
        $this->assertSame([], $pack['excluded']);
        $this->assertFalse($pack['truncated']);
        $this->assertSame(0, $pack['count']);
        $this->assertSame(0, $pack['estimated_tokens']);
        $this->assertSame(1000, $pack['budget']);
    }

    public function test_missing_token_field_uses_default_per_node_cost(): void
    {
        // No 'tokens' on any node → default 200 each. Budget 450 fits two (400),
        // the third (600) does not.
        $nodes = [
            ['id' => 'a'],
            ['id' => 'b'],
            ['id' => 'c'],
        ];

        $pack = $this->assembler->assemble($nodes, 450, ['default_node_tokens' => 200]);

        $this->assertSame(['a', 'b'], $this->ids($pack['included']));
        $this->assertSame(['c'], $this->ids($pack['excluded']));
        $this->assertSame(400, $pack['estimated_tokens']);
        $this->assertTrue($pack['truncated']);
    }

    public function test_garbage_and_nonpositive_token_costs_fall_back_to_default(): void
    {
        // 'tokens' values that cannot be a real cost → each becomes the default (50).
        $nodes = [
            ['id' => 'a', 'tokens' => 'not-a-number'],
            ['id' => 'b', 'tokens' => -10],
            ['id' => 'c', 'tokens' => 0],
            ['id' => 'd', 'tokens' => null],
        ];

        $pack = $this->assembler->assemble($nodes, 150, ['default_node_tokens' => 50]);

        // 150 budget / 50 each → first three fit (150), fourth excluded.
        $this->assertSame(['a', 'b', 'c'], $this->ids($pack['included']));
        $this->assertSame(['d'], $this->ids($pack['excluded']));
        $this->assertSame(150, $pack['estimated_tokens']);
    }

    public function test_fractional_token_cost_rounds_up(): void
    {
        // 100.5 must count as 101 (ceil) so the node is never under-counted.
        $nodes = [
            ['id' => 'a', 'tokens' => 100.5],
        ];

        $fits = $this->assembler->assemble($nodes, 101);
        $this->assertSame(['a'], $this->ids($fits['included']));
        $this->assertSame(101, $fits['estimated_tokens']);

        $doesNotFit = $this->assembler->assemble($nodes, 100);
        $this->assertSame([], $doesNotFit['included']);
        $this->assertSame(['a'], $this->ids($doesNotFit['excluded']));
        $this->assertTrue($doesNotFit['truncated']);
    }

    public function test_non_array_nodes_are_excluded_not_passed_through(): void
    {
        $nodes = [
            ['id' => 'a', 'tokens' => 100],
            'garbage-string',
            42,
            null,
            ['id' => 'b', 'tokens' => 100],
        ];

        $pack = $this->assembler->assemble($nodes, 1000);

        // Both real array nodes are included; the three non-array entries are excluded.
        $this->assertSame(['a', 'b'], $this->ids($pack['included']));
        $this->assertSame(['garbage-string', 42, null], $pack['excluded']);
        $this->assertTrue($pack['truncated']);
        $this->assertSame(200, $pack['estimated_tokens']);
        $this->assertSame(2, $pack['count']);
    }

    public function test_numeric_string_token_cost_is_honoured(): void
    {
        $nodes = [
            ['id' => 'a', 'tokens' => '120'],
            ['id' => 'b', 'tokens' => '  80 '],
        ];

        $pack = $this->assembler->assemble($nodes, 200);

        $this->assertSame(['a', 'b'], $this->ids($pack['included']));
        $this->assertSame(200, $pack['estimated_tokens']);
        $this->assertFalse($pack['truncated']);
    }

    public function test_output_is_deterministic_across_repeated_calls(): void
    {
        $nodes = $this->nodes([100, 500, 50, 30, 500, 20]);

        $first = $this->assembler->assemble($nodes, 250, ['fill_gaps' => true]);
        $second = $this->assembler->assemble($nodes, 250, ['fill_gaps' => true]);

        $this->assertSame($first, $second);
    }

    public function test_estimated_tokens_never_exceeds_budget_in_fill_gaps(): void
    {
        // A spread of sizes under a tight budget; whatever is admitted must never
        // overrun. fill_gaps should pack greedily but stay within budget.
        $nodes = $this->nodes([70, 200, 40, 200, 25, 10]);

        $budget = 130;
        $pack = $this->assembler->assemble($nodes, $budget, ['fill_gaps' => true]);

        $this->assertLessThanOrEqual($budget, $pack['estimated_tokens']);
        // 70 fits (70); 200 skip; 40 fits (110); 200 skip; 25 skip would overrun? no:
        // 110+25=135 > 130 → skip; 10 fits (120).
        $this->assertSame(['n0', 'n2', 'n5'], $this->ids($pack['included']));
        $this->assertSame(120, $pack['estimated_tokens']);
    }
}
