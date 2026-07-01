<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLocalClientFallbackPolicy;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainLocalClientFallbackPolicyTest extends TestCase
{
    private function policy(): AtlasExternalBrainLocalClientFallbackPolicy
    {
        return new AtlasExternalBrainLocalClientFallbackPolicy;
    }

    private function baseFacts(array $overrides = []): array
    {
        return array_merge([
            'local_client_available' => true,
            'subscription_reliable' => true,
            'atlas_native_fallback_capacity_available' => true,
            'manual_muscle_available' => false,
        ], $overrides);
    }

    // ── AC: available local client — continues on it since a fallback also exists ──

    public function test_available_local_client_continues_with_local_client(): void
    {
        $result = $this->policy()->decide($this->baseFacts());

        $this->assertSame(AtlasExternalBrainLocalClientFallbackPolicy::DECISION_CONTINUE_WITH_LOCAL_CLIENT, $result['decision']);
        $this->assertFalse($result['native_fallback_required']);
    }

    // ── AC: unavailable local client → native_fallback_required ───────────────

    public function test_unavailable_local_client_requires_native_fallback(): void
    {
        $result = $this->policy()->decide($this->baseFacts(['local_client_available' => false]));

        $this->assertTrue($result['native_fallback_required']);
        $this->assertSame(AtlasExternalBrainLocalClientFallbackPolicy::DECISION_FALLBACK_TO_ATLAS_NATIVE, $result['decision']);
    }

    // ── AC: fragile local client → native_fallback_required, never continues ──

    public function test_fragile_local_client_requires_native_fallback_even_when_otherwise_available(): void
    {
        $result = $this->policy()->decide($this->baseFacts(['local_client_fragile' => true]));

        $this->assertTrue($result['native_fallback_required']);
        $this->assertNotSame(AtlasExternalBrainLocalClientFallbackPolicy::DECISION_CONTINUE_WITH_LOCAL_CLIENT, $result['decision']);
        $this->assertTrue($result['local_client_fragile']);
    }

    // ── AC: paid-api-only local client → native_fallback_required ─────────────

    public function test_paid_api_only_local_client_requires_native_fallback(): void
    {
        $result = $this->policy()->decide($this->baseFacts(['local_client_paid_api_only' => true]));

        $this->assertTrue($result['native_fallback_required']);
        $this->assertNotSame(AtlasExternalBrainLocalClientFallbackPolicy::DECISION_CONTINUE_WITH_LOCAL_CLIENT, $result['decision']);
        $this->assertTrue($result['local_client_paid_api_only']);
    }

    // ── AC: unverified local client → native_fallback_required ────────────────

    public function test_unverified_local_client_requires_native_fallback(): void
    {
        $result = $this->policy()->decide($this->baseFacts(['local_client_verified' => false]));

        $this->assertTrue($result['native_fallback_required']);
        $this->assertNotSame(AtlasExternalBrainLocalClientFallbackPolicy::DECISION_CONTINUE_WITH_LOCAL_CLIENT, $result['decision']);
        $this->assertFalse($result['local_client_verified']);
    }

    // ── AC: native fallback — falls back cleanly when local client is unusable ──

    public function test_native_fallback_used_when_local_client_unusable_and_fallback_capacity_exists(): void
    {
        $result = $this->policy()->decide($this->baseFacts(['local_client_fragile' => true]));

        $this->assertSame(AtlasExternalBrainLocalClientFallbackPolicy::DECISION_FALLBACK_TO_ATLAS_NATIVE, $result['decision']);
        $this->assertTrue($result['native_fallback_required']);
    }

    // ── AC: steady_state_requires_external_client is refused (always false) ───

    public function test_steady_state_requires_external_client_is_always_false(): void
    {
        foreach ([
            $this->baseFacts(),
            $this->baseFacts(['local_client_available' => false]),
            $this->baseFacts(['local_client_fragile' => true]),
            $this->baseFacts(['local_client_paid_api_only' => true]),
            $this->baseFacts(['local_client_verified' => false]),
            $this->baseFacts(['atlas_native_fallback_capacity_available' => false, 'manual_muscle_available' => false]),
            [], // fully empty facts — worst case
        ] as $facts) {
            $result = $this->policy()->decide($facts);
            $this->assertFalse(
                $result['steady_state_requires_external_client'],
                'steady_state_requires_external_client must be false for facts: '.json_encode($facts),
            );
        }
    }

    // ── output shape ────────────────────────────────────────────────────────────

    public function test_output_has_new_required_keys(): void
    {
        $result = $this->policy()->decide($this->baseFacts());

        foreach ([
            'native_fallback_required',
            'steady_state_requires_external_client',
            'local_client_fragile',
            'local_client_paid_api_only',
            'local_client_verified',
        ] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
    }

    public function test_local_client_would_be_sole_path_pauses_and_still_requires_no_external_dependency(): void
    {
        $result = $this->policy()->decide($this->baseFacts([
            'atlas_native_fallback_capacity_available' => false,
            'manual_muscle_available' => false,
        ]));

        $this->assertSame(AtlasExternalBrainLocalClientFallbackPolicy::DECISION_PAUSE_PROVIDER_ROUTING, $result['decision']);
        $this->assertFalse($result['native_fallback_required']);
        $this->assertFalse($result['steady_state_requires_external_client']);
    }
}
