<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiGatewayService;
use App\Services\Ai\AtlasDecideService;
use ReflectionClass;
use Tests\TestCase;

class AiGatewayProviderGateTest extends TestCase
{
    public function test_gemini_manual_request_is_allowed_for_dev_workflow(): void
    {
        config([
            'atlas.ai.default_provider' => 'gemini_cli',
            'atlas.ai.providers.gemini_cli.allow_manual' => true,
        ]);

        $provider = $this->providerFromOptions([
            'provider' => 'gemini_cli',
            'agent_slug' => 'desenvolvedor',
            'payload' => [
                'atlas_workflow_mode' => 'dev',
                'requested_provider' => 'gemini_cli',
            ],
        ]);

        $this->assertSame('gemini_cli', $provider);
    }

    public function test_gemini_default_falls_back_to_claude_for_debug_task(): void
    {
        config([
            'atlas.ai.default_provider' => 'gemini_cli',
            'atlas.ai.providers.gemini_cli.allow_auto' => true,
            'atlas.ai.providers.gemini_cli.allow_manual' => true,
        ]);

        $provider = $this->providerFromOptions([
            'payload' => [
                'atlas_workflow_mode' => 'dev',
                'task_type' => 'debug',
            ],
        ]);

        $this->assertSame('claude_cli', $provider);
    }

    public function test_manual_provider_flag_fails_when_provider_manual_use_is_disabled(): void
    {
        config([
            'atlas.ai.default_provider' => 'gemini_cli',
            'atlas.ai.providers.gemini_cli.allow_manual' => false,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Provider gemini_cli esta bloqueado para uso manual');

        $this->providerFromOptions([
            'provider' => 'gemini_cli',
            'payload' => [
                'atlas_workflow_mode' => 'direct',
            ],
        ]);
    }

    public function test_automatic_gemini_stays_available_for_non_dev_when_enabled(): void
    {
        config([
            'atlas.ai.default_provider' => 'claude_cli',
            'atlas.ai.providers.gemini_cli.allow_auto' => true,
        ]);

        $provider = $this->providerFromOptions([
            'provider' => 'gemini_cli',
            'source_type' => 'system',
            'payload' => [
                'automatic' => true,
                'atlas_workflow_mode' => 'research',
            ],
        ]);

        $this->assertSame('gemini_cli', $provider);
    }

    public function test_default_codex_is_used_when_no_provider_is_requested(): void
    {
        config([
            'atlas.ai.default_provider' => 'codex_cli',
            'atlas.ai.providers.codex_cli.allow_manual' => true,
            'atlas.ai.providers.codex_cli.allow_auto' => true,
        ]);

        $manual = $this->providerFromOptions([
            'payload' => [
                'atlas_workflow_mode' => 'direct',
            ],
        ]);
        $automatic = $this->providerFromOptions([
            'source_type' => 'system',
            'payload' => [
                'automatic' => true,
                'atlas_workflow_mode' => 'research',
            ],
        ]);

        $this->assertSame('codex_cli', $manual);
        $this->assertSame('codex_cli', $automatic);
    }

    public function test_atlas_decide_normalizes_auto_without_manual_override(): void
    {
        $options = app(AtlasDecideService::class)->normalizeOptions([
            'provider' => 'gemini_cli',
            'payload' => [
                'requested_provider' => 'auto',
                'operator_requested_provider' => 'auto',
            ],
        ]);

        $this->assertSame('atlas_decide', data_get($options, 'payload.decision_mode'));
        $this->assertSame('auto', data_get($options, 'payload.operator_requested_provider'));
        $this->assertNull(data_get($options, 'payload.requested_provider'));
        $this->assertArrayNotHasKey('provider', $options);
        $this->assertFalse(app(AtlasDecideService::class)->receiptForTrace($options, 'gemini_cli')['was_overridden']);
    }

    public function test_atlas_decide_prefers_gemini_for_auto_attachment_when_enabled(): void
    {
        config([
            'atlas.ai.default_provider' => 'claude_cli',
            'atlas.ai.providers.gemini_cli.allow_auto' => true,
        ]);

        $provider = $this->providerFromOptions([
            'provider' => 'gemini_cli',
            'payload' => [
                'operator_requested_provider' => 'auto',
                'visual_input' => ['image_count' => 1],
            ],
        ]);

        $this->assertSame('gemini_cli', $provider);
    }

    public function test_atlas_decide_prefers_codex_for_programming_when_auto_allowed(): void
    {
        config([
            'atlas.ai.default_provider' => 'claude_cli',
            'atlas.ai.providers.codex_cli.allow_auto' => true,
        ]);

        $provider = $this->providerFromOptions([
            'payload' => [
                'decision_mode' => 'atlas_decide',
                'operator_requested_provider' => 'auto',
                'atlas_workflow_mode' => 'dev',
            ],
        ]);

        $this->assertSame('codex_cli', $provider);
    }

    public function test_atlas_decide_respects_codex_auto_block_for_programming(): void
    {
        config([
            'atlas.ai.default_provider' => 'claude_cli',
            'atlas.ai.providers.codex_cli.allow_auto' => false,
        ]);

        $provider = $this->providerFromOptions([
            'payload' => [
                'decision_mode' => 'atlas_decide',
                'operator_requested_provider' => 'auto',
                'atlas_workflow_mode' => 'dev',
            ],
        ]);

        $this->assertSame('claude_cli', $provider);
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function providerFromOptions(array $options): string
    {
        $service = app(AiGatewayService::class);
        $options = app(AtlasDecideService::class)->normalizeOptions($options);
        $method = (new ReflectionClass($service))->getMethod('providerFromOptions');
        $method->setAccessible(true);

        return $method->invoke($service, $options);
    }
}
