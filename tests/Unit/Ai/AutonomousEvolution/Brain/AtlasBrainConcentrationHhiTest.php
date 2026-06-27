<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainConcentrationHhi;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHintToPathTranslator;
use Tests\TestCase;

final class AtlasBrainConcentrationHhiTest extends TestCase
{
    private function tail(array $hints): array
    {
        return array_map(static fn (string $h) => ['action_hint' => $h], $hints);
    }

    public function test_insufficient_samples(): void
    {
        $r = (new AtlasBrainConcentrationHhi)->compute(
            $this->tail(['compound']),
            new AtlasBrainHintToPathTranslator,
        );
        self::assertSame('insufficient_samples', $r['status']);
    }

    public function test_monopoly_hhi_is_one(): void
    {
        $r = (new AtlasBrainConcentrationHhi)->compute(
            $this->tail(array_fill(0, 6, 'compound')),
            new AtlasBrainHintToPathTranslator,
        );
        self::assertSame(1.0, $r['hhi']);
        self::assertSame('concentrated', $r['status']);
    }

    public function test_uniform_hhi_equals_1_over_n(): void
    {
        $r = (new AtlasBrainConcentrationHhi)->compute(
            $this->tail(['compound', 'harvest_frontier', 'use_drafted_candidate', 'gate_regression']),
            new AtlasBrainHintToPathTranslator,
        );
        // 4 paths, 0.25 each → HHI = 0.0625 * 4 = 0.25
        self::assertSame(0.25, $r['hhi']);
        self::assertSame('moderate', $r['status']);
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainConcentrationHhi.php',
                true
            )
        );
    }
}
