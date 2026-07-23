<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainMuscleOutcomePromptFeedbackCompiler;
use Tests\TestCase;

final class AtlasExternalBrainMuscleOutcomePromptFeedbackCompilerTest extends TestCase
{
    private function compiler(): AtlasExternalBrainMuscleOutcomePromptFeedbackCompiler
    {
        return new AtlasExternalBrainMuscleOutcomePromptFeedbackCompiler;
    }

    public function test_repeated_allowed_files_too_narrow_give_backs_create_prompt_patch(): void
    {
        $r = $this->compiler()->compile([
            'outcomes' => [
                ['family' => 'orphan-wiring', 'outcome' => 'give_back', 'give_back_reason' => 'allowed_files_too_narrow'],
                ['family' => 'orphan-wiring', 'outcome' => 'give_back', 'give_back_reason' => 'allowed_files_too_narrow'],
            ],
        ]);

        self::assertCount(1, $r['prompt_patches']);
        self::assertSame('orphan-wiring', $r['prompt_patches'][0]['family']);
        self::assertSame('require_implementation_plus_test_scope_and_closure_verification', $r['prompt_patches'][0]['patch']);
    }

    public function test_single_narrow_give_back_does_not_create_prompt_patch(): void
    {
        $r = $this->compiler()->compile([
            'outcomes' => [
                ['family' => 'orphan-wiring', 'outcome' => 'give_back', 'give_back_reason' => 'allowed_files_too_narrow'],
            ],
        ]);

        self::assertSame([], $r['prompt_patches']);
    }

    public function test_routing_hint_distinguishes_fast_strong_worker_from_weak_cross_module(): void
    {
        $r = $this->compiler()->compile([
            'outcomes' => [
                ['worker_id' => 'w1', 'outcome' => 'success', 'task_shape' => 'test_heavy_low_risk'],
                ['worker_id' => 'w1', 'outcome' => 'success', 'task_shape' => 'test_heavy_low_risk'],
                ['worker_id' => 'w1', 'outcome' => 'give_back', 'task_shape' => 'cross_module'],
                ['worker_id' => 'w1', 'outcome' => 'give_back', 'task_shape' => 'cross_module'],
            ],
        ]);

        self::assertCount(1, $r['routing_hints']);
        self::assertSame('w1', $r['routing_hints'][0]['worker']);
        self::assertSame('test_heavy_low_risk', $r['routing_hints'][0]['assign']);
        self::assertSame('cross_module', $r['routing_hints'][0]['avoid']);
    }

    public function test_no_routing_hint_when_both_shapes_perform_similarly(): void
    {
        $r = $this->compiler()->compile([
            'outcomes' => [
                ['worker_id' => 'w2', 'outcome' => 'success', 'task_shape' => 'test_heavy_low_risk'],
                ['worker_id' => 'w2', 'outcome' => 'success', 'task_shape' => 'cross_module'],
            ],
        ]);

        self::assertSame([], $r['routing_hints']);
    }

    public function test_promoted_spec_pattern_requires_repeats_strong_proof_and_low_template_similarity(): void
    {
        $r = $this->compiler()->compile([
            'outcomes' => [
                ['spec_pattern' => 'wire-orphan', 'outcome' => 'success', 'elapsed_seconds' => 100, 'proof_strength' => 0.9, 'template_similarity' => 0.1],
                ['spec_pattern' => 'wire-orphan', 'outcome' => 'success', 'elapsed_seconds' => 120, 'proof_strength' => 0.85, 'template_similarity' => 0.2],
            ],
        ]);

        self::assertCount(1, $r['promoted_spec_patterns']);
        self::assertSame('wire-orphan', $r['promoted_spec_patterns'][0]['spec_pattern']);
        self::assertTrue($r['promoted_spec_patterns'][0]['promoted']);
        self::assertArrayNotHasKey('anti_template_farm_warning', $r['promoted_spec_patterns'][0]);
    }

    public function test_weak_proof_pattern_is_not_promoted(): void
    {
        $r = $this->compiler()->compile([
            'outcomes' => [
                ['spec_pattern' => 'weak-pattern', 'outcome' => 'success', 'elapsed_seconds' => 100, 'proof_strength' => 0.2],
                ['spec_pattern' => 'weak-pattern', 'outcome' => 'success', 'elapsed_seconds' => 100, 'proof_strength' => 0.1],
            ],
        ]);

        self::assertSame([], $r['promoted_spec_patterns']);
    }

    public function test_template_farming_pattern_is_flagged_even_when_otherwise_promoted(): void
    {
        $r = $this->compiler()->compile([
            'outcomes' => [
                ['spec_pattern' => 'templated', 'outcome' => 'success', 'elapsed_seconds' => 100, 'proof_strength' => 0.9, 'template_similarity' => 0.95],
                ['spec_pattern' => 'templated', 'outcome' => 'success', 'elapsed_seconds' => 100, 'proof_strength' => 0.9, 'template_similarity' => 0.95],
            ],
        ]);

        self::assertCount(1, $r['promoted_spec_patterns']);
        self::assertTrue($r['promoted_spec_patterns'][0]['anti_template_farm_warning']);
    }
}
