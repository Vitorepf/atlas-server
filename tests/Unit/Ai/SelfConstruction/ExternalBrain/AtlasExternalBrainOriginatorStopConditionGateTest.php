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

        foreach (['schema', 'verdict', 'stop_reason', 'blocking_reasons', 'evidence_cited'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::SCHEMA, $result['schema']);
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
}
