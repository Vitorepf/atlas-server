<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainReflectionProvenanceChain;
use Tests\TestCase;

final class AtlasBrainReflectionProvenanceChainTest extends TestCase
{
    public function test_walks_chain_to_root(): void
    {
        $tail = [
            ['cycle_id' => 'a', 'parent_cycle_id' => null],
            ['cycle_id' => 'b', 'parent_cycle_id' => 'a'],
            ['cycle_id' => 'c', 'parent_cycle_id' => 'b'],
        ];
        $r = (new AtlasBrainReflectionProvenanceChain)->chain($tail, 'c');
        self::assertSame(['a', 'b', 'c'], $r['chain']);
        self::assertFalse($r['truncated']);
    }

    public function test_missing_target_returns_single_entry(): void
    {
        $tail = [['cycle_id' => 'a', 'parent_cycle_id' => null]];
        $r = (new AtlasBrainReflectionProvenanceChain)->chain($tail, 'unknown');
        self::assertSame(['unknown'], $r['chain']);
    }

    public function test_cycle_loop_is_safely_broken(): void
    {
        $tail = [
            ['cycle_id' => 'a', 'parent_cycle_id' => 'b'],
            ['cycle_id' => 'b', 'parent_cycle_id' => 'a'],
        ];
        $r = (new AtlasBrainReflectionProvenanceChain)->chain($tail, 'a');
        self::assertSame(['b', 'a'], $r['chain']);
        self::assertFalse($r['truncated']);
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainReflectionProvenanceChain.php',
                true
            )
        );
    }
}
