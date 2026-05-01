<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiProviderChoiceBuilder;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class AiProviderChoiceBuilderTest extends TestCase
{
    public function test_rate_limited_offers_other_provider_and_wait_within_24h(): void
    {
        config()->set('atlas.ai.providers.codex_cli.fallback_model', null);
        CarbonImmutable::setTestNow('2026-05-01 09:00:00');
        $builder = new AiProviderChoiceBuilder();
        $resetAt = CarbonImmutable::parse('2026-05-01 14:00:00');

        $options = $builder->build('rate_limited', 'codex_cli', 'gpt-5.5', $resetAt);

        $ids = array_column($options, 'id');
        $this->assertSame(['switch_provider', 'wait_for_reset', 'cancel', 'retry_same'], $ids);
        $this->assertSame('claude_cli', $options[0]['provider']);
        $this->assertSame('switch_provider', $options[0]['action']);
        $this->assertSame($resetAt->toIso8601String(), $options[1]['available_at_iso']);

        CarbonImmutable::setTestNow();
    }

    public function test_rate_limited_omits_wait_when_reset_too_far(): void
    {
        config()->set('atlas.ai.providers.codex_cli.fallback_model', null);
        CarbonImmutable::setTestNow('2026-05-01 09:00:00');
        $builder = new AiProviderChoiceBuilder();
        $resetAt = CarbonImmutable::parse('2026-05-05 10:24:00');

        $options = $builder->build('rate_limited', 'codex_cli', 'gpt-5.5', $resetAt);

        $ids = array_column($options, 'id');
        $this->assertSame(['switch_provider', 'cancel', 'retry_same'], $ids);

        CarbonImmutable::setTestNow();
    }

    public function test_rate_limited_without_reset_only_offers_switch_and_cancel(): void
    {
        config()->set('atlas.ai.providers.claude_cli.fallback_model', null);
        $builder = new AiProviderChoiceBuilder();
        $options = $builder->build('rate_limited', 'claude_cli', 'sonnet-4.6', null);

        $ids = array_column($options, 'id');
        $this->assertSame(['switch_provider', 'cancel', 'retry_same'], $ids);
        $this->assertSame('codex_cli', $options[0]['provider']);
    }

    public function test_auth_expired_offers_login_required_and_cancel(): void
    {
        $builder = new AiProviderChoiceBuilder();
        $options = $builder->build('auth_expired', 'claude_cli', 'sonnet-4.6', null);

        $ids = array_column($options, 'id');
        $this->assertSame(['login_required', 'cancel'], $ids);
        $this->assertSame('fail', $options[0]['action']);
    }

    public function test_unsupported_error_returns_only_cancel(): void
    {
        $builder = new AiProviderChoiceBuilder();
        $options = $builder->build('cli_error', 'claude_cli', null, null);

        $this->assertCount(1, $options);
        $this->assertSame('cancel', $options[0]['id']);
    }

    public function test_rate_limited_includes_downgrade_when_fallback_configured(): void
    {
        config()->set('atlas.ai.providers.codex_cli.fallback_model', 'gpt-5-4-mini');
        $builder = new AiProviderChoiceBuilder();

        $options = $builder->build('rate_limited', 'codex_cli', 'gpt-5.5', null);

        $ids = array_column($options, 'id');
        $this->assertContains('downgrade_model', $ids);
        $downgrade = collect($options)->firstWhere('id', 'downgrade_model');
        $this->assertSame('downgrade_model', $downgrade['action']);
        $this->assertSame('codex_cli', $downgrade['provider']);
        $this->assertSame('gpt-5-4-mini', $downgrade['model']);
    }

    public function test_rate_limited_omits_downgrade_when_fallback_null(): void
    {
        config()->set('atlas.ai.providers.codex_cli.fallback_model', null);
        $builder = new AiProviderChoiceBuilder();

        $options = $builder->build('rate_limited', 'codex_cli', 'gpt-5.5', null);

        $ids = array_column($options, 'id');
        $this->assertNotContains('downgrade_model', $ids);
    }

    public function test_rate_limited_always_includes_retry_same_at_end(): void
    {
        config()->set('atlas.ai.providers.codex_cli.fallback_model', 'gpt-5-4-mini');
        $builder = new AiProviderChoiceBuilder();

        $options = $builder->build('rate_limited', 'codex_cli', 'gpt-5.5', null);

        $this->assertSame('retry_same', $options[count($options) - 1]['id']);
        $this->assertSame('retry_same', $options[count($options) - 1]['action']);
    }

    public function test_auth_expired_does_not_include_retry_same_or_downgrade(): void
    {
        $builder = new AiProviderChoiceBuilder();
        $options = $builder->build('auth_expired', 'claude_cli', 'sonnet-4.6', null);

        $ids = array_column($options, 'id');
        $this->assertNotContains('retry_same', $ids);
        $this->assertNotContains('downgrade_model', $ids);
    }
}
