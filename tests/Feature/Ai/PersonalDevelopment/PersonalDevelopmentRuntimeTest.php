<?php

namespace Tests\Feature\Ai\PersonalDevelopment;

use App\Services\Ai\PersonalDevelopment\PersonalDevelopmentMemoryPolicy;
use App\Services\Ai\PersonalDevelopment\PersonalDevelopmentRuntime;
use Tests\TestCase;

class PersonalDevelopmentRuntimeTest extends TestCase
{
    public function test_memory_policy_is_private_and_provider_safe_only_after_redaction(): void
    {
        $policy = app(PersonalDevelopmentMemoryPolicy::class);

        $raw = $policy->classify([
            'title' => 'Private routine note',
            'body' => 'Raw personal reflection.',
            'redaction_status' => 'clean',
        ]);
        $redacted = $policy->classify([
            'title' => 'Private routine note',
            'body' => 'Raw personal reflection with sensitive detail.',
            'redacted_title' => 'Routine note',
            'redacted_body' => 'Provider-safe routine reflection.',
            'redaction_status' => 'redacted',
        ]);

        $this->assertSame('private', $raw['privacy_class']);
        $this->assertFalse($raw['provider_safe']);
        $this->assertTrue($raw['review_required']);
        $this->assertSame([], $raw['provider_payload']);

        $this->assertSame('private', $redacted['privacy_class']);
        $this->assertTrue($redacted['provider_safe']);
        $this->assertFalse($redacted['review_required']);
        $this->assertSame('Provider-safe routine reflection.', data_get($redacted, 'provider_payload.body'));
    }

    public function test_memory_policy_blocks_redacted_payload_that_reuses_raw_personal_text(): void
    {
        $result = app(PersonalDevelopmentMemoryPolicy::class)->classify([
            'title' => 'Private note',
            'body' => 'Raw private personal text.',
            'redacted_body' => 'Raw private personal text.',
            'redaction_status' => 'redacted',
        ]);

        $this->assertSame('private', $result['privacy_class']);
        $this->assertFalse($result['provider_safe']);
        $this->assertSame([], $result['provider_payload']);
        $this->assertContains('redacted_payload_reuses_raw_personal_text', $result['review_reasons']);
    }

    public function test_runtime_returns_structured_plan_and_artifacts_without_destructive_side_effects(): void
    {
        $result = app(PersonalDevelopmentRuntime::class)->execute('focus_plan', [
            'goal' => 'Protect deep work blocks',
            'observations' => ['mornings have fewer interruptions'],
            'unexpected_private_blob' => 'This should not become artifact input.',
        ], [
            'evidence_refs' => [
                ['type' => 'review_note', 'id' => 'note-1'],
                ['type' => 'invalid'],
            ],
        ]);

        $this->assertSame('planned', $result['status']);
        $this->assertSame('personal_development.focus_plan', $result['flow']);
        $this->assertSame('focus_block_plan', data_get($result, 'plan.type'));
        $this->assertTrue((bool) data_get($result, 'plan.input_ready'));
        $this->assertContains('goal', data_get($result, 'input_contract.accepted_keys'));
        $this->assertContains('unexpected_private_blob', data_get($result, 'input_contract.ignored_keys'));
        $this->assertContains('attention_budget', data_get($result, 'plan.focus_areas'));
        $this->assertNotEmpty($result['artifacts']);
        $this->assertSame([['type' => 'review_note', 'id' => 'note-1']], $result['evidence_refs']);
        $this->assertContains('calendar_write', $result['blocked_actions']);
        $this->assertFalse((bool) data_get($result, 'side_effects.calendar_mutations'));
        $this->assertFalse((bool) data_get($result, 'side_effects.task_mutations'));
        $this->assertFalse((bool) data_get($result, 'side_effects.destructive_actions'));
        $this->assertTrue((bool) data_get($result, 'safety_contract.non_clinical'));
        $this->assertTrue((bool) data_get($result, 'safety_contract.no_psychological_diagnosis'));
    }

    public function test_runtime_checks_sensitivity_against_raw_input_even_when_key_is_ignored(): void
    {
        $result = app(PersonalDevelopmentRuntime::class)->execute('reflect', [
            'intent' => 'Review the week.',
            'raw_diary_export' => 'Private note mentioning anxiety.',
        ]);

        $this->assertContains('raw_diary_export', data_get($result, 'input_contract.ignored_keys'));
        $this->assertSame('needs_human_review', $result['status']);
        $this->assertContains('anxiety', data_get($result, 'sensitivity.markers'));
        $this->assertContains('sensitive_personal_recommendation_requires_human_review', $result['approval_reasons']);
    }

    public function test_sensitive_recommendations_are_routed_to_human_review(): void
    {
        $result = app(PersonalDevelopmentRuntime::class)->execute('recovery_plan', [
            'intent' => 'Criar uma rotina de recuperação depois de sinais de esgotamento.',
        ]);

        $this->assertSame('needs_human_review', $result['status']);
        $this->assertTrue($result['approval_required']);
        $this->assertContains('sensitive_personal_recommendation_requires_human_review', $result['approval_reasons']);
        $this->assertContains('esgotamento', data_get($result, 'sensitivity.markers'));
        $this->assertFalse((bool) data_get($result, 'side_effects.task_mutations'));
    }

    public function test_forge_remains_approval_required_after_human_approval_but_can_produce_plan(): void
    {
        $result = app(PersonalDevelopmentRuntime::class)->execute('forge', [
            'intent' => 'Integrate goals, focus, learning, and weekly review.',
        ], [
            'human_approved' => true,
        ]);

        $this->assertSame('planned', $result['status']);
        $this->assertTrue($result['approval_required']);
        $this->assertContains('forge_flow_requires_human_approval', $result['approval_reasons']);
        $this->assertSame('integrated_personal_operating_plan', data_get($result, 'plan.type'));
        $this->assertContains('personal_operating_system_brief', array_column($result['artifacts'], 'id'));
    }

    public function test_runtime_has_flow_specific_artifacts_for_every_supported_flow(): void
    {
        $expectedArtifacts = [
            'reflect' => 'reflection_brief',
            'daily_review' => 'daily_review_summary',
            'weekly_review' => 'weekly_review_summary',
            'habit_design' => 'habit_experiment_card',
            'focus_plan' => 'focus_block_brief',
            'learning_plan' => 'learning_loop_plan',
            'energy_review' => 'energy_pattern_review',
            'goal_decomposition' => 'goal_breakdown',
            'recovery_plan' => 'recovery_routine_brief',
            'forge' => 'personal_operating_system_brief',
        ];

        foreach ($expectedArtifacts as $flow => $artifactId) {
            $result = app(PersonalDevelopmentRuntime::class)->execute($flow, ['intent' => 'Draft a plan.'], [
                'human_approved' => $flow === 'forge',
            ]);

            $this->assertSame('personal_development.'.$flow, $result['flow']);
            $this->assertContains($artifactId, array_column($result['artifacts'], 'id'));
            $this->assertContains('human_review_packet', array_column($result['artifacts'], 'id'));
            $this->assertTrue((bool) data_get($result, 'safety_contract.private_by_default'));
            $this->assertFalse((bool) data_get($result, 'side_effects.habit_mutations'));
        }
    }
}
