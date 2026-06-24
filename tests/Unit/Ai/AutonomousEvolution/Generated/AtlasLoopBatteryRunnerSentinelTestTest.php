<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Generated;

use App\Services\Ai\AutonomousEvolution\Generated\AtlasLoopBatteryRunnerSentinelTest;
use PHPUnit\Framework\TestCase;

final class AtlasLoopBatteryRunnerSentinelTestTest extends TestCase
{
    public function test_describes_the_required_battery_runner_sentinel_contract(): void
    {
        $payload = (new AtlasLoopBatteryRunnerSentinelTest)->describe();

        $this->assertSame(AtlasLoopBatteryRunnerSentinelTest::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('available', $payload['status']);
        $this->assertSame('tests/Feature/Loop/Constitution/AtlasLoopBatteryRunnerSentinelTest.php', $payload['canonical_test_path']);
        $this->assertSame(5, $payload['assertion_count']);
        $this->assertContains('property_gated_self_edit_requires_green_battery_runner', $payload['assertions']);
        $this->assertContains('malformed_json_rejects_fail_closed', $payload['assertions']);
        $this->assertFalse($payload['execution_allowed']);
        $this->assertFalse($payload['dispatch_allowed']);
        $this->assertFalse($payload['provider_call_allowed']);
        $this->assertFalse($payload['workspace_mutation_allowed']);
    }

    public function test_evaluate_passes_only_when_every_required_assertion_is_observed(): void
    {
        $service = new AtlasLoopBatteryRunnerSentinelTest;
        $contract = $service->describe();

        $passed = $service->evaluate($contract['assertions']);

        $this->assertSame('passed', $passed['status']);
        $this->assertTrue($passed['sentinel_green']);
        $this->assertSame([], $passed['missing_assertions']);
    }

    public function test_evaluate_blocks_fail_closed_when_an_assertion_is_missing(): void
    {
        $service = new AtlasLoopBatteryRunnerSentinelTest;
        $contract = $service->describe();
        $observed = array_values(array_filter(
            $contract['assertions'],
            static fn (string $assertion): bool => $assertion !== 'subprocess_timeout_rejects_fail_closed',
        ));

        $blocked = $service->evaluate($observed);

        $this->assertSame('blocked', $blocked['status']);
        $this->assertFalse($blocked['sentinel_green']);
        $this->assertSame(['subprocess_timeout_rejects_fail_closed'], $blocked['missing_assertions']);
        $this->assertFalse($blocked['execution_allowed']);
        $this->assertFalse($blocked['self_programming_allowed']);
    }
}
