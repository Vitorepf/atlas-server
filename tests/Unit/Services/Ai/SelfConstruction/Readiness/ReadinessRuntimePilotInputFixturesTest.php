<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\Readiness\ReadinessRuntimePilotInputFixtures;
use Tests\TestCase;

final class ReadinessRuntimePilotInputFixturesTest extends TestCase
{
    public function test_registry_task_packet_has_scalar_workspace_policy_and_no_lease_requirement(): void
    {
        $packet = ReadinessRuntimePilotInputFixtures::defaultAgentRuntimeRegistryTaskPacket();

        self::assertIsString($packet['workspace_policy']);
        self::assertSame('none', $packet['workspace_policy']);
        self::assertFalse($packet['requires_lease']);
        self::assertSame('low', $packet['risk_level']);
    }

    public function test_primary_runtime_pilot_input_has_non_empty_required_facts(): void
    {
        $input = ReadinessRuntimePilotInputFixtures::defaultRuntimePilotInput();

        self::assertNotSame('', $input['objective']);
        self::assertNotSame('', $input['source']);
        self::assertNotSame('', $input['operator_id']);
        self::assertNotEmpty($input['allowed_files']);
        self::assertNotEmpty($input['scope_in']);
        self::assertNotEmpty($input['acceptance_criteria']);
        self::assertNotEmpty($input['required_evidence']);
    }

    public function test_secondary_runtime_pilot_input_has_non_empty_required_facts(): void
    {
        $input = ReadinessRuntimePilotInputFixtures::defaultRuntimePilotInputSecondary();

        self::assertNotSame('', $input['objective']);
        self::assertNotSame('', $input['source']);
        self::assertNotSame('', $input['operator_id']);
        self::assertNotEmpty($input['allowed_files']);
        self::assertNotEmpty($input['scope_in']);
        self::assertNotEmpty($input['acceptance_criteria']);
        self::assertNotEmpty($input['required_evidence']);
    }

    public function test_both_runtime_pilot_inputs_request_zero_token_budget_and_low_risk(): void
    {
        foreach ([
            ReadinessRuntimePilotInputFixtures::defaultRuntimePilotInput(),
            ReadinessRuntimePilotInputFixtures::defaultRuntimePilotInputSecondary(),
        ] as $input) {
            self::assertSame(0, $input['max_token_budget']);
            self::assertSame('low', $input['risk_level']);
        }
    }

    public function test_both_runtime_pilot_inputs_forbid_routes_api_php(): void
    {
        foreach ([
            ReadinessRuntimePilotInputFixtures::defaultRuntimePilotInput(),
            ReadinessRuntimePilotInputFixtures::defaultRuntimePilotInputSecondary(),
        ] as $input) {
            self::assertContains('routes/api.php', $input['forbidden_files']);
        }
    }

    public function test_fixtures_are_pure_data_with_no_side_effect_calls(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/SelfConstruction/Readiness/ReadinessRuntimePilotInputFixtures.php'));
        foreach (['exec(', 'shell_exec', 'proc_open', 'Http::', 'DB::', 'Storage::', 'Artisan::', '`git '] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $src, "fixtures must not contain {$forbidden}");
        }
    }

    public function test_fixture_calls_are_deterministic_across_invocations(): void
    {
        self::assertSame(
            ReadinessRuntimePilotInputFixtures::defaultRuntimePilotInput(),
            ReadinessRuntimePilotInputFixtures::defaultRuntimePilotInput(),
        );
        self::assertSame(
            ReadinessRuntimePilotInputFixtures::defaultRuntimePilotInputSecondary(),
            ReadinessRuntimePilotInputFixtures::defaultRuntimePilotInputSecondary(),
        );
        self::assertSame(
            ReadinessRuntimePilotInputFixtures::defaultAgentRuntimeRegistryTaskPacket(),
            ReadinessRuntimePilotInputFixtures::defaultAgentRuntimeRegistryTaskPacket(),
        );
    }
}
