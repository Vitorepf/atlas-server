<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\Readiness\ReadinessRuntimePilotInputFixtures;
use Tests\TestCase;

class ReadinessRuntimePilotInputFixturesTest extends TestCase
{
    public function test_default_runtime_pilot_input_has_required_keys(): void
    {
        $input = ReadinessRuntimePilotInputFixtures::defaultRuntimePilotInput();

        foreach ([
            'objective', 'source', 'operator_id', 'parent_run_id',
            'allowed_files', 'forbidden_files', 'scope_in',
            'acceptance_criteria', 'required_evidence',
            'risk_level', 'max_runtime_seconds', 'max_token_budget',
            'workspace_policy', 'continuation_context',
        ] as $key) {
            self::assertArrayHasKey($key, $input, "defaultRuntimePilotInput must have key: {$key}");
        }
    }

    public function test_default_runtime_pilot_input_is_primary_run(): void
    {
        $input = ReadinessRuntimePilotInputFixtures::defaultRuntimePilotInput();

        self::assertSame('AGENT-CONTROL-PLANE-RUNTIME-PILOT-0001', $input['parent_run_id']);
        self::assertSame('operator-runtime-pilot', $input['operator_id']);
        self::assertSame('simulated_worktree', $input['workspace_policy']['isolation']);
        self::assertSame(0, $input['max_token_budget']);
    }

    public function test_default_runtime_pilot_input_secondary_has_distinct_run_id(): void
    {
        $primary = ReadinessRuntimePilotInputFixtures::defaultRuntimePilotInput();
        $secondary = ReadinessRuntimePilotInputFixtures::defaultRuntimePilotInputSecondary();

        self::assertNotSame($primary['parent_run_id'], $secondary['parent_run_id']);
        self::assertSame('AGENT-CONTROL-PLANE-RUNTIME-PILOT-0002', $secondary['parent_run_id']);
        self::assertSame('operator-runtime-pilot-secondary', $secondary['operator_id']);
    }

    public function test_secondary_input_has_no_continuation_context(): void
    {
        $secondary = ReadinessRuntimePilotInputFixtures::defaultRuntimePilotInputSecondary();

        self::assertArrayNotHasKey('continuation_context', $secondary);
    }

    public function test_default_agent_runtime_registry_task_packet_shape(): void
    {
        $packet = ReadinessRuntimePilotInputFixtures::defaultAgentRuntimeRegistryTaskPacket();

        self::assertSame('AGENT-CONTROL-PLANE-REGISTRY-PROBE-0001', $packet['task_packet_id']);
        self::assertSame('Agent Runtime Registry orchestrator readiness projection', $packet['objective']);
        self::assertSame('low', $packet['risk_level']);
        self::assertSame('none', $packet['workspace_policy']);
        self::assertFalse($packet['requires_lease']);
        self::assertSame([], $packet['required_capabilities']);
    }

    public function test_registry_packet_differs_from_pilot_input(): void
    {
        $packet = ReadinessRuntimePilotInputFixtures::defaultAgentRuntimeRegistryTaskPacket();
        $pilot = ReadinessRuntimePilotInputFixtures::defaultRuntimePilotInput();

        self::assertNotSame($packet['task_packet_id'], $pilot['parent_run_id']);
    }

    public function test_fixtures_are_deterministic(): void
    {
        $a1 = ReadinessRuntimePilotInputFixtures::defaultRuntimePilotInput();
        $a2 = ReadinessRuntimePilotInputFixtures::defaultRuntimePilotInput();
        $b1 = ReadinessRuntimePilotInputFixtures::defaultRuntimePilotInputSecondary();
        $b2 = ReadinessRuntimePilotInputFixtures::defaultRuntimePilotInputSecondary();
        $c1 = ReadinessRuntimePilotInputFixtures::defaultAgentRuntimeRegistryTaskPacket();
        $c2 = ReadinessRuntimePilotInputFixtures::defaultAgentRuntimeRegistryTaskPacket();

        self::assertSame($a1, $a2);
        self::assertSame($b1, $b2);
        self::assertSame($c1, $c2);
    }

    public function test_pilot_inputs_declare_forbidden_routes(): void
    {
        $primary = ReadinessRuntimePilotInputFixtures::defaultRuntimePilotInput();
        $secondary = ReadinessRuntimePilotInputFixtures::defaultRuntimePilotInputSecondary();

        self::assertContains('routes/api.php', $primary['forbidden_files']);
        self::assertContains('routes/api.php', $secondary['forbidden_files']);
    }
}
