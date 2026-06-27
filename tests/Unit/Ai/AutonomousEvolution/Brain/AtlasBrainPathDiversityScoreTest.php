<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHintToPathTranslator;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPathDiversityScore;
use Tests\TestCase;

final class AtlasBrainPathDiversityScoreTest extends TestCase
{
    private function tail(array $hints): array
    {
        return array_map(static fn (string $h) => ['action_hint' => $h], $hints);
    }

    public function test_insufficient_samples_returns_zero(): void
    {
        $r = (new AtlasBrainPathDiversityScore)->compute($this->tail(['harvest_frontier']), new AtlasBrainHintToPathTranslator);
        self::assertSame('insufficient_samples', $r['status']);
        self::assertSame(0.0, $r['score']);
    }

    public function test_single_path_is_concentrated(): void
    {
        $r = (new AtlasBrainPathDiversityScore)->compute(
            $this->tail(array_fill(0, 6, 'harvest_frontier')),
            new AtlasBrainHintToPathTranslator,
        );
        self::assertSame('concentrated', $r['status']);
        self::assertSame(0.0, $r['score']);
    }

    public function test_uniform_distribution_scores_one(): void
    {
        $r = (new AtlasBrainPathDiversityScore)->compute(
            $this->tail(['harvest_frontier', 'compound', 'use_drafted_candidate', 'gate_regression']),
            new AtlasBrainHintToPathTranslator,
        );
        self::assertSame(1.0, $r['score']);
        self::assertSame('diverse', $r['status']);
        self::assertSame(4, $r['observed_paths']);
    }

    public function test_skewed_distribution_below_diverse_threshold(): void
    {
        // 7 harvest + 1 compound: heavily skewed
        $r = (new AtlasBrainPathDiversityScore)->compute(
            $this->tail(array_merge(array_fill(0, 7, 'harvest_frontier'), ['compound'])),
            new AtlasBrainHintToPathTranslator,
        );
        self::assertLessThan(0.75, $r['score']);
        self::assertNotSame('diverse', $r['status']);
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainPathDiversityScore.php',
                true
            )
        );
    }
}
