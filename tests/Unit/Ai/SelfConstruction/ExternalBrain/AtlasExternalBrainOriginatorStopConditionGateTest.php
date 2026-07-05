<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOriginatorStopConditionGate;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainOriginatorStopConditionGateTest extends TestCase
{
    private AtlasExternalBrainOriginatorStopConditionGate $gate;

    protected function setUp(): void
    {
        $this->gate = new AtlasExternalBrainOriginatorStopConditionGate;
    }

    private function eval(array $input): array
    {
        return $this->gate->evaluate($input);
    }

    // ── Schema / required keys ────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->eval([]);

        foreach (['schema', 'verdict', 'stop_reason', 'blocking_reasons', 'evidence_cited',
                  'can_stop', 'continuation_required', 'missing_evidence', 'next_required_action'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::SCHEMA, $result['schema']);
    }

    // ── quota_count alone is insufficient to stop ─────────────────────────────

    public function test_quota_met_alone_without_value_score_anti_goodhart_or_outcome_evidence_does_not_stop(): void
    {
        $result = $this->eval(['quota_count' => 10, 'quota_target' => 10]);

        $this->assertFalse($result['can_stop']);
        $this->assertTrue($result['continuation_required']);
        $this->assertNotSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_HONEST_STOP, $result['verdict']);
        $this->assertContains('value_score', $result['missing_evidence']);
        $this->assertContains('anti_goodhart_pass', $result['missing_evidence']);
        $this->assertContains('outcome_learning_evidence', $result['missing_evidence']);
    }

    public function test_quota_met_with_value_score_anti_goodhart_and_outcome_evidence_honest_stops(): void
    {
        $result = $this->eval([
            'quota_count'               => 10,
            'quota_target'              => 10,
            'value_score'               => 0.85,
            'anti_goodhart_pass'        => true,
            'outcome_learning_evidence' => ['delivered_real_capability_gain'],
        ]);

        $this->assertTrue($result['can_stop']);
        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_HONEST_STOP, $result['verdict']);
        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::REASON_QUOTA_MET_WITH_VALUE, $result['stop_reason']);
        $this->assertSame([], $result['missing_evidence']);
    }

    public function test_quota_met_missing_only_anti_goodhart_pass_does_not_stop(): void
    {
        $result = $this->eval([
            'quota_count'               => 5,
            'quota_target'              => 5,
            'value_score'               => 0.9,
            'outcome_learning_evidence' => ['some_evidence'],
        ]);

        $this->assertFalse($result['can_stop']);
        $this->assertContains('anti_goodhart_pass', $result['missing_evidence']);
        $this->assertNotContains('value_score', $result['missing_evidence']);
    }

    // ── honest_exhausted requires the full evidence triad ─────────────────────

    public function test_honest_exhausted_requires_searched_surfaces_breakthrough_attempts_and_no_candidates(): void
    {
        $result = $this->eval([
            'searched_surfaces_evidence'                => ['surface_a_searched', 'surface_b_searched'],
            'attempted_breakthrough_patterns_evidence'   => ['tried_pattern_x'],
            'no_enqueueable_high_value_candidates'       => true,
        ]);

        $this->assertTrue($result['can_stop']);
        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_HONEST_STOP, $result['verdict']);
        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::REASON_HONEST_EXHAUSTED, $result['stop_reason']);
    }

    public function test_honest_exhausted_missing_no_candidates_flag_does_not_stop(): void
    {
        $result = $this->eval([
            'all_escalation_modes_tried'                => true,
            'searched_surfaces_evidence'                => ['surface_a_searched'],
            'attempted_breakthrough_patterns_evidence'   => ['tried_pattern_x'],
            'no_enqueueable_high_value_candidates'       => false,
        ]);

        $this->assertNotSame(AtlasExternalBrainOriginatorStopConditionGate::REASON_HONEST_EXHAUSTED, $result['stop_reason']);
        $this->assertContains('no_enqueueable_high_value_candidates', $result['missing_evidence']);
    }

    public function test_honest_exhausted_missing_breakthrough_evidence_does_not_stop(): void
    {
        $result = $this->eval([
            'all_escalation_modes_tried'           => true,
            'searched_surfaces_evidence'           => ['surface_a_searched'],
            'no_enqueueable_high_value_candidates' => true,
        ]);

        $this->assertNotSame(AtlasExternalBrainOriginatorStopConditionGate::REASON_HONEST_EXHAUSTED, $result['stop_reason']);
        $this->assertContains('attempted_breakthrough_patterns_evidence', $result['missing_evidence']);
    }

    // ── can_stop / continuation_required / next_required_action consistency ───

    public function test_honest_stop_sets_can_stop_true_and_no_continuation_required(): void
    {
        $result = $this->eval([
            'quality_target_reached'  => true,
            'quality_target_evidence' => ['cert_passed'],
        ]);

        $this->assertTrue($result['can_stop']);
        $this->assertFalse($result['continuation_required']);
        $this->assertSame('none_required', $result['next_required_action']);
    }

    public function test_non_stop_verdict_sets_can_stop_false_and_continuation_required_true(): void
    {
        $result = $this->eval(['remaining_escalation_modes' => 2]);

        $this->assertFalse($result['can_stop']);
        $this->assertTrue($result['continuation_required']);
        $this->assertNotSame('none_required', $result['next_required_action']);
    }

    // ── honest_stop — quality target reached with evidence (AC2) ─────────────

    public function test_quality_target_reached_with_evidence_is_honest_stop(): void
    {
        $result = $this->eval([
            'quality_target_reached'  => true,
            'quality_target_evidence' => ['tests_green', 'score_9.5'],
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_HONEST_STOP, $result['verdict']);
        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::REASON_QUALITY_TARGET, $result['stop_reason']);
        $this->assertNotEmpty($result['evidence_cited']);
        $this->assertEmpty($result['blocking_reasons']);
    }

    public function test_quality_target_without_evidence_does_not_honest_stop(): void
    {
        $result = $this->eval([
            'quality_target_reached'  => true,
            'quality_target_evidence' => [],
        ]);

        $this->assertNotSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_HONEST_STOP, $result['verdict']);
    }

    // ── honest_stop — exhaustion with evidence (AC2) ──────────────────────────

    public function test_exhausted_with_evidence_is_honest_stop(): void
    {
        $result = $this->eval([
            'all_escalation_modes_tried' => true,
            'escalation_evidence'        => ['mode_a_tried', 'mode_b_tried'],
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_HONEST_STOP, $result['verdict']);
        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::REASON_EXHAUSTED, $result['stop_reason']);
    }

    public function test_exhausted_without_evidence_does_not_honest_stop(): void
    {
        $result = $this->eval([
            'all_escalation_modes_tried' => true,
            'escalation_evidence'        => [],
        ]);

        $this->assertNotSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_HONEST_STOP, $result['verdict']);
    }

    // ── honest_stop — saturated surface (AC2) ────────────────────────────────

    public function test_saturated_surface_with_dedup_is_honest_stop(): void
    {
        $result = $this->eval([
            'no_high_leverage_surface_remaining' => true,
            'deduplication_confirmed'            => true,
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_HONEST_STOP, $result['verdict']);
        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::REASON_SURFACE_SATURATED, $result['stop_reason']);
    }

    public function test_no_surface_without_dedup_is_not_honest_stop(): void
    {
        $result = $this->eval([
            'no_high_leverage_surface_remaining' => true,
            'deduplication_confirmed'            => false,
        ]);

        $this->assertNotSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_HONEST_STOP, $result['verdict']);
    }

    // ── drain_first — queue pressure (AC3) ───────────────────────────────────

    public function test_queue_pressure_non_urgent_yields_drain_first(): void
    {
        $result = $this->eval([
            'queue_pressure_high' => true,
            'task_urgency'        => 'non_urgent',
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_DRAIN_FIRST, $result['verdict']);
        $this->assertNotEmpty($result['evidence_cited']);
    }

    public function test_queue_pressure_urgent_does_not_drain_first(): void
    {
        $result = $this->eval([
            'queue_pressure_high' => true,
            'task_urgency'        => 'urgent',
        ]);

        $this->assertNotSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_DRAIN_FIRST, $result['verdict']);
    }

    // ── consolidate_first (AC3) ───────────────────────────────────────────────

    public function test_consolidation_pressure_yields_consolidate_first(): void
    {
        $result = $this->eval(['consolidation_pressure_high' => true]);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_CONSOLIDATE_FIRST, $result['verdict']);
        $this->assertNotEmpty($result['blocking_reasons']);
    }

    // ── repair_first — gate regression (AC3) ─────────────────────────────────

    public function test_gate_regression_yields_repair_first(): void
    {
        $result = $this->eval(['gate_regression_detected' => true]);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_REPAIR_FIRST, $result['verdict']);
        $this->assertContains('gate_regression_detected', $result['blocking_reasons']);
    }

    public function test_repair_first_beats_honest_stop(): void
    {
        $result = $this->eval([
            'gate_regression_detected'  => true,
            'quality_target_reached'    => true,
            'quality_target_evidence'   => ['score_ok'],
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_REPAIR_FIRST, $result['verdict']);
    }

    // ── AC1: continue_search — remaining escalation modes ────────────────────

    public function test_remaining_escalation_modes_yields_continue_search(): void
    {
        $result = $this->eval(['remaining_escalation_modes' => 2]);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_CONTINUE_SEARCH, $result['verdict']);
        $this->assertStringContainsString('remaining_escalation_modes:2', implode(' ', $result['blocking_reasons']));
    }

    // ── AC1: continue_search — open surfaces ─────────────────────────────────

    public function test_open_surfaces_yields_continue_search(): void
    {
        $result = $this->eval(['open_surfaces_remaining' => 3]);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_CONTINUE_SEARCH, $result['verdict']);
        $this->assertStringContainsString('open_surfaces_remaining:3', implode(' ', $result['blocking_reasons']));
    }

    // ── AC1: continue_search — first_pass_only ────────────────────────────────

    public function test_first_pass_only_yields_continue_search(): void
    {
        $result = $this->eval(['first_pass_only' => true]);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_CONTINUE_SEARCH, $result['verdict']);
        $this->assertContains('first_pass_only:insufficient_exploration', $result['blocking_reasons']);
    }

    // ── escalate_ambition — evidence exists, quality not met (AC3) ───────────

    public function test_partial_evidence_without_quality_target_yields_escalate_ambition(): void
    {
        $result = $this->eval([
            'quality_target_reached'  => false,
            'quality_target_evidence' => ['partial_score:7.8'],
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_ESCALATE_AMBITION, $result['verdict']);
        $this->assertContains('partial_score:7.8', $result['evidence_cited']);
        $this->assertContains('quality_target_not_met', $result['blocking_reasons']);
    }

    // ── premature_stop — no evidence at all (AC1) ────────────────────────────

    public function test_empty_input_is_premature_stop(): void
    {
        $result = $this->eval([]);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_PREMATURE_STOP, $result['verdict']);
        $this->assertEmpty($result['evidence_cited']);
        $this->assertContains('no_evidence_cited', $result['blocking_reasons']);
        $this->assertNull($result['stop_reason']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_same_input_same_output(): void
    {
        $input = ['quality_target_reached' => true, 'quality_target_evidence' => ['ev1']];
        $this->assertSame($this->gate->evaluate($input), $this->gate->evaluate($input));
    }

    // ── honest_stop via high saturation + low value yield ────────────────────

    public function test_high_saturation_low_yield_yields_honest_stop(): void
    {
        $result = $this->eval(['saturation_level' => 0.90, 'value_yield_score' => 0.20]);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_HONEST_STOP, $result['verdict']);
        $this->assertSame(
            AtlasExternalBrainOriginatorStopConditionGate::REASON_HIGH_SATURATION_LOW_YIELD,
            $result['stop_reason'],
        );
        $blocking = implode(' ', $result['blocking_reasons']);
        $this->assertStringContainsString('saturation_level', $blocking);
        $this->assertStringContainsString('value_yield_score', $blocking);
    }

    public function test_saturation_stop_evidence_includes_give_back_rate(): void
    {
        $result = $this->eval([
            'saturation_level'  => 0.85,
            'value_yield_score' => 0.25,
            'give_back_rate'    => 0.40,
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_HONEST_STOP, $result['verdict']);
        $this->assertStringContainsString('give_back_rate', implode(' ', $result['evidence_cited']));
    }

    public function test_high_saturation_but_high_yield_does_not_trigger_saturation_stop(): void
    {
        $result = $this->eval(['saturation_level' => 0.90, 'value_yield_score' => 0.80]);

        $this->assertNotSame(
            AtlasExternalBrainOriginatorStopConditionGate::REASON_HIGH_SATURATION_LOW_YIELD,
            $result['stop_reason'],
        );
    }

    // ── low saturation + high verified opportunity → continue ─────────────────

    public function test_low_saturation_high_yield_with_open_surfaces_yields_continue_search(): void
    {
        $result = $this->eval([
            'saturation_level'        => 0.20,
            'value_yield_score'       => 0.85,
            'open_surfaces_remaining' => 3,
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_CONTINUE_SEARCH, $result['verdict']);
        $this->assertStringContainsString('open_surfaces_remaining:3', implode(' ', $result['blocking_reasons']));
    }

    // ── reduce_scope — duplicate pressure + low value yield ──────────────────

    public function test_duplicate_pressure_low_yield_yields_reduce_scope(): void
    {
        $result = $this->eval(['duplicate_pressure_high' => true, 'value_yield_score' => 0.20]);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_REDUCE_SCOPE, $result['verdict']);
        $this->assertNull($result['stop_reason']);
        $blocking = implode(' ', $result['blocking_reasons']);
        $this->assertStringContainsString('duplicate_pressure_high:true', $blocking);
        $this->assertStringContainsString('value_yield_score', $blocking);
    }

    public function test_reduce_scope_evidence_includes_give_back_rate(): void
    {
        $result = $this->eval([
            'duplicate_pressure_high' => true,
            'value_yield_score'       => 0.25,
            'give_back_rate'          => 0.45,
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_REDUCE_SCOPE, $result['verdict']);
        $this->assertStringContainsString('give_back_rate', implode(' ', $result['evidence_cited']));
    }

    public function test_duplicate_pressure_but_high_yield_does_not_reduce_scope(): void
    {
        $result = $this->eval(['duplicate_pressure_high' => true, 'value_yield_score' => 0.80]);

        $this->assertNotSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_REDUCE_SCOPE, $result['verdict']);
    }

    // ── five distinct verdicts each reachable (AC2 coverage) ─────────────────

    public function test_all_five_ac_verdicts_are_each_reachable(): void
    {
        $cases = [
            'continue_search'   => ['open_surfaces_remaining' => 1],
            'consolidate_first' => ['consolidation_pressure_high' => true],
            'honest_stop'       => ['saturation_level' => 0.9, 'value_yield_score' => 0.1],
            'reduce_scope'      => ['duplicate_pressure_high' => true, 'value_yield_score' => 0.1],
            'escalate_ambition' => ['quality_target_evidence' => ['partial_score:7.0']],
        ];

        foreach ($cases as $expected => $input) {
            $this->assertSame($expected, $this->eval($input)['verdict'], "Expected verdict {$expected}");
        }
    }

    // ── live signal normalization (next_action) ───────────────────────────────

    public function test_live_self_heal_signal_maps_to_repair_first_and_beats_create_signal(): void
    {
        $result = $this->eval([
            'next_action' => 'self_heal_queue_before_creating',
            'quality_target_reached' => true,
            'quality_target_evidence' => ['tests_green'],
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_REPAIR_FIRST, $result['verdict']);
    }

    public function test_live_self_heal_signal_beats_escalate_signal(): void
    {
        $result = $this->eval([
            'next_action' => 'self_heal_queue',
            'quality_target_evidence' => ['partial_score:7.0'],
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_REPAIR_FIRST, $result['verdict']);
    }

    public function test_live_drain_signal_maps_to_drain_first_without_duplicated_boolean(): void
    {
        $result = $this->eval([
            'next_action' => 'drain_existing_queue',
            'task_urgency' => 'non_urgent',
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_DRAIN_FIRST, $result['verdict']);
    }

    public function test_live_wait_signal_does_not_become_queue_pressure_or_stop_origination(): void
    {
        $result = $this->eval([
            'next_action' => 'wait',
            'task_urgency' => 'non_urgent',
            'open_surfaces_remaining' => 1,
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_CONTINUE_SEARCH, $result['verdict']);
        $this->assertNotContains('queue_pressure_high:drain_before_new_origination', $result['blocking_reasons']);
    }

    public function test_live_monitor_idle_supply_signal_does_not_become_queue_pressure_or_stop_origination(): void
    {
        $result = $this->eval([
            'next_action' => 'monitor_idle_supply',
            'task_urgency' => 'non_urgent',
            'open_surfaces_remaining' => 1,
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_CONTINUE_SEARCH, $result['verdict']);
        $this->assertNotContains('queue_pressure_high:drain_before_new_origination', $result['blocking_reasons']);
    }

    public function test_live_consolidate_signal_maps_to_consolidate_first_without_duplicated_boolean(): void
    {
        $result = $this->eval(['next_action' => 'consolidate_existing_tasks']);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_CONSOLIDATE_FIRST, $result['verdict']);
    }

    public function test_live_create_high_leverage_batch_continues_when_modes_remain(): void
    {
        $result = $this->eval([
            'next_action' => 'create_high_leverage_batch',
            'remaining_escalation_modes' => 2,
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_CONTINUE_SEARCH, $result['verdict']);
    }

    public function test_live_create_high_leverage_batch_escalates_when_evidence_exists_and_nothing_left(): void
    {
        $result = $this->eval([
            'next_action' => 'create_high_leverage_batch',
            'quality_target_evidence' => ['partial_score:7.0'],
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_ESCALATE_AMBITION, $result['verdict']);
    }

    public function test_live_create_high_leverage_batch_never_introduces_evidence_free_honest_stop(): void
    {
        $result = $this->eval(['next_action' => 'create_high_leverage_batch']);

        $this->assertNotSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_HONEST_STOP, $result['verdict']);
        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_PREMATURE_STOP, $result['verdict']);
    }

    public function test_live_observe_and_wait_signal_does_not_override_anything(): void
    {
        $result = $this->eval([
            'next_action' => 'observe_and_wait',
            'quality_target_reached' => true,
            'quality_target_evidence' => ['tests_green'],
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_HONEST_STOP, $result['verdict']);
    }

    public function test_live_signal_never_weakens_explicit_gate_regression(): void
    {
        $result = $this->eval([
            'next_action' => 'create_high_leverage_batch',
            'gate_regression_detected' => true,
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_REPAIR_FIRST, $result['verdict']);
    }

    // ── continue_originating: supply sufficient but high-leverage targets exist ──

    public function test_sufficient_supply_with_high_leverage_targets_continues_originating(): void
    {
        $result = $this->eval([
            'supply_sufficient' => true,
            'high_leverage_unqueued_targets' => 3,
        ]);
        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_CONTINUE_ORIGINATING, $result['verdict']);
        $this->assertFalse($result['can_stop']);
        $this->assertSame('continue_originating_high_leverage_targets', $result['next_required_action']);
    }

    public function test_sufficient_supply_without_targets_does_not_continue_originating(): void
    {
        $result = $this->eval([
            'supply_sufficient' => true,
            'high_leverage_unqueued_targets' => 0,
        ]);
        $this->assertNotSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_CONTINUE_ORIGINATING, $result['verdict']);
    }

    public function test_insufficient_supply_with_targets_does_not_continue_originating(): void
    {
        $result = $this->eval([
            'supply_sufficient' => false,
            'high_leverage_unqueued_targets' => 2,
        ]);
        $this->assertNotSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_CONTINUE_ORIGINATING, $result['verdict']);
    }

    public function test_continue_originating_beats_premature_stop(): void
    {
        $result = $this->eval([
            'supply_sufficient' => true,
            'high_leverage_unqueued_targets' => 1,
        ]);
        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_CONTINUE_ORIGINATING, $result['verdict']);
        $this->assertNotSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_PREMATURE_STOP, $result['verdict']);
    }

    // ── AC2: quota met alone without value_score, anti_goodhart, or outcome evidence does not stop ──

    public function test_quota_met_alone_does_not_stop_and_requires_continuation(): void
    {
        $result = $this->eval([
            'quota_count' => 10,
            'quota_target' => 10,
            'value_score' => null,
            'anti_goodhart_pass' => false,
            'outcome_learning_evidence' => [],
        ]);

        $this->assertFalse($result['can_stop']);
        $this->assertTrue($result['continuation_required']);
    }

    // ── AC3: quota met with all evidence allows honest stop ──

    public function test_quota_met_with_all_evidence_allows_honest_stop(): void
    {
        $result = $this->eval([
            'quota_count' => 10,
            'quota_target' => 10,
            'value_score' => 0.8,
            'anti_goodhart_pass' => true,
            'outcome_learning_evidence' => ['lesson_1'],
        ]);

        $this->assertTrue($result['can_stop']);
        $this->assertFalse($result['continuation_required']);
    }

    // ── AC4: missing evidence list names the exact missing proof dimensions ──

    public function test_missing_evidence_list_names_exact_missing_dimensions(): void
    {
        $result = $this->eval([
            'quota_count' => 10,
            'quota_target' => 10,
            'value_score' => null,
            'anti_goodhart_pass' => false,
            'outcome_learning_evidence' => [],
        ]);

        $this->assertNotEmpty($result['missing_evidence']);
        $this->assertContains('value_score', $result['missing_evidence']);
        $this->assertContains('anti_goodhart_pass', $result['missing_evidence']);
        $this->assertContains('outcome_learning_evidence', $result['missing_evidence']);
    }
}
