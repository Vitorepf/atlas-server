<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHintToPathTranslator;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPathCadenceVariance;
use Tests\TestCase;

final class AtlasBrainPathCadenceVarianceTest extends TestCase
{
    private function tail(array $hints): array
    {
        return array_map(static fn (string $h) => ['action_hint' => $h], $hints);
    }

    public function test_regular_cadence_zero_variance(): void
    {
        // compound every 3 cycles
        $tail = $this->tail(['compound', 'x', 'x', 'compound', 'x', 'x', 'compound', 'x', 'x', 'compound']);
        $r = (new AtlasBrainPathCadenceVariance)->compute($tail, new AtlasBrainHintToPathTranslator);
        self::assertSame(0.0, $r['by_path']['compounding']['variance']);
    }

    public function test_irregular_cadence_high_variance(): void
    {
        // compound at 0,1,10,11 → gaps 1,9,1 → high variance
        $tail = $this->tail(['compound', 'compound', 'x', 'x', 'x', 'x', 'x', 'x', 'x', 'x', 'compound', 'compound']);
        $r = (new AtlasBrainPathCadenceVariance)->compute($tail, new AtlasBrainHintToPathTranslator);
        self::assertGreaterThan(5.0, $r['by_path']['compounding']['variance']);
    }

    public function test_insufficient_picks_zero(): void
    {
        $r = (new AtlasBrainPathCadenceVariance)->compute($this->tail(['compound']), new AtlasBrainHintToPathTranslator);
        self::assertSame(0.0, $r['by_path']['compounding']['variance']);
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainPathCadenceVariance.php',
                true
            )
        );
    }
}
