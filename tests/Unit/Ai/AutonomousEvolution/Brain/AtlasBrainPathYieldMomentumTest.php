<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHintToPathTranslator;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPathYieldMomentum;
use Tests\TestCase;

final class AtlasBrainPathYieldMomentumTest extends TestCase
{
    private function rs(string $hint, string $kind): array
    {
        return ['action_hint' => $hint, 'result_kind' => $kind];
    }

    public function test_returns_empty_when_tail_too_small(): void
    {
        $r = (new AtlasBrainPathYieldMomentum)->compute(
            [$this->rs('harvest_frontier', 'accepted')],
            new AtlasBrainHintToPathTranslator,
        );
        self::assertSame([], $r['by_path']);
    }

    public function test_detects_improving_path(): void
    {
        $tail = array_merge(
            array_fill(0, 3, $this->rs('harvest_frontier', 'refused')),
            array_fill(0, 3, $this->rs('harvest_frontier', 'accepted')),
        );
        $r = (new AtlasBrainPathYieldMomentum)->compute($tail, new AtlasBrainHintToPathTranslator);
        self::assertSame('improving', $r['by_path']['frontier-harvest']['trend']);
        self::assertGreaterThan(0, $r['by_path']['frontier-harvest']['delta']);
    }

    public function test_detects_declining_path(): void
    {
        $tail = array_merge(
            array_fill(0, 3, $this->rs('compound', 'accepted')),
            array_fill(0, 3, $this->rs('compound', 'refused')),
        );
        $r = (new AtlasBrainPathYieldMomentum)->compute($tail, new AtlasBrainHintToPathTranslator);
        self::assertSame('declining', $r['by_path']['compounding']['trend']);
    }

    public function test_detects_flat_path(): void
    {
        $tail = array_merge(
            [$this->rs('use_drafted_candidate', 'accepted'), $this->rs('use_drafted_candidate', 'refused'), $this->rs('use_drafted_candidate', 'accepted')],
            [$this->rs('use_drafted_candidate', 'accepted'), $this->rs('use_drafted_candidate', 'refused'), $this->rs('use_drafted_candidate', 'accepted')],
        );
        $r = (new AtlasBrainPathYieldMomentum)->compute($tail, new AtlasBrainHintToPathTranslator);
        self::assertSame('flat', $r['by_path']['pattern-design']['trend']);
    }

    public function test_skips_unknown_hints(): void
    {
        $tail = array_fill(0, 6, $this->rs('totally_unknown_hint', 'accepted'));
        $r = (new AtlasBrainPathYieldMomentum)->compute($tail, new AtlasBrainHintToPathTranslator);
        self::assertSame([], $r['by_path']);
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainPathYieldMomentum.php',
                true
            )
        );
    }
}
