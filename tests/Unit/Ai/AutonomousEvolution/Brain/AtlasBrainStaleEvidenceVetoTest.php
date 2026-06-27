<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainStaleEvidenceVeto;
use Tests\TestCase;

final class AtlasBrainStaleEvidenceVetoTest extends TestCase
{
    public function test_fresh_evidence_accepted(): void
    {
        $r = (new AtlasBrainStaleEvidenceVeto)->review(60, 3600);
        self::assertSame('accept', $r['verdict']);
    }

    public function test_stale_evidence_vetoed(): void
    {
        $r = (new AtlasBrainStaleEvidenceVeto)->review(7200, 3600);
        self::assertSame('veto', $r['verdict']);
    }

    public function test_boundary_age_accepted(): void
    {
        $r = (new AtlasBrainStaleEvidenceVeto)->review(3600, 3600);
        self::assertSame('accept', $r['verdict']);
    }

    public function test_negative_age_treated_as_future_dated(): void
    {
        $r = (new AtlasBrainStaleEvidenceVeto)->review(-60);
        self::assertSame('accept', $r['verdict']);
        self::assertSame('future_dated_skipped', $r['reason']);
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainStaleEvidenceVeto.php',
                true
            )
        );
    }
}
