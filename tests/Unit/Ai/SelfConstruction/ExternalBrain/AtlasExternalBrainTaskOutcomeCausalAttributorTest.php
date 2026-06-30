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
}
