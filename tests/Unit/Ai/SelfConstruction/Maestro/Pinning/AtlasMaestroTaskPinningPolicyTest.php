<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Pinning;

use App\Services\Ai\SelfConstruction\Maestro\Pinning\AtlasMaestroTaskPinningPolicy;
use App\Services\Ai\SelfConstruction\Maestro\Pinning\AtlasMaestroTaskPinningRegistry;
use Tests\TestCase;

final class AtlasMaestroTaskPinningPolicyTest extends TestCase
{
    private function policy(): AtlasMaestroTaskPinningPolicy
    {
        $registry = new AtlasMaestroTaskPinningRegistry(sys_get_temp_dir().'/atlas-pin-req-'.bin2hex(random_bytes(6)).'.json');

        return new AtlasMaestroTaskPinningPolicy($registry);
    }

    // ── AC: capability-fit pinning is allowed with ttl ──────────────────────────

    public function test_capability_fit_pinning_is_allowed_with_ttl(): void
    {
        $result = $this->policy()->evaluatePinRequest(['reason_category' => 'capability_fit', 'ttl_seconds' => 1800]);

        $this->assertSame(AtlasMaestroTaskPinningPolicy::REQUEST_ALLOW, $result['decision']);
        $this->assertSame(AtlasMaestroTaskPinningPolicy::REQUEST_REASON_APPROVED, $result['reason']);
    }

    public function test_continuity_and_recovery_pinning_are_also_allowed_with_ttl(): void
    {
        $continuity = $this->policy()->evaluatePinRequest(['reason_category' => 'continuity', 'ttl_seconds' => 600]);
        $recovery = $this->policy()->evaluatePinRequest(['reason_category' => 'recovery', 'ttl_seconds' => 600]);

        $this->assertSame(AtlasMaestroTaskPinningPolicy::REQUEST_ALLOW, $continuity['decision']);
        $this->assertSame(AtlasMaestroTaskPinningPolicy::REQUEST_ALLOW, $recovery['decision']);
    }

    // ── AC: vague preference pinning is rejected ────────────────────────────────

    public function test_vague_preference_pinning_is_rejected(): void
    {
        $result = $this->policy()->evaluatePinRequest(['reason_category' => 'preference', 'ttl_seconds' => 600]);

        $this->assertSame(AtlasMaestroTaskPinningPolicy::REQUEST_REFUSE, $result['decision']);
        $this->assertSame(AtlasMaestroTaskPinningPolicy::REQUEST_REASON_VAGUE_CATEGORY, $result['reason']);
    }

    public function test_missing_reason_category_is_rejected(): void
    {
        $result = $this->policy()->evaluatePinRequest(['ttl_seconds' => 600]);

        $this->assertSame(AtlasMaestroTaskPinningPolicy::REQUEST_REFUSE, $result['decision']);
        $this->assertSame(AtlasMaestroTaskPinningPolicy::REQUEST_REASON_VAGUE_CATEGORY, $result['reason']);
    }

    // ── AC: expired or starvation-risk pins are refused with reason ─────────────

    public function test_non_positive_ttl_is_refused_as_expired(): void
    {
        $result = $this->policy()->evaluatePinRequest(['reason_category' => 'capability_fit', 'ttl_seconds' => 0]);

        $this->assertSame(AtlasMaestroTaskPinningPolicy::REQUEST_REFUSE, $result['decision']);
        $this->assertSame(AtlasMaestroTaskPinningPolicy::REQUEST_REASON_INVALID_TTL, $result['reason']);
    }

    public function test_excessively_long_ttl_is_refused_as_starvation_risk(): void
    {
        $result = $this->policy()->evaluatePinRequest(['reason_category' => 'capability_fit', 'ttl_seconds' => 999_999]);

        $this->assertSame(AtlasMaestroTaskPinningPolicy::REQUEST_REFUSE, $result['decision']);
        $this->assertSame(AtlasMaestroTaskPinningPolicy::REQUEST_REASON_STARVATION_RISK, $result['reason']);
    }

    public function test_result_carries_reason_category_and_ttl_seconds(): void
    {
        $result = $this->policy()->evaluatePinRequest(['reason_category' => 'capability_fit', 'ttl_seconds' => 300]);

        $this->assertSame('capability_fit', $result['reason_category']);
        $this->assertSame(300, $result['ttl_seconds']);
    }
}
