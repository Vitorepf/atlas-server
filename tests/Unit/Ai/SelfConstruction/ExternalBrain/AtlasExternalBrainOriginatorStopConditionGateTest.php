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

    // ── Schema / required keys ────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->gate->evaluate([]);

        foreach (['schema', 'verdict', 'stop_reason', 'blocking_reasons', 'evidence_cited'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::SCHEMA, $result['schema']);
    }

    // ── AC2: honest_stop — quality_target_reached ─────────────────────────────

    public function test_quality_target_reached_with_evidence_is_honest_stop(): void
    {
        $result = $this->gate->evaluate([
            'quality_target_reached'   => true,
            'quality_target_evidence'  => ['tests_green', 'score_9.5'],
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_HONEST_STOP, $result['verdict']);
        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::REASON_QUALITY_TARGET, $result['stop_reason']);
        $this->assertNotEmpty($result['evidence_cited']);
    }

    public function test_quality_target_reached_without_evidence_is_not_honest_stop(): void
    {
        $result = $this->gate->evaluate([
            'quality_target_reached'  => true,
            'quality_target_evidence' => [], // no evidence
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_PREMATURE_STOP, $result['verdict']);
    }

    // ── AC2: honest_stop — exhausted_with_evidence ────────────────────────────

    public function test_all_escalation_modes_tried_with_evidence_is_honest_stop(): void
    {
        $result = $this->gate->evaluate([
            'all_escalation_modes_tried' => true,
            'escalation_evidence'        => ['mode_a_tried', 'mode_b_tried'],
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_HONEST_STOP, $result['verdict']);
        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::REASON_EXHAUSTED, $result['stop_reason']);
    }

    public function test_escalation_tried_without_evidence_is_premature(): void
    {
        $result = $this->gate->evaluate([
            'all_escalation_modes_tried' => true,
            'escalation_evidence'        => [],
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_PREMATURE_STOP, $result['verdict']);
    }

    // ── AC2: honest_stop — queue_pressure_deferral ────────────────────────────

    public function test_queue_pressure_high_non_urgent_is_honest_stop(): void
    {
        $result = $this->gate->evaluate([
            'queue_pressure_high' => true,
            'task_urgency'        => 'non_urgent',
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_HONEST_STOP, $result['verdict']);
        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::REASON_QUEUE_PRESSURE, $result['stop_reason']);
    }

    public function test_queue_pressure_high_but_urgent_is_not_honest_stop(): void
    {
        $result = $this->gate->evaluate([
            'queue_pressure_high' => true,
            'task_urgency'        => 'urgent',
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_PREMATURE_STOP, $result['verdict']);
    }

    // ── AC2: honest_stop — surface_saturated ─────────────────────────────────

    public function test_no_high_leverage_surface_with_dedup_confirmed_is_honest_stop(): void
    {
        $result = $this->gate->evaluate([
            'no_high_leverage_surface_remaining' => true,
            'deduplication_confirmed'             => true,
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_HONEST_STOP, $result['verdict']);
        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::REASON_SURFACE_SATURATED, $result['stop_reason']);
    }

    public function test_no_surface_without_dedup_is_premature(): void
    {
        $result = $this->gate->evaluate([
            'no_high_leverage_surface_remaining' => true,
            'deduplication_confirmed'             => false,
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_PREMATURE_STOP, $result['verdict']);
    }

    // ── AC1: premature_stop when escalation modes remain ─────────────────────

    public function test_remaining_escalation_modes_triggers_premature_stop(): void
    {
        $result = $this->gate->evaluate([
            'remaining_escalation_modes' => 2,
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_PREMATURE_STOP, $result['verdict']);
        $blocking = implode(' ', $result['blocking_reasons']);
        $this->assertStringContainsString('remaining_escalation_modes', $blocking);
    }

    // ── AC1: premature_stop when open surfaces remain ────────────────────────

    public function test_open_surfaces_remaining_triggers_premature_stop(): void
    {
        $result = $this->gate->evaluate([
            'open_surfaces_remaining' => 3,
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_PREMATURE_STOP, $result['verdict']);
        $blocking = implode(' ', $result['blocking_reasons']);
        $this->assertStringContainsString('open_surfaces_remaining', $blocking);
    }

    // ── First pass only is premature ──────────────────────────────────────────

    public function test_first_pass_only_triggers_premature_stop(): void
    {
        $result = $this->gate->evaluate([
            'first_pass_only' => true,
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::VERDICT_PREMATURE_STOP, $result['verdict']);
        $blocking = implode(' ', $result['blocking_reasons']);
        $this->assertStringContainsString('first_pass_only', $blocking);
    }

    // ── honest_stop clears blocking_reasons ───────────────────────────────────

    public function test_honest_stop_has_no_blocking_reasons(): void
    {
        $result = $this->gate->evaluate([
            'quality_target_reached'  => true,
            'quality_target_evidence' => ['score_achieved'],
        ]);

        $this->assertSame([], $result['blocking_reasons']);
    }

    // ── premature_stop has null stop_reason ──────────────────────────────────

    public function test_premature_stop_has_null_stop_reason(): void
    {
        $result = $this->gate->evaluate(['first_pass_only' => true]);

        $this->assertNull($result['stop_reason']);
    }

    // ── honest_stop priority: quality_target beats exhausted ─────────────────

    public function test_quality_target_takes_priority_over_exhausted(): void
    {
        $result = $this->gate->evaluate([
            'quality_target_reached'     => true,
            'quality_target_evidence'    => ['score_ok'],
            'all_escalation_modes_tried' => true,
            'escalation_evidence'        => ['tried_a', 'tried_b'],
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorStopConditionGate::REASON_QUALITY_TARGET, $result['stop_reason']);
    }
}
