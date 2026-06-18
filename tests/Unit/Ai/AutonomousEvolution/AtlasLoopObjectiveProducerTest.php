<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopObjectiveProducer;
use Tests\TestCase;

/**
 * Freezes the producer's PURE selection: pick the biggest verifiable leap that clears the
 * ambition floor, skip dreams (non-verifiable), and emit NOTHING when only trivia exists
 * (the loop never gets a faxina task from the rédea).
 */
class AtlasLoopObjectiveProducerTest extends TestCase
{
    private function producer(): AtlasLoopObjectiveProducer
    {
        return new AtlasLoopObjectiveProducer;
    }

    public function test_selects_the_highest_leverage_verifiable_leap(): void
    {
        $winner = $this->producer()->select([
            ['path' => 'trivial', 'caller_count' => 1, 'cyclomatic' => 1, 'verifiable' => true],
            ['path' => 'big-hub', 'caller_count' => 20, 'cyclomatic' => 30, 'strategic_impact' => 1.0, 'cost' => 1.0, 'risk' => 1.0, 'verifiable' => true],
        ]);

        $this->assertNotNull($winner);
        $this->assertSame('big-hub', $winner['path']);
        $this->assertGreaterThanOrEqual(0.6, $winner['_score']['leverage']);
    }

    public function test_emits_nothing_when_only_trivia_exists(): void
    {
        $winner = $this->producer()->select([
            ['path' => 'tiny-a', 'caller_count' => 1, 'cyclomatic' => 2, 'verifiable' => true],
            ['path' => 'tiny-b', 'caller_count' => 0, 'cyclomatic' => 1, 'verifiable' => true],
        ]);

        $this->assertNull($winner);
    }

    public function test_skips_a_higher_leverage_dream_for_a_verifiable_leap(): void
    {
        // 'dream' scores higher (cheaper) but is NOT verifiable → rejected; the verifiable hub wins.
        $winner = $this->producer()->select([
            ['path' => 'dream', 'caller_count' => 20, 'cyclomatic' => 30, 'strategic_impact' => 1.0, 'cost' => 0.2, 'risk' => 0.2, 'verifiable' => false],
            ['path' => 'real', 'caller_count' => 20, 'cyclomatic' => 30, 'strategic_impact' => 1.0, 'cost' => 1.0, 'risk' => 1.0, 'verifiable' => true],
        ]);

        $this->assertNotNull($winner);
        $this->assertSame('real', $winner['path']);
    }
}
