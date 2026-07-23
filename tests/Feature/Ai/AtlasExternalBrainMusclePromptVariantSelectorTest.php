<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainMusclePromptVariantSelector;
use Tests\TestCase;

final class AtlasExternalBrainMusclePromptVariantSelectorTest extends TestCase
{
    private function selector(): AtlasExternalBrainMusclePromptVariantSelector
    {
        return new AtlasExternalBrainMusclePromptVariantSelector;
    }

    public function test_output_has_required_keys(): void
    {
        $r = $this->selector()->select([]);

        foreach (['schema', 'prompt_variant_id', 'included_sections', 'omitted_sections', 'guardrails', 'reason'] as $key) {
            $this->assertArrayHasKey($key, $r);
        }
        $this->assertSame(AtlasExternalBrainMusclePromptVariantSelector::SCHEMA, $r['schema']);
    }

    public function test_default_selects_external_muscle_variant(): void
    {
        $r = $this->selector()->select([]);

        $this->assertSame(AtlasExternalBrainMusclePromptVariantSelector::VARIANT_EXTERNAL_MUSCLE, $r['prompt_variant_id']);
        $this->assertNotEmpty($r['reason']);
    }

    public function test_local_subscription_client_selects_its_variant(): void
    {
        $r = $this->selector()->select(['muscle_type' => 'local_subscription_client']);

        $this->assertSame(AtlasExternalBrainMusclePromptVariantSelector::VARIANT_LOCAL_SUBSCRIPTION_CLIENT, $r['prompt_variant_id']);
    }

    public function test_high_risk_task_selects_its_variant(): void
    {
        $r = $this->selector()->select(['risk_level' => 'high']);

        $this->assertSame(AtlasExternalBrainMusclePromptVariantSelector::VARIANT_HIGH_RISK_TASK, $r['prompt_variant_id']);
        $this->assertContains('risk_review_checklist', $r['included_sections']);
        $this->assertContains('dual_review_requirement', $r['included_sections']);
    }

    public function test_low_context_task_selects_its_variant(): void
    {
        $r = $this->selector()->select(['context_size' => 'low']);

        $this->assertSame(AtlasExternalBrainMusclePromptVariantSelector::VARIANT_LOW_CONTEXT_TASK, $r['prompt_variant_id']);
        $this->assertContains('minimal_scope_reminder', $r['included_sections']);
        $this->assertNotContains('full_codebase_context', $r['included_sections']);
    }

    public function test_recovery_task_mode_selects_its_variant(): void
    {
        $r = $this->selector()->select(['task_mode' => 'recovery']);

        $this->assertSame(AtlasExternalBrainMusclePromptVariantSelector::VARIANT_RECOVERY_GIVE_BACK_TASK, $r['prompt_variant_id']);
        $this->assertContains('recovery_diagnosis_steps', $r['included_sections']);
        $this->assertContains('duplicate_detection_steps', $r['included_sections']);
    }

    public function test_give_back_task_mode_selects_recovery_variant(): void
    {
        $r = $this->selector()->select(['task_mode' => 'give_back']);

        $this->assertSame(AtlasExternalBrainMusclePromptVariantSelector::VARIANT_RECOVERY_GIVE_BACK_TASK, $r['prompt_variant_id']);
    }

    public function test_recovery_mode_takes_priority_over_high_risk_and_muscle_type(): void
    {
        $r = $this->selector()->select([
            'task_mode' => 'give_back',
            'risk_level' => 'high',
            'muscle_type' => 'local_subscription_client',
        ]);

        $this->assertSame(AtlasExternalBrainMusclePromptVariantSelector::VARIANT_RECOVERY_GIVE_BACK_TASK, $r['prompt_variant_id']);
    }

    public function test_required_guardrails_always_present(): void
    {
        $r = $this->selector()->select([]);

        foreach (['allowed_files_only', 'no_manual_git_commands', 'prove_before_report', 'give_back_on_duplicate'] as $guardrail) {
            $this->assertContains($guardrail, $r['guardrails']);
        }
    }

