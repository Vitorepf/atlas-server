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

    // ── next_cycle_strategy ───────────────────────────────────────────────────

    public function test_next_cycle_strategy_key_present_in_output(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeLearningIntegration)->integrate([]);

        $this->assertArrayHasKey('next_cycle_strategy', $verdict);
        $strategy = $verdict['next_cycle_strategy'];
        $this->assertArrayHasKey('success_yield', $strategy);
        $this->assertArrayHasKey('give_back_causes', $strategy);
        $this->assertArrayHasKey('regression_signals', $strategy);
        $this->assertArrayHasKey('capability_gaps', $strategy);
        $this->assertArrayHasKey('next_cycle_confidence_score', $strategy);
    }

    public function test_proven_merge_increases_confidence_score(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeLearningIntegration)->integrate([
            ['kind' => 'merge', 'evidence_hash' => 'h1', 'task_class' => 'gate'],
            ['kind' => 'merge', 'evidence_hash' => 'h2', 'task_class' => 'gate'],
        ]);

        $strategy = $verdict['next_cycle_strategy'];
        $this->assertGreaterThan(0.0, $strategy['next_cycle_confidence_score']);
        $this->assertSame(1.0, $strategy['success_yield']);
    }

    public function test_proxy_success_does_not_increase_confidence_score(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeLearningIntegration)->integrate([
            ['kind' => 'merge', 'evidence_hash' => 'h1', 'proxy_success' => true],
            ['kind' => 'merge', 'evidence_hash' => 'h2', 'proxy_success' => true],
        ]);

        $strategy = $verdict['next_cycle_strategy'];
        $this->assertSame(0.0, $strategy['next_cycle_confidence_score']);
        $this->assertSame(0.0, $strategy['success_yield']);
    }

    public function test_unproven_outcome_empty_evidence_does_not_increase_confidence(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeLearningIntegration)->integrate([
            ['kind' => 'merge', 'evidence_hash' => ''],  // no evidence = unproven
        ]);

        $strategy = $verdict['next_cycle_strategy'];
        $this->assertSame(0.0, $strategy['next_cycle_confidence_score']);
    }

    public function test_give_back_causes_grouped_by_class(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeLearningIntegration)->integrate([
            ['kind' => 'give_back', 'evidence_hash' => 'h1', 'class' => 'scope_gap'],
            ['kind' => 'give_back', 'evidence_hash' => 'h2', 'class' => 'scope_gap'],
            ['kind' => 'give_back', 'evidence_hash' => 'h3', 'class' => 'missing_impl'],
        ]);

        $causes = $verdict['next_cycle_strategy']['give_back_causes'];
        $byClass = array_column($causes, 'count', 'cause');
        $this->assertSame(2, $byClass['scope_gap']);
        $this->assertSame(1, $byClass['missing_impl']);
    }

    public function test_regression_signals_populated_from_regression_true_outcomes(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeLearningIntegration)->integrate([
            ['kind' => 'verification', 'evidence_hash' => 'reg-h1', 'regression' => true],
            ['kind' => 'verification', 'evidence_hash' => 'ok-h2', 'regression' => false],
            ['kind' => 'verification', 'evidence_hash' => 'reg-h3', 'regression' => true],
        ]);

        $signals = $verdict['next_cycle_strategy']['regression_signals'];
        $this->assertContains('reg-h1', $signals);
        $this->assertContains('reg-h3', $signals);
        $this->assertNotContains('ok-h2', $signals);
    }

    public function test_capability_gaps_identifies_task_classes_with_only_failures(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeLearningIntegration)->integrate([
            ['kind' => 'merge',     'evidence_hash' => 'h1', 'task_class' => 'gate'],  // success
            ['kind' => 'give_back', 'evidence_hash' => 'h2', 'task_class' => 'refactor'],  // only fail
            ['kind' => 'give_back', 'evidence_hash' => 'h3', 'task_class' => 'gate'],  // fail but gate also has success
        ]);

        $gaps = $verdict['next_cycle_strategy']['capability_gaps'];
        $this->assertContains('refactor', $gaps);
        $this->assertNotContains('gate', $gaps);  // gate has a proven success
    }

    public function test_empty_outcomes_yields_zero_confidence_and_empty_strategy(): void
    {
        $strategy = (new AtlasSelfConstructionContinuousRuntimeLearningIntegration)->integrate([])['next_cycle_strategy'];

        $this->assertSame(0.0, $strategy['success_yield']);
        $this->assertSame(0.0, $strategy['next_cycle_confidence_score']);
        $this->assertSame([], $strategy['give_back_causes']);
        $this->assertSame([], $strategy['regression_signals']);
        $this->assertSame([], $strategy['capability_gaps']);
    }
}
