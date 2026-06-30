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
}
