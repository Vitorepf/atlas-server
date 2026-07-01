<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOriginatorDutyCycleContract;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainOriginatorDutyCycleContractTest extends TestCase
{
    public function test_healthy_queue_with_active_mission_is_not_terminal_and_not_stop_or_wait(): void
    {
        $result = (new AtlasExternalBrainOriginatorDutyCycleContract)->evaluate([
            'mission_active' => true,
            'quota_remaining' => 10,
            'claimable_depth' => 28,
            'servable_now' => 28,
            'active_workers' => 4,
            'replenish_action' => 'wait',
        ]);

        self::assertFalse($result['terminal']);
        self::assertNotContains($result['next_action'], ['stop', 'wait']);
        self::assertSame(AtlasExternalBrainOriginatorDutyCycleContract::ACTION_CONSOLIDATE_WITH_NEXT_BATCH, $result['next_action']);
    }

    public function test_quota_complete_is_terminal_with_machine_readable_reason(): void
    {
        $result = (new AtlasExternalBrainOriginatorDutyCycleContract)->evaluate([
            'mission_active' => true,
            'quota_complete' => true,
        ]);

        self::assertTrue($result['terminal']);
        self::assertSame(AtlasExternalBrainOriginatorDutyCycleContract::TERMINAL_QUOTA_COMPLETE, $result['terminal_reason']);
    }

    public function test_disabled_switch_is_terminal_with_machine_readable_reason(): void
    {
        $result = (new AtlasExternalBrainOriginatorDutyCycleContract)->evaluate([
            'mission_active' => true,
            'brain_enabled' => false,
        ]);

        self::assertTrue($result['terminal']);
        self::assertSame(AtlasExternalBrainOriginatorDutyCycleContract::TERMINAL_DISABLED_SWITCH, $result['terminal_reason']);
    }

    public function test_no_value_after_exhaustive_escalation_is_terminal(): void
    {
        $result = (new AtlasExternalBrainOriginatorDutyCycleContract)->evaluate([
            'mission_active' => true,
            'exhaustive_escalation_attempted' => true,
            'value_found_after_escalation' => false,
        ]);

        self::assertTrue($result['terminal']);
        self::assertSame(
            AtlasExternalBrainOriginatorDutyCycleContract::TERMINAL_NO_VALUE_AFTER_EXHAUSTIVE_ESCALATION,
            $result['terminal_reason'],
        );
    }

    public function test_local_surface_exhausted_with_quota_remaining_pivots_with_escalation_fronts(): void
    {
        $result = (new AtlasExternalBrainOriginatorDutyCycleContract)->evaluate([
            'mission_active' => true,
            'quota_remaining' => 5,
            'local_surface_exhausted' => true,
        ]);

        self::assertFalse($result['terminal']);
        self::assertSame(AtlasExternalBrainOriginatorDutyCycleContract::ACTION_PIVOT_OR_RESEARCH, $result['next_action']);
        self::assertNotEmpty($result['required_escalation_fronts']);
    }

    public function test_starving_queue_keeps_originating(): void
    {
        $result = (new AtlasExternalBrainOriginatorDutyCycleContract)->evaluate([
            'mission_active' => true,
            'quota_remaining' => 10,
            'claimable_depth' => 1,
            'servable_now' => 1,
            'active_workers' => 4,
            'replenish_action' => 'urgent',
        ]);

        self::assertFalse($result['terminal']);
        self::assertSame(AtlasExternalBrainOriginatorDutyCycleContract::ACTION_KEEP_ORIGINATING, $result['next_action']);
    }
}
