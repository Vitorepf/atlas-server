<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainComposedHealthGrade;
use Tests\TestCase;

final class AtlasBrainComposedHealthGradeTest extends TestCase
{
    public function test_perfect_score_grade_a(): void
    {
        $r = (new AtlasBrainComposedHealthGrade)->grade(1.0, 1.0, 6);
        self::assertSame(100, $r['score']);
        self::assertSame('A', $r['letter']);
    }

    public function test_zero_grade_f(): void
    {
        $r = (new AtlasBrainComposedHealthGrade)->grade(0.0, 0.0, 0);
        self::assertSame(0, $r['score']);
        self::assertSame('F', $r['letter']);
    }

    public function test_intermediate_grade(): void
    {
        // 0.6 div + 0.6 cal + 3 cov → 0.4*60 + 0.4*60 + 0.2*50 = 24+24+10 = 58 → C
        $r = (new AtlasBrainComposedHealthGrade)->grade(0.6, 0.6, 3);
        self::assertSame('C', $r['letter']);
    }

    public function test_coverage_clamps_at_6(): void
    {
        $r1 = (new AtlasBrainComposedHealthGrade)->grade(0.0, 0.0, 6);
        $r2 = (new AtlasBrainComposedHealthGrade)->grade(0.0, 0.0, 100);
        self::assertSame($r1['score'], $r2['score']);
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainComposedHealthGrade.php',
                true
            )
        );
    }
}
