<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainBriefHistogram;
use Tests\TestCase;

final class AtlasBrainBriefHistogramTest extends TestCase
{
    public function test_empty_priors_returns_empty_histogram(): void
    {
        $r = (new AtlasBrainBriefHistogram)->histogram([]);
        self::assertSame(0, $r['total']);
        self::assertSame([], $r['by_hint']);
        self::assertNull($r['top']);
    }

    public function test_counts_hints_with_percentages_sorted_desc(): void
    {
        $priors = [
            ['kind' => 'note', 'reflection' => 'leverage_brief: use_drafted_candidate — x'],
            ['kind' => 'note', 'reflection' => 'leverage_brief: use_drafted_candidate — y'],
            ['kind' => 'note', 'reflection' => 'leverage_brief: rotate_path — z'],
            ['kind' => 'note', 'reflection' => 'leverage_brief: use_drafted_candidate — w'],
        ];

        $r = (new AtlasBrainBriefHistogram)->histogram($priors);

        self::assertSame(4, $r['total']);
        self::assertSame('use_drafted_candidate', $r['by_hint'][0]['hint']);
        self::assertSame(3, $r['by_hint'][0]['count']);
        self::assertSame(75, $r['by_hint'][0]['pct']);
        self::assertSame('rotate_path', $r['by_hint'][1]['hint']);
        self::assertSame('use_drafted_candidate', $r['top']);
    }

    public function test_skips_non_leverage_brief_reflections(): void
    {
        $r = (new AtlasBrainBriefHistogram)->histogram([
            ['reflection' => 'some other note'],
            ['reflection' => 'leverage_brief: compound — x'],
        ]);
        self::assertSame(1, $r['total']);
        self::assertSame('compound', $r['top']);
    }

    public function test_histogram_organ_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit(
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainBriefHistogram.php',
            true
        );
        self::assertSame('forbidden', $verdict);
    }
}
