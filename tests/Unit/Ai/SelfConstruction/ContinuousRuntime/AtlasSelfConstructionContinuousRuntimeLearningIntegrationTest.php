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

    public function test_quarantine_outcome_is_kept_in_receipt_and_compounding_but_not_reusable_without_evidence(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeLearningIntegration)->integrate([
            [
                'kind' => AtlasSelfConstructionContinuousRuntimeLearningIntegration::OUTCOME_QUARANTINE,
                'task_packet_id' => 'pkt-q', 'lease_id' => 'l-q', 'evidence_hash' => 'hq',
                'class' => 'quarantine_blast_radius', 'occurrence_count' => 1, 'outcome' => 'quarantine',
            ],
        ]);

        $this->assertCount(1, $verdict['receipt_inputs']);
        $this->assertCount(1, $verdict['compounding_inputs']);
        $this->assertCount(1, $verdict['learning_inputs']);
        $this->assertFalse($verdict['learning_inputs'][0]['reusable'], 'one-off quarantine must not be promotable');
    }

    public function test_stale_outcomes_are_excluded_from_all_output_sections(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeLearningIntegration)->integrate([
            [
                'kind' => 'verification', 'task_packet_id' => 'stale-1', 'lease_id' => 'ls-1',
                'evidence_hash' => 'hs1', 'class' => 'stale_pattern', 'occurrence_count' => 5,
                'outcome' => 'passed', 'stale' => true,
            ],
            [
                'kind' => 'verification', 'task_packet_id' => 'fresh-1', 'lease_id' => 'lf-1',
                'evidence_hash' => 'hf1', 'class' => 'fresh_pattern', 'occurrence_count' => 3,
                'outcome' => 'passed',
            ],
        ]);

        $this->assertCount(1, $verdict['learning_inputs'], 'stale outcome must not appear in learning');
        $this->assertCount(1, $verdict['receipt_inputs'], 'stale outcome must not appear in receipt');
        $this->assertSame('fresh_pattern', $verdict['learning_inputs'][0]['class']);
    }

    public function test_empty_input_yields_empty_output_sections(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeLearningIntegration)->integrate([]);
        $this->assertSame([], $verdict['learning_inputs']);
        $this->assertSame([], $verdict['receipt_inputs']);
        $this->assertSame([], $verdict['compounding_inputs']);
        $this->assertSame([], $verdict['required_promotion_evidence_hashes']);
    }

    // --- evidence quality + promotion_blockers ---

    public function test_empty_evidence_hash_makes_not_reusable_with_promotion_blocker(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeLearningIntegration)->integrate([
            [
                'kind' => 'verification', 'task_packet_id' => 'p', 'lease_id' => 'l',
                'evidence_hash' => '',  // empty
                'class' => 'gate_pattern', 'occurrence_count' => 5, 'outcome' => 'passed',
            ],
        ]);

        $li = $verdict['learning_inputs'][0];
        $this->assertFalse($li['reusable']);
        $this->assertContains('empty_evidence_hash', $li['promotion_blockers']);
    }

    public function test_unknown_outcome_kind_makes_not_reusable_with_promotion_blocker(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeLearningIntegration)->integrate([
            [
                'kind' => 'alien_outcome', 'task_packet_id' => 'p', 'lease_id' => 'l',
                'evidence_hash' => 'eh1',
                'class' => 'gate_pattern', 'occurrence_count' => 5, 'outcome' => 'alien',
            ],
        ]);

        $li = $verdict['learning_inputs'][0];
        $this->assertFalse($li['reusable']);
        $this->assertContains('unknown_outcome_kind:alien_outcome', $li['promotion_blockers']);
    }

    public function test_poison_signature_never_reusable_even_with_high_occurrence_count(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeLearningIntegration)->integrate([
            [
                'kind' => 'quarantine', 'task_packet_id' => 'p', 'lease_id' => 'l',
                'evidence_hash' => 'eh2', 'poison_signature' => true,
                'class' => 'quarantine_blast_radius', 'occurrence_count' => 99, 'learning_required' => true,
            ],
        ]);

        $li = $verdict['learning_inputs'][0];
        $this->assertFalse($li['reusable']);
        $this->assertContains('poison_signature', $li['promotion_blockers']);
    }

    public function test_clean_input_has_empty_promotion_blockers(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeLearningIntegration)->integrate([
            [
                'kind' => 'verification', 'task_packet_id' => 'p', 'lease_id' => 'l',
                'evidence_hash' => 'eh3',
                'class' => 'gate_pattern', 'occurrence_count' => 3, 'outcome' => 'passed',
            ],
        ]);

        $li = $verdict['learning_inputs'][0];
        $this->assertTrue($li['reusable']);
        $this->assertSame([], $li['promotion_blockers']);
    }

    public function test_worker_quality_propagated_into_compounding_inputs(): void
    {
        $wq = ['client_id' => 'w-1', 'success_rate' => 0.9];
        $verdict = (new AtlasSelfConstructionContinuousRuntimeLearningIntegration)->integrate([
            [
                'kind' => 'merge', 'task_packet_id' => 'p', 'lease_id' => 'l',
                'evidence_hash' => 'eh4', 'worker_quality' => $wq,
            ],
        ]);

        $this->assertSame($wq, $verdict['compounding_inputs'][0]['worker_quality']);
    }

    public function test_worker_quality_null_when_absent(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeLearningIntegration)->integrate([
            ['kind' => 'merge', 'task_packet_id' => 'p', 'lease_id' => 'l', 'evidence_hash' => 'eh5'],
        ]);

        $this->assertNull($verdict['compounding_inputs'][0]['worker_quality']);
    }
}
