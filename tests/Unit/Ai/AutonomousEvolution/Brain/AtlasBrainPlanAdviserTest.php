<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPlanAdviser;
use Tests\TestCase;

/**
 * FROZEN proof of the plan adviser — picks the top recommended finding via explicit priority.
 */
final class AtlasBrainPlanAdviserTest extends TestCase
{
    public function test_critical_finding_outranks_warn_and_info(): void
    {
        $r = (new AtlasBrainPlanAdviser)->advise([
            ['severity' => 'info', 'code' => 'frontier_empty', 'advice' => 'a'],
            ['severity' => 'critical', 'code' => 'gate_regression', 'advice' => 'b'],
            ['severity' => 'warn', 'code' => 'master_switch_off', 'advice' => 'c'],
        ]);
        self::assertSame('gate_regression', $r['recommended']['code']);
    }

    public function test_info_ordering_promotes_actionable_codes(): void
    {
        $r = (new AtlasBrainPlanAdviser)->advise([
            ['severity' => 'info', 'code' => 'brief_histogram_skewed', 'advice' => 'a'],
            ['severity' => 'info', 'code' => 'result_kind_starvation', 'advice' => 'b'],
            ['severity' => 'info', 'code' => 'frontier_empty', 'advice' => 'c'],
        ]);
        // starvation (90) > frontier_empty (75) > histogram_skewed (50)
        self::assertSame('result_kind_starvation', $r['recommended']['code']);
    }

    public function test_empty_findings_return_null_recommendation(): void
    {
        $r = (new AtlasBrainPlanAdviser)->advise([]);
        self::assertNull($r['recommended']);
        self::assertStringContainsString('healthy', $r['rationale']);
    }

    public function test_adviser_organ_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit(
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainPlanAdviser.php',
            true
        );
        self::assertSame('forbidden', $verdict);
    }
}
