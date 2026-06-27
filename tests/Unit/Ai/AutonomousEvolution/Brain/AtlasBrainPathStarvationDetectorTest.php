<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHintToPathTranslator;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPathStarvationDetector;
use Tests\TestCase;

final class AtlasBrainPathStarvationDetectorTest extends TestCase
{
    public function test_detects_paths_hit_and_starved(): void
    {
        $r = (new AtlasBrainPathStarvationDetector)->detect(
            ['by_hint' => [
                ['hint' => 'harvest_frontier', 'count' => 3],
                ['hint' => 'use_drafted_candidate', 'count' => 1],
            ]],
            new AtlasBrainHintToPathTranslator
        );

        // hit = frontier-harvest + pattern-design.
        self::assertSame(['frontier-harvest', 'pattern-design'], $r['hit']);
        // 7 canonical - 2 hit = 5 starved.
        self::assertCount(5, $r['starved']);
        self::assertContains('compounding', $r['starved']);
        self::assertContains('adversarial-critique', $r['starved']);
    }

    public function test_counts_ambiguous_hints(): void
    {
        $r = (new AtlasBrainPathStarvationDetector)->detect(
            ['by_hint' => [['hint' => 'unknown_hint', 'count' => 5]]],
            new AtlasBrainHintToPathTranslator
        );
        self::assertSame(1, $r['ambiguous_hints']);
        self::assertSame([], $r['hit']);
        self::assertCount(7, $r['starved']);
    }

    public function test_detector_organ_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit(
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainPathStarvationDetector.php',
            true
        );
        self::assertSame('forbidden', $verdict);
    }
}
