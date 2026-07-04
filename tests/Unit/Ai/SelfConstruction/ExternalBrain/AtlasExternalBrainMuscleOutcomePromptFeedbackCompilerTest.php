<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainMuscleOutcomePromptFeedbackCompiler;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainMuscleOutcomePromptFeedbackCompilerTest extends TestCase
{
    public function test_repeated_narrow_allowed_files_give_back_emits_prompt_patch(): void
    {
        $result = (new AtlasExternalBrainMuscleOutcomePromptFeedbackCompiler)->compile([
            'outcomes' => [
                ['family' => 'wiring', 'outcome' => 'give_back', 'give_back_reason' => 'allowed_files_too_narrow'],
                ['family' => 'wiring', 'outcome' => 'give_back', 'give_back_reason' => 'allowed_files_too_narrow'],
            ],
        ]);

        $this->assertCount(1, $result['prompt_patches']);
        $this->assertSame('wiring', $result['prompt_patches'][0]['family']);
        $this->assertSame('require_implementation_plus_test_scope_and_closure_verification', $result['prompt_patches'][0]['patch']);
    }

    public function test_single_give_back_does_not_trigger_prompt_patch(): void
    {
        $result = (new AtlasExternalBrainMuscleOutcomePromptFeedbackCompiler)->compile([
            'outcomes' => [
                ['family' => 'wiring', 'outcome' => 'give_back', 'give_back_reason' => 'allowed_files_too_narrow'],
            ],
        ]);

        $this->assertSame([], $result['prompt_patches']);
    }

    public function test_worker_with_skill_fit_gets_routing_hint(): void
    {
        $outcomes = [];
        for ($i = 0; $i < 5; $i++) {
            $outcomes[] = ['worker_id' => 'w1', 'outcome' => 'success', 'task_shape' => 'test_heavy_low_risk'];
        }
        for ($i = 0; $i < 5; $i++) {
            $outcomes[] = ['worker_id' => 'w1', 'outcome' => $i < 4 ? 'give_back' : 'success', 'task_shape' => 'cross_module'];
        }

        $result = (new AtlasExternalBrainMuscleOutcomePromptFeedbackCompiler)->compile(['outcomes' => $outcomes]);

        $this->assertCount(1, $result['routing_hints']);
        $this->assertSame('w1', $result['routing_hints'][0]['worker']);
        $this->assertSame('test_heavy_low_risk', $result['routing_hints'][0]['assign']);
        $this->assertSame('cross_module', $result['routing_hints'][0]['avoid']);
    }

    public function test_no_routing_hint_when_worker_is_uniformly_strong(): void
    {
        $outcomes = [];
        for ($i = 0; $i < 3; $i++) {
            $outcomes[] = ['worker_id' => 'w2', 'outcome' => 'success', 'task_shape' => 'test_heavy_low_risk'];
            $outcomes[] = ['worker_id' => 'w2', 'outcome' => 'success', 'task_shape' => 'cross_module'];
        }

        $result = (new AtlasExternalBrainMuscleOutcomePromptFeedbackCompiler)->compile(['outcomes' => $outcomes]);

        $this->assertSame([], $result['routing_hints']);
    }

    public function test_fast_strong_proof_successes_promote_spec_pattern(): void
    {
        $outcomes = [
            ['spec_pattern' => 'p1', 'outcome' => 'success', 'elapsed_seconds' => 100, 'proof_strength' => 0.9],
            ['spec_pattern' => 'p1', 'outcome' => 'success', 'elapsed_seconds' => 150, 'proof_strength' => 0.85],
        ];

        $result = (new AtlasExternalBrainMuscleOutcomePromptFeedbackCompiler)->compile(['outcomes' => $outcomes]);

        $this->assertCount(1, $result['promoted_spec_patterns']);
        $this->assertTrue($result['promoted_spec_patterns'][0]['promoted']);
        $this->assertArrayNotHasKey('anti_template_farm_warning', $result['promoted_spec_patterns'][0]);
    }

    public function test_promoted_pattern_preserves_anti_template_farm_warning(): void
    {
        $outcomes = [
            ['spec_pattern' => 'p2', 'outcome' => 'success', 'elapsed_seconds' => 100, 'proof_strength' => 0.9, 'template_similarity' => 0.85],
            ['spec_pattern' => 'p2', 'outcome' => 'success', 'elapsed_seconds' => 150, 'proof_strength' => 0.85, 'template_similarity' => 0.9],
        ];

        $result = (new AtlasExternalBrainMuscleOutcomePromptFeedbackCompiler)->compile(['outcomes' => $outcomes]);

        $this->assertTrue($result['promoted_spec_patterns'][0]['anti_template_farm_warning']);
    }

    public function test_slow_pattern_is_not_promoted(): void
    {
        $outcomes = [
            ['spec_pattern' => 'p3', 'outcome' => 'success', 'elapsed_seconds' => 900, 'proof_strength' => 0.9],
            ['spec_pattern' => 'p3', 'outcome' => 'success', 'elapsed_seconds' => 950, 'proof_strength' => 0.9],
        ];

        $result = (new AtlasExternalBrainMuscleOutcomePromptFeedbackCompiler)->compile(['outcomes' => $outcomes]);

        $this->assertSame([], $result['promoted_spec_patterns']);
    }

    public function test_empty_outcomes_yields_empty_report(): void
    {
        $result = (new AtlasExternalBrainMuscleOutcomePromptFeedbackCompiler)->compile([]);

        $this->assertSame([], $result['prompt_patches']);
        $this->assertSame([], $result['routing_hints']);
        $this->assertSame([], $result['promoted_spec_patterns']);
        $this->assertSame([], $result['worker_routing_updates']);
        $this->assertSame([], $result['prompt_hardening_updates']);
    }

    // ── worker_routing_updates ──────────────────────────────────────────────────

    public function test_fast_strong_proof_successes_promote_worker(): void
    {
        $outcomes = [
            ['worker_id' => 'w1', 'outcome' => 'success', 'elapsed_seconds' => 100, 'proof_strength' => 0.9],
            ['worker_id' => 'w1', 'outcome' => 'success', 'elapsed_seconds' => 120, 'proof_strength' => 0.85],
        ];

        $result = (new AtlasExternalBrainMuscleOutcomePromptFeedbackCompiler)->compile(['outcomes' => $outcomes]);

        $this->assertCount(1, $result['worker_routing_updates']);
        $this->assertSame('w1', $result['worker_routing_updates'][0]['worker']);
        $this->assertSame('promote', $result['worker_routing_updates'][0]['action']);
    }

    public function test_repeated_retries_suppress_worker(): void
    {
        $outcomes = [
            ['worker_id' => 'w2', 'outcome' => 'retry'],
            ['worker_id' => 'w2', 'outcome' => 'retry'],
        ];

        $result = (new AtlasExternalBrainMuscleOutcomePromptFeedbackCompiler)->compile(['outcomes' => $outcomes]);

        $this->assertCount(1, $result['worker_routing_updates']);
        $this->assertSame('suppress', $result['worker_routing_updates'][0]['action']);
        $this->assertContains('repeated_retries', $result['worker_routing_updates'][0]['reasons']);
    }

    public function test_high_give_back_rate_suppresses_worker(): void
    {
        $outcomes = [
            ['worker_id' => 'w3', 'outcome' => 'give_back', 'give_back_reason' => 'anything'],
            ['worker_id' => 'w3', 'outcome' => 'success', 'proof_strength' => 0.9, 'elapsed_seconds' => 100],
        ];

        $result = (new AtlasExternalBrainMuscleOutcomePromptFeedbackCompiler)->compile(['outcomes' => $outcomes]);

        $this->assertCount(1, $result['worker_routing_updates']);
        $this->assertSame('suppress', $result['worker_routing_updates'][0]['action']);
        $this->assertContains('high_give_back_rate', $result['worker_routing_updates'][0]['reasons']);
    }

    public function test_weak_proof_strength_suppresses_worker(): void
    {
        $outcomes = [
            ['worker_id' => 'w4', 'outcome' => 'success', 'proof_strength' => 0.1, 'elapsed_seconds' => 100],
            ['worker_id' => 'w4', 'outcome' => 'success', 'proof_strength' => 0.1, 'elapsed_seconds' => 100],
        ];

        $result = (new AtlasExternalBrainMuscleOutcomePromptFeedbackCompiler)->compile(['outcomes' => $outcomes]);

        $this->assertCount(1, $result['worker_routing_updates']);
        $this->assertSame('suppress', $result['worker_routing_updates'][0]['action']);
        $this->assertContains('weak_proof_strength', $result['worker_routing_updates'][0]['reasons']);
    }

    public function test_suppression_takes_priority_over_promotion(): void
    {
        // fast+strong successes but also repeated retries — must suppress, not promote.
        $outcomes = [
            ['worker_id' => 'w5', 'outcome' => 'success', 'elapsed_seconds' => 100, 'proof_strength' => 0.9],
            ['worker_id' => 'w5', 'outcome' => 'success', 'elapsed_seconds' => 100, 'proof_strength' => 0.9],
            ['worker_id' => 'w5', 'outcome' => 'retry'],
            ['worker_id' => 'w5', 'outcome' => 'retry'],
        ];

        $result = (new AtlasExternalBrainMuscleOutcomePromptFeedbackCompiler)->compile(['outcomes' => $outcomes]);

        $this->assertCount(1, $result['worker_routing_updates']);
        $this->assertSame('suppress', $result['worker_routing_updates'][0]['action']);
    }

    public function test_no_routing_update_when_no_signal_fires(): void
    {
        $outcomes = [
            ['worker_id' => 'w6', 'outcome' => 'success', 'elapsed_seconds' => 900, 'proof_strength' => 0.5],
        ];

        $result = (new AtlasExternalBrainMuscleOutcomePromptFeedbackCompiler)->compile(['outcomes' => $outcomes]);

        $this->assertSame([], $result['worker_routing_updates']);
    }

    // ── prompt_hardening_updates ─────────────────────────────────────────────────

    public function test_high_template_similarity_emits_hardening_update(): void
    {
        $outcomes = [
            ['family' => 'wiring', 'outcome' => 'success', 'template_similarity' => 0.9],
            ['family' => 'wiring', 'outcome' => 'success', 'template_similarity' => 0.85],
        ];

        $result = (new AtlasExternalBrainMuscleOutcomePromptFeedbackCompiler)->compile(['outcomes' => $outcomes]);

        $signals = array_column($result['prompt_hardening_updates'], 'signal');
        $this->assertContains('high_template_similarity', $signals);
        $entry = $result['prompt_hardening_updates'][array_search('high_template_similarity', $signals, true)];
        $this->assertSame(2, $entry['occurrences']);
        $this->assertSame('diversify_spec_pattern_away_from_template_farm', $entry['recommended_patch_id']);
    }

    public function test_repeated_test_only_or_proxy_give_back_emits_hardening_update(): void
    {
        $outcomes = [
            ['family' => 'wiring', 'outcome' => 'give_back', 'give_back_reason' => 'proxy_implementation'],
            ['family' => 'wiring', 'outcome' => 'give_back', 'give_back_reason' => 'test_only_change'],
        ];

        $result = (new AtlasExternalBrainMuscleOutcomePromptFeedbackCompiler)->compile(['outcomes' => $outcomes]);

        $signals = array_column($result['prompt_hardening_updates'], 'signal');
        $this->assertContains('test_only_or_proxy_pattern', $signals);
        $entry = $result['prompt_hardening_updates'][array_search('test_only_or_proxy_pattern', $signals, true)];
        $this->assertSame(2, $entry['occurrences']);
        $this->assertSame('require_real_behavior_change_not_test_only_or_proxy', $entry['recommended_patch_id']);
    }

    public function test_repeated_narrow_allowed_files_emits_hardening_update_too(): void
    {
        $outcomes = [
            ['family' => 'wiring', 'outcome' => 'give_back', 'give_back_reason' => 'allowed_files_too_narrow'],
            ['family' => 'wiring', 'outcome' => 'give_back', 'give_back_reason' => 'allowed_files_too_narrow'],
        ];

        $result = (new AtlasExternalBrainMuscleOutcomePromptFeedbackCompiler)->compile(['outcomes' => $outcomes]);

        $signals = array_column($result['prompt_hardening_updates'], 'signal');
        $this->assertContains('allowed_files_too_narrow', $signals);
    }

    public function test_single_occurrence_does_not_trigger_hardening_update(): void
    {
        $outcomes = [
            ['family' => 'wiring', 'outcome' => 'success', 'template_similarity' => 0.9],
        ];

        $result = (new AtlasExternalBrainMuscleOutcomePromptFeedbackCompiler)->compile(['outcomes' => $outcomes]);

        $this->assertSame([], $result['prompt_hardening_updates']);
    }

    // ── negativePatternPatches: weak_green, proxy_success, template_similarity ──

    public function test_repeated_weak_green_emits_require_concrete_command_path(): void
    {
        $r = (new AtlasExternalBrainMuscleOutcomePromptFeedbackCompiler)->negativePatternPatches([
            ['outcome' => 'success', 'proof_strength' => 0.3, 'behavior_delta' => 0.8],
            ['outcome' => 'success', 'proof_strength' => 0.2, 'behavior_delta' => 0.8],
        ]);
        $this->assertCount(1, $r);
        $this->assertSame('require_concrete_command_path_and_behavior_delta', $r[0]['recommended_patch_id']);
        $this->assertSame('repeated_weak_green', $r[0]['pattern']);
    }

    public function test_repeated_proxy_success_emits_reject_proxy_patch(): void
    {
        $r = (new AtlasExternalBrainMuscleOutcomePromptFeedbackCompiler)->negativePatternPatches([
            ['outcome' => 'success', 'proof_strength' => 0.9, 'behavior_delta' => 0.05],
            ['outcome' => 'success', 'proof_strength' => 0.9, 'behavior_delta' => 0.01],
        ]);
        $this->assertContains($r[0]['recommended_patch_id'], ['reject_proxy_or_wrapper_success_without_capability_delta']);
        $this->assertSame('repeated_proxy_success', $r[0]['pattern']);
    }

    public function test_high_template_similarity_without_proof_emits_patch(): void
    {
        $r = (new AtlasExternalBrainMuscleOutcomePromptFeedbackCompiler)->negativePatternPatches([
            ['outcome' => 'success', 'proof_strength' => 0.6, 'template_similarity' => 0.9, 'behavior_delta' => 0.3],
            ['outcome' => 'success', 'proof_strength' => 0.5, 'template_similarity' => 0.85, 'behavior_delta' => 0.2],
        ]);
        $this->assertContains($r[0]['recommended_patch_id'], ['require_proof_strength_and_behavior_delta_before_promotion']);
        $this->assertSame('high_template_similarity_without_proof', $r[0]['pattern']);
    }

    public function test_single_occurrence_does_not_emit_patch(): void
    {
        $r = (new AtlasExternalBrainMuscleOutcomePromptFeedbackCompiler)->negativePatternPatches([
            ['outcome' => 'success', 'proof_strength' => 0.3, 'behavior_delta' => 0.8],
        ]);
        $this->assertSame([], $r);
    }
}
