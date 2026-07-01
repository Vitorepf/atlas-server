<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Health;

use App\Services\Ai\SelfConstruction\Maestro\Health\AtlasMaestroBlockedQueueUnblockPlanner;
use Tests\TestCase;

final class AtlasMaestroBlockedQueueUnblockPlannerTest extends TestCase
{
    private function svc(): AtlasMaestroBlockedQueueUnblockPlanner
    {
        return new AtlasMaestroBlockedQueueUnblockPlanner;
    }

    private function pkt(string $id, array $overrides = []): array
    {
        return array_merge(['task_packet_id' => $id, 'downstream_unlock_count' => 0], $overrides);
    }

    // ── root-cause → action mapping ───────────────────────────────────────────

    public function test_operator_only_flag_maps_to_operator_only_action(): void
    {
        $r = $this->svc()->plan([$this->pkt('p1', ['operator_only' => true])]);

        $this->assertSame(AtlasMaestroBlockedQueueUnblockPlanner::ACTION_OPERATOR_ONLY, $r['entries'][0]['action']);
        $this->assertSame('operator_only_or_forbidden_self_target', $r['entries'][0]['root_cause']);
    }

    public function test_forbidden_self_target_maps_to_operator_only_action(): void
    {
        $r = $this->svc()->plan([$this->pkt('p1', ['forbidden_self_target' => true])]);

        $this->assertSame(AtlasMaestroBlockedQueueUnblockPlanner::ACTION_OPERATOR_ONLY, $r['entries'][0]['action']);
    }

    public function test_schema_errors_maps_to_create_migration_task(): void
    {
        $r = $this->svc()->plan([$this->pkt('p1', ['schema_errors' => ['missing column X']])]);

        $this->assertSame(AtlasMaestroBlockedQueueUnblockPlanner::ACTION_CREATE_MIGRATION_TASK, $r['entries'][0]['action']);
        $this->assertSame('schema_errors', $r['entries'][0]['root_cause']);
    }

    public function test_deficiencies_maps_to_rescope(): void
    {
        $r = $this->svc()->plan([$this->pkt('p1', ['deficiencies' => ['contradictory_acceptance']])]);

        $this->assertSame(AtlasMaestroBlockedQueueUnblockPlanner::ACTION_RESCOPE, $r['entries'][0]['action']);
        $this->assertSame('contradictory_or_missing_spec', $r['entries'][0]['root_cause']);
    }

    public function test_blocking_deficiencies_also_maps_to_rescope(): void
    {
        $r = $this->svc()->plan([$this->pkt('p1', ['blocking_deficiencies' => ['missing_acceptance_criteria']])]);

        $this->assertSame(AtlasMaestroBlockedQueueUnblockPlanner::ACTION_RESCOPE, $r['entries'][0]['action']);
    }

    public function test_stale_dependency_maps_to_fix_dependency(): void
    {
        $r = $this->svc()->plan([$this->pkt('p1', ['dependency_status' => 'stale'])]);

        $this->assertSame(AtlasMaestroBlockedQueueUnblockPlanner::ACTION_FIX_DEPENDENCY, $r['entries'][0]['action']);
        $this->assertSame('stale_or_missing_dependency', $r['entries'][0]['root_cause']);
    }

    public function test_missing_dependency_maps_to_fix_dependency(): void
    {
        $r = $this->svc()->plan([$this->pkt('p1', ['dependency_status' => 'missing'])]);

        $this->assertSame(AtlasMaestroBlockedQueueUnblockPlanner::ACTION_FIX_DEPENDENCY, $r['entries'][0]['action']);
    }

    public function test_high_give_back_count_maps_to_retire(): void
    {
        $r = $this->svc()->plan([$this->pkt('p1', ['give_back_count' => 8])]);

        $this->assertSame(AtlasMaestroBlockedQueueUnblockPlanner::ACTION_RETIRE, $r['entries'][0]['action']);
        $this->assertSame('repeated_give_back_or_already_done', $r['entries'][0]['root_cause']);
    }

    public function test_already_done_maps_to_retire(): void
    {
        $r = $this->svc()->plan([$this->pkt('p1', ['already_done' => true])]);

        $this->assertSame(AtlasMaestroBlockedQueueUnblockPlanner::ACTION_RETIRE, $r['entries'][0]['action']);
    }

