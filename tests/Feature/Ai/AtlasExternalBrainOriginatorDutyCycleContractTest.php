<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOriginatorDutyCycleContract;
use Tests\TestCase;

final class AtlasExternalBrainOriginatorDutyCycleContractTest extends TestCase
{
    public function test_comfortable_queue_with_wait_action_consolidates_instead_of_stopping(): void
    {
        $result = (new AtlasExternalBrainOriginatorDutyCycleContract)->evaluate([
            'replenish_action' => 'wait',
            'claimable_depth' => 40,
            'active_workers' => 5,
        ]);

        $this->assertFalse($result['terminal']);
        $this->assertSame(
            AtlasExternalBrainOriginatorDutyCycleContract::ACTION_CONSOLIDATE_WITH_NEXT_BATCH,
            $result['next_action'],
        );
        $this->assertNotSame('stop', $result['next_action']);
        $this->assertNotNull($result['next_action']);
    }

    public function test_local_surface_exhausted_pivots_to_research_with_escalation_fronts(): void
    {
        $result = (new AtlasExternalBrainOriginatorDutyCycleContract)->evaluate([
            'local_surface_exhausted' => true,
        ]);

        $this->assertFalse($result['terminal']);
        $this->assertSame(
            AtlasExternalBrainOriginatorDutyCycleContract::ACTION_PIVOT_OR_RESEARCH,
            $result['next_action'],
        );
        $this->assertNotSame('no_task_created', $result['next_action']);
        $this->assertSame(
            ['cross_domain_search', 'deep_architecture_scan', 'research_adaptation'],
            $result['required_escalation_fronts'],
        );
    }

    public function test_quota_complete_is_terminal(): void
    {
        $result = (new AtlasExternalBrainOriginatorDutyCycleContract)->evaluate(['quota_complete' => true]);

        $this->assertTrue($result['terminal']);
        $this->assertSame(AtlasExternalBrainOriginatorDutyCycleContract::TERMINAL_QUOTA_COMPLETE, $result['terminal_reason']);
    }

    public function test_disabled_switch_is_terminal(): void
    {
        $result = (new AtlasExternalBrainOriginatorDutyCycleContract)->evaluate(['brain_enabled' => false]);

        $this->assertTrue($result['terminal']);
        $this->assertSame(AtlasExternalBrainOriginatorDutyCycleContract::TERMINAL_DISABLED_SWITCH, $result['terminal_reason']);
    }

    public function test_exhaustive_escalation_with_no_value_found_is_terminal(): void
    {
        $result = (new AtlasExternalBrainOriginatorDutyCycleContract)->evaluate([
            'exhaustive_escalation_attempted' => true,
            'value_found_after_escalation' => false,
        ]);

        $this->assertTrue($result['terminal']);
        $this->assertSame(
            AtlasExternalBrainOriginatorDutyCycleContract::TERMINAL_NO_VALUE_AFTER_EXHAUSTIVE_ESCALATION,
            $result['terminal_reason'],
        );
    }

    public function test_only_the_three_terminal_conditions_produce_terminal_true(): void
    {
        $nonTerminalScenarios = [
            ['replenish_action' => 'wait', 'claimable_depth' => 40, 'active_workers' => 5],
            ['local_surface_exhausted' => true],
            ['mission_active' => true],
            ['exhaustive_escalation_attempted' => true, 'value_found_after_escalation' => true],
        ];

        foreach ($nonTerminalScenarios as $facts) {
            $result = (new AtlasExternalBrainOriginatorDutyCycleContract)->evaluate($facts);
            $this->assertFalse($result['terminal'], 'expected non-terminal for: '.json_encode($facts));
        }

        $terminalScenarios = [
            ['quota_complete' => true],
            ['brain_enabled' => false],
            ['exhaustive_escalation_attempted' => true, 'value_found_after_escalation' => false],
        ];

        foreach ($terminalScenarios as $facts) {
            $result = (new AtlasExternalBrainOriginatorDutyCycleContract)->evaluate($facts);
            $this->assertTrue($result['terminal'], 'expected terminal for: '.json_encode($facts));
        }
    }
}
