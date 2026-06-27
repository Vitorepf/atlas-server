<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHintEntropy;
use Tests\TestCase;

/**
 * FROZEN proof of the hint entropy organ — Shannon bits over the action_hint distribution.
 */
final class AtlasBrainHintEntropyTest extends TestCase
{
    public function test_uniform_two_hint_distribution_yields_1_bit_and_normalized_1(): void
    {
        $e = (new AtlasBrainHintEntropy)->compute([
            'by_hint' => [
                ['hint' => 'A', 'count' => 5],
                ['hint' => 'B', 'count' => 5],
            ],
        ]);

        self::assertSame(10, $e['total']);
        self::assertSame(2, $e['alphabet']);
        self::assertSame(1.0, $e['bits']);
        self::assertSame(1.0, $e['normalized']);
    }

    public function test_single_hint_yields_zero_entropy(): void
    {
        $e = (new AtlasBrainHintEntropy)->compute([
            'by_hint' => [
                ['hint' => 'A', 'count' => 10],
            ],
        ]);

        self::assertSame(10, $e['total']);
        self::assertSame(1, $e['alphabet']);
        self::assertSame(0.0, $e['bits']);
        self::assertSame(0.0, $e['normalized']);
    }

    public function test_empty_input_returns_zeroes(): void
    {
        $e = (new AtlasBrainHintEntropy)->compute([]);

        self::assertSame(0, $e['total']);
        self::assertSame(0, $e['alphabet']);
        self::assertSame(0.0, $e['bits']);
        self::assertSame(0.0, $e['normalized']);
    }

    public function test_entropy_organ_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit(
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainHintEntropy.php',
            true
        );
        self::assertSame('forbidden', $verdict);
    }
}
