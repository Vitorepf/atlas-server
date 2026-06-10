<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusProviderNormalizer;
use Tests\TestCase;

final class AreaFocusProviderNormalizerTest extends TestCase
{
    public function test_normalizes_shared_provider_aliases(): void
    {
        $this->assertSame('cursor_cli', AreaFocusProviderNormalizer::providerId(' cursor-agent '));
        $this->assertSame('cursor_cli', AreaFocusProviderNormalizer::providerId('composer_2_5'));
        $this->assertSame('claude_cli', AreaFocusProviderNormalizer::providerId('claude-code'));
        $this->assertSame('claude_cli', AreaFocusProviderNormalizer::providerId('opus'));
        $this->assertSame('codex_cli', AreaFocusProviderNormalizer::providerId('openai_codex'));
        $this->assertSame('gemini_cli', AreaFocusProviderNormalizer::providerId('gemini'));
        $this->assertSame('minimax_m27_cli', AreaFocusProviderNormalizer::providerId('minimax_m27'));
    }

    public function test_preserves_unknown_provider_and_applies_empty_fallback_only_for_blank_values(): void
    {
        $this->assertSame('custom_provider', AreaFocusProviderNormalizer::providerId(' custom_provider ', 'cursor_cli'));
        $this->assertSame('cursor_cli', AreaFocusProviderNormalizer::providerId(' ', 'cursor_cli'));
        $this->assertSame('', AreaFocusProviderNormalizer::providerId(' '));
    }

    public function test_minimax_m3_aliases_are_opt_in(): void
    {
        $this->assertSame('minimax_m3', AreaFocusProviderNormalizer::providerId('minimax_m3'));
        $this->assertSame('minimax_m3_cli', AreaFocusProviderNormalizer::providerId('minimax_m3', includeMinimaxM3: true));
        $this->assertSame('minimax_m3_cli', AreaFocusProviderNormalizer::providerId('minimax_m3_cli', includeMinimaxM3: true));
    }

    public function test_quarantine_provider_id_preserves_legacy_quality_lock_aliases(): void
    {
        $this->assertSame('claude_cli', AreaFocusProviderNormalizer::quarantineProviderId('claude sonnet 4 6'));
        $this->assertSame('claude_cli', AreaFocusProviderNormalizer::quarantineProviderId('sonnet_4_6'));
        $this->assertSame('minimax_m27_cli', AreaFocusProviderNormalizer::quarantineProviderId('minimax_cli'));
        $this->assertSame('minimax_m27_cli', AreaFocusProviderNormalizer::quarantineProviderId('minimax_m3'));
        $this->assertSame('custom_provider', AreaFocusProviderNormalizer::quarantineProviderId(' custom_provider '));
    }
}
