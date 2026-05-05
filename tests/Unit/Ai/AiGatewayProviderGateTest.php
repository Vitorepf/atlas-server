<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiGatewayService;
use App\Services\Ai\AtlasDecideService;
use App\Services\Ai\FairClaudePolicy;
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

    public function test_visual_dev_task_keeps_claude_default_with_image_attachments(): void
    {
        config([
            'atlas.ai.default_provider' => 'claude_cli',
            'atlas.ai.providers.codex_cli.allow_auto' => false,
            'atlas.ai.providers.codex_cli.allow_manual' => true,
            'atlas.ai.providers.gemini_cli.allow_auto' => true,
        ]);

        $provider = $this->providerFromOptions([
            'payload' => [
                'decision_mode' => 'atlas_decide',
                'operator_requested_provider' => 'auto',
                'atlas_workflow_mode' => 'dev',
                'visual_input' => ['image_count' => 1],
                'attachments' => [
                    'images' => [
                        ['path' => '/tmp/screen.png', 'mime_type' => 'image/png'],
                    ],
                ],
            ],
        ]);

        $this->assertSame('claude_cli', $provider);
    }

    public function test_manual_claude_provider_accepts_image_attachments(): void
    {
        $provider = $this->providerFromOptions([
            'provider' => 'claude_cli',
            'payload' => [
                'requested_provider' => 'claude_cli',
                'attachments' => [
                    'images' => [
                        ['path' => '/tmp/screen.png', 'mime_type' => 'image/png'],
                    ],
                ],
            ],
        ]);

        $this->assertSame('claude_cli', $provider);
    }

    public function test_fair_mode_provider_gate_rejects_non_claude_provider(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('fair_mode_violation');

        $service = app(AiGatewayService::class);
        $method = (new ReflectionClass($service))->getMethod('enforceFairModeProvider');
        $method->setAccessible(true);
        $method->invoke($service, [
            'fair_mode' => app(FairClaudePolicy::class)->metadata(),
        ], 'codex_cli');
    }

    public function test_fair_mode_model_gate_rejects_model_drift(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('fair_mode_violation');

        $service = app(AiGatewayService::class);
        $method = (new ReflectionClass($service))->getMethod('enforceFairModeModel');
        $method->setAccessible(true);
        $method->invoke($service, [
            'fair_mode' => app(FairClaudePolicy::class)->metadata(),
            'requested_model' => 'claude-opus-4-7',
            'requested_model_alias' => 'opus',
            'requested_model_tier' => 'premium',
        ], 'claude_cli', 'claude-haiku');
    }

    public function test_fair_mode_disables_atlas_scout_and_limits_decision_candidates_to_claude(): void
    {
        $service = app(AiGatewayService::class);
        $options = app(AtlasDecideService::class)->normalizeOptions([
            'provider' => 'claude_cli',
            'source_type' => 'system',
            'payload' => [
                'automatic' => true,
                'atlas_workflow_mode' => 'dev',
                'fair_mode' => app(FairClaudePolicy::class)->metadata(),
                'requested_model' => 'claude-opus-4-7',
                'requested_model_alias' => 'opus',
                'requested_model_tier' => 'premium',
                'ai_policy_override' => app(FairClaudePolicy::class)->runtimeOverride([
                    'model' => 'claude-opus-4-7',
                    'label' => 'Claude Opus 4.7',
                    'tier' => 'premium',
                ]),
            ],
        ]);

        $providerMethod = (new ReflectionClass($service))->getMethod('providerFromOptions');
        $providerMethod->setAccessible(true);
        $provider = $providerMethod->invoke($service, $options);

        $this->assertSame('claude_cli', $provider);

        $scoutMethod = (new ReflectionClass($service))->getMethod('atlasScoutGate');
        $scoutMethod->setAccessible(true);
        $scoutGate = $scoutMethod->invoke($service, $options, 'claude_cli', 'claude-opus-4-7');

        $this->assertFalse($scoutGate['enabled']);
        $this->assertSame('fair_mode_single_provider', $scoutGate['activation_status']);
        $this->assertSame('fair_mode_atlas_decide_disabled', $scoutGate['blocked_reason']);
        $this->assertSame('claude_cli', $scoutGate['scout_provider']);
        $this->assertSame('claude-opus-4-7', $scoutGate['scout_model']);

        $candidatesMethod = (new ReflectionClass($service))->getMethod('decisionCandidates');
        $candidatesMethod->setAccessible(true);
        $candidates = $candidatesMethod->invoke($service, $options, 'claude_cli');

        $this->assertCount(1, $candidates);
        $this->assertSame('claude_cli', $candidates[0]['provider']);
        $this->assertTrue($candidates[0]['fair_mode_locked']);
    }

    public function test_fair_mode_strips_tampered_dual_review_and_never_runs_council(): void
    {
        $service = app(AiGatewayService::class);
        $policy = app(FairClaudePolicy::class);
        $providerMethod = (new ReflectionClass($service))->getMethod('providerFromOptions');
        $providerMethod->setAccessible(true);

        $this->assertSame('claude_cli', $providerMethod->invoke($service, [
            'provider' => 'claude_cli',
            'payload' => [
                'fair_mode' => $policy->metadata(),
                'execution_policy' => 'dual_review',
                'council_providers' => ['claude_cli', 'codex_cli'],
            ],
        ]));

        $enforceMethod = (new ReflectionClass($service))->getMethod('enforceFairModeProvider');
        $enforceMethod->setAccessible(true);
        $payload = $enforceMethod->invoke($service, [
            'fair_mode' => $policy->metadata(),
            'execution_policy' => 'dual_review',
            'council_providers' => ['claude_cli', 'codex_cli'],
        ], 'claude_cli');

        $this->assertNull($payload['execution_policy']);
        $this->assertNull($payload['council_providers']);
        $this->assertTrue($payload['council_disabled_by_fair_mode']);

        $shouldRunCouncilMethod = (new ReflectionClass($service))->getMethod('shouldRunCouncil');
        $shouldRunCouncilMethod->setAccessible(true);

        $this->assertFalse($shouldRunCouncilMethod->invoke($service, [
            'provider' => 'claude_cli',
            'payload' => [
                ...$payload,
                'execution_policy' => 'dual_review',
                'council_providers' => ['claude_cli', 'codex_cli'],
            ],
        ]));
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
