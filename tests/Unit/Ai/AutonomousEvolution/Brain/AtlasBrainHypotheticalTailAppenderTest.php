<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHintToPathTranslator;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHypotheticalTailAppender;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPathDiversityScore;
use Tests\TestCase;

final class AtlasBrainHypotheticalTailAppenderTest extends TestCase
{
    public function test_appends_n_synthetic_reflections(): void
    {
        $real = [['action_hint' => 'compound', 'result_kind' => 'accepted']];
        $out = (new AtlasBrainHypotheticalTailAppender)->build($real, [
            ['hint' => 'harvest_frontier', 'kind' => 'refused', 'count' => 3],
        ]);
        self::assertCount(4, $out);
        self::assertTrue($out[3]['synthetic']);
        self::assertSame('harvest_frontier', $out[1]['action_hint']);
    }

    public function test_input_tail_not_mutated(): void
    {
        $real = [['action_hint' => 'compound', 'result_kind' => 'accepted']];
        $copy = $real;
        (new AtlasBrainHypotheticalTailAppender)->build($real, [['hint' => 'compound', 'kind' => 'refused', 'count' => 2]]);
        self::assertSame($copy, $real);
    }

    public function test_invalid_specs_skipped(): void
    {
        $out = (new AtlasBrainHypotheticalTailAppender)->build([], [
            ['hint' => '', 'kind' => 'refused', 'count' => 5],
            ['hint' => 'compound', 'kind' => '', 'count' => 5],
            ['hint' => 'compound', 'kind' => 'refused', 'count' => -3],
        ]);
        self::assertSame([], $out);
    }

    public function test_composes_with_diversity_score(): void
    {
        $real = array_fill(0, 4, ['action_hint' => 'compound', 'result_kind' => 'accepted']);
        $hypothetical = (new AtlasBrainHypotheticalTailAppender)->build($real, [
            ['hint' => 'harvest_frontier', 'kind' => 'accepted', 'count' => 4],
            ['hint' => 'gate_regression', 'kind' => 'accepted', 'count' => 4],
            ['hint' => 'use_drafted_candidate', 'kind' => 'accepted', 'count' => 4],
        ]);
        $score = (new AtlasBrainPathDiversityScore)->compute($hypothetical, new AtlasBrainHintToPathTranslator);
        self::assertSame('diverse', $score['status']);
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainHypotheticalTailAppender.php',
                true
            )
        );
    }
}
