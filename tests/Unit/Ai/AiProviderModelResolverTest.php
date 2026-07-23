<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiProviderModelResolver;
use App\Services\Ai\Policy\AtlasAiRuntimeSettings;
use Tests\TestCase;

class AiProviderModelResolverTest extends TestCase
{
    public function test_gemini_auto_defaults_to_flash_alias_from_catalog(): void
    {
        config()->set('atlas.ai.providers.gemini_cli.models.gemini_flash.model', 'gemini-3.5-flash');
        config()->set('atlas.ai.providers.gemini_cli.models.gemini_pro.model', 'gemini-3.1-pro-preview');
        config()->set('atlas.ai.providers.gemini_cli.default_model_alias', 'gemini_flash');

        $resolution = app(AiProviderModelResolver::class)->resolveWithSource('gemini_cli', 'auto');

        $this->assertSame('gemini-3.5-flash', $resolution['model']);
        $this->assertSame('gemini_flash', $resolution['selected_model_alias']);
        $this->assertSame('gemini', $resolution['model_family']);
        $this->assertSame('atlas_decide', $resolution['selection_source']);
        $this->assertSame(['gemini-3.5-flash', 'gemini-3.1-pro-preview'], $resolution['allowed_models']);
    }

    public function test_gemini_auto_promotes_to_pro_for_deep_compute_effort(): void
    {
        config()->set('atlas.ai.providers.gemini_cli.models.gemini_flash.model', 'gemini-3.5-flash');
        config()->set('atlas.ai.providers.gemini_cli.models.gemini_pro.model', 'gemini-3.1-pro-preview');

        $resolution = app(AiProviderModelResolver::class)->resolveWithSource('gemini_cli', 'auto', [
            'compute_effort' => ['atlas_level' => 'max'],
            'domain' => 'programming',
        ]);

        $this->assertSame('gemini-3.1-pro-preview', $resolution['model']);
        $this->assertSame('gemini_pro', $resolution['selected_model_alias']);
        $this->assertSame('premium', $resolution['model_tier']);
        $this->assertSame('atlas_decide', $resolution['selection_source']);
    }

    public function test_gemini_manual_alias_resolves_inside_catalog(): void
    {
        config()->set('atlas.ai.providers.gemini_cli.models.gemini_flash.model', 'gemini-3.5-flash');
        config()->set('atlas.ai.providers.gemini_cli.models.gemini_pro.model', 'gemini-3.1-pro-preview');

        $resolution = app(AiProviderModelResolver::class)->resolveWithSource('gemini_cli', 'gemini_pro');

        $this->assertSame('gemini-3.1-pro-preview', $resolution['model']);
        $this->assertSame('gemini_pro', $resolution['selected_model_alias']);
        $this->assertSame('gemini_pro', $resolution['operator_requested_model_alias']);
        $this->assertSame('manual_override', $resolution['selection_source']);
    }

    public function test_gemini_explicit_model_id_resolves_to_catalog_alias(): void
    {
        config()->set('atlas.ai.providers.gemini_cli.models.gemini_flash.model', 'gemini-3.5-flash');
        config()->set('atlas.ai.providers.gemini_cli.models.gemini_pro.model', 'gemini-3.1-pro-preview');

        $resolution = app(AiProviderModelResolver::class)->resolveWithSource('gemini_cli', 'gemini-3.1-pro-preview');

        $this->assertSame('gemini-3.1-pro-preview', $resolution['model']);
        $this->assertSame('gemini_pro', $resolution['selected_model_alias']);
        $this->assertSame('manual_override', $resolution['selection_source']);
    }

    public function test_gemini_unknown_manual_alias_fails_closed(): void
    {
        config()->set('atlas.ai.providers.gemini_cli.models.gemini_flash.model', 'gemini-3.5-flash');
        config()->set('atlas.ai.providers.gemini_cli.models.gemini_pro.model', 'gemini-3.1-pro-preview');

        $resolution = app(AiProviderModelResolver::class)->resolveWithSource('gemini_cli', 'gemini_ultra');

        $this->assertNull($resolution['model']);
        $this->assertSame('policy_violation', $resolution['selection_source']);
        $this->assertSame('model_not_allowed', $resolution['error_code']);
        $this->assertSame(['gemini_flash', 'gemini_pro'], $resolution['available_model_aliases']);
    }

    public function test_gemini_runtime_settings_preserve_catalog_and_default_alias(): void
    {
        $settings = app(AtlasAiRuntimeSettings::class);

        $provider = $settings->providerConfig('gemini_cli', [
            'providers' => [
                'gemini_cli' => [
                    'model' => 'gemini-3.5-flash',
                    'model_identity' => 'gemini-3.5-flash',
                    'model_label' => 'Gemini Flash',
                    'model_tier' => 'daily',
                    'default_model_alias' => 'gemini_flash',
                    'allow_auto' => true,
                ],
            ],
        ]);

        $this->assertSame('gemini-3.5-flash', $provider['model']);
        $this->assertSame('gemini-3.5-flash', $provider['model_identity']);
        $this->assertSame('Gemini Flash', $provider['model_label']);
        $this->assertSame('daily', $provider['model_tier']);
        $this->assertSame('gemini_flash', $provider['default_model_alias']);
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

    public function test_generic_provider_auto_promotes_model_by_compute_effort(): void
    {
        config()->set('atlas.ai.providers.claude_cli.model', 'claude-sonnet-4-6');
        config()->set('atlas.ai.providers.claude_cli.model_label', 'Claude Sonnet 4.6');
        config()->set('atlas.ai.providers.claude_cli.premium_model', 'claude-opus-4-7');
        config()->set('atlas.ai.providers.claude_cli.premium_model_label', 'Claude Opus 4.7');
        config()->set('atlas.ai.providers.claude_cli.fallback_model', 'claude-haiku-4-5');
        config()->set('atlas.ai.providers.claude_cli.fallback_model_label', 'Claude Haiku 4.5');

        $resolver = app(AiProviderModelResolver::class);

        $deep = $resolver->resolveWithSource('claude_cli', 'auto', [
            'compute_effort' => ['atlas_level' => 'deep'],
        ]);
        $fast = $resolver->resolveWithSource('claude_cli', 'auto', [
            'compute_effort' => ['atlas_level' => 'fast'],
        ]);
        $balanced = $resolver->resolveWithSource('claude_cli', 'auto', [
            'compute_effort' => ['atlas_level' => 'balanced'],
        ]);

        $this->assertSame('claude-opus-4-7', $deep['model']);
        $this->assertSame('premium', $deep['selected_model_alias']);
        $this->assertSame('claude-haiku-4-5', $fast['model']);
        $this->assertSame('fallback', $fast['selected_model_alias']);
        $this->assertSame('claude-sonnet-4-6', $balanced['model']);
        $this->assertSame('default', $balanced['selected_model_alias']);
    }
}