    public function test_no_signals_maps_to_leave_blocked(): void
    {
        $r = $this->svc()->plan([$this->pkt('p1')]);

        $this->assertSame(AtlasMaestroBlockedQueueUnblockPlanner::ACTION_LEAVE_BLOCKED, $r['entries'][0]['action']);
        $this->assertSame('unresolvable_blocked', $r['entries'][0]['root_cause']);
    }

    // ── priority ordering ─────────────────────────────────────────────────────

    public function test_entries_sorted_by_downstream_unlock_count_descending(): void
    {
        $packets = [
            $this->pkt('low', ['downstream_unlock_count' => 1, 'already_done' => true]),
            $this->pkt('high', ['downstream_unlock_count' => 10, 'deficiencies' => ['bad spec']]),
            $this->pkt('mid', ['downstream_unlock_count' => 5, 'schema_errors' => ['missing col']]),
        ];

        $r = $this->svc()->plan($packets);

        $this->assertSame('high', $r['entries'][0]['task_packet_id']);
        $this->assertSame('mid', $r['entries'][1]['task_packet_id']);
        $this->assertSame('low', $r['entries'][2]['task_packet_id']);
    }

    // ── implementation_work_enqueued ──────────────────────────────────────────

    public function test_operator_only_and_forbidden_self_target_classified_operator_only_lane_with_refusal_reason(): void
    {
        foreach ([['operator_only' => true], ['forbidden_self_target' => true]] as $overrides) {
            $r = $this->svc()->plan([$this->pkt('p1', $overrides)]);
            $entry = $r['entries'][0];

            $this->assertSame(AtlasMaestroBlockedQueueUnblockPlanner::LANE_OPERATOR_ONLY, $entry['lane']);
            $this->assertFalse($entry['implementation_work_enqueued']);
            $this->assertNotEmpty($entry['refusal_reason']);
        }
    }

    public function test_schema_errors_and_deficiencies_classified_respec_only_not_implementation_work_lane(): void
    {
        $schema = $this->svc()->plan([$this->pkt('p1', ['schema_errors' => ['e1']])]);
        $deficiency = $this->svc()->plan([$this->pkt('p2', ['deficiencies' => ['d1']])]);

        $this->assertSame(AtlasMaestroBlockedQueueUnblockPlanner::LANE_RESPEC_ONLY, $schema['entries'][0]['lane']);
        $this->assertSame(AtlasMaestroBlockedQueueUnblockPlanner::LANE_RESPEC_ONLY, $deficiency['entries'][0]['lane']);
    }

    public function test_dependency_repair_with_downstream_unlock_count_is_safe_impl_work_and_sorted_deterministically(): void
    {
        $r = $this->svc()->plan([
            $this->pkt('low', ['dependency_status' => 'stale', 'downstream_unlock_count' => 1]),
            $this->pkt('high', ['dependency_status' => 'stale', 'downstream_unlock_count' => 5]),
        ]);

        $this->assertSame(AtlasMaestroBlockedQueueUnblockPlanner::LANE_SAFE_IMPL_WORK, $r['entries'][0]['lane']);
        $this->assertSame('high', $r['entries'][0]['task_packet_id']);
        $this->assertSame('low', $r['entries'][1]['task_packet_id']);
    }

    public function test_operator_only_refuses_implementation_work(): void
    {
        $r = $this->svc()->plan([$this->pkt('p1', ['operator_only' => true])]);

        $this->assertFalse($r['entries'][0]['implementation_work_enqueued']);
        $this->assertContains('p1', $r['implementation_work_refused_ids']);
    }

    public function test_leave_blocked_refuses_implementation_work(): void
    {
        $r = $this->svc()->plan([$this->pkt('p1')]);

        $this->assertFalse($r['entries'][0]['implementation_work_enqueued']);
        $this->assertContains('p1', $r['implementation_work_refused_ids']);
    }

    public function test_rescope_enqueues_implementation_work(): void
    {
        $r = $this->svc()->plan([$this->pkt('p1', ['deficiencies' => ['bad spec']])]);

        $this->assertTrue($r['entries'][0]['implementation_work_enqueued']);
        $this->assertNotContains('p1', $r['implementation_work_refused_ids']);
    }

