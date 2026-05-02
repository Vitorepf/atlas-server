<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiGatewayService;
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

    public function test_manual_provider_flag_is_respected_for_gemini(): void
    {
        config([
            'atlas.ai.default_provider' => 'gemini_cli',
            'atlas.ai.providers.gemini_cli.allow_manual' => false,
        ]);

        $provider = $this->providerFromOptions([
            'provider' => 'gemini_cli',
            'payload' => [
                'atlas_workflow_mode' => 'direct',
            ],
        ]);

        $this->assertSame('claude_cli', $provider);
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

    /**
     * @param  array<string,mixed>  $options
     */
    private function providerFromOptions(array $options): string
    {
        $service = app(AiGatewayService::class);
        $method = (new ReflectionClass($service))->getMethod('providerFromOptions');
        $method->setAccessible(true);

        return $method->invoke($service, $options);
    }
}
