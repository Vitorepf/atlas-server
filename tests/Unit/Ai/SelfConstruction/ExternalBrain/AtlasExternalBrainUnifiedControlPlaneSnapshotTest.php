<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainUnifiedControlPlaneSnapshot;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainUnifiedControlPlaneSnapshotTest extends TestCase
{
    private AtlasExternalBrainUnifiedControlPlaneSnapshot $snapshot;

    protected function setUp(): void
    {
        $this->snapshot = new AtlasExternalBrainUnifiedControlPlaneSnapshot;
    }

    private function healthy(array $overrides = []): array
    {
        return array_merge([
            'queue_pressure'           => 'low',
            'simplification_pressure'  => 'low',
            'model_amplifier_status'   => 'healthy',
            'maturity_gap_count'       => 0,
            'worker_success_rate'      => 0.85,
            'give_back_rate'           => 0.05,
            'task_value_degrading'     => false,
            'muscle_outcomes_degrading' => false,
        ], $overrides);
    }

    // ── Schema / required keys ────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->snapshot->compose($this->healthy());

        foreach (['schema', 'status', 'top_risks', 'next_decision', 'recommended_batch_theme', 'stop_go_verdict'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::SCHEMA, $result['schema']);
    }

    // ── AC2: green / go path ──────────────────────────────────────────────────

    public function test_healthy_input_returns_green_go(): void
    {
        $result = $this->snapshot->compose($this->healthy());

        $this->assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::STATUS_GREEN, $result['status']);
        $this->assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::VERDICT_GO, $result['stop_go_verdict']);
        $this->assertSame([], $result['top_risks']);
    }

    // ── AC2: red / stop — give_back_rate too high ─────────────────────────────

    public function test_high_give_back_rate_returns_red_stop(): void
    {
        $result = $this->snapshot->compose($this->healthy(['give_back_rate' => 0.35]));

        $this->assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::STATUS_RED, $result['status']);
        $this->assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::VERDICT_STOP, $result['stop_go_verdict']);
        $this->assertStringContainsString('give_back_rate', implode(' ', $result['top_risks']));
    }

    // ── AC2: red — worker_success_rate too low ────────────────────────────────

    public function test_low_worker_success_rate_returns_red(): void
    {
        $result = $this->snapshot->compose($this->healthy(['worker_success_rate' => 0.40]));

        $this->assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::STATUS_RED, $result['status']);
        $this->assertStringContainsString('worker_success_rate', implode(' ', $result['top_risks']));
    }

    // ── AC2: red — amplifier rollback_candidate ───────────────────────────────

    public function test_rollback_candidate_amplifier_returns_red(): void
    {
        $result = $this->snapshot->compose($this->healthy(['model_amplifier_status' => 'rollback_candidate']));

        $this->assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::STATUS_RED, $result['status']);
        $this->assertContains('model_amplifier_status:rollback_candidate', $result['top_risks']);
    }

    // ── AC2: yellow / watch paths ─────────────────────────────────────────────

    public function test_high_queue_pressure_returns_yellow(): void
    {
        $result = $this->snapshot->compose($this->healthy(['queue_pressure' => 'high']));

        $this->assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::STATUS_YELLOW, $result['status']);
        $this->assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::VERDICT_WATCH, $result['stop_go_verdict']);
    }

    public function test_amplifier_watch_returns_yellow(): void
    {
        $result = $this->snapshot->compose($this->healthy(['model_amplifier_status' => 'watch']));

        $this->assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::STATUS_YELLOW, $result['status']);
        $this->assertContains('model_amplifier_status:watch', $result['top_risks']);
    }

    public function test_moderate_give_back_returns_yellow(): void
    {
        $result = $this->snapshot->compose($this->healthy(['give_back_rate' => 0.20]));

        $this->assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::STATUS_YELLOW, $result['status']);
    }

    // ── AC3: refuses green when value or muscle is degrading ──────────────────

    public function test_task_value_degrading_blocks_green(): void
    {
        $result = $this->snapshot->compose($this->healthy(['task_value_degrading' => true]));

        $this->assertNotSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::STATUS_GREEN, $result['status']);
        $this->assertContains('task_value_degrading:true', $result['top_risks']);
    }

    public function test_muscle_outcomes_degrading_blocks_green(): void
    {
        $result = $this->snapshot->compose($this->healthy(['muscle_outcomes_degrading' => true]));

        $this->assertNotSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::STATUS_GREEN, $result['status']);
        $this->assertContains('muscle_outcomes_degrading:true', $result['top_risks']);
    }

    // ── AC2: next_decision — create_more_tasks ────────────────────────────────

    public function test_maturity_gaps_trigger_create_more_tasks(): void
    {
        $result = $this->snapshot->compose($this->healthy(['maturity_gap_count' => 5]));

        $this->assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::DECISION_CREATE, $result['next_decision']);
    }

    // ── AC2: next_decision — consolidate_existing_tasks ───────────────────────

    public function test_high_queue_pressure_triggers_consolidate(): void
    {
        $result = $this->snapshot->compose($this->healthy(['queue_pressure' => 'high']));

        $this->assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::DECISION_CONSOLIDATE, $result['next_decision']);
    }

    public function test_high_simplification_pressure_triggers_consolidate(): void
    {
        $result = $this->snapshot->compose($this->healthy(['simplification_pressure' => 'high']));

        $this->assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::DECISION_CONSOLIDATE, $result['next_decision']);
    }

    // ── AC2: next_decision — consolidate takes priority over create ───────────

    public function test_consolidate_wins_over_maturity_gaps(): void
    {
        $result = $this->snapshot->compose($this->healthy([
            'queue_pressure'    => 'high',
            'maturity_gap_count' => 10,
        ]));

        $this->assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::DECISION_CONSOLIDATE, $result['next_decision']);
    }

    // ── AC2: monitor when nothing significant ────────────────────────────────

    public function test_stable_zero_gaps_returns_monitor(): void
    {
        $result = $this->snapshot->compose($this->healthy(['maturity_gap_count' => 0]));

        $this->assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::DECISION_MONITOR, $result['next_decision']);
    }

    // ── recommended_batch_theme is non-empty ─────────────────────────────────

    public function test_batch_theme_is_always_non_empty(): void
    {
        foreach (['low', 'medium', 'high'] as $pressure) {
            $result = $this->snapshot->compose($this->healthy(['queue_pressure' => $pressure]));
            $this->assertNotEmpty($result['recommended_batch_theme']);
        }
    }

    // ── Red status recommends stabilization ──────────────────────────────────

    public function test_red_batch_theme_mentions_stabilize(): void
    {
        $result = $this->snapshot->compose($this->healthy(['give_back_rate' => 0.50]));

        $this->assertStringContainsString('stabilize', $result['recommended_batch_theme']);
    }

    // ── AC1: ranked_focus always present ─────────────────────────────────────

    public function test_ranked_focus_always_present_in_output(): void
    {
        $r = $this->snapshot->compose($this->healthy());

        $this->assertArrayHasKey('ranked_focus', $r);
    }

    public function test_ranked_focus_task_fabric_when_healthy(): void
    {
        $r = $this->snapshot->compose($this->healthy());

        $this->assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::FOCUS_TASK_FABRIC, $r['ranked_focus']);
    }

    public function test_ranked_focus_capability_gap_when_maturity_gaps(): void
    {
        $r = $this->snapshot->compose($this->healthy(['maturity_gap_count' => 3]));

        $this->assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::FOCUS_CAPABILITY_GAP, $r['ranked_focus']);
    }

    public function test_ranked_focus_outcome_learning_when_value_degrading(): void
    {
        $r = $this->snapshot->compose($this->healthy(['task_value_degrading' => true]));

        $this->assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::FOCUS_OUTCOME_LEARNING, $r['ranked_focus']);
    }

    public function test_ranked_focus_model_amplifier_when_watch(): void
    {
        $r = $this->snapshot->compose($this->healthy(['model_amplifier_status' => 'watch']));

        $this->assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::FOCUS_MODEL_AMPLIFIER, $r['ranked_focus']);
    }

    public function test_ranked_focus_simplification_when_pressure_high(): void
    {
        $r = $this->snapshot->compose($this->healthy(['simplification_pressure' => 'high']));

        $this->assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::FOCUS_SIMPLIFICATION, $r['ranked_focus']);
    }

    // ── AC2: self_heal precedes create when rates degraded ───────────────────

    public function test_ranked_focus_self_heal_when_give_back_rate_high(): void
    {
        $r = $this->snapshot->compose($this->healthy(['give_back_rate' => 0.35, 'maturity_gap_count' => 5]));

        $this->assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::FOCUS_SELF_HEAL, $r['ranked_focus']);
    }

    public function test_ranked_focus_self_heal_when_malformed_rate_high(): void
    {
        $r = $this->snapshot->compose($this->healthy(['malformed_rate' => 0.35, 'maturity_gap_count' => 5]));

        $this->assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::FOCUS_SELF_HEAL, $r['ranked_focus']);
    }

    public function test_ranked_focus_self_heal_when_success_rate_very_low(): void
    {
        $r = $this->snapshot->compose($this->healthy(['worker_success_rate' => 0.40, 'maturity_gap_count' => 5]));

        $this->assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::FOCUS_SELF_HEAL, $r['ranked_focus']);
    }

    public function test_ranked_focus_self_heal_over_simplification_when_both(): void
    {
        $r = $this->snapshot->compose($this->healthy([
            'give_back_rate'          => 0.35,
            'simplification_pressure' => 'high',
        ]));

        $this->assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::FOCUS_SELF_HEAL, $r['ranked_focus']);
    }
}
