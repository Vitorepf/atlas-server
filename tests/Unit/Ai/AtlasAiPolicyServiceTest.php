<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\Policy\AtlasAiPolicyService;
use App\Services\Ai\AtlasDecideService;
use App\Services\Ai\FairClaudePolicy;
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
        $this->assertSame(2, $profile['schema_version']);
        $this->assertSame('atlas-ai-policy-v2', $profile['policy_version']);
        $this->assertSame('programming', $profile['domain']);
        $this->assertSame('programming.forge', $profile['flow']);
        $this->assertSame('AtlasProgrammingOrchestrator', data_get($profile, 'domain_profile.orchestrator'));
        $this->assertSame('EngineeringHarness', data_get($profile, 'flow_profile.runtime'));
        $this->assertSame('atlas-ai-policy-v2', data_get($profile, 'effective_policy.policy_version'));
        $this->assertSame('legacy_execution_policy', data_get($profile, 'effective_policy.execution_authority'));
        $this->assertSame('scout_execute_review', data_get($profile, 'effective_policy.operational_contracts.model_graph.graph'));
        $this->assertSame('context_scout', data_get($profile, 'effective_policy.operational_contracts.model_graph.nodes.0.role'));
        $this->assertSame('harness', data_get($profile, 'effective_policy.operational_contracts.tools.mode'));
        $this->assertSame('strict', data_get($profile, 'effective_policy.operational_contracts.gates.minimum_gate'));
        $this->assertContains('quality_scan', data_get($profile, 'effective_policy.operational_contracts.gates.required_gates'));
        $this->assertSame('high', $profile['autonomy_level']);
        $this->assertSame('best_quality', $profile['default_model_policy']);
        $this->assertSame('atlas.runtime_budget.governance_contract.v1', data_get($profile, 'budget_policy.governance_contract.schema_version'));
        $this->assertFalse(data_get($profile, 'budget_policy.governance_contract.autonomy_escalation_allowed'));
        $this->assertFalse(data_get($profile, 'budget_policy.governance_contract.budget_limit_auto_raise_allowed'));
        $this->assertTrue(data_get($profile, 'budget_policy.governance_contract.requires_human_review_for_limit_change'));
        $this->assertTrue(data_get($profile, 'budget_policy.governance_contract.requires_decision_receipt_for_limit_change'));
        $this->assertContains('change_provider_policy_from_budget_signal', data_get($profile, 'budget_policy.governance_contract.forbidden_actions'));
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
        $this->assertSame('programming', data_get($profile, 'profile_context.domain'));
        $this->assertSame('programming.dev', data_get($profile, 'profile_context.flow'));
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

    public function test_auto_candidate_provider_handles_image_rich_input_without_500(): void
    {
        config([
            'atlas.ai.default_provider' => 'claude_cli',
            'atlas.ai.providers.gemini_cli.allow_auto' => true,
        ]);

        $options = app(AtlasDecideService::class)->normalizeOptions([
            'source_type' => 'manual',
            'input_text' => 'analise esta imagem',
            'payload' => [
                'app_surface' => 'atlas_desktop_ai',
                'decision_mode' => 'atlas_decide',
                'operator_requested_provider' => 'auto',
                'rich_input_payload' => [
                    'schema_version' => 'atlas.rich_input.payload.v1',
                    'uploaded_image_ids' => ['img_123'],
                    'source_manifest' => [[
                        'kind' => 'image',
                        'mime_type' => 'image/png',
                        'uploaded_id' => 'img_123',
                    ]],
                ],
            ],
        ]);

        $candidate = app(AtlasDecideService::class)->candidateProvider($options, 'claude_cli', 'auto');

        $this->assertSame('gemini_cli', $candidate);
    }

    public function test_session_policy_override_locks_provider_model_without_overriding_executor(): void
    {
        $profile = app(AtlasAiPolicyService::class)->effectiveProfile([
            'source_type' => 'manual',
            'payload' => [
                'app_surface' => 'atlas_cli',
                'atlas_workflow_mode' => 'dev',
                'ai_policy_override' => [
                    'default_provider' => 'codex_cli',
                    'providers' => [
                        'codex_cli' => [
                            'model' => 'gpt-5.5',
                            'model_label' => 'GPT-5.5',
                            'model_tier' => 'premium',
                            'allow_auto' => true,
                        ],
                    ],
                    'allowed_models' => [
                        'codex_cli' => ['gpt-5.5'],
                    ],
                    'execution_policy' => [
                        'executor_preference' => 'engineering_harness',
                    ],
                ],
            ],
        ]);

        $this->assertSame('codex_cli', $profile['default_provider']);
        $this->assertSame(['gpt-5.5'], data_get($profile, 'allowed_models.codex_cli'));
        $this->assertSame('gpt-5.5', data_get($profile, 'providers.codex_cli.model'));
        $this->assertSame('premium', data_get($profile, 'providers.codex_cli.model_tier'));
        $this->assertSame('simple_provider_execution', data_get($profile, 'execution_policy.executor_preference'));
        $this->assertSame('simple_provider_execution', data_get($profile, 'effective_policy.execution_policy.executor_preference'));
        $this->assertSame('simple_provider_execution', data_get($profile, 'effective_policy.profile_declared_execution_policy.executor_preference'));
        $this->assertSame('single_executor', data_get($profile, 'effective_policy.operational_contracts.model_graph.graph'));
        $this->assertSame('workspace_write', data_get($profile, 'effective_policy.operational_contracts.tools.mode'));
        $this->assertTrue((bool) data_get($profile, 'effective_policy.session_override.present'));
        $this->assertContains('providers', data_get($profile, 'effective_policy.session_override.applied_keys'));
        $this->assertContains(
            'session_execution_policy_ignored_until_effective_policy_v2_execution_authority',
            data_get($profile, 'effective_policy.merge_warnings')
        );
    }

    public function test_gemini_policy_allowlist_includes_flash_and_pro_catalog_models(): void
    {
        config()->set('atlas.ai.providers.gemini_cli.models.gemini_flash.model', 'gemini-3.5-flash');
        config()->set('atlas.ai.providers.gemini_cli.models.gemini_pro.model', 'gemini-3.1-pro-preview');

        $profile = app(AtlasAiPolicyService::class)->effectiveProfile([
            'source_type' => 'manual',
            'payload' => [
                'app_surface' => 'atlas_cli',
                'atlas_workflow_mode' => 'research',
            ],
        ]);

        $this->assertSame(
            ['gemini-3.5-flash', 'gemini-3.1-pro-preview'],
            data_get($profile, 'allowed_models.gemini_cli'),
        );
        $this->assertSame('gemini_flash', data_get($profile, 'providers.gemini_cli.default_model_alias'));
        $this->assertSame('gemini-3.5-flash', data_get($profile, 'providers.gemini_cli.models.gemini_flash.model'));
        $this->assertSame('gemini-3.1-pro-preview', data_get($profile, 'providers.gemini_cli.models.gemini_pro.model'));
    }

    public function test_fair_claude_override_collapses_forge_model_graph_to_single_claude_executor(): void
    {
        $profile = app(AtlasAiPolicyService::class)->effectiveProfile([
            'source_type' => 'manual',
            'payload' => [
                'app_surface' => 'atlas_cli',
                'atlas_workflow_mode' => 'dev',
                'dev_execution_plan' => [
                    'programming_profile' => 'forge',
                    'fair_mode' => app(FairClaudePolicy::class)->metadata(),
                ],
                'ai_policy_override' => app(FairClaudePolicy::class)->runtimeOverride([
                    'model' => 'claude-opus-test',
                    'label' => 'Claude Opus Test',
                    'tier' => 'premium',
                ]),
            ],
        ]);

        $this->assertSame(['claude_cli'], data_get($profile, 'effective_policy.runtime_policy.enabled_providers'));
        $this->assertSame(['claude_cli'], data_get($profile, 'effective_policy.runtime_policy.fallback_order'));
        $this->assertSame(['claude-opus-test'], data_get($profile, 'effective_policy.runtime_policy.allowed_models.claude_cli'));
        $this->assertNull(data_get($profile, 'effective_policy.runtime_policy.allowed_models.codex_cli'));
        $this->assertFalse(data_get($profile, 'effective_policy.runtime_policy.providers.codex_cli.allow_manual'));
        $this->assertSame('single_executor', data_get($profile, 'effective_policy.operational_contracts.model_graph.graph'));
        $this->assertSame('claude_cli', data_get($profile, 'effective_policy.operational_contracts.model_graph.nodes.0.provider'));
        $this->assertSame(['claude_cli'], data_get($profile, 'effective_policy.operational_contracts.model_graph.nodes.0.fallback_order'));
        $this->assertCount(1, data_get($profile, 'effective_policy.operational_contracts.model_graph.nodes'));
    }
}
