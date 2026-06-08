<?php

namespace Tests\Unit\Engineering\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphBenchmark;
use PHPUnit\Framework\TestCase;

class CodeGraphBenchmarkTest extends TestCase
{
    /**
     * @param  array<int,array<string,mixed>>  $samples
     */
    private function measure(array $samples): array
    {
        return (new CodeGraphBenchmark)->measure($samples);
    }

    public function test_aggregates_totals_and_ratio_math_on_known_samples(): void
    {
        // graph: 100 + 300 = 400; naive: 1000 + 1500 = 2500.
        $result = $this->measure([
            ['graph_tokens' => 100, 'naive_tokens' => 1000, 'label' => 'who_calls_X'],
            ['graph_tokens' => 300, 'naive_tokens' => 1500],
        ]);

        $this->assertSame(CodeGraphBenchmark::SCHEMA, $result['schema_version']);
        $this->assertSame(2, $result['sample_count']);
        $this->assertSame(400, $result['total_graph_tokens']);
        $this->assertSame(2500, $result['total_naive_tokens']);

        // ratio = 2500 / 400 = 6.25 (graph is 6.25x cheaper).
        $this->assertSame(6.25, $result['reduction_ratio']);
        // pct = (1 - 400/2500) * 100 = 84.0.
        $this->assertSame(84.0, $result['reduction_pct']);
    }

    public function test_per_sample_breakdown_is_correct_and_deterministic(): void
    {
        $result = $this->measure([
            ['graph_tokens' => 200, 'naive_tokens' => 800, 'label' => 'blast_radius'],
            ['graph_tokens' => 50, 'naive_tokens' => 50],
        ]);

        $first = $result['per_sample'][0];
        $this->assertSame(0, $first['index']);
        $this->assertSame('blast_radius', $first['label']);
        $this->assertSame(200, $first['graph_tokens']);
        $this->assertSame(800, $first['naive_tokens']);
        $this->assertSame(600, $first['saved_tokens']);
        $this->assertSame(4.0, $first['reduction_ratio']);  // 800 / 200
        $this->assertSame(75.0, $first['reduction_pct']);   // (1 - 200/800) * 100

        // Equal cost => ratio 1.0, pct 0.0, no negative savings.
        $second = $result['per_sample'][1];
        $this->assertSame(1, $second['index']);
        $this->assertSame('sample_1', $second['label']); // synthesized label
        $this->assertSame(0, $second['saved_tokens']);
        $this->assertSame(1.0, $second['reduction_ratio']);
        $this->assertSame(0.0, $second['reduction_pct']);
    }

    public function test_empty_samples_are_safe(): void
    {
        $result = $this->measure([]);

        $this->assertSame(0, $result['sample_count']);
        $this->assertSame(0, $result['total_graph_tokens']);
        $this->assertSame(0, $result['total_naive_tokens']);
        $this->assertSame(0.0, $result['reduction_ratio']);
        $this->assertSame(0.0, $result['reduction_pct']);
        $this->assertSame([], $result['per_sample']);
    }

    public function test_divide_by_zero_is_safe_when_graph_tokens_zero(): void
    {
        // graph total 0 => ratio must be 0.0, never INF.
        $result = $this->measure([
            ['graph_tokens' => 0, 'naive_tokens' => 1200],
        ]);

        $this->assertSame(0, $result['total_graph_tokens']);
        $this->assertSame(1200, $result['total_naive_tokens']);
        $this->assertSame(0.0, $result['reduction_ratio']);
        $this->assertIsFloat($result['reduction_ratio']);
        $this->assertTrue(is_finite($result['reduction_ratio']));
        // naive non-zero, graph zero => 100% saved.
        $this->assertSame(100.0, $result['reduction_pct']);

        $sample = $result['per_sample'][0];
        $this->assertSame(0.0, $sample['reduction_ratio']);
        $this->assertSame(100.0, $sample['reduction_pct']);
    }

    public function test_divide_by_zero_is_safe_when_naive_tokens_zero(): void
    {
        // naive total 0 => pct must be 0.0, never NaN/INF.
        $result = $this->measure([
            ['graph_tokens' => 500, 'naive_tokens' => 0],
        ]);

        $this->assertSame(0.0, $result['reduction_pct']);
        $this->assertIsFloat($result['reduction_pct']);
        $this->assertTrue(is_finite($result['reduction_pct']));
        // graph non-zero, naive zero => ratio 0/500 = 0.0.
        $this->assertSame(0.0, $result['reduction_ratio']);
    }

    public function test_malformed_counts_are_clamped_and_cannot_poison_totals(): void
    {
        $result = $this->measure([
            ['graph_tokens' => 'NaN', 'naive_tokens' => INF],           // both invalid -> 0
            ['graph_tokens' => -50, 'naive_tokens' => -100],            // negatives -> 0
            ['graph_tokens' => '120', 'naive_tokens' => 480.0],         // numeric string + float -> 120 / 480
            'not-an-array',                                             // skipped entirely
            ['naive_tokens' => 300],                                    // missing graph -> 0
        ]);

        // Five entries; the non-array string is skipped, the four arrays are counted.
        $this->assertSame(4, $result['sample_count']);
        $this->assertSame(4, count($result['per_sample']));
        $this->assertSame(120, $result['total_graph_tokens']); // 0 + 0 + 120 + 0
        $this->assertSame(780, $result['total_naive_tokens']); // 0 + 0 + 480 + 300
        $this->assertTrue(is_finite($result['reduction_ratio']));
        $this->assertTrue(is_finite($result['reduction_pct']));

        // The numeric-string/float row resolved correctly.
        $row = $result['per_sample'][2];
        $this->assertSame(120, $row['graph_tokens']);
        $this->assertSame(480, $row['naive_tokens']);
        $this->assertSame(4.0, $row['reduction_ratio']); // 480 / 120
    }
}
