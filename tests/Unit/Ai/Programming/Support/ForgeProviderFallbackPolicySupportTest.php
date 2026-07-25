<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\Support;

use App\Services\Ai\Programming\AtlasForgeProviderFallbackPolicyService as Policy;
use App\Services\Ai\Programming\Support\ForgeProviderFallbackPolicySupport as Support;
use PHPUnit\Framework\TestCase;

/**
 * Pure Support peel for Forge provider fallback policy — no I/O, no service, no DB.
 */
final class ForgeProviderFallbackPolicySupportTest extends TestCase
{
    public function test_normalize_fallback_chain_drops_empty_and_non_arrays(): void
    {
        $chain = Support::normalizeFallbackChain([
            'not-an-entry',
            ['role' => null, 'provider' => null, 'model' => null],
            ['role' => '  secondary  ', 'provider' => 'codex_cli', 'model' => 'gpt-5', 'order' => 2],
            ['role' => 'primary', 'provider' => 'claude_cli', 'capable' => false],
        ]);

        $this->assertCount(2, $chain);
        $this->assertSame('secondary', $chain[0]['role']);
        $this->assertSame(2, $chain[0]['order']);
        $this->assertTrue($chain[0]['capable']);
        $this->assertSame('primary', $chain[1]['role']);
        $this->assertFalse($chain[1]['capable']);
    }

    public function test_normalize_fallback_chain_non_array_is_empty(): void
    {
        $this->assertSame([], Support::normalizeFallbackChain(null));
        $this->assertSame([], Support::normalizeFallbackChain('chain'));
    }

    public function test_normalize_roles_requires_role_and_defaults_status(): void
    {
        $roles = Support::normalizeRoles([
            ['provider' => 'x'],
            ['role' => '  reviewer  ', 'provider' => 'claude_cli', 'model' => 'opus'],
            'skip-me',
        ]);

        $this->assertCount(1, $roles);
        $this->assertSame('reviewer', $roles[0]['role']);
        $this->assertSame('available', $roles[0]['status']);
        $this->assertSame('claude_cli', $roles[0]['provider']);
    }

    public function test_pick_fallback_prefers_ordered_capable_chain_excluding_failed_tuple(): void
    {
        $chain = Support::normalizeFallbackChain([
            ['role' => 'primary', 'provider' => 'claude_cli', 'model' => 'opus', 'order' => 0],
            ['role' => 'secondary', 'provider' => 'codex_cli', 'model' => 'gpt-5', 'order' => 1],
            ['role' => 'tertiary', 'provider' => 'gemini_cli', 'model' => 'gem', 'order' => 2, 'capable' => false],
        ]);

        $picked = Support::pickFallback('primary', 'claude_cli', 'opus', $chain, []);

        $this->assertNotNull($picked);
        $this->assertSame('secondary', $picked['role']);
        $this->assertSame('codex_cli', $picked['provider']);
        $this->assertSame('fallback_chain', $picked['source']);
    }

    public function test_pick_fallback_falls_through_to_topology_role(): void
    {
        $roles = Support::normalizeRoles([
            ['role' => 'primary', 'provider' => 'claude_cli', 'model' => 'opus', 'status' => 'selected'],
            ['role' => 'reviewer', 'provider' => 'codex_cli', 'model' => 'gpt-5', 'status' => 'available'],
        ]);

        $picked = Support::pickFallback('primary', 'claude_cli', 'opus', [], $roles);

        $this->assertNotNull($picked);
        $this->assertSame('reviewer', $picked['role']);
        $this->assertSame('topology_role', $picked['source']);
    }

    public function test_pick_fallback_returns_null_when_no_capable_candidate(): void
    {
        $chain = Support::normalizeFallbackChain([
            ['role' => 'primary', 'provider' => 'claude_cli', 'model' => 'opus', 'order' => 0],
            ['role' => 'secondary', 'provider' => 'codex_cli', 'model' => 'gpt-5', 'order' => 1, 'capable' => false],
        ]);
        $roles = Support::normalizeRoles([
            ['role' => 'primary', 'provider' => 'claude_cli', 'model' => 'opus', 'status' => 'unavailable'],
        ]);

        $this->assertNull(Support::pickFallback('primary', 'claude_cli', 'opus', $chain, $roles));
    }

    public function test_cooldown_seconds_for_known_failure_types(): void
    {
        $this->assertSame(60, Support::cooldownSecondsFor(Policy::FAILURE_RATE_LIMIT));
        $this->assertSame(600, Support::cooldownSecondsFor(Policy::FAILURE_QUOTA_EXHAUSTED));
        $this->assertSame(30, Support::cooldownSecondsFor(Policy::FAILURE_TIMEOUT));
        $this->assertSame(120, Support::cooldownSecondsFor(Policy::FAILURE_MODEL_UNAVAILABLE));
        $this->assertSame(30, Support::cooldownSecondsFor(Policy::FAILURE_PROVIDER_ERROR));
        $this->assertSame(900, Support::cooldownSecondsFor(Policy::FAILURE_CAPACITY_EXHAUSTED));
        $this->assertSame(0, Support::cooldownSecondsFor(Policy::FAILURE_AUTH_FAILED));
        $this->assertSame(0, Support::cooldownSecondsFor(Policy::FAILURE_CONTEXT_LIMIT));
        $this->assertSame(0, Support::cooldownSecondsFor('unknown_failure'));
    }
}
