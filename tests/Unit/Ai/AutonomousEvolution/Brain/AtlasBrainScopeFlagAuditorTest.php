<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainScopeFlagAuditor;
use Tests\TestCase;

final class AtlasBrainScopeFlagAuditorTest extends TestCase
{
    public function test_no_oddities_when_all_consistent(): void
    {
        $a = new AtlasBrainScopeFlagAuditor;
        self::assertSame([], $a->audit(true, true, true, false)['oddities']);
        self::assertSame([], $a->audit(false, false, false, false)['oddities']);
    }

    public function test_recording_without_digesting_flagged(): void
    {
        $a = (new AtlasBrainScopeFlagAuditor)->audit(true, true, false, false);
        $codes = array_column($a['oddities'], 'code');
        self::assertContains('recording_without_digesting', $codes);
    }

    public function test_master_on_reflection_off_flagged(): void
    {
        $a = (new AtlasBrainScopeFlagAuditor)->audit(true, false, true, false);
        $codes = array_column($a['oddities'], 'code');
        self::assertContains('master_on_reflection_off', $codes);
    }

    public function test_auditor_organ_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit(
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainScopeFlagAuditor.php',
            true
        );
        self::assertSame('forbidden', $verdict);
    }
}
