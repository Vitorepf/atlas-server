<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery\Cortex\Hotpath;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Hotpath\AtlasCortexHotpathFrequencyReporter;
use Tests\TestCase;

class AtlasCortexHotpathFrequencyReporterTest extends TestCase
{
    public function test_fixed_window_frequencies_ordered_by_cycle_count_desc_then_fqcn_asc(): void
    {
        $reporter = new AtlasCortexHotpathFrequencyReporter();
        $rows = $reporter->report([
            ['cycle_id' => 'c1', 'touched_fqcns' => ['App\\A', 'App\\B']],
            ['cycle_id' => 'c2', 'touched_fqcns' => ['App\\A', 'App\\C']],
            ['cycle_id' => 'c3', 'touched_fqcns' => ['App\\A', 'App\\B']],
            ['cycle_id' => 'c4', 'touched_fqcns' => ['App\\D']],
        ]);

        self::assertSame('App\\A', $rows[0]['fqcn']);
        self::assertSame(3, $rows[0]['cycle_count']);
        self::assertSame(4, $rows[0]['window_size']);
        self::assertSame(3 / 4, $rows[0]['frequency']);

        // Tie between B and C at count=1? Actually B appears in c1+c3 = 2, C appears in c2 only.
        self::assertSame('App\\B', $rows[1]['fqcn']);
        self::assertSame(2, $rows[1]['cycle_count']);

        // C and D tie at 1 → alphabetical.
        self::assertSame('App\\C', $rows[2]['fqcn']);
        self::assertSame('App\\D', $rows[3]['fqcn']);
    }

    public function test_empty_input_yields_empty_list(): void
    {
        $reporter = new AtlasCortexHotpathFrequencyReporter();
        self::assertSame([], $reporter->report([]));
    }

    public function test_duplicate_fqcn_within_same_cycle_counts_once(): void
    {
        $reporter = new AtlasCortexHotpathFrequencyReporter();
        $rows = $reporter->report([
            ['touched_fqcns' => ['App\\X', 'App\\X', 'App\\X']],
            ['touched_fqcns' => ['App\\X']],
        ]);
        self::assertSame(2, $rows[0]['cycle_count']);
    }

    public function test_output_rows_have_no_forbidden_aggregate_scalar_keys(): void
    {
        $reporter = new AtlasCortexHotpathFrequencyReporter();
        $rows = $reporter->report([
            ['touched_fqcns' => ['App\\A']],
        ]);
        foreach ($rows as $row) {
            foreach (['score', 'rank', 'hot', 'composite', 'total'] as $forbidden) {
                self::assertArrayNotHasKey($forbidden, $row);
            }
            self::assertSame(['fqcn', 'cycle_count', 'window_size', 'frequency'], array_keys($row));
        }
    }

    public function test_window_size_equals_cycle_facts_count_even_for_empty_cycle(): void
    {
        $reporter = new AtlasCortexHotpathFrequencyReporter();
        $rows = $reporter->report([
            ['touched_fqcns' => ['App\\A']],
            ['touched_fqcns' => []],
            ['touched_fqcns' => ['App\\A']],
        ]);
        self::assertSame(3, $rows[0]['window_size']);
        self::assertSame(2, $rows[0]['cycle_count']);
    }
}
