<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainResultKindHistogram;
use Tests\TestCase;

/**
 * FROZEN proof of the result_kind histogram — distribution of cycle outcomes from reflection rows.
 */
final class AtlasBrainResultKindHistogramTest extends TestCase
{
    public function test_counts_kinds_sorted_desc_and_computes_starvation_pct(): void
    {
        $h = (new AtlasBrainResultKindHistogram)->histogram([
            ['result_kind' => 'blocked'],
            ['result_kind' => 'blocked'],
            ['result_kind' => 'note'],
            ['result_kind' => 'exhausted'],
            ['result_kind' => 'note'],
        ]);

        // 5 rows: blocked=2, note=2, exhausted=1 ⇒ starvation = blocked+exhausted = 3 / 5 = 60%.
        self::assertSame(5, $h['total']);
        self::assertSame(60, $h['starvation_pct']);
        // sorted desc by count, then kind asc: blocked (2) before note (2) — alphabetical tie-break.
        self::assertSame(['kind' => 'blocked', 'count' => 2, 'pct' => 40], $h['by_kind'][0]);
        self::assertSame('note', $h['by_kind'][1]['kind']);
    }

    public function test_empty_input_returns_zeros(): void
    {
        $h = (new AtlasBrainResultKindHistogram)->histogram([]);
        self::assertSame(0, $h['total']);
        self::assertSame([], $h['by_kind']);
        self::assertSame(0, $h['starvation_pct']);
    }

    public function test_histogram_organ_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit(
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainResultKindHistogram.php',
            true
        );
        self::assertSame('forbidden', $verdict);
    }
}
