<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainAuthorJudgeOverlapCheck;
use Tests\TestCase;

final class AtlasBrainAuthorJudgeOverlapCheckTest extends TestCase
{
    public function test_no_overlap_no_violation(): void
    {
        $r = (new AtlasBrainAuthorJudgeOverlapCheck)->check(['a.php', 'b.php'], ['c.php']);
        self::assertFalse($r['violation']);
        self::assertSame([], $r['overlap']);
    }

    public function test_overlap_flags_violation(): void
    {
        $r = (new AtlasBrainAuthorJudgeOverlapCheck)->check(['a.php', 'judge.php'], ['judge.php', 'other.php']);
        self::assertTrue($r['violation']);
        self::assertSame(['judge.php'], $r['overlap']);
    }

    public function test_duplicates_normalised(): void
    {
        $r = (new AtlasBrainAuthorJudgeOverlapCheck)->check(['a.php', 'a.php', 'a.php'], ['a.php']);
        self::assertTrue($r['violation']);
        self::assertSame(['a.php'], $r['overlap']);
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainAuthorJudgeOverlapCheck.php',
                true
            )
        );
    }
}
