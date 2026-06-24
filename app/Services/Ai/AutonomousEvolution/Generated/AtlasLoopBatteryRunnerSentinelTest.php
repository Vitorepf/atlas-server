<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Generated;

final class AtlasLoopBatteryRunnerSentinelTest
{
    public const SCHEMA_VERSION = 'atlas.loop.battery_runner_sentinel_test.v1';

    public const MODE = 'read_only_generated_docgap_capability';

    /** @return array<string, mixed> */
    public function describe(): array
    {
        $assertions = [
            'property_gated_self_edit_requires_green_battery_runner',
            'subprocess_crash_rejects_fail_closed',
            'subprocess_timeout_rejects_fail_closed',
            'missing_verdict_rejects_fail_closed',
            'malformed_json_rejects_fail_closed',
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => 'available',
            'canonical_test_path' => 'tests/Feature/Loop/Constitution/AtlasLoopBatteryRunnerSentinelTest.php',
            'generated_scope_path' => 'app/Services/Ai/AutonomousEvolution/Generated/AtlasLoopBatteryRunnerSentinelTest.php',
            'assertions' => $assertions,
            'assertion_count' => count($assertions),
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'workspace_mutation_allowed' => false,
        ];
    }

    /**
     * @param  array<int, string>  $observedAssertions
     * @return array<string, mixed>
     */
    public function evaluate(array $observedAssertions): array
    {
        $contract = $this->describe();
        $required = (array) $contract['assertions'];
        $observed = array_values(array_unique(array_map('strval', $observedAssertions)));
        $missing = array_values(array_diff($required, $observed));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $missing === [] ? 'passed' : 'blocked',
            'sentinel_green' => $missing === [],
            'required_assertion_count' => count($required),
            'observed_assertion_count' => count($observed),
            'missing_assertions' => $missing,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'workspace_mutation_allowed' => false,
        ];
    }
}
