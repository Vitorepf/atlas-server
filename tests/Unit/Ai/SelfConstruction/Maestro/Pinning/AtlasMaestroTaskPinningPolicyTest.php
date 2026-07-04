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

    // ── evaluatePinWithContract: pin_allowed, pin_ttl_seconds, release_reason, safety_evidence_required ──

    public function test_evaluate_pin_with_contract_has_required_keys(): void
    {
        $result = $this->policy()->evaluatePinWithContract([
            'reason_category' => 'capability_fit',
            'ttl_seconds' => 300,
            'safety_evidence' => ['proven_capability'],
        ]);
        $this->assertArrayHasKey('pin_allowed', $result);
        $this->assertArrayHasKey('pin_ttl_seconds', $result);
        $this->assertArrayHasKey('release_reason', $result);
        $this->assertArrayHasKey('safety_evidence_required', $result);
    }

    public function test_pin_allowed_when_valid_capability_fit_with_evidence(): void
    {
        $result = $this->policy()->evaluatePinWithContract([
            'reason_category' => 'capability_fit',
            'ttl_seconds' => 300,
            'worker_fit' => 0.8,
            'safety_evidence' => ['proven_capability'],
        ]);
        $this->assertTrue($result['pin_allowed']);
        $this->assertSame(300, $result['pin_ttl_seconds']);
        $this->assertTrue($result['safety_evidence_required']);
    }

    public function test_pin_rejected_when_capability_fit_without_safety_evidence(): void
    {
        $result = $this->policy()->evaluatePinWithContract([
            'reason_category' => 'capability_fit',
            'ttl_seconds' => 300,
            'worker_fit' => 0.8,
        ]);
        $this->assertFalse($result['pin_allowed']);
        $this->assertSame('capability_fit_requires_safety_evidence', $result['release_reason']);
    }

    public function test_pin_released_when_worker_fit_below_threshold(): void
    {
        $result = $this->policy()->evaluatePinWithContract([
            'reason_category' => 'capability_fit',
            'ttl_seconds' => 300,
            'worker_fit' => 0.3,
            'safety_evidence' => ['proven_capability'],
        ]);
        $this->assertFalse($result['pin_allowed']);
        $this->assertSame('worker_fit_below_threshold', $result['release_reason']);
    }

    public function test_continuity_pin_does_not_require_safety_evidence(): void
    {
        $result = $this->policy()->evaluatePinWithContract([
            'reason_category' => 'continuity',
            'ttl_seconds' => 300,
        ]);
        $this->assertTrue($result['pin_allowed']);
        $this->assertFalse($result['safety_evidence_required']);
    }
}
