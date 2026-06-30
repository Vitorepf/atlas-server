<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Replenisher;

use App\Services\Ai\SelfConstruction\Replenisher\AtlasSelfConstructionNativeReplenisherPreflight;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionNativeReplenisherPreflightWorkerStarvationTest extends TestCase
{
    public function test_wait_advice_with_worker_floor_breach_overrides_to_allowed_with_starvation_reason(): void
    {
        $preflight = new AtlasSelfConstructionNativeReplenisherPreflight();
        $result = $preflight->evaluateWaitOverride([
            'maestro_urgency' => 'wait',
            'worker_floor_breach' => true,
        ]);

        $this->assertTrue($result['allowed']);
        $this->assertSame(AtlasSelfConstructionNativeReplenisherPreflight::REASON_WORKER_STARVATION_RISK, $result['reason']);
    }

    public function test_wait_advice_with_comfortable_buffer_remains_not_allowed_wait_ok(): void
    {
        $preflight = new AtlasSelfConstructionNativeReplenisherPreflight();
        $result = $preflight->evaluateWaitOverride([
            'maestro_urgency' => 'wait',
            'worker_floor_breach' => false,
            'replenish_soon' => false,
        ]);

        $this->assertFalse($result['allowed']);
        $this->assertSame(AtlasSelfConstructionNativeReplenisherPreflight::REASON_WAIT_OK, $result['reason']);
    }

    public function test_replenish_soon_signal_also_overrides_wait(): void
    {
        $preflight = new AtlasSelfConstructionNativeReplenisherPreflight();
        $result = $preflight->evaluateWaitOverride([
            'maestro_urgency' => 'wait',
            'worker_floor_breach' => false,
            'replenish_soon' => true,
        ]);

        $this->assertTrue($result['allowed']);
        $this->assertSame(AtlasSelfConstructionNativeReplenisherPreflight::REASON_WORKER_STARVATION_RISK, $result['reason']);
    }

    public function test_non_wait_urgency_is_always_allowed(): void
    {
        $preflight = new AtlasSelfConstructionNativeReplenisherPreflight();
        $result = $preflight->evaluateWaitOverride(['maestro_urgency' => 'urgent']);

        $this->assertTrue($result['allowed']);
        $this->assertSame(AtlasSelfConstructionNativeReplenisherPreflight::REASON_MAESTRO_URGENCY_NOT_WAIT, $result['reason']);
    }

    public function test_defaults_to_wait_when_urgency_omitted(): void
    {
        $preflight = new AtlasSelfConstructionNativeReplenisherPreflight();
        $result = $preflight->evaluateWaitOverride([]);

        $this->assertFalse($result['allowed']);
        $this->assertSame(AtlasSelfConstructionNativeReplenisherPreflight::REASON_WAIT_OK, $result['reason']);
    }

    public function test_buffer_below_target_overrides_wait_with_buffer_shortfall_reason(): void
    {
        $preflight = new AtlasSelfConstructionNativeReplenisherPreflight();
        $result = $preflight->evaluateWaitOverride([
            'maestro_urgency' => 'wait',
            'claimable_per_active_worker' => 3,
            'worker_buffer_target_per_worker' => 5,
        ]);

        $this->assertTrue($result['allowed']);
        $this->assertSame(AtlasSelfConstructionNativeReplenisherPreflight::REASON_BUFFER_BELOW_TARGET, $result['reason']);
    }

    public function test_buffer_meeting_target_remains_not_allowed(): void
    {
        $preflight = new AtlasSelfConstructionNativeReplenisherPreflight();
        $result = $preflight->evaluateWaitOverride([
            'maestro_urgency' => 'wait',
            'claimable_per_active_worker' => 5,
            'worker_buffer_target_per_worker' => 5,
        ]);

        $this->assertFalse($result['allowed']);
        $this->assertSame(AtlasSelfConstructionNativeReplenisherPreflight::REASON_WAIT_OK, $result['reason']);
    }

    public function test_buffer_facts_omitted_does_not_affect_wait_ok_default(): void
    {
        $preflight = new AtlasSelfConstructionNativeReplenisherPreflight();
        $result = $preflight->evaluateWaitOverride(['maestro_urgency' => 'wait']);

        $this->assertFalse($result['allowed']);
        $this->assertSame(AtlasSelfConstructionNativeReplenisherPreflight::REASON_WAIT_OK, $result['reason']);
    }

    public function test_hard_floor_takes_priority_over_buffer_target_check(): void
    {
        $preflight = new AtlasSelfConstructionNativeReplenisherPreflight();
        $result = $preflight->evaluateWaitOverride([
            'maestro_urgency' => 'wait',
            'worker_floor_breach' => true,
            'claimable_per_active_worker' => 5,
            'worker_buffer_target_per_worker' => 5,
        ]);

        $this->assertTrue($result['allowed']);
        $this->assertSame(AtlasSelfConstructionNativeReplenisherPreflight::REASON_WORKER_STARVATION_RISK, $result['reason']);
    }
}
