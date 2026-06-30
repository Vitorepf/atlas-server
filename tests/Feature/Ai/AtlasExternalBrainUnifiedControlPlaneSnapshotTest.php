<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainUnifiedControlPlaneSnapshot;
use Tests\TestCase;

final class AtlasExternalBrainUnifiedControlPlaneSnapshotTest extends TestCase
{
    private AtlasExternalBrainUnifiedControlPlaneSnapshot $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasExternalBrainUnifiedControlPlaneSnapshot;
    }

    private function compose(array $input): array
    {
        return $this->service->compose($input);
    }

    private function healthy(): array
    {
        return [
            'give_back_rate'      => 0.05,
            'malformed_rate'      => 0.05,
            'worker_success_rate' => 0.90,
        ];
    }

    // ── AC1: red stop_go_verdict wins over task creation ─────────────────────

    public function test_high_give_back_rate_triggers_stop_verdict(): void
    {
        $r = $this->compose(array_merge($this->healthy(), [
            'give_back_rate'      => 0.35,  // > 0.30 red floor
            'maturity_gap_count'  => 5,     // would normally create tasks
        ]));

        $this->assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::STATUS_RED,   $r['status']);
        $this->assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::VERDICT_STOP, $r['stop_go_verdict']);
    }

    public function test_high_malformed_rate_triggers_red_status(): void
    {
        $r = $this->compose(array_merge($this->healthy(), [
            'malformed_rate' => 0.35,  // > 0.30 red floor
        ]));

        $this->assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::STATUS_RED,   $r['status']);
        $this->assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::VERDICT_STOP, $r['stop_go_verdict']);
    }

    public function test_low_worker_success_rate_triggers_red_status(): void
    {
        $r = $this->compose(array_merge($this->healthy(), [
            'worker_success_rate' => 0.40,  // < 0.50 red ceiling
        ]));

        $this->assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::STATUS_RED,   $r['status']);
        $this->assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::VERDICT_STOP, $r['stop_go_verdict']);
    }

    public function test_red_verdict_overrides_task_creation_decision(): void
    {
        // Even with maturity gaps, stop verdict forbids "create_more_tasks" as priority.
        $r = $this->compose(array_merge($this->healthy(), [
            'give_back_rate'     => 0.35,
            'maturity_gap_count' => 10,
        ]));

        $this->assertSame('stop', $r['stop_go_verdict']);
        // next_decision can still say consolidate/monitor (governed by queue/simplification, not gap),
        // but the stop_go_verdict is the authoritative gate — never "go" when red.
        $this->assertNotSame('go', $r['stop_go_verdict']);
    }

    public function test_rollback_candidate_amplifier_is_also_red(): void
    {
        $r = $this->compose(array_merge($this->healthy(), [
            'model_amplifier_status' => 'rollback_candidate',
        ]));

        $this->assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::STATUS_RED, $r['status']);
        $this->assertSame('stop', $r['stop_go_verdict']);
    }

    // ── AC2: simplification_pressure=high → consolidate before create ─────────

    public function test_high_simplification_pressure_chooses_consolidate_over_create(): void
    {
        $r = $this->compose(array_merge($this->healthy(), [
            'simplification_pressure' => 'high',
            'maturity_gap_count'      => 5,  // would normally pick create_more_tasks
        ]));

        $this->assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::DECISION_CONSOLIDATE, $r['next_decision']);
    }

    public function test_high_queue_pressure_also_consolidates_before_create(): void
    {
        $r = $this->compose(array_merge($this->healthy(), [
            'queue_pressure'     => 'high',
            'maturity_gap_count' => 3,
        ]));

        $this->assertSame('consolidate_existing_tasks', $r['next_decision']);
    }

    public function test_create_more_tasks_when_no_pressure_but_has_gaps(): void
    {
        $r = $this->compose(array_merge($this->healthy(), [
            'maturity_gap_count' => 3,
        ]));

        $this->assertSame('create_more_tasks', $r['next_decision']);
    }

    public function test_monitor_when_no_pressure_and_no_gaps(): void
    {
        $r = $this->compose($this->healthy());

        $this->assertSame('monitor', $r['next_decision']);
        $this->assertSame('go',      $r['stop_go_verdict']);
    }

    // ── AC3: ranked_focus priority order ──────────────────────────────────────

    public function test_self_heal_is_top_priority_when_give_back_exceeds_red_floor(): void
    {
        $r = $this->compose(array_merge($this->healthy(), [
            'give_back_rate'          => 0.35,
            'simplification_pressure' => 'high',  // would be simplification without self_heal
        ]));

        $this->assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::FOCUS_SELF_HEAL, $r['ranked_focus']);
    }

    public function test_self_heal_when_malformed_rate_high(): void
    {
        $r = $this->compose(array_merge($this->healthy(), ['malformed_rate' => 0.35]));
        $this->assertSame('self_heal', $r['ranked_focus']);
    }

    public function test_self_heal_when_worker_success_rate_below_red_ceiling(): void
    {
        $r = $this->compose(array_merge($this->healthy(), ['worker_success_rate' => 0.40]));
        $this->assertSame('self_heal', $r['ranked_focus']);
    }

    public function test_simplification_is_second_priority_when_self_heal_not_triggered(): void
    {
        $r = $this->compose(array_merge($this->healthy(), [
            'simplification_pressure' => 'high',
            'model_amplifier_status'  => 'watch',  // would be model_amplifier without simplification
        ]));

        $this->assertSame('simplification', $r['ranked_focus']);
    }

    public function test_model_amplifier_is_third_priority(): void
    {
        $r = $this->compose(array_merge($this->healthy(), [
            'model_amplifier_status' => 'watch',
            'task_value_degrading'   => true,  // would be outcome_learning without amplifier watch
        ]));

        $this->assertSame('model_amplifier', $r['ranked_focus']);
    }

    public function test_outcome_learning_is_fourth_priority(): void
    {
        $r = $this->compose(array_merge($this->healthy(), [
            'task_value_degrading' => true,
            'maturity_gap_count'   => 3,  // would be capability_gap without degrading
        ]));

        $this->assertSame('outcome_learning', $r['ranked_focus']);
    }

    public function test_capability_gap_is_fifth_priority(): void
    {
        $r = $this->compose(array_merge($this->healthy(), [
            'maturity_gap_count' => 3,
        ]));

        $this->assertSame('capability_gap', $r['ranked_focus']);
    }

    public function test_task_fabric_is_default_when_system_healthy(): void
    {
        $r = $this->compose($this->healthy());
        $this->assertSame('task_fabric', $r['ranked_focus']);
    }

    // ── AC4: pure and deterministic ───────────────────────────────────────────

    public function test_compose_is_deterministic(): void
    {
        $input = array_merge($this->healthy(), [
            'simplification_pressure' => 'high',
            'maturity_gap_count'      => 2,
            'task_value_degrading'    => true,
        ]);

        $a = $this->compose($input);
        $b = $this->compose($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_refuses_green_when_task_value_degrading(): void
    {
        // AC4: refuses green when task_value_degrading, even if rates look ok.
        $r = $this->compose(array_merge($this->healthy(), [
            'task_value_degrading' => true,
        ]));

        $this->assertNotSame('green', $r['status']);
        $this->assertNotSame('go',    $r['stop_go_verdict']);
    }

    public function test_refuses_green_when_muscle_outcomes_degrading(): void
    {
        $r = $this->compose(array_merge($this->healthy(), [
            'muscle_outcomes_degrading' => true,
        ]));

        $this->assertNotSame('green', $r['status']);
        $this->assertNotSame('go',    $r['stop_go_verdict']);
    }
}
