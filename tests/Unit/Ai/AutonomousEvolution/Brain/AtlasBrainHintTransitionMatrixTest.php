<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHintTransitionMatrix;
use Tests\TestCase;

/**
 * FROZEN proof of the hint transition matrix — markov-style adjacent-pair counter over the action_hint
 * time series. Pétreo organ.
 */
final class AtlasBrainHintTransitionMatrixTest extends TestCase
{
    public function test_build_counts_adjacent_pairs_and_flags_self_loops(): void
    {
        // Sequence (oldest → newest): A, A, B, A, B  ⇒ transitions:
        //   A→A (1), A→B (2), B→A (1) ⇒ self_loop_count=1, transitions=4.
        $rows = [
            ['signals' => ['action_hint' => 'A']],
            ['signals' => ['action_hint' => 'A']],
            ['signals' => ['action_hint' => 'B']],
            ['signals' => ['action_hint' => 'A']],
            ['signals' => ['action_hint' => 'B']],
        ];
        $m = (new AtlasBrainHintTransitionMatrix)->build($rows);

        self::assertSame(4, $m['transitions']);
        self::assertSame(1, $m['self_loop_count']);
        // by_pair sorted desc by count, then from asc, then to asc — A→B (2) first.
        self::assertSame(['from' => 'A', 'to' => 'B', 'count' => 2], $m['by_pair'][0]);
    }

    public function test_build_parses_hint_from_reflection_text_when_signals_missing(): void
    {
        $rows = [
            ['reflection' => 'leverage_brief: rotate_path — rationale'],
            ['reflection' => 'leverage_brief: harvest_frontier — r2'],
        ];
        $m = (new AtlasBrainHintTransitionMatrix)->build($rows);
        self::assertSame(1, $m['transitions']);
        self::assertSame(['from' => 'rotate_path', 'to' => 'harvest_frontier', 'count' => 1], $m['by_pair'][0]);
    }

    public function test_build_ignores_non_brief_reflections(): void
    {
        $rows = [
            ['reflection' => 'just a note, no leverage_brief prefix'],
            ['signals' => ['action_hint' => 'rotate_path']],
        ];
        $m = (new AtlasBrainHintTransitionMatrix)->build($rows);
        self::assertSame(0, $m['transitions']);
    }

    public function test_matrix_organ_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit(
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainHintTransitionMatrix.php',
            true
        );
        self::assertSame('forbidden', $verdict);
    }
}
