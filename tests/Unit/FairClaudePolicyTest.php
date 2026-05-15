<?php

namespace Tests\Unit;

use App\Services\Ai\FairClaudePolicy;
use Tests\TestCase;

class FairClaudePolicyTest extends TestCase
{
    public function test_claude_only_normalizes_to_single_provider_no_decide_and_fallback_disabled(): void
    {
        $policy = app(FairClaudePolicy::class);

        $flags = $policy->normalizeFlags([
            'claude_only' => true,
            'single_provider' => false,
            'no_decide' => false,
            'fallback_disabled' => false,
        ]);

        $this->assertTrue($flags['fair_mode']);
        $this->assertTrue($flags['single_provider']);
        $this->assertTrue($flags['no_decide']);
        $this->assertTrue($flags['fallback_disabled']);
        $this->assertSame(['claude_cli'], $policy->metadata()['allowed_providers']);
    }

    public function test_explicit_fair_mode_normalizes_to_full_claude_lock(): void
    {
        $policy = app(FairClaudePolicy::class);

        $flags = $policy->normalizeFlags([
            'fair_mode' => true,
            'claude_only' => false,
            'single_provider' => false,
            'no_decide' => false,
            'fallback_disabled' => false,
        ]);

        $this->assertTrue($flags['fair_mode']);
        $this->assertTrue($flags['single_provider']);
        $this->assertTrue($flags['no_decide']);
        $this->assertTrue($flags['fallback_disabled']);
    }

    public function test_accepts_claude_cli_with_opus_premium_selection(): void
    {
        $result = app(FairClaudePolicy::class)->validate('claude_cli', [
            'provider' => 'claude_cli',
            'model' => 'claude-opus-4-7',
            'alias' => 'opus',
            'tier' => 'premium',
        ]);

        $this->assertTrue($result['ok']);
    }

