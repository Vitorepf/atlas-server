<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Hotpath\AtlasCortexHotpathFrequencyReporter;
use Tests\TestCase;

/**
 * Feature-level gate for AtlasCortexHotpathFrequencyReporter.
 *
 * Verifies per-cycle FQCN dedup, cross-cycle increment, exact output shape
 * (no aggregate scalar), and deterministic sort (cycle_count DESC, fqcn ASC).
 */
final class AtlasCortexHotpathFrequencyReporterTest extends TestCase
{
    public function test_duplicate_fqcn_within_one_cycle_counts_once(): void
    {
        $reporter = new AtlasCortexHotpathFrequencyReporter();

        $rows = $reporter->report([
            ['touched_fqcns' => ['App\\X', 'App\\X', 'App\\X']],
            ['touched_fqcns' => ['App\\X']],
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['cycle_count']);
    }

    public function test_same_fqcn_across_multiple_cycles_increments_cycle_count(): void
    {
        $reporter = new AtlasCortexHotpathFrequencyReporter();

        $rows = $reporter->report([
            ['cycle_id' => 'c1', 'touched_fqcns' => ['App\\A']],
            ['cycle_id' => 'c2', 'touched_fqcns' => ['App\\A']],
            ['cycle_id' => 'c3', 'touched_fqcns' => ['App\\A']],
        ]);

        $this->assertSame(3, $rows[0]['cycle_count']);
        $this->assertSame(3, $rows[0]['window_size']);
        $this->assertEquals(1.0, $rows[0]['frequency']);
    }

    public function test_rows_contain_only_fqcn_cycle_count_window_size_frequency(): void
    {
        $reporter = new AtlasCortexHotpathFrequencyReporter();

        $rows = $reporter->report([
            ['touched_fqcns' => ['App\\A', 'App\\B']],
        ]);

        foreach ($rows as $row) {
            $this->assertSame(['fqcn', 'cycle_count', 'window_size', 'frequency'], array_keys($row));
        }
    }

    public function test_sort_is_cycle_count_desc_then_fqcn_asc(): void
    {
        $reporter = new AtlasCortexHotpathFrequencyReporter();

        $rows = $reporter->report([
            ['touched_fqcns' => ['App\\C']],
            ['touched_fqcns' => ['App\\A', 'App\\B']],
            ['touched_fqcns' => ['App\\A']],
        ]);

        // A appears in 2 cycles (highest), B and C in 1 each → tie broken alphabetically.
        $this->assertSame('App\\A', $rows[0]['fqcn']);
        $this->assertSame(2, $rows[0]['cycle_count']);
        $this->assertSame('App\\B', $rows[1]['fqcn']);
        $this->assertSame('App\\C', $rows[2]['fqcn']);
    }

    public function test_empty_input_returns_empty_list(): void
    {
        $this->assertSame([], (new AtlasCortexHotpathFrequencyReporter())->report([]));
    }
}
