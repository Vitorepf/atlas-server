<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\MultiAgentLoopCertification;

use App\Services\Ai\SelfConstruction\MultiAgentLoopCertification\AgentControlPlaneTerminalLoopHealthDigestPresenceProbe;
use Tests\TestCase;

class AgentControlPlaneTerminalLoopHealthDigestPresenceProbeTest extends TestCase
{
    /**
     * Each predicate calls the live digest service and returns a boolean.
     * We verify the method exists, executes without error, and returns bool.
     * The full surface of non-execution guarantees is tested by the digest
     * service's own suite; here we verify the wiring is correct.
     */
    public function test_fleet_launch_plan_present_returns_bool(): void
    {
        self::assertIsBool(AgentControlPlaneTerminalLoopHealthDigestPresenceProbe::fleetLaunchPlanPresent());
    }

    public function test_fleet_replenishment_plan_present_returns_bool(): void
    {
        self::assertIsBool(AgentControlPlaneTerminalLoopHealthDigestPresenceProbe::fleetReplenishmentPlanPresent());
    }

    public function test_fleet_resume_rollup_present_returns_bool(): void
    {
        self::assertIsBool(AgentControlPlaneTerminalLoopHealthDigestPresenceProbe::fleetResumeRollupPresent());
    }

    public function test_fleet_evidence_rollup_present_returns_bool(): void
    {
        self::assertIsBool(AgentControlPlaneTerminalLoopHealthDigestPresenceProbe::fleetEvidenceRollupPresent());
    }

    public function test_fleet_operator_handoff_present_returns_bool(): void
    {
        self::assertIsBool(AgentControlPlaneTerminalLoopHealthDigestPresenceProbe::fleetOperatorHandoffPresent());
    }

    public function test_fleet_lane_isolation_present_returns_bool(): void
    {
        self::assertIsBool(AgentControlPlaneTerminalLoopHealthDigestPresenceProbe::fleetLaneIsolationPresent());
    }

    public function test_cycle_supervisor_present_returns_bool(): void
    {
        self::assertIsBool(AgentControlPlaneTerminalLoopHealthDigestPresenceProbe::cycleSupervisorPresent());
    }

    public function test_fleet_launch_runbook_present_returns_bool(): void
    {
        self::assertIsBool(AgentControlPlaneTerminalLoopHealthDigestPresenceProbe::fleetLaunchRunbookPresent());
    }

    public function test_all_predicates_are_deterministic(): void
    {
        // Each predicate calls a fresh digest — results should be stable
        $a = AgentControlPlaneTerminalLoopHealthDigestPresenceProbe::fleetLaunchPlanPresent();
        $b = AgentControlPlaneTerminalLoopHealthDigestPresenceProbe::fleetLaunchPlanPresent();

        self::assertSame($a, $b);
    }
}
