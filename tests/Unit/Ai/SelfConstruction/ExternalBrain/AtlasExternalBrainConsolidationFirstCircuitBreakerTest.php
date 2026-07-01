<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainConsolidationFirstCircuitBreaker;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainConsolidationFirstCircuitBreakerTest extends TestCase
{
    private AtlasExternalBrainConsolidationFirstCircuitBreaker $cb;

    protected function setUp(): void
    {
        $this->cb = new AtlasExternalBrainConsolidationFirstCircuitBreaker;
    }

    private function eval(array $metrics): array
    {
        return $this->cb->evaluate($metrics);
    }

    private function actionsOf(array $r): array
    {
        return $r['consolidation_actions'] ?? [];
    }

    private function actionTypes(array $r): array
    {
        return array_column($this->actionsOf($r), 'action');
    }

    // ── Output shape ──────────────────────────────────────────────────────────

    public function test_output_has_required_keys_when_consolidating(): void
    {
        $r = $this->eval(['overlap_score' => 0.6, 'class_growth_count' => 40]);

        $this->assertSame(AtlasExternalBrainConsolidationFirstCircuitBreaker::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('decision', $r);
        $this->assertArrayHasKey('simplification_risk', $r);
        $this->assertArrayHasKey('consolidation_actions', $r);
        $this->assertArrayHasKey('triggers', $r);
    }

    public function test_output_has_required_keys_when_continuing(): void
    {
        $r = $this->eval(['overlap_score' => 0.1, 'capability_gap_count' => 5]);

        $this->assertSame(AtlasExternalBrainConsolidationFirstCircuitBreaker::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('decision', $r);
        $this->assertArrayHasKey('simplification_risk', $r);
        $this->assertArrayHasKey('capability_gaps', $r);
    }

    // ── AC: consolidate_first when overlap + growth both exceeded ─────────────

    public function test_high_overlap_and_growth_triggers_consolidate_first(): void
    {
        $r = $this->eval(['overlap_score' => 0.6, 'class_growth_count' => 40]);

        $this->assertSame(AtlasExternalBrainConsolidationFirstCircuitBreaker::DECISION_CONSOLIDATE, $r['decision']);
    }

    public function test_only_high_overlap_no_growth_does_not_trigger(): void
    {
        $r = $this->eval(['overlap_score' => 0.6, 'class_growth_count' => 5]);

        $this->assertSame(AtlasExternalBrainConsolidationFirstCircuitBreaker::DECISION_CONTINUE, $r['decision']);
    }

    public function test_only_high_growth_no_overlap_does_not_trigger(): void
    {
        $r = $this->eval(['overlap_score' => 0.1, 'class_growth_count' => 40]);

        $this->assertSame(AtlasExternalBrainConsolidationFirstCircuitBreaker::DECISION_CONTINUE, $r['decision']);
    }

    // ── AC: secondary signals (2+) also trigger consolidate_first ────────────

    public function test_two_secondary_signals_trigger_consolidate(): void
    {
        $r = $this->eval([
            'duplicate_capability_names'     => ['cap_a', 'cap_b', 'cap_c'],
            'shallow_scaffold_ratio'         => 0.5,
        ]);

        $this->assertSame(AtlasExternalBrainConsolidationFirstCircuitBreaker::DECISION_CONSOLIDATE, $r['decision']);
    }

    public function test_one_secondary_signal_alone_does_not_trigger(): void
    {
        $r = $this->eval(['duplicate_capability_names' => ['a', 'b', 'c']]);

        $this->assertSame(AtlasExternalBrainConsolidationFirstCircuitBreaker::DECISION_CONTINUE, $r['decision']);
    }

    // ── AC: consolidation actions include merge / retire / simplify ───────────

    public function test_high_overlap_includes_merge_action(): void
    {
        $r = $this->eval(['overlap_score' => 0.6, 'class_growth_count' => 40]);

        $this->assertContains('merge', $this->actionTypes($r));
    }

    public function test_high_scaffold_includes_retire_action(): void
    {
        $r = $this->eval([
            'duplicate_capability_names' => ['a', 'b', 'c'],
            'shallow_scaffold_ratio'     => 0.5,
        ]);

        $this->assertContains('retire', $this->actionTypes($r));
    }

    public function test_high_debt_includes_simplify_action(): void
    {
        $r = $this->eval([
            'duplicate_capability_names'   => ['a', 'b', 'c'],
            'unresolved_simplification_debt' => 6,
        ]);

        $this->assertContains('simplify', $this->actionTypes($r));
    }

    public function test_consolidation_actions_never_empty(): void
    {
        $r = $this->eval(['overlap_score' => 0.6, 'class_growth_count' => 50]);

        $this->assertNotEmpty($this->actionsOf($r));
    }

    public function test_consolidation_actions_contain_only_allowed_types(): void
    {
        $r       = $this->eval(['overlap_score' => 0.6, 'class_growth_count' => 40]);
        $allowed = ['merge', 'retire', 'simplify'];

        foreach ($this->actionTypes($r) as $type) {
            $this->assertContains($type, $allowed, "Unexpected action type: {$type}");
        }
    }

    // ── AC: continue_building reports simplification_risk + capability_gaps ───

    public function test_low_overlap_with_material_gaps_continues_building(): void
    {
        $r = $this->eval(['overlap_score' => 0.1, 'capability_gap_count' => 5]);

        $this->assertSame(AtlasExternalBrainConsolidationFirstCircuitBreaker::DECISION_CONTINUE, $r['decision']);
        $this->assertSame(5, $r['capability_gaps']);
    }

    public function test_continue_building_reports_simplification_risk(): void
    {
        $r = $this->eval(['overlap_score' => 0.2, 'capability_gap_count' => 3]);

        $this->assertIsFloat($r['simplification_risk']);
        $this->assertGreaterThanOrEqual(0.0, $r['simplification_risk']);
        $this->assertLessThanOrEqual(1.0, $r['simplification_risk']);
    }

    // ── Simplification risk ───────────────────────────────────────────────────

    public function test_all_signals_maxed_gives_high_risk(): void
    {
        $r = $this->eval([
            'overlap_score'                  => 1.0,
            'class_growth_count'             => 200,
            'duplicate_capability_names'     => range(1, 10),
            'shallow_scaffold_ratio'         => 1.0,
            'unresolved_simplification_debt' => 10,
        ]);

        $this->assertEqualsWithDelta(1.0, $r['simplification_risk'], 0.0001);
    }

    public function test_zero_signals_gives_zero_risk(): void
    {
        $r = $this->eval([]);

        $this->assertEqualsWithDelta(0.0, $r['simplification_risk'], 0.0001);
    }

    // ── Triggers ─────────────────────────────────────────────────────────────

    public function test_triggers_list_overlapping_signals(): void
    {
        $r = $this->eval(['overlap_score' => 0.6, 'class_growth_count' => 40]);

        $this->assertContains('overlap_exceeded', $r['triggers']);
        $this->assertContains('class_growth_exceeded', $r['triggers']);
    }

    public function test_triggers_empty_when_continuing(): void
    {
        $r = $this->eval(['overlap_score' => 0.1]);

        $this->assertSame([], $r['triggers']);
    }

    // ── Custom thresholds ─────────────────────────────────────────────────────

    public function test_custom_thresholds_respected(): void
    {
        // Default overlap threshold 0.4; use 0.9 → overlap 0.6 won't trigger
        $r = $this->eval([
            'overlap_score'       => 0.6,
            'class_growth_count'  => 40,
            'overlap_threshold'   => 0.9,
            'growth_threshold'    => 100,
        ]);

        $this->assertSame(AtlasExternalBrainConsolidationFirstCircuitBreaker::DECISION_CONTINUE, $r['decision']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $m = ['overlap_score' => 0.5, 'class_growth_count' => 35, 'capability_gap_count' => 2];

        $this->assertSame(json_encode($this->eval($m)), json_encode($this->eval($m)));
    }

    // ── evaluateEnqueueGate ──────────────────────────────────────────────────────

    public function test_healthy_state_permits_enqueue_with_no_recommendation(): void
    {
        $r = $this->cb->evaluateEnqueueGate([
            'queue_saturation' => 0.1,
            'organ_sprawl_score' => 0.1,
            'redundant_scaffold_count' => 0,
            'marginal_new_task_value' => 0.8,
        ]);

        $this->assertSame('none', $r['recommendation']);
        $this->assertFalse($r['circuit_open']);
        $this->assertTrue($r['enqueue_permitted']);
    }

    public function test_queue_saturation_recommends_learn_from_outcomes_and_blocks_enqueue(): void
    {
        $r = $this->cb->evaluateEnqueueGate(['queue_saturation' => 0.9]);

        $this->assertSame('learn_from_outcomes', $r['recommendation']);
        $this->assertTrue($r['circuit_open']);
        $this->assertFalse($r['enqueue_permitted']);
    }

    public function test_organ_sprawl_recommends_consolidate(): void
    {
        $r = $this->cb->evaluateEnqueueGate(['organ_sprawl_score' => 0.7]);

        $this->assertSame('consolidate', $r['recommendation']);
        $this->assertFalse($r['enqueue_permitted']);
    }

    public function test_redundant_scaffolds_recommends_retire(): void
    {
        $r = $this->cb->evaluateEnqueueGate(['redundant_scaffold_count' => 4]);

        $this->assertSame('retire', $r['recommendation']);
        $this->assertFalse($r['enqueue_permitted']);
    }

    public function test_low_marginal_value_recommends_simplify(): void
    {
        $r = $this->cb->evaluateEnqueueGate(['marginal_new_task_value' => 0.1]);

        $this->assertSame('simplify', $r['recommendation']);
        $this->assertFalse($r['enqueue_permitted']);
    }

    public function test_task_that_unlocks_consolidation_is_permitted_despite_open_circuit(): void
    {
        $r = $this->cb->evaluateEnqueueGate([
            'queue_saturation' => 0.9,
            'proposed_task' => ['unlocks_consolidation' => true],
        ]);

        $this->assertTrue($r['circuit_open']);
        $this->assertTrue($r['enqueue_permitted']);
        $this->assertTrue($r['permitted_via_exception']);
    }

    public function test_task_that_removes_blocker_is_permitted_despite_open_circuit(): void
    {
        $r = $this->cb->evaluateEnqueueGate([
            'organ_sprawl_score' => 0.7,
            'proposed_task' => ['removes_blocker' => true],
        ]);

        $this->assertTrue($r['enqueue_permitted']);
        $this->assertTrue($r['permitted_via_exception']);
    }

    public function test_ordinary_task_during_open_circuit_is_not_permitted(): void
    {
        $r = $this->cb->evaluateEnqueueGate([
            'organ_sprawl_score' => 0.7,
            'proposed_task' => ['unlocks_consolidation' => false, 'removes_blocker' => false],
        ]);

        $this->assertFalse($r['enqueue_permitted']);
        $this->assertFalse($r['permitted_via_exception']);
    }

    public function test_queue_saturation_outranks_other_triggers(): void
    {
        $r = $this->cb->evaluateEnqueueGate([
            'queue_saturation' => 0.9,
            'organ_sprawl_score' => 0.9,
            'redundant_scaffold_count' => 10,
            'marginal_new_task_value' => 0.0,
        ]);

        $this->assertSame('learn_from_outcomes', $r['recommendation']);
    }

    // ── evaluateOrganProposal — AC: safe add ──────────────────────────────────

    public function test_safe_add_organ_proposal_is_not_blocked_and_has_no_recommended_actions(): void
    {
        $r = $this->cb->evaluateOrganProposal([
            'queue_saturation' => 0.1,
            'organ_sprawl_score' => 0.1,
            'redundant_scaffold_count' => 0,
            'marginal_new_task_value' => 0.8,
            'duplicate_responsibility_score' => 0.1,
            'cohesion_score' => 0.9,
        ]);

        $this->assertFalse($r['blocked']);
        $this->assertNull($r['reason']);
        $this->assertSame([], $r['recommended_actions']);
    }

    // ── evaluateOrganProposal — AC: duplicate-organ block ─────────────────────

    public function test_duplicate_organ_proposal_is_blocked_with_merge_action_and_required_proof(): void
    {
        $r = $this->cb->evaluateOrganProposal([
            'duplicate_responsibility_score' => 0.8,
        ]);

        $this->assertTrue($r['blocked']);
        $this->assertTrue($r['duplicate_responsibility_high']);
        $this->assertSame(AtlasExternalBrainConsolidationFirstCircuitBreaker::BLOCKED_REASON_CONSOLIDATION_FIRST_SPRAWL, $r['reason']);

        $mergeAction = array_values(array_filter($r['recommended_actions'], fn (array $a) => $a['action'] === 'merge'))[0] ?? null;
        $this->assertNotNull($mergeAction);
        $this->assertNotEmpty($mergeAction['required_proof']);
    }

    // ── evaluateOrganProposal — AC: stale-scaffold retirement ─────────────────

    public function test_stale_scaffold_proposal_is_blocked_with_delete_action(): void
    {
        $r = $this->cb->evaluateOrganProposal([
            'redundant_scaffold_count' => 5,
        ]);

        $this->assertTrue($r['blocked']);
        $this->assertSame('retire', $r['recommendation']);

        $deleteAction = array_values(array_filter($r['recommended_actions'], fn (array $a) => $a['action'] === 'delete'))[0] ?? null;
        $this->assertNotNull($deleteAction);
        $this->assertNotEmpty($deleteAction['required_proof']);
    }

    // ── evaluateOrganProposal — AC: low-cohesion consolidation ────────────────

    public function test_low_cohesion_proposal_is_blocked_with_simplify_action(): void
    {
        $r = $this->cb->evaluateOrganProposal([
            'cohesion_score' => 0.2,
        ]);

        $this->assertTrue($r['blocked']);
        $this->assertTrue($r['low_cohesion']);

        $simplifyAction = array_values(array_filter($r['recommended_actions'], fn (array $a) => $a['action'] === 'simplify' && $a['reason'] === 'low_cohesion_score'))[0] ?? null;
        $this->assertNotNull($simplifyAction);
        $this->assertNotEmpty($simplifyAction['required_proof']);
    }

    // ── evaluateOrganProposal — AC: missing parity proof ──────────────────────

    public function test_missing_parity_proof_proposal_is_blocked_with_require_parity_proof_action(): void
    {
        $r = $this->cb->evaluateOrganProposal([
            'parity_proof_required' => true,
            'parity_proof_present' => false,
        ]);

        $this->assertTrue($r['blocked']);
        $this->assertTrue($r['missing_parity_proof']);

        $proofAction = array_values(array_filter($r['recommended_actions'], fn (array $a) => $a['action'] === 'require_parity_proof'))[0] ?? null;
        $this->assertNotNull($proofAction);
        $this->assertNotEmpty($proofAction['required_proof']);
    }

    public function test_present_parity_proof_does_not_block_on_that_signal(): void
    {
        $r = $this->cb->evaluateOrganProposal([
            'parity_proof_required' => true,
            'parity_proof_present' => true,
        ]);

        $this->assertFalse($r['missing_parity_proof']);
        $this->assertFalse($r['blocked']);
    }

    // ── evaluateOrganProposal — exemption still applies to new signals ────────

    public function test_exempt_proposal_kind_is_not_blocked_despite_low_cohesion(): void
    {
        $r = $this->cb->evaluateOrganProposal([
            'cohesion_score' => 0.1,
            'proposed_task' => ['kind' => 'consolidation'],
        ]);

        $this->assertFalse($r['blocked']);
    }
}
