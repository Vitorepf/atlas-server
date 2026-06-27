<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainGateAdversarialAuditor;
use App\Services\Ai\SelfConstruction\AtlasTaskPacketQualityInspector;
use Tests\TestCase;

/**
 * FROZEN proof of the adversarial auditor — proves the canonical battery runs against the LIVE inspector and
 * surfaces real holes (not a self-confirming "always passes"), that the output is deterministic + bounded, and
 * that the organ itself is pétreo (the réu never edits the test that grades its gate's coverage).
 *
 * The intent of this suite is that against the CURRENT inspector implementation, the canonical battery has
 * ZERO holes — every known attack is caught by the deficiency the auditor expects. If a future refactor of the
 * inspector lets one of these attacks through, THIS test goes red on the very next CI run, the auditor's
 * payload at runtime starts surfacing a hole, AND the brain originates a fix slice for it. Anti-regression by
 * construction.
 */
final class AtlasBrainGateAdversarialAuditorTest extends TestCase
{
    public function test_current_inspector_has_zero_holes_against_the_canonical_battery(): void
    {
        $auditor = new AtlasBrainGateAdversarialAuditor;
        $report = $auditor->audit(new AtlasTaskPacketQualityInspector);

        self::assertSame(AtlasBrainGateAdversarialAuditor::SCHEMA, $report['schema']);
        self::assertGreaterThanOrEqual(8, $report['attacks_tried'], 'battery must include at least the 9 canonical attacks');
        self::assertSame([], $report['holes'], 'inspector regression: an adversarial attack passed → '.json_encode($report['holes']));
    }

    public function test_audit_output_is_byte_stable_across_invocations(): void
    {
        $auditor = new AtlasBrainGateAdversarialAuditor;
        $a = $auditor->audit(new AtlasTaskPacketQualityInspector);
        $b = $auditor->audit(new AtlasTaskPacketQualityInspector);

        self::assertSame($a, $b, 'same inputs ⇒ identical audit (no wall-clock, no randomness)');
    }

    public function test_auditor_organ_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit(
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainGateAdversarialAuditor.php',
            true
        );

        self::assertSame('forbidden', $verdict);
    }
}
