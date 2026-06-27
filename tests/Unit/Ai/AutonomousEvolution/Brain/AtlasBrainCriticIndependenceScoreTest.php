<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainCriticIndependenceScore;
use Tests\TestCase;

final class AtlasBrainCriticIndependenceScoreTest extends TestCase
{
    public function test_identical_critics_flagged_non_independent(): void
    {
        $findings = array_fill(0, 6, ['a' => true, 'b' => true]);
        $r = (new AtlasBrainCriticIndependenceScore)->score($findings);
        self::assertCount(1, $r['pairs']);
        self::assertSame('non_independent', $r['pairs'][0]['label']);
        self::assertSame(1.0, $r['pairs'][0]['agreement_rate']);
    }

    public function test_diverse_critics_ok(): void
    {
        $findings = [
            ['a' => true, 'b' => false],
            ['a' => false, 'b' => true],
            ['a' => true, 'b' => false],
            ['a' => false, 'b' => true],
            ['a' => true, 'b' => false],
        ];
        $r = (new AtlasBrainCriticIndependenceScore)->score($findings);
        self::assertSame('ok', $r['pairs'][0]['label']);
    }

    public function test_below_min_shared_skipped(): void
    {
        $r = (new AtlasBrainCriticIndependenceScore)->score(array_fill(0, 3, ['a' => true, 'b' => true]));
        self::assertSame([], $r['pairs']);
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainCriticIndependenceScore.php',
                true
            )
        );
    }
}
