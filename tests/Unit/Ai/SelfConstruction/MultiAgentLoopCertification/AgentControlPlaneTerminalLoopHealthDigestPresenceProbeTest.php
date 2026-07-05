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

    // --- sectionStatusMap() ------------------------------------------

    public function test_section_status_map_returns_all_required_sections(): void
    {
        $map = AgentControlPlaneTerminalLoopHealthDigestPresenceProbe::sectionStatusMap();

        self::assertArrayHasKey('sections', $map);
        $requiredSections = [
            'fleet_launch_plan',
            'fleet_replenishment_plan',
            'fleet_resume_rollup',
            'fleet_evidence_rollup',
            'fleet_operator_handoff',
            'fleet_lane_isolation',
            'cycle_supervisor',
            'fleet_launch_runbook',
        ];
        foreach ($requiredSections as $section) {
            self::assertArrayHasKey($section, $map['sections'], "sectionStatusMap must include {$section}");
        }
    }

    public function test_section_status_map_each_section_has_required_fields(): void
    {
        $map = AgentControlPlaneTerminalLoopHealthDigestPresenceProbe::sectionStatusMap();

        foreach ($map['sections'] as $name => $section) {
            self::assertArrayHasKey('present', $section, "{$name} missing present");
            self::assertArrayHasKey('schema_ok', $section, "{$name} missing schema_ok");
            self::assertArrayHasKey('expected_schema', $section, "{$name} missing expected_schema");
            self::assertArrayHasKey('observed_schema', $section, "{$name} missing observed_schema");
            self::assertArrayHasKey('blocker', $section, "{$name} missing blocker");
            self::assertArrayHasKey('blocker_reason', $section, "{$name} missing blocker_reason");
            self::assertIsBool($section['present']);
            self::assertIsBool($section['schema_ok']);
            self::assertIsBool($section['blocker']);
        }
    }

    public function test_section_status_map_reports_blockers_for_absent_or_mismatched_sections(): void
    {
        $map = AgentControlPlaneTerminalLoopHealthDigestPresenceProbe::sectionStatusMap();

        self::assertArrayHasKey('blockers', $map);
        self::assertArrayHasKey('blocker_count', $map);
        self::assertArrayHasKey('all_present', $map);
        self::assertSame($map['blocker_count'], count($map['blockers']));
        self::assertSame($map['all_present'], $map['blocker_count'] === 0);
    }

    public function test_section_status_map_blocker_reasons_are_descriptive(): void
    {
        $map = AgentControlPlaneTerminalLoopHealthDigestPresenceProbe::sectionStatusMap();

        foreach ($map['sections'] as $name => $section) {
            if ($section['blocker']) {
                self::assertNotSame('', $section['blocker_reason'],
                    "{$name} is blocked but has empty blocker_reason");
                self::assertTrue(
                    str_contains($section['blocker_reason'], $name),
                    "blocker_reason for {$name} should contain the section name",
                );
            } else {
                self::assertSame('', $section['blocker_reason'],
                    "{$name} is not blocked but has a non-empty blocker_reason");
            }
        }
    }

    public function test_section_status_map_is_deterministic(): void
    {
        $a = AgentControlPlaneTerminalLoopHealthDigestPresenceProbe::sectionStatusMap();
        $b = AgentControlPlaneTerminalLoopHealthDigestPresenceProbe::sectionStatusMap();

        self::assertSame($a['blockers'], $b['blockers']);
        self::assertSame($a['blocker_count'], $b['blocker_count']);
        self::assertSame($a['all_present'], $b['all_present']);
    }

    public function test_section_status_map_schema_is_stable(): void
    {
        $map = AgentControlPlaneTerminalLoopHealthDigestPresenceProbe::sectionStatusMap();

        self::assertSame(
            'atlas.self_construction.terminal_loop_health_digest_presence_probe.section_status_map.v1',
            $map['schema'],
        );
    }
}
