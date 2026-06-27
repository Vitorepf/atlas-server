<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHintToPathTranslator;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPathOscillationDetector;
use Tests\TestCase;

final class AtlasBrainPathOscillationDetectorTest extends TestCase
{
    private function tail(array $hints): array
    {
        return array_map(static fn (string $h) => ['action_hint' => $h], $hints);
    }

    public function test_no_detection_when_below_min_run(): void
    {
        $r = (new AtlasBrainPathOscillationDetector)->detect(
            $this->tail(['harvest_frontier', 'compound', 'harvest_frontier']),
            new AtlasBrainHintToPathTranslator,
        );
        self::assertFalse($r['detected']);
    }

    public function test_detects_ababab_run(): void
    {
        $r = (new AtlasBrainPathOscillationDetector)->detect(
            $this->tail(['harvest_frontier', 'compound', 'harvest_frontier', 'compound', 'harvest_frontier', 'compound']),
            new AtlasBrainHintToPathTranslator,
        );
        self::assertTrue($r['detected']);
        self::assertSame(['frontier-harvest', 'compounding'], $r['pair']);
        self::assertSame(6, $r['run_length']);
    }

    public function test_no_detection_when_three_paths_rotate(): void
    {
        $r = (new AtlasBrainPathOscillationDetector)->detect(
            $this->tail(['harvest_frontier', 'compound', 'use_drafted_candidate', 'harvest_frontier', 'compound', 'use_drafted_candidate']),
            new AtlasBrainHintToPathTranslator,
        );
        self::assertFalse($r['detected']);
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainPathOscillationDetector.php',
                true
            )
        );
    }
}
