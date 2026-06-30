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
}