    // ── grouping and envelope ─────────────────────────────────────────────────

    public function test_by_action_groups_ids_correctly(): void
    {
        $packets = [
            $this->pkt('a', ['operator_only' => true]),
            $this->pkt('b', ['operator_only' => true]),
            $this->pkt('c', ['deficiencies' => ['bad']]),
        ];

        $r = $this->svc()->plan($packets);

        $this->assertCount(2, $r['by_action'][AtlasMaestroBlockedQueueUnblockPlanner::ACTION_OPERATOR_ONLY]);
        $this->assertCount(1, $r['by_action'][AtlasMaestroBlockedQueueUnblockPlanner::ACTION_RESCOPE]);
        $this->assertSame(3, $r['total_blocked']);
        $this->assertSame(AtlasMaestroBlockedQueueUnblockPlanner::SCHEMA, $r['schema_version']);
    }

    public function test_empty_input_returns_empty_plan(): void
    {
        $r = $this->svc()->plan([]);

        $this->assertSame(0, $r['total_blocked']);
        $this->assertSame([], $r['entries']);
        $this->assertSame([], $r['by_action']);
        $this->assertSame([], $r['implementation_work_refused_ids']);
    }

    // ── AC: claimable repair paths / retire candidates / passive-wait refusal ──

    public function test_converts_dependency_forbidden_target_and_malformed_acceptance_into_actions(): void
    {
        $r = $this->svc()->plan([
            ['task_packet_id' => 'a', 'dependency_status' => 'stale'],
            ['task_packet_id' => 'b', 'forbidden_self_target' => true],
            ['task_packet_id' => 'c', 'deficiencies' => ['contradictory_acceptance']],
            ['task_packet_id' => 'd', 'give_back_count' => 10],
        ]);

        $byId = array_column($r['entries'], null, 'task_packet_id');
        $this->assertSame(AtlasMaestroBlockedQueueUnblockPlanner::ACTION_FIX_DEPENDENCY, $byId['a']['action']);
        $this->assertSame(AtlasMaestroBlockedQueueUnblockPlanner::ACTION_OPERATOR_ONLY, $byId['b']['action']);
        $this->assertSame(AtlasMaestroBlockedQueueUnblockPlanner::ACTION_RESCOPE, $byId['c']['action']);
        $this->assertSame(AtlasMaestroBlockedQueueUnblockPlanner::ACTION_RETIRE, $byId['d']['action']);
    }

    public function test_refuses_passive_wait_when_a_claimable_repair_path_exists(): void
    {
        $r = $this->svc()->plan([
            ['task_packet_id' => 'repairable', 'dependency_status' => 'missing'],
            ['task_packet_id' => 'genuinely_stuck'],
        ]);

        $byId = array_column($r['entries'], null, 'task_packet_id');
        $this->assertNotNull($byId['repairable']['passive_wait_blocked_reason']);
        $this->assertNull($byId['genuinely_stuck']['passive_wait_blocked_reason']);
    }

    public function test_output_includes_unblock_plan_claimable_repair_specs_retire_candidates_and_passive_wait_reason(): void
    {
        $r = $this->svc()->plan([
            ['task_packet_id' => 'a', 'dependency_status' => 'stale'],
            ['task_packet_id' => 'b', 'give_back_count' => 10],
            ['task_packet_id' => 'c'],
        ]);

        foreach (['unblock_plan', 'claimable_repair_specs', 'retire_candidates'] as $key) {
            $this->assertArrayHasKey($key, $r, "Missing key: {$key}");
        }
        $this->assertContains('b', $r['retire_candidates']);
        $repairIds = array_column($r['claimable_repair_specs'], 'task_packet_id');
        $this->assertContains('a', $repairIds);
        $this->assertArrayHasKey('passive_wait_blocked_reason', $r['entries'][0]);
    }

    // ── AC1: classification into the 5 canonical unblock classes ─────────────

    public function test_deficiencies_classify_as_unblock_class_respec(): void
    {
        $r = $this->svc()->plan([$this->pkt('p1', ['deficiencies' => ['bad spec']])]);
        $this->assertSame(AtlasMaestroBlockedQueueUnblockPlanner::UNBLOCK_CLASS_RESPEC, $r['entries'][0]['unblock_class']);
    }

