<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHintToPathTranslator;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPathCadenceDetector;
use Tests\TestCase;

final class AtlasBrainPathCadenceDetectorTest extends TestCase
{
    private function tail(array $hints): array
    {
        return array_map(static fn (string $h) => ['action_hint' => $h], $hints);
    }

    public function test_single_pick_returns_zero_gap(): void
    {
        $r = (new AtlasBrainPathCadenceDetector)->detect($this->tail(['compound']), new AtlasBrainHintToPathTranslator);
        self::assertSame(0.0, $r['by_path']['compounding']['avg_gap']);
        self::assertSame(1, $r['by_path']['compounding']['pick_count']);
    }

    public function test_regular_cadence(): void
    {
        // compound every 3 cycles: [compound, _, _, compound, _, _, compound]
        $tail = $this->tail(['compound', 'harvest_frontier', 'harvest_frontier', 'compound', 'harvest_frontier', 'harvest_frontier', 'compound']);
        $r = (new AtlasBrainPathCadenceDetector)->detect($tail, new AtlasBrainHintToPathTranslator);
        self::assertSame(3.0, $r['by_path']['compounding']['avg_gap']);
        self::assertSame(3, $r['by_path']['compounding']['pick_count']);
    }

    public function test_unknown_hints_skipped(): void
    {
        $r = (new AtlasBrainPathCadenceDetector)->detect($this->tail(['unknown', 'unknown']), new AtlasBrainHintToPathTranslator);
        self::assertSame([], $r['by_path']);
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainPathCadenceDetector.php',
                true
            )
        );
    }
}
