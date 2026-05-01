<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiProviderChoiceBuilder;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class AiProviderChoiceBuilderTest extends TestCase
{
    public function test_rate_limited_offers_other_provider_and_wait_within_24h(): void
    {
        CarbonImmutable::setTestNow('2026-05-01 09:00:00');
        $builder = new AiProviderChoiceBuilder();
        $resetAt = CarbonImmutable::parse('2026-05-01 14:00:00');

        $options = $builder->build('rate_limited', 'codex_cli', 'gpt-5.5', $resetAt);

        $ids = array_column($options, 'id');
        $this->assertSame(['switch_provider', 'wait_for_reset', 'cancel'], $ids);
        $this->assertSame('claude_cli', $options[0]['provider']);
        $this->assertSame('switch_provider', $options[0]['action']);
        $this->assertSame($resetAt->toIso8601String(), $options[1]['available_at_iso']);

        CarbonImmutable::setTestNow();
    }

    public function test_rate_limited_omits_wait_when_reset_too_far(): void
    {
        CarbonImmutable::setTestNow('2026-05-01 09:00:00');
        $builder = new AiProviderChoiceBuilder();
        $resetAt = CarbonImmutable::parse('2026-05-05 10:24:00');

        $options = $builder->build('rate_limited', 'codex_cli', 'gpt-5.5', $resetAt);

        $ids = array_column($options, 'id');
        $this->assertSame(['switch_provider', 'cancel'], $ids);

        CarbonImmutable::setTestNow();
    }

    public function test_rate_limited_without_reset_only_offers_switch_and_cancel(): void
    {
        $builder = new AiProviderChoiceBuilder();
        $options = $builder->build('rate_limited', 'claude_cli', 'sonnet-4.6', null);

        $ids = array_column($options, 'id');
        $this->assertSame(['switch_provider', 'cancel'], $ids);
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
}
