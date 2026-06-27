<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainCriticalConsensusGate;
use Tests\TestCase;

final class AtlasBrainCriticalConsensusGateTest extends TestCase
{
    public function test_empty_abstains(): void
    {
        $r = (new AtlasBrainCriticalConsensusGate)->decide([]);
        self::assertSame('abstain', $r['verdict']);
    }

    public function test_majority_supports(): void
    {
        $r = (new AtlasBrainCriticalConsensusGate)->decide(['a' => true, 'b' => true, 'c' => false]);
        self::assertSame('supports', $r['verdict']);
        self::assertSame(['c'], $r['dissent']);
    }

    public function test_majority_refutes(): void
    {
        $r = (new AtlasBrainCriticalConsensusGate)->decide(['a' => false, 'b' => false, 'c' => true]);
        self::assertSame('refutes', $r['verdict']);
        self::assertSame(['c'], $r['dissent']);
    }

    public function test_tie_abstains(): void
    {
        $r = (new AtlasBrainCriticalConsensusGate)->decide(['a' => true, 'b' => false]);
        self::assertSame('abstain', $r['verdict']);
        self::assertSame([], $r['dissent']);
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainCriticalConsensusGate.php',
                true
            )
        );
    }
}
