<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiProviderModelResolver;
use App\Services\Ai\AtlasAiRuntimeSettings;
use Tests\TestCase;

class AiProviderModelResolverTest extends TestCase
{
    public function test_gemini_model_is_fixed_even_when_explicit_or_configured_model_differs(): void
    {
        config()->set('atlas.ai.providers.gemini_cli.model', 'gemini-2.5-pro');
        config()->set('atlas.ai.providers.gemini_cli.model_identity', 'gemini-2.5-pro');

        $resolution = app(AiProviderModelResolver::class)->resolveWithSource('gemini_cli', 'gemini-2.5-pro');

        $this->assertSame('gemini-3.1-pro-preview', $resolution['model']);
        $this->assertSame('provider_fixed_model', $resolution['source']);
        $this->assertSame('Gemini 3.1 Pro Preview', $resolution['model_label']);
        $this->assertSame('premium', $resolution['model_tier']);
    }

    public function test_gemini_runtime_settings_ignore_model_overrides(): void
    {
        $settings = app(AtlasAiRuntimeSettings::class);

        $provider = $settings->providerConfig('gemini_cli', [
            'providers' => [
                'gemini_cli' => [
                    'model' => 'gemini-2.5-pro',
                    'model_identity' => 'gemini-2.5-pro',
                    'model_label' => 'Wrong Gemini',
                    'model_tier' => 'daily',
                    'allow_auto' => true,
                ],
            ],
        ]);

        $this->assertSame('gemini-3.1-pro-preview', $provider['model']);
        $this->assertSame('gemini-3.1-pro-preview', $provider['model_identity']);
        $this->assertSame('Gemini 3.1 Pro Preview', $provider['model_label']);
        $this->assertSame('premium', $provider['model_tier']);
        $this->assertNull($provider['fallback_model']);
        $this->assertTrue($provider['allow_auto']);
    }

    public function test_premium_model_aliases_resolve_with_labels_and_tiers(): void
    {
        config()->set('atlas.ai.providers.claude_cli.premium_model', 'claude-opus-4-7');
        config()->set('atlas.ai.providers.claude_cli.premium_model_label', 'Claude Opus 4.7');
        config()->set('atlas.ai.providers.codex_cli.premium_model', 'gpt-5.5');
        config()->set('atlas.ai.providers.codex_cli.premium_model_label', 'GPT-5.5');

        $resolver = app(AiProviderModelResolver::class);

        $claude = $resolver->resolveWithSource('claude_cli', 'claude-opus-4-7');
        $codex = $resolver->resolveWithSource('codex_cli', 'gpt-5.5');

        $this->assertSame('claude-opus-4-7', $claude['model']);
        $this->assertSame('Claude Opus 4.7', $claude['model_label']);
        $this->assertSame('premium', $claude['model_tier']);
        $this->assertSame('gpt-5.5', $codex['model']);
        $this->assertSame('GPT-5.5', $codex['model_label']);
        $this->assertSame('premium', $codex['model_tier']);
    }
}
