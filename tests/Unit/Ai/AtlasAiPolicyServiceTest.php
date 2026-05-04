<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AtlasAiPolicyService;
use App\Services\Ai\AtlasDecideService;
use Tests\TestCase;

class AtlasAiPolicyServiceTest extends TestCase
{
    public function test_effective_profile_for_forge_is_high_autonomy_programming_policy(): void
    {
        config([
            'atlas.ai.default_provider' => 'claude_cli',
            'atlas.ai.providers.codex_cli.allow_auto' => true,
        ]);

        $profile = app(AtlasAiPolicyService::class)->effectiveProfile([
            'source_type' => 'manual',
            'payload' => [
                'app_surface' => 'atlas_cli',
                'atlas_workflow_mode' => 'dev',
                'dev_execution_plan' => [
                    'programming_profile' => 'forge',
                ],
            ],
        ]);

        $this->assertSame('programming.forge', $profile['profile_id']);
        $this->assertSame('high', $profile['autonomy_level']);
        $this->assertSame('best_quality', $profile['default_model_policy']);
        $this->assertContains('open_brain_required', $profile['required_gates']);
        $this->assertContains('tool_runtime_gate', $profile['required_gates']);
        $this->assertContains('codex_cli', $profile['fallback_order']);
        $this->assertSame('engineering_harness', data_get($profile, 'execution_policy.executor_preference'));
        $this->assertSame(5, data_get($profile, 'execution_policy.max_iterations'));
        $this->assertTrue((bool) data_get($profile, 'execution_policy.quality_required'));
        $this->assertSame('required', data_get($profile, 'execution_policy.open_brain'));
    }

    public function test_effective_profile_exposes_native_repair_policy_for_complete_dev(): void
    {
        $profile = app(AtlasAiPolicyService::class)->effectiveProfile([
            'source_type' => 'manual',
            'payload' => [
                'app_surface' => 'atlas_cli',
                'atlas_workflow_mode' => 'dev',
                'dev_execution_plan' => [
                    'programming_profile' => 'dev',
                    'operator_options' => [
                        'complete' => true,
                        'max_iterations' => 4,
                    ],
                ],
            ],
        ]);

        $this->assertSame('programming.dev', $profile['profile_id']);
        $this->assertSame('dev_repair_executor', data_get($profile, 'execution_policy.executor_preference'));
        $this->assertSame(4, data_get($profile, 'execution_policy.max_iterations'));
        $this->assertTrue((bool) data_get($profile, 'execution_policy.auto_test'));
        $this->assertTrue((bool) data_get($profile, 'profile_context.programming'));
    }

    public function test_background_policy_requires_explicit_auto_allow(): void
    {
        $profile = app(AtlasAiPolicyService::class)->effectiveProfile([
            'source_type' => 'scheduled',
            'payload' => [
                'app_surface' => 'scheduled',
                'atlas_workflow_mode' => 'dev',
                'task_type' => 'programming',
            ],
        ]);

        $this->assertSame('programming.dev', $profile['profile_id']);
        $this->assertSame('background', $profile['surface']);
        $this->assertFalse((bool) $profile['allow_auto']);
        $this->assertFalse((bool) data_get($profile, 'execution_policy.background_execution.allowed'));
        $this->assertTrue((bool) data_get($profile, 'execution_policy.background_execution.requires_explicit_allow'));
        $this->assertSame('low', data_get($profile, 'execution_policy.background_execution.max_autonomy'));
        $this->assertContains('background_safety_gate', $profile['required_gates']);
    }

    public function test_operational_decision_selects_policy_fallback_when_codex_auto_is_blocked_for_programming(): void
    {
        config([
            'atlas.ai.default_provider' => 'claude_cli',
            'atlas.ai.providers.codex_cli.allow_auto' => false,
            'atlas.ai.providers.claude_cli.allow_auto' => true,
        ]);

        $options = app(AtlasDecideService::class)->normalizeOptions([
            'source_type' => 'manual',
            'input_text' => 'implemente a feature',
            'payload' => [
                'app_surface' => 'atlas_cli',
                'atlas_workflow_mode' => 'dev',
                'decision_mode' => 'atlas_decide',
                'operator_requested_provider' => 'auto',
            ],
        ]);

        $decision = app(AtlasDecideService::class)->operationalDecision($options)->toArray();

        $this->assertSame('codex_cli', data_get($decision, 'provider_selection.candidate_provider'));
        $this->assertSame('claude_cli', data_get($decision, 'provider_selection.selected_provider'));
        $this->assertSame('candidate_auto_disabled', data_get($decision, 'provider_selection.fallback_reason'));
        $this->assertSame('programming.dev', data_get($decision, 'policy_profile_id'));
    }
}
