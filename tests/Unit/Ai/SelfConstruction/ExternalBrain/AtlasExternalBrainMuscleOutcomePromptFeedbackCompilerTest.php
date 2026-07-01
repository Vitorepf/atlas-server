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
    }
}
