<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ContinuousRuntime;

use App\Services\Ai\SelfConstruction\ContinuousRuntime\AtlasSelfConstructionContinuousRuntimeLearningIntegration;
use Tests\TestCase;

final class AtlasSelfConstructionContinuousRuntimeLearningIntegrationTest extends TestCase
{
    public function test_success_learning_with_high_occurrence_is_reusable(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeLearningIntegration)->integrate([
            [
                'kind' => 'verification',
                'task_packet_id' => 'pkt-1', 'lease_id' => 'l-1', 'evidence_hash' => 'h1',
                'organ' => 'verification_court', 'task_class' => 'gate', 'cycle_id' => 'c-1',
                'class' => 'gate_pattern_canonical', 'occurrence_count' => 3, 'outcome' => 'passed',
            ],
        ]);

        $this->assertTrue($verdict['learning_inputs'][0]['reusable']);
        $this->assertSame('gate_pattern_canonical', $verdict['learning_inputs'][0]['class']);
        $this->assertSame(1, count($verdict['receipt_inputs']));
        $this->assertSame(1, count($verdict['compounding_inputs']));
    }

    public function test_failure_learning_with_learning_required_flag_is_reusable_at_count_1(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeLearningIntegration)->integrate([
            [
                'kind' => 'merge',
                'task_packet_id' => 'pkt-2', 'lease_id' => 'l-2', 'evidence_hash' => 'h2',
                'class' => 'merge_conflict_pattern', 'occurrence_count' => 1, 'learning_required' => true,
                'outcome' => 'failed',
            ],
        ]);

        $this->assertTrue($verdict['learning_inputs'][0]['reusable']);
    }

    public function test_give_back_learning_with_single_occurrence_is_kept_but_not_reusable(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeLearningIntegration)->integrate([
            [
                'kind' => 'give_back',
                'task_packet_id' => 'pkt-3', 'lease_id' => 'l-3', 'evidence_hash' => 'h3',
                'class' => 'scope_gap', 'occurrence_count' => 1, 'outcome' => 'give_back',
            ],
        ]);

        $this->assertCount(1, $verdict['learning_inputs']);
        $this->assertFalse($verdict['learning_inputs'][0]['reusable'], 'one-off is kept but not promotable');
    }

    public function test_no_class_outcome_yields_no_learning_input(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeLearningIntegration)->integrate([
            [
                'kind' => 'queue_repair',
                'task_packet_id' => 'pkt-4', 'lease_id' => 'l-4', 'evidence_hash' => 'h4',
                // no class — no learning candidate, but compounding + receipt still emit
            ],
        ]);

        $this->assertSame([], $verdict['learning_inputs']);
        $this->assertCount(1, $verdict['receipt_inputs']);
        $this->assertCount(1, $verdict['compounding_inputs']);
    }

    public function test_merge_outcomes_populate_required_promotion_evidence_hashes(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeLearningIntegration)->integrate([
            ['kind' => 'verification', 'evidence_hash' => 'h-verify'],
            ['kind' => 'merge', 'evidence_hash' => 'h-merge-a'],
            ['kind' => 'merge', 'evidence_hash' => 'h-merge-b'],
            ['kind' => 'merge', 'evidence_hash' => 'h-merge-a'], // duplicate
        ]);

        $this->assertSame(['h-merge-a', 'h-merge-b'], $verdict['required_promotion_evidence_hashes']);
        $this->assertNotContains('h-verify', $verdict['required_promotion_evidence_hashes']);
    }

    public function test_empty_input_yields_empty_output_sections(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeLearningIntegration)->integrate([]);
        $this->assertSame([], $verdict['learning_inputs']);
        $this->assertSame([], $verdict['receipt_inputs']);
        $this->assertSame([], $verdict['compounding_inputs']);
        $this->assertSame([], $verdict['required_promotion_evidence_hashes']);
    }
}