    public function test_rejects_non_claude_provider(): void
    {
        $result = app(FairClaudePolicy::class)->validate('codex_cli', [
            'provider' => 'claude_cli',
            'model' => 'claude-opus-4-7',
            'alias' => 'opus',
            'tier' => 'premium',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame(FairClaudePolicy::ERROR_CODE, $result['error']);
    }

    public function test_rejects_non_opus_model_selection(): void
    {
        $result = app(FairClaudePolicy::class)->validate('claude_cli', [
            'provider' => 'codex_cli',
            'model' => 'gpt-5.5',
            'alias' => 'codex-premium',
            'tier' => 'premium',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame(FairClaudePolicy::ERROR_CODE, $result['error']);
    }

    public function test_rejects_claude_fallback_model(): void
    {
        $result = app(FairClaudePolicy::class)->validate('claude_cli', [
            'provider' => 'claude_cli',
            'model' => 'claude-haiku',
            'alias' => 'haiku',
            'tier' => 'fallback',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame(FairClaudePolicy::ERROR_CODE, $result['error']);
    }

    public function test_invocation_rejects_missing_locked_model_metadata(): void
    {
        $result = app(FairClaudePolicy::class)->validateInvocation('claude_cli', 'claude-sonnet-4-6', [
            'fair_mode' => app(FairClaudePolicy::class)->metadata(),
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame(FairClaudePolicy::ERROR_CODE, $result['error']);
    }

    public function test_invocation_rejects_unresolved_actual_model(): void
    {
        $result = app(FairClaudePolicy::class)->validateInvocation('claude_cli', null, [
            'fair_mode' => app(FairClaudePolicy::class)->metadata(),
            'requested_model' => 'claude-opus-4-7',
            'requested_model_alias' => 'opus',
            'requested_model_tier' => 'premium',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame(FairClaudePolicy::ERROR_CODE, $result['error']);
        $this->assertSame('claude-opus-4-7', data_get($result, 'details.expected_model'));
    }

    public function test_accepts_claude_cli_with_sonnet_premium_selection(): void
    {
        $result = app(FairClaudePolicy::class)->validate('claude_cli', [
            'provider' => 'claude_cli',
            'model' => 'claude-sonnet-4-6',
            'alias' => 'sonnet',
            'tier' => 'premium',
        ]);

        $this->assertTrue($result['ok']);
    }

    public function test_accepts_claude_cli_with_sonnet_daily_selection(): void
    {
        $result = app(FairClaudePolicy::class)->validate('claude_cli', [
            'provider' => 'claude_cli',
            'model' => 'claude-sonnet-4-6',
            'alias' => 'sonnet',
            'tier' => 'daily',
        ]);

        $this->assertTrue($result['ok']);
    }

    public function test_validate_rejects_unallowed_alias_with_provider_model_not_available_sub_error(): void
    {
        $result = app(FairClaudePolicy::class)->validate('claude_cli', [
            'provider' => 'claude_cli',
            'model' => 'claude-haiku',
            'alias' => 'haiku',
            'tier' => 'premium',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame(FairClaudePolicy::ERROR_CODE, $result['error']);
        $this->assertSame(
            FairClaudePolicy::MODEL_NOT_AVAILABLE_ERROR,
            data_get($result, 'details.sub_error'),
        );
        $this->assertSame(
            FairClaudePolicy::MODEL_LOCK_ALLOWLIST,
            data_get($result, 'details.allowed_aliases'),
        );
    }

    public function test_invocation_accepts_sonnet_alias(): void
    {
        $result = app(FairClaudePolicy::class)->validateInvocation(
            'claude_cli',
            'claude-sonnet-4-6',
            [
                'fair_mode' => app(FairClaudePolicy::class)->metadata(),
                'requested_model' => 'claude-sonnet-4-6',
                'requested_model_alias' => 'sonnet',
                'requested_model_tier' => 'daily',
            ],
        );

        $this->assertTrue($result['ok']);
    }

    public function test_invocation_rejects_unallowed_alias(): void
    {
        $result = app(FairClaudePolicy::class)->validateInvocation(
            'claude_cli',
            'claude-haiku-3-5',
            [
                'fair_mode' => app(FairClaudePolicy::class)->metadata(),
                'requested_model' => 'claude-haiku-3-5',
                'requested_model_alias' => 'haiku',
                'requested_model_tier' => 'premium',
            ],
        );

        $this->assertFalse($result['ok']);
        $this->assertSame(
            FairClaudePolicy::MODEL_NOT_AVAILABLE_ERROR,
            data_get($result, 'details.sub_error'),
        );
    }

    public function test_model_lock_allowlist_contains_opus_and_sonnet(): void
    {
        $this->assertContains('opus', FairClaudePolicy::MODEL_LOCK_ALLOWLIST);
        $this->assertContains('sonnet', FairClaudePolicy::MODEL_LOCK_ALLOWLIST);
        $this->assertNotContains('haiku', FairClaudePolicy::MODEL_LOCK_ALLOWLIST);
    }

    public function test_runtime_override_locks_effective_policy_to_claude_only(): void
    {
        $override = app(FairClaudePolicy::class)->runtimeOverride([
            'model' => 'claude-opus-4-7',
            'label' => 'Claude Opus 4.7',
            'tier' => 'premium',
        ]);

        $this->assertSame('claude_cli', $override['default_provider']);
        $this->assertSame(['claude_cli'], $override['enabled_providers']);
        $this->assertSame(['codex_cli', 'gemini_cli'], $override['disabled_providers']);
        $this->assertSame(['claude_cli'], $override['fallback_order']);
        $this->assertFalse($override['allow_council']);
        $this->assertFalse($override['allow_multistage_graph']);
        $this->assertSame(['claude-opus-4-7'], data_get($override, 'allowed_models.claude_cli'));
        $this->assertSame([], data_get($override, 'allowed_models.codex_cli'));
        $this->assertFalse(data_get($override, 'providers.gemini_cli.allow_auto'));
    }
}
