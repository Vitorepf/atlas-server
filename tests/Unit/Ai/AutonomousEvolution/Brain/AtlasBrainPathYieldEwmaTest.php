<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHintToPathTranslator;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPathYieldEwma;
use Tests\TestCase;

final class AtlasBrainPathYieldEwmaTest extends TestCase
{
    private function rs(string $hint, string $kind): array
    {
        return ['action_hint' => $hint, 'result_kind' => $kind];
    }

    public function test_all_accepted_yields_one(): void
    {
        $tail = array_fill(0, 8, $this->rs('compound', 'accepted'));
        $r = (new AtlasBrainPathYieldEwma)->compute($tail, new AtlasBrainHintToPathTranslator);
        self::assertSame(1.0, $r['by_path']['compounding']['ewma']);
        self::assertSame(8, $r['by_path']['compounding']['samples']);
    }

    public function test_recent_samples_weigh_more(): void
    {
        // mostly refused early, then accepted — EWMA should be higher than naive avg
        $tail = array_merge(
            array_fill(0, 5, $this->rs('harvest_frontier', 'refused')),
            array_fill(0, 3, $this->rs('harvest_frontier', 'accepted')),
        );
        $r = (new AtlasBrainPathYieldEwma)->compute($tail, new AtlasBrainHintToPathTranslator, 0.5);
        // naive avg = 3/8 = 0.375; EWMA with alpha=0.5 emphasizes recent → expect > 0.375
        self::assertGreaterThan(0.375, $r['by_path']['frontier-harvest']['ewma']);
    }

    public function test_alpha_clamped(): void
    {
        $tail = array_fill(0, 4, $this->rs('compound', 'accepted'));
        $r = (new AtlasBrainPathYieldEwma)->compute($tail, new AtlasBrainHintToPathTranslator, 2.0);
        self::assertSame(1.0, $r['alpha']);
    }

    public function test_unknown_hints_skipped(): void
    {
        $tail = array_fill(0, 5, $this->rs('totally_unknown', 'accepted'));
        $r = (new AtlasBrainPathYieldEwma)->compute($tail, new AtlasBrainHintToPathTranslator);
        self::assertSame([], $r['by_path']);
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainPathYieldEwma.php',
                true
            )
        );
    }
}
