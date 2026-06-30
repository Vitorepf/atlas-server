<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainTaskOutcomeCausalAttributor;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainTaskOutcomeCausalAttributorTest extends TestCase
{
    private AtlasExternalBrainTaskOutcomeCausalAttributor $attributor;

    protected function setUp(): void
    {
        $this->attributor = new AtlasExternalBrainTaskOutcomeCausalAttributor;
    }

    private function goodExecution(array $overrides = []): array
    {
        return array_replace_recursive([
            'spec'    => ['quality_score' => 0.9, 'has_acceptance_criteria' => true, 'evidence_strength' => 0.8, 'complexity' => 'low'],
            'worker'  => ['quality_score' => 0.85, 'best_task_classes' => ['new_service'], 'avoid_task_classes' => [], 'task_class' => 'new_service'],
            'queue'   => ['contention_level' => 'low'],
            'outcome' => ['result' => 'success', 'had_evidence' => true, 'shallow_success' => false],
        ], $overrides);
    }

    // ── Schema / required keys ────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->attributor->attribute($this->goodExecution());

        foreach (['schema', 'primary_cause', 'contributing_causes', 'confidence', 'recommended_originator_adjustment', 'attribution_id'] as $k) {
            $this->assertArrayHasKey($k, $result, "Missing: {$k}");
        }
        $this->assertSame(AtlasExternalBrainTaskOutcomeCausalAttributor::SCHEMA, $result['schema']);
    }

    // ── AC1: good execution ───────────────────────────────────────────────────

    public function test_good_spec_good_worker_success_with_evidence_is_good_execution(): void
    {
        $result = $this->attributor->attribute($this->goodExecution());

        $this->assertSame(AtlasExternalBrainTaskOutcomeCausalAttributor::CAUSE_GOOD_EXECUTION, $result['primary_cause']);
        $this->assertSame('continue_current_approach', $result['recommended_originator_adjustment']);
        $this->assertSame('high', $result['confidence']);
    }

    // ── AC1: poor spec ────────────────────────────────────────────────────────

    public function test_low_quality_score_causes_poor_spec(): void
    {
        $result = $this->attributor->attribute($this->goodExecution([
            'spec'    => ['quality_score' => 0.2, 'has_acceptance_criteria' => true],
            'outcome' => ['result' => 'give_back'],
        ]));

        $this->assertSame(AtlasExternalBrainTaskOutcomeCausalAttributor::CAUSE_POOR_SPEC, $result['primary_cause']);
        $this->assertSame('improve_spec_quality', $result['recommended_originator_adjustment']);
    }

    public function test_missing_acceptance_criteria_causes_poor_spec(): void
    {
        $result = $this->attributor->attribute($this->goodExecution([
            'spec'    => ['quality_score' => 0.8, 'has_acceptance_criteria' => false],
            'outcome' => ['result' => 'give_back'],
        ]));

        $this->assertSame(AtlasExternalBrainTaskOutcomeCausalAttributor::CAUSE_POOR_SPEC, $result['primary_cause']);
        $this->assertContains('missing_acceptance_criteria', $result['contributing_causes']);
    }

    // ── AC2: worker mismatch distinguished from poor spec ────────────────────

    public function test_good_spec_in_avoid_class_causes_worker_mismatch_not_poor_spec(): void
    {
        $result = $this->attributor->attribute($this->goodExecution([
            'spec'    => ['quality_score' => 0.85, 'has_acceptance_criteria' => true, 'evidence_strength' => 0.8, 'complexity' => 'low'],
            'worker'  => ['quality_score' => 0.8, 'avoid_task_classes' => ['refactor'], 'best_task_classes' => [], 'task_class' => 'refactor'],
            'outcome' => ['result' => 'give_back', 'had_evidence' => false, 'shallow_success' => false],
        ]));

        $this->assertSame(AtlasExternalBrainTaskOutcomeCausalAttributor::CAUSE_WORKER_MISMATCH, $result['primary_cause']);
        $this->assertSame('route_to_better_worker', $result['recommended_originator_adjustment']);
        $this->assertContains('worker_avoid_class_matched', $result['contributing_causes']);
    }

    // ── AC2: bad spec + avoid class → poor spec wins ─────────────────────────

    public function test_poor_spec_wins_even_when_worker_also_mismatched(): void
    {
        $result = $this->attributor->attribute($this->goodExecution([
            'spec'    => ['quality_score' => 0.1, 'has_acceptance_criteria' => false],
            'worker'  => ['quality_score' => 0.8, 'avoid_task_classes' => ['refactor'], 'best_task_classes' => [], 'task_class' => 'refactor'],
            'outcome' => ['result' => 'give_back'],
        ]));

        $this->assertSame(AtlasExternalBrainTaskOutcomeCausalAttributor::CAUSE_POOR_SPEC, $result['primary_cause']);
    }

    // ── Shallow evidence ─────────────────────────────────────────────────────

    public function test_success_with_shallow_flag_causes_shallow_evidence(): void
    {
        $result = $this->attributor->attribute($this->goodExecution([
            'outcome' => ['result' => 'success', 'had_evidence' => true, 'shallow_success' => true],
        ]));

        $this->assertSame(AtlasExternalBrainTaskOutcomeCausalAttributor::CAUSE_SHALLOW_EVIDENCE, $result['primary_cause']);
        $this->assertSame('strengthen_evidence_requirement', $result['recommended_originator_adjustment']);
    }

    public function test_success_with_weak_evidence_strength_causes_shallow_evidence(): void
    {
        $result = $this->attributor->attribute($this->goodExecution([
            'spec'    => ['quality_score' => 0.9, 'has_acceptance_criteria' => true, 'evidence_strength' => 0.2, 'complexity' => 'low'],
            'outcome' => ['result' => 'success', 'had_evidence' => true, 'shallow_success' => false],
        ]));

        $this->assertSame(AtlasExternalBrainTaskOutcomeCausalAttributor::CAUSE_SHALLOW_EVIDENCE, $result['primary_cause']);
    }

    // ── Implementation complexity ─────────────────────────────────────────────

    public function test_high_complexity_with_give_back_causes_complexity(): void
    {
        $result = $this->attributor->attribute($this->goodExecution([
            'spec'    => ['quality_score' => 0.8, 'has_acceptance_criteria' => true, 'evidence_strength' => 0.7, 'complexity' => 'high'],
            'outcome' => ['result' => 'give_back', 'had_evidence' => false, 'shallow_success' => false],
        ]));

        $this->assertSame(AtlasExternalBrainTaskOutcomeCausalAttributor::CAUSE_COMPLEXITY, $result['primary_cause']);
        $this->assertSame('split_into_smaller_tasks', $result['recommended_originator_adjustment']);
    }

    // ── Worker capability gap ─────────────────────────────────────────────────

    public function test_low_worker_quality_with_failed_gate_causes_capability_gap(): void
    {
        $result = $this->attributor->attribute($this->goodExecution([
            'spec'    => ['quality_score' => 0.8, 'has_acceptance_criteria' => true, 'evidence_strength' => 0.7, 'complexity' => 'low'],
            'worker'  => ['quality_score' => 0.3, 'avoid_task_classes' => [], 'best_task_classes' => [], 'task_class' => 'new_service'],
            'outcome' => ['result' => 'failed_gate', 'had_evidence' => false, 'shallow_success' => false],
        ]));

        $this->assertSame(AtlasExternalBrainTaskOutcomeCausalAttributor::CAUSE_WORKER_CAPABILITY_GAP, $result['primary_cause']);
    }

    // ── Queue contention ─────────────────────────────────────────────────────

    public function test_high_contention_with_give_back_causes_queue_contention(): void
    {
        $result = $this->attributor->attribute($this->goodExecution([
            'worker'  => ['quality_score' => 0.85, 'avoid_task_classes' => [], 'best_task_classes' => [], 'task_class' => 'new_service'],
            'queue'   => ['contention_level' => 'high'],
            'outcome' => ['result' => 'give_back', 'had_evidence' => false, 'shallow_success' => false],
        ]));

        $this->assertSame(AtlasExternalBrainTaskOutcomeCausalAttributor::CAUSE_QUEUE_CONTENTION, $result['primary_cause']);
        $this->assertSame('reduce_contention', $result['recommended_originator_adjustment']);
    }

    // ── attribution_id is deterministic ──────────────────────────────────────

    public function test_attribution_id_is_deterministic_for_same_input(): void
    {
        $input = $this->goodExecution();
        $a = $this->attributor->attribute($input);
        $b = $this->attributor->attribute($input);

        $this->assertSame($a['attribution_id'], $b['attribution_id']);
    }

    // ── AC1: routing_family_mismatch ─────────────────────────────────────────

    public function test_routing_family_mismatch_when_capable_worker_repeatedly_gives_back(): void
    {
        $r = $this->attributor->attribute($this->goodExecution([
            'worker'  => ['quality_score' => 0.9, 'avoid_task_classes' => [], 'task_class' => 'refactor', 'repeated_give_back_count' => 3],
            'outcome' => ['result' => 'give_back', 'had_evidence' => true, 'shallow_success' => false],
        ]));

        $this->assertSame(AtlasExternalBrainTaskOutcomeCausalAttributor::CAUSE_ROUTING_FAMILY_MISMATCH, $r['primary_cause']);
        $this->assertSame(AtlasExternalBrainTaskOutcomeCausalAttributor::ROUTING_SIGNAL_NEGATIVE, $r['routing_signal']);
        $this->assertSame('reassign_to_better_task_family', $r['recommended_originator_adjustment']);
        $this->assertContains('repeated_give_back_count:3', $r['contributing_causes']);
    }

    public function test_routing_family_mismatch_not_triggered_below_threshold(): void
    {
        $r = $this->attributor->attribute($this->goodExecution([
            'worker'  => ['quality_score' => 0.9, 'avoid_task_classes' => [], 'task_class' => 'refactor', 'repeated_give_back_count' => 1],
            'outcome' => ['result' => 'give_back', 'had_evidence' => true, 'shallow_success' => false],
        ]));

        $this->assertNotSame(AtlasExternalBrainTaskOutcomeCausalAttributor::CAUSE_ROUTING_FAMILY_MISMATCH, $r['primary_cause']);
    }

    public function test_routing_family_mismatch_not_triggered_for_low_quality_worker(): void
    {
        // Low-quality worker giving back → worker_capability_gap, not routing_family_mismatch.
        $r = $this->attributor->attribute($this->goodExecution([
            'worker'  => ['quality_score' => 0.3, 'avoid_task_classes' => [], 'task_class' => 'refactor', 'repeated_give_back_count' => 5],
            'outcome' => ['result' => 'give_back', 'had_evidence' => true, 'shallow_success' => false],
        ]));

        $this->assertNotSame(AtlasExternalBrainTaskOutcomeCausalAttributor::CAUSE_ROUTING_FAMILY_MISMATCH, $r['primary_cause']);
    }

    public function test_custom_give_back_threshold_honored(): void
    {
        $r = $this->attributor->attribute($this->goodExecution([
            'worker'  => ['quality_score' => 0.9, 'avoid_task_classes' => [], 'task_class' => 'refactor',
                          'repeated_give_back_count' => 1, 'give_back_threshold' => 1],
            'outcome' => ['result' => 'give_back', 'had_evidence' => true, 'shallow_success' => false],
        ]));

        $this->assertSame(AtlasExternalBrainTaskOutcomeCausalAttributor::CAUSE_ROUTING_FAMILY_MISMATCH, $r['primary_cause']);
    }

    // ── AC2: routing_signal positive for good execution ──────────────────────

    public function test_routing_signal_positive_for_good_execution(): void
    {
        $r = $this->attributor->attribute($this->goodExecution());

        $this->assertSame(AtlasExternalBrainTaskOutcomeCausalAttributor::ROUTING_SIGNAL_POSITIVE, $r['routing_signal']);
    }

    public function test_routing_signal_positive_with_repeated_success(): void
    {
        $r = $this->attributor->attribute($this->goodExecution([
            'worker' => ['quality_score' => 0.9, 'task_class' => 'new_service', 'repeated_success_count' => 5],
        ]));

        $this->assertSame(AtlasExternalBrainTaskOutcomeCausalAttributor::ROUTING_SIGNAL_POSITIVE, $r['routing_signal']);
        $this->assertContains('repeated_success_count:5', $r['contributing_causes']);
    }

    public function test_routing_signal_neutral_for_non_routing_cause(): void
    {
        $r = $this->attributor->attribute([
            'spec'    => ['quality_score' => 0.1, 'has_acceptance_criteria' => false],
            'outcome' => ['result' => 'give_back'],
        ]);

        $this->assertSame(AtlasExternalBrainTaskOutcomeCausalAttributor::ROUTING_SIGNAL_NEUTRAL, $r['routing_signal']);
    }

    public function test_routing_signal_always_present_in_output(): void
    {
        $r = $this->attributor->attribute([]);

        $this->assertArrayHasKey('routing_signal', $r);
    }

    // ── graph_adjustment ──────────────────────────────────────────────────────

    public function test_result_has_graph_adjustment_key(): void
    {
        $r = $this->attributor->attribute($this->goodExecution());

        $this->assertArrayHasKey('graph_adjustment', $r);
        foreach (['action', 'task_packet_id', 'family', 'reason'] as $k) {
            $this->assertArrayHasKey($k, $r['graph_adjustment'], "missing graph_adjustment key: {$k}");
        }
    }

    // ── AC1: scope_failure / poisoned_acceptance → block_chain or respec_before_retry ──

    public function test_scope_failure_graph_adjustment_is_block_chain(): void
    {
        $r = $this->attributor->attribute([
            'spec' => ['forbidden_files_detected' => true, 'quality_score' => 0.9, 'has_acceptance_criteria' => true],
            'task_packet_id' => 'pkt-scope',
            'family' => 'fam-scope',
        ]);

        $this->assertSame(AtlasExternalBrainTaskOutcomeCausalAttributor::CAUSE_SCOPE_FAILURE, $r['primary_cause']);
        $this->assertContains($r['graph_adjustment']['action'], [
            AtlasExternalBrainTaskOutcomeCausalAttributor::ADJUSTMENT_BLOCK_CHAIN,
            AtlasExternalBrainTaskOutcomeCausalAttributor::ADJUSTMENT_RESPEC_BEFORE_RETRY,
        ]);
        $this->assertSame('pkt-scope', $r['graph_adjustment']['task_packet_id']);
        $this->assertSame('fam-scope', $r['graph_adjustment']['family']);
    }

    public function test_poisoned_acceptance_graph_adjustment_is_block_chain_or_respec(): void
    {
        $r = $this->attributor->attribute([
            'spec'    => ['quality_score' => 0.9, 'has_acceptance_criteria' => true, 'contradictory_acceptance' => true],
            'outcome' => ['result' => 'give_back'],
            'task_packet_id' => 'pkt-poison',
            'family' => 'fam-poison',
        ]);

        $this->assertSame(AtlasExternalBrainTaskOutcomeCausalAttributor::CAUSE_POISONED_ACCEPTANCE, $r['primary_cause']);
        $this->assertContains($r['graph_adjustment']['action'], [
            AtlasExternalBrainTaskOutcomeCausalAttributor::ADJUSTMENT_BLOCK_CHAIN,
            AtlasExternalBrainTaskOutcomeCausalAttributor::ADJUSTMENT_RESPEC_BEFORE_RETRY,
        ]);
        $this->assertSame('pkt-poison', $r['graph_adjustment']['task_packet_id']);
        $this->assertSame('fam-poison', $r['graph_adjustment']['family']);
    }

    public function test_graph_adjustment_refs_are_null_when_not_supplied(): void
    {
        $r = $this->attributor->attribute([
            'spec' => ['forbidden_files_detected' => true, 'quality_score' => 0.9, 'has_acceptance_criteria' => true],
        ]);

        $this->assertNull($r['graph_adjustment']['task_packet_id']);
        $this->assertNull($r['graph_adjustment']['family']);
    }

    // ── AC2: good_execution → continue_chain or promote_family with positive routing signal ──

    public function test_good_execution_graph_adjustment_is_continue_chain_or_promote_family(): void
    {
        $r = $this->attributor->attribute($this->goodExecution());

        $this->assertSame(AtlasExternalBrainTaskOutcomeCausalAttributor::CAUSE_GOOD_EXECUTION, $r['primary_cause']);
        $this->assertContains($r['graph_adjustment']['action'], [
            AtlasExternalBrainTaskOutcomeCausalAttributor::ADJUSTMENT_CONTINUE_CHAIN,
            AtlasExternalBrainTaskOutcomeCausalAttributor::ADJUSTMENT_PROMOTE_FAMILY,
        ]);
        $this->assertSame(AtlasExternalBrainTaskOutcomeCausalAttributor::ROUTING_SIGNAL_POSITIVE, $r['routing_signal']);
    }

    public function test_good_execution_with_repeated_success_promotes_family(): void
    {
        $r = $this->attributor->attribute($this->goodExecution([
            'worker' => ['repeated_success_count' => 5, 'success_threshold' => 2],
        ]));

        $this->assertSame(AtlasExternalBrainTaskOutcomeCausalAttributor::ADJUSTMENT_PROMOTE_FAMILY, $r['graph_adjustment']['action']);
    }

    public function test_good_execution_without_repeated_success_continues_chain(): void
    {
        $r = $this->attributor->attribute($this->goodExecution());

        $this->assertSame(AtlasExternalBrainTaskOutcomeCausalAttributor::ADJUSTMENT_CONTINUE_CHAIN, $r['graph_adjustment']['action']);
    }

    // ── AC3: worker_mismatch / routing_family_mismatch → reroute_worker, never spec-quality ──

    public function test_worker_mismatch_graph_adjustment_is_reroute_worker(): void
    {
        $r = $this->attributor->attribute([
            'spec'    => ['quality_score' => 0.9, 'has_acceptance_criteria' => true],
            'worker'  => ['quality_score' => 0.85, 'task_class' => 'risky_refactor', 'avoid_task_classes' => ['risky_refactor']],
            'outcome' => ['result' => 'give_back'],
        ]);

        $this->assertSame(AtlasExternalBrainTaskOutcomeCausalAttributor::CAUSE_WORKER_MISMATCH, $r['primary_cause']);
        $this->assertSame(AtlasExternalBrainTaskOutcomeCausalAttributor::ADJUSTMENT_REROUTE_WORKER, $r['graph_adjustment']['action']);
        $this->assertNotSame(AtlasExternalBrainTaskOutcomeCausalAttributor::ADJUSTMENT_SPLIT_BEFORE_RETRY, $r['graph_adjustment']['action']);
    }

    public function test_routing_family_mismatch_graph_adjustment_is_reroute_worker(): void
    {
        $r = $this->attributor->attribute([
            'spec'    => ['quality_score' => 0.9, 'has_acceptance_criteria' => true],
            'worker'  => ['quality_score' => 0.8, 'task_class' => 'frontend', 'avoid_task_classes' => [], 'repeated_give_back_count' => 3, 'give_back_threshold' => 2],
            'outcome' => ['result' => 'give_back'],
        ]);

        $this->assertSame(AtlasExternalBrainTaskOutcomeCausalAttributor::CAUSE_ROUTING_FAMILY_MISMATCH, $r['primary_cause']);
        $this->assertSame(AtlasExternalBrainTaskOutcomeCausalAttributor::ADJUSTMENT_REROUTE_WORKER, $r['graph_adjustment']['action']);
        // Must NOT be labelled as a spec-quality problem.
        $this->assertNotSame(AtlasExternalBrainTaskOutcomeCausalAttributor::ADJUSTMENT_SPLIT_BEFORE_RETRY, $r['graph_adjustment']['action']);
        $this->assertNotSame(AtlasExternalBrainTaskOutcomeCausalAttributor::ADJUSTMENT_RESPEC_BEFORE_RETRY, $r['graph_adjustment']['action']);
    }

    public function test_attribute_is_deterministic_with_graph_adjustment(): void
    {
        $input = [
            'spec'           => ['quality_score' => 0.9, 'has_acceptance_criteria' => true],
            'worker'         => ['quality_score' => 0.85, 'task_class' => 'risky', 'avoid_task_classes' => ['risky']],
            'outcome'        => ['result' => 'give_back'],
            'task_packet_id' => 'pkt-det',
            'family'         => 'fam-det',
        ];

        $a = $this->attributor->attribute($input);
        $b = $this->attributor->attribute($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
