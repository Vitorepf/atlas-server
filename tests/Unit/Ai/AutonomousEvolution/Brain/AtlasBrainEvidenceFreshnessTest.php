<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainEvidenceFreshness;
use Tests\TestCase;

/**
 * FROZEN proof of the evidence freshness organ — age of newest reflection row.
 */
final class AtlasBrainEvidenceFreshnessTest extends TestCase
{
    public function test_age_seconds_is_now_minus_newest_recorded_at(): void
    {
        $now = 1_000_000;
        $rows = [
            ['recorded_at' => $now - 100],
            ['recorded_at' => $now - 50],
            ['recorded_at' => $now - 200],
        ];
        $r = (new AtlasBrainEvidenceFreshness)->inspect($rows, $now);
        self::assertTrue($r['has_evidence']);
        self::assertSame($now - 50, $r['newest_recorded_at']);
        self::assertSame(50, $r['age_seconds']);
    }

    public function test_empty_rows_reports_no_evidence(): void
    {
        $r = (new AtlasBrainEvidenceFreshness)->inspect([], 0);
        self::assertFalse($r['has_evidence']);
        self::assertNull($r['newest_recorded_at']);
        self::assertNull($r['age_seconds']);
    }

    public function test_freshness_organ_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit(
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainEvidenceFreshness.php',
            true
        );
        self::assertSame('forbidden', $verdict);
    }
}
