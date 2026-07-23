<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Services\Ai\AiProviderModelResolver;
use App\Services\Ai\SelfConstruction\Governance\AtlasTaskGovernancePolicyPlane;
use Tests\TestCase;

final class DevModelTierPolicyResolutionTest extends TestCase
{
    private function configureClaudeCli(): void
    {
        config()->set('atlas.ai.providers.claude_cli.model', 'claude-sonnet-4-6');
        config()->set('atlas.ai.providers.claude_cli.model_label', 'Claude Sonnet 4.6');
        config()->set('atlas.ai.providers.claude_cli.fallback_model', 'claude-haiku-4-5');
        config()->set('atlas.ai.providers.claude_cli.fallback_model_label', 'Claude Haiku 4.5');
    }

    private function resolverWithPolicy(array $policyConfig): AiProviderModelResolver
    {
        return new AiProviderModelResolver(
            app(\App\Services\Ai\Policy\AtlasAiRuntimeSettings::class),
            app(\App\Services\Ai\GeminiModelCatalog::class),
            new AtlasTaskGovernancePolicyPlane($policyConfig),
        );
    }

    // ── (a) small tier declared -> resolver resolves the small tier when dev task shape rides in context ──

    public function test_small_tier_policy_resolves_small_tier_via_fallback_model(): void
    {
        $this->configureClaudeCli();

        $resolver = $this->resolverWithPolicy([
            'dev_model_tier_policy' => [
                'read_only' => ['low' => ['small' => 'small']],
            ],
        ]);

        $resolution = $resolver->resolveWithSource('claude_cli', 'auto', [
            'dev_task' => [
                'task_kind' => 'read_only',
                'risk_level' => 'low',
                'workcell_size_class' => 'small',
            ],
        ]);

        $this->assertSame('claude-haiku-4-5', $resolution['model']);
        $this->assertSame('small', $resolution['model_tier']);
        $this->assertSame('dev_model_tier_policy', $resolution['source']);
    }

    public function test_frontier_tier_policy_leaves_resolution_unchanged(): void
    {
        $this->configureClaudeCli();

        $resolver = $this->resolverWithPolicy([
            'dev_model_tier_policy' => [
                'read_only' => ['low' => ['small' => 'frontier']],
            ],
        ]);

        $withDevTask = $resolver->resolveWithSource('claude_cli', 'auto', [
            'dev_task' => ['task_kind' => 'read_only', 'risk_level' => 'low', 'workcell_size_class' => 'small'],
        ]);
        $withoutDevTask = $resolver->resolveWithSource('claude_cli', 'auto', []);

        $this->assertSame($withoutDevTask, $withDevTask);
    }

    // ── (b) empty config -> byte-identical to today ─────────────────────────

    public function test_empty_config_dev_task_resolution_is_byte_identical_to_no_dev_task(): void
    {
        $this->configureClaudeCli();

        $resolver = $this->resolverWithPolicy([]);

        $withDevTask = $resolver->resolveWithSource('claude_cli', 'auto', [
            'dev_task' => ['task_kind' => 'read_only', 'risk_level' => 'low', 'workcell_size_class' => 'small'],
        ]);
        $withoutDevTask = $resolver->resolveWithSource('claude_cli', 'auto', []);

        $this->assertSame($withoutDevTask, $withDevTask);
        $this->assertNotSame('small', $withDevTask['model_tier']);
    }

    public function test_undeclared_combination_falls_back_to_frontier_and_stays_unchanged(): void
    {
        $this->configureClaudeCli();

        $resolver = $this->resolverWithPolicy([
            'dev_model_tier_policy' => [
                'read_only' => ['low' => ['small' => 'small']],
            ],
        ]);

        // Different task_kind not declared in policy -> falls back to frontier -> unchanged resolution.
        $result = $resolver->resolveWithSource('claude_cli', 'auto', [
            'dev_task' => ['task_kind' => 'write', 'risk_level' => 'high', 'workcell_size_class' => 'large'],
        ]);
        $baseline = $resolver->resolveWithSource('claude_cli', 'auto', []);

        $this->assertSame($baseline, $result);
    }

    // ── (c) callers that omit the dev task shape are untouched ──────────────

    public function test_caller_without_dev_task_key_is_untouched_even_with_small_tier_policy_present(): void
    {
        $this->configureClaudeCli();

        $resolverWithPolicy = $this->resolverWithPolicy([
            'dev_model_tier_policy' => [
                'read_only' => ['low' => ['small' => 'small']],
            ],
        ]);
        $resolverWithoutPolicy = $this->resolverWithPolicy([]);

        $result = $resolverWithPolicy->resolveWithSource('claude_cli', 'auto', ['domain' => 'programming']);
        $baseline = $resolverWithoutPolicy->resolveWithSource('claude_cli', 'auto', ['domain' => 'programming']);

        $this->assertSame($baseline, $result);
    }

    public function test_gemini_provider_ignores_dev_task_shape_entirely(): void
    {
        config()->set('atlas.ai.providers.gemini_cli.models.gemini_flash.model', 'gemini-3.5-flash');
        config()->set('atlas.ai.providers.gemini_cli.models.gemini_pro.model', 'gemini-3.1-pro-preview');
        config()->set('atlas.ai.providers.gemini_cli.default_model_alias', 'gemini_flash');

        $resolver = $this->resolverWithPolicy([
            'dev_model_tier_policy' => [
                'read_only' => ['low' => ['small' => 'small']],
            ],
        ]);

        $withDevTask = $resolver->resolveWithSource('gemini_cli', 'auto', [
            'dev_task' => ['task_kind' => 'read_only', 'risk_level' => 'low', 'workcell_size_class' => 'small'],
        ]);
        $withoutDevTask = $resolver->resolveWithSource('gemini_cli', 'auto', []);

        $this->assertSame($withoutDevTask, $withDevTask);
    }

    public function test_default_constructed_resolver_reads_the_real_shipped_config(): void
    {
        // No policy plane injected -> falls back to `new AtlasTaskGovernancePolicyPlane`, which reads the
        // REAL config/atlas_task_governance.php. That file declares read_only/low/small -> small, so this
        // proves the wiring is live end-to-end, not just reachable through an injected override.
        $this->configureClaudeCli();

        $resolver = new AiProviderModelResolver(
            app(\App\Services\Ai\Policy\AtlasAiRuntimeSettings::class),
            app(\App\Services\Ai\GeminiModelCatalog::class),
        );

        $withDevTask = $resolver->resolveWithSource('claude_cli', 'auto', [
            'dev_task' => ['task_kind' => 'read_only', 'risk_level' => 'low', 'workcell_size_class' => 'small'],
        ]);

        $this->assertSame('claude-haiku-4-5', $withDevTask['model']);
        $this->assertSame('small', $withDevTask['model_tier']);
    }

    public function test_default_constructed_resolver_untouched_when_dev_task_omitted(): void
    {
        $this->configureClaudeCli();

        $resolver = new AiProviderModelResolver(
            app(\App\Services\Ai\Policy\AtlasAiRuntimeSettings::class),
            app(\App\Services\Ai\GeminiModelCatalog::class),
        );

        $result = $resolver->resolveWithSource('claude_cli', 'auto', []);

        $this->assertSame('claude-sonnet-4-6', $result['model']);
        $this->assertNotSame('small', $result['model_tier']);
    }
}
