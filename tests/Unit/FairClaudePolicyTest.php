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
}