    public function test_schema_errors_classify_as_unblock_class_respec(): void
    {
        $r = $this->svc()->plan([$this->pkt('p1', ['schema_errors' => ['missing col']])]);
        $this->assertSame(AtlasMaestroBlockedQueueUnblockPlanner::UNBLOCK_CLASS_RESPEC, $r['entries'][0]['unblock_class']);
    }

    public function test_retire_action_classifies_as_unblock_class_cancel_duplicate(): void
    {
        $r = $this->svc()->plan([$this->pkt('p1', ['already_done' => true])]);
        $this->assertSame(AtlasMaestroBlockedQueueUnblockPlanner::UNBLOCK_CLASS_CANCEL_DUPLICATE, $r['entries'][0]['unblock_class']);
    }

    public function test_operator_only_classifies_as_unblock_class_operator_only(): void
    {
        $r = $this->svc()->plan([$this->pkt('p1', ['operator_only' => true])]);
        $this->assertSame(AtlasMaestroBlockedQueueUnblockPlanner::UNBLOCK_CLASS_OPERATOR_ONLY, $r['entries'][0]['unblock_class']);
    }

    public function test_fix_dependency_classifies_as_unblock_class_dependency_unblock(): void
    {
        $r = $this->svc()->plan([$this->pkt('p1', ['dependency_status' => 'stale'])]);
        $this->assertSame(AtlasMaestroBlockedQueueUnblockPlanner::UNBLOCK_CLASS_DEPENDENCY_UNBLOCK, $r['entries'][0]['unblock_class']);
    }

    public function test_leave_blocked_classifies_as_unblock_class_keep_quarantined(): void
    {
        $r = $this->svc()->plan([$this->pkt('p1')]);
        $this->assertSame(AtlasMaestroBlockedQueueUnblockPlanner::UNBLOCK_CLASS_KEEP_QUARANTINED, $r['entries'][0]['unblock_class']);
    }

    // ── AC2: ordering by recovered claimable value net of risk, not raw fan-out ──

    public function test_high_value_low_risk_packet_outranks_high_fanout_high_risk_packet(): void
    {
        $r = $this->svc()->plan([
            $this->pkt('high-fanout-risky', [
                'downstream_unlock_count' => 10,
                'risk_level' => 'high',
                'recovered_claimable_value' => 2,
                'dependency_status' => 'stale',
            ]),
            $this->pkt('lower-fanout-high-value', [
                'downstream_unlock_count' => 2,
                'risk_level' => 'low',
                'recovered_claimable_value' => 9,
                'dependency_status' => 'stale',
            ]),
        ]);

        $this->assertSame('lower-fanout-high-value', $r['entries'][0]['task_packet_id']);
        $this->assertSame('high-fanout-risky', $r['entries'][1]['task_packet_id']);
    }

    public function test_priority_score_penalizes_risk_level(): void
    {
        $r = $this->svc()->plan([
            $this->pkt('p1', ['recovered_claimable_value' => 5, 'risk_level' => 'high']),
        ]);

        $this->assertSame(3.0, $r['entries'][0]['priority_score']);
    }

    public function test_recovered_claimable_value_defaults_to_downstream_unlock_count(): void
    {
        $r = $this->svc()->plan([$this->pkt('p1', ['downstream_unlock_count' => 4])]);

        $this->assertSame(4.0, $r['entries'][0]['recovered_claimable_value']);
        $this->assertSame(4.0, $r['entries'][0]['priority_score']);
    }

    public function test_low_value_blocked_packet_not_prioritized_over_high_leverage_recoverable_work(): void
    {
        $r = $this->svc()->plan([
            $this->pkt('low-value-but-many-siblings', [
                'downstream_unlock_count' => 20,
                'recovered_claimable_value' => 0,
                'risk_level' => 'low',
                'deficiencies' => ['low value duplicate cluster'],
            ]),
            $this->pkt('high-leverage-recoverable', [
                'downstream_unlock_count' => 1,
                'recovered_claimable_value' => 15,
                'risk_level' => 'low',
                'dependency_status' => 'missing',
            ]),
        ]);

        $this->assertSame('high-leverage-recoverable', $r['entries'][0]['task_packet_id']);
    }
}