    public function test_no_paid_api_dependency_guardrail_only_for_local_subscription_client(): void
    {
        $local = $this->selector()->select(['muscle_type' => 'local_subscription_client']);
        $external = $this->selector()->select(['muscle_type' => 'external_muscle']);

        $this->assertContains('no_paid_api_dependency', $local['guardrails']);
        $this->assertNotContains('no_paid_api_dependency', $external['guardrails']);
    }

    public function test_included_and_omitted_sections_are_disjoint_and_cover_all_sections(): void
    {
        $r = $this->selector()->select(['risk_level' => 'high']);

        $this->assertSame([], array_intersect($r['included_sections'], $r['omitted_sections']));
        $this->assertNotEmpty($r['omitted_sections']);
    }

    public function test_select_is_deterministic(): void
    {
        $input = ['muscle_type' => 'local_subscription_client', 'risk_level' => 'medium'];

        $first = $this->selector()->select($input);
        $second = $this->selector()->select($input);

        $this->assertSame($first, $second);
    }

    public function test_tiny_token_budget_selects_low_context_task_even_for_external_muscle_low_risk(): void
    {
        $r = $this->selector()->select([
            'muscle_type' => 'external_muscle',
            'risk_level' => 'low',
            'token_budget_class' => 'tiny',
        ]);

        $this->assertSame(AtlasExternalBrainMusclePromptVariantSelector::VARIANT_LOW_CONTEXT_TASK, $r['prompt_variant_id']);
    }

    public function test_high_risk_still_selects_high_risk_task_over_tiny_budget_unless_recovery(): void
    {
        $r = $this->selector()->select([
            'risk_level' => 'high',
            'token_budget_class' => 'tiny',
        ]);

        $this->assertSame(AtlasExternalBrainMusclePromptVariantSelector::VARIANT_HIGH_RISK_TASK, $r['prompt_variant_id']);

        $recovery = $this->selector()->select([
            'risk_level' => 'high',
            'token_budget_class' => 'tiny',
            'task_mode' => 'recovery',
        ]);
        $this->assertSame(AtlasExternalBrainMusclePromptVariantSelector::VARIANT_RECOVERY_GIVE_BACK_TASK, $recovery['prompt_variant_id']);
    }

    public function test_every_omitted_section_has_a_reason_and_local_client_keeps_no_paid_api_dependency(): void
    {
        $r = $this->selector()->select([
            'muscle_type' => 'local_subscription_client',
            'token_budget_class' => 'tiny',
        ]);

        foreach ($r['omitted_sections'] as $section) {
            $this->assertArrayHasKey($section, $r['omitted_section_reasons']);
            $this->assertNotEmpty($r['omitted_section_reasons'][$section]);
        }
        $this->assertContains('no_paid_api_dependency', $r['guardrails']);
    }

    // ── evidenceDensityCheck: evidence_density_included, reason ──

    public function test_low_context_weak_evidence_includes_evidence_density(): void
    {
        $r = (new AtlasExternalBrainMusclePromptVariantSelector)->evidenceDensityCheck([
            'muscle_type' => 'external_muscle',
            'task_risk' => 'low_context',
            'evidence_strength' => 'weak',
            'give_back_risk' => 'low',
        ]);
        $this->assertTrue($r['evidence_density_included']);
        $this->assertSame('low_context_task_with_weak_evidence_requires_density', $r['reason']);
    }

    public function test_local_subscription_high_give_back_includes_evidence_density(): void
    {
        $r = (new AtlasExternalBrainMusclePromptVariantSelector)->evidenceDensityCheck([
            'muscle_type' => 'local_subscription_client',
            'task_risk' => 'normal',
            'evidence_strength' => 'strong',
            'give_back_risk' => 'high',
        ]);
        $this->assertTrue($r['evidence_density_included']);
        $this->assertSame('local_subscription_with_high_give_back_risk_requires_density', $r['reason']);
    }

    public function test_normal_prompt_no_extra_evidence_density_bulk(): void
    {
        $r = (new AtlasExternalBrainMusclePromptVariantSelector)->evidenceDensityCheck([
            'muscle_type' => 'external_muscle',
            'task_risk' => 'normal',
            'evidence_strength' => 'strong',
            'give_back_risk' => 'low',
        ]);
        $this->assertFalse($r['evidence_density_included']);
        $this->assertSame('normal_prompt_no_extra_evidence_density_bulk', $r['reason']);
    }
}
