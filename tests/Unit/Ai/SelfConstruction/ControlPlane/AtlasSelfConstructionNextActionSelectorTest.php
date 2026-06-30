<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\ControlPlane\AtlasSelfConstructionNextActionSelector;
use Tests\TestCase;

final class AtlasSelfConstructionNextActionSelectorTest extends TestCase
{
    private function readyOrgans(): array
    {
        return [
            'ready_organs' => ['cortex', 'goal_value'],
            'blocked_organs' => [],
            'missing_organs' => [],
            'degraded_organs' => [],
        ];
    }

    private function scopeAllowed(): array
    {
        return ['allowed' => true];
    }

    private function execMode(): array
    {
        return ['mode' => 'execute'];
    }

    private function emptyQueue(): array
    {
        return ['open_verifications' => 0, 'ready_to_promote' => 0, 'tasks_pending_workers' => 0, 'backlog_acceptance_items' => 0];
    }

    public function test_missing_organ_repair_takes_priority(): void
    {
        $organs = $this->readyOrgans();
        $organs['missing_organs'] = ['native_worker'];

        $verdict = (new AtlasSelfConstructionNextActionSelector)->select($organs, $this->scopeAllowed(), $this->execMode(), $this->emptyQueue());
        $this->assertSame(AtlasSelfConstructionNextActionSelector::ACTION_REPAIR_ORGANS, $verdict['action']);
        $this->assertContains('missing_organ:native_worker', $verdict['reasons']);
    }

    public function test_blocked_organ_repair_priority(): void
    {
        $organs = $this->readyOrgans();
        $organs['blocked_organs'] = [['organ' => 'verification_court', 'reason' => 'phpunit_exit_1']];

        $verdict = (new AtlasSelfConstructionNextActionSelector)->select($organs, $this->scopeAllowed(), $this->execMode(), $this->emptyQueue());
        $this->assertSame(AtlasSelfConstructionNextActionSelector::ACTION_REPAIR_ORGANS, $verdict['action']);
        $this->assertContains('blocked_organ:verification_court', $verdict['reasons']);
    }

    public function test_degraded_knowledge_sync_runs_knowledge_sync_before_anything_else(): void
    {
        $organs = $this->readyOrgans();
        $organs['degraded_organs'] = [['organ' => 'knowledge_sync', 'reason' => 'context_pack_stale']];
        $queue = ['open_verifications' => 5, 'ready_to_promote' => 5, 'tasks_pending_workers' => 5, 'backlog_acceptance_items' => 5];

        $verdict = (new AtlasSelfConstructionNextActionSelector)->select($organs, $this->scopeAllowed(), $this->execMode(), $queue);
        $this->assertSame(AtlasSelfConstructionNextActionSelector::ACTION_RUN_KNOWLEDGE_SYNC, $verdict['action']);
    }

    public function test_create_task_packets_when_only_backlog_is_present(): void
    {
        $queue = $this->emptyQueue();
        $queue['backlog_acceptance_items'] = 3;

        $verdict = (new AtlasSelfConstructionNextActionSelector)->select($this->readyOrgans(), $this->scopeAllowed(), $this->execMode(), $queue);
        $this->assertSame(AtlasSelfConstructionNextActionSelector::ACTION_CREATE_TASK_PACKETS, $verdict['action']);
    }

    public function test_schedule_workers_when_tasks_pending(): void
    {
        $queue = $this->emptyQueue();
        $queue['tasks_pending_workers'] = 2;

        $verdict = (new AtlasSelfConstructionNextActionSelector)->select($this->readyOrgans(), $this->scopeAllowed(), $this->execMode(), $queue);
        $this->assertSame(AtlasSelfConstructionNextActionSelector::ACTION_SCHEDULE_WORKERS, $verdict['action']);
    }

    public function test_verify_candidates_when_open_verifications(): void
    {
        $queue = $this->emptyQueue();
        $queue['open_verifications'] = 1;

        $verdict = (new AtlasSelfConstructionNextActionSelector)->select($this->readyOrgans(), $this->scopeAllowed(), $this->execMode(), $queue);
        $this->assertSame(AtlasSelfConstructionNextActionSelector::ACTION_VERIFY_CANDIDATES, $verdict['action']);
    }

    public function test_prepare_merge_when_ready_to_promote(): void
    {
        $queue = $this->emptyQueue();
        $queue['ready_to_promote'] = 1;

        $verdict = (new AtlasSelfConstructionNextActionSelector)->select($this->readyOrgans(), $this->scopeAllowed(), $this->execMode(), $queue);
        $this->assertSame(AtlasSelfConstructionNextActionSelector::ACTION_PREPARE_MERGE, $verdict['action']);
    }

    public function test_autonomy_off_holds_position_even_with_full_queue(): void
    {
        $queue = ['open_verifications' => 9, 'ready_to_promote' => 9, 'tasks_pending_workers' => 9, 'backlog_acceptance_items' => 9];

        $verdict = (new AtlasSelfConstructionNextActionSelector)->select($this->readyOrgans(), $this->scopeAllowed(), ['mode' => 'off'], $queue);
        $this->assertSame(AtlasSelfConstructionNextActionSelector::ACTION_HOLD_POSITION, $verdict['action']);
        $this->assertContains('autonomy_mode_off', $verdict['reasons']);
    }

    public function test_observe_mode_holds_position_for_execution_actions(): void
    {
        $queue = $this->emptyQueue();
        $queue['tasks_pending_workers'] = 5;

        $verdict = (new AtlasSelfConstructionNextActionSelector)->select($this->readyOrgans(), $this->scopeAllowed(), ['mode' => 'observe'], $queue);
        $this->assertSame(AtlasSelfConstructionNextActionSelector::ACTION_HOLD_POSITION, $verdict['action']);
        $this->assertContains('autonomy_mode_observe_only', $verdict['reasons']);
    }

    public function test_scope_gate_not_allowed_holds_position_with_named_reasons(): void
    {
        $scope = ['allowed' => false, 'reasons' => ['forbidden_target']];

        $verdict = (new AtlasSelfConstructionNextActionSelector)->select($this->readyOrgans(), $scope, $this->execMode(), $this->emptyQueue());
        $this->assertSame(AtlasSelfConstructionNextActionSelector::ACTION_HOLD_POSITION, $verdict['action']);
        $this->assertContains('scope_gate_not_allowed', $verdict['reasons']);
        $this->assertContains('scope:forbidden_target', $verdict['reasons']);
    }

    public function test_idle_queue_holds_position_with_named_reason(): void
    {
        $verdict = (new AtlasSelfConstructionNextActionSelector)->select($this->readyOrgans(), $this->scopeAllowed(), $this->execMode(), $this->emptyQueue());
        $this->assertSame(AtlasSelfConstructionNextActionSelector::ACTION_HOLD_POSITION, $verdict['action']);
        $this->assertContains('queue_idle', $verdict['reasons']);
    }

    public function test_malformed_count_triggers_repair_queue_over_schedule_workers(): void
    {
        $queue = $this->emptyQueue();
        $queue['malformed_count'] = 3;
        $queue['tasks_pending_workers'] = 5;

        $verdict = (new AtlasSelfConstructionNextActionSelector)->select($this->readyOrgans(), $this->scopeAllowed(), $this->execMode(), $queue);
        $this->assertSame(AtlasSelfConstructionNextActionSelector::ACTION_REPAIR_QUEUE, $verdict['action']);
        $this->assertContains('malformed_queue_packets:3', $verdict['reasons']);
    }

    public function test_poison_packets_triggers_repair_queue_over_verify_candidates(): void
    {
        $queue = $this->emptyQueue();
        $queue['poison_packets'] = 1;
        $queue['open_verifications'] = 4;

        $verdict = (new AtlasSelfConstructionNextActionSelector)->select($this->readyOrgans(), $this->scopeAllowed(), $this->execMode(), $queue);
        $this->assertSame(AtlasSelfConstructionNextActionSelector::ACTION_REPAIR_QUEUE, $verdict['action']);
        $this->assertContains('poison_queue_packets:1', $verdict['reasons']);
    }

    public function test_repair_queue_beats_create_task_packets_and_prepare_merge(): void
    {
        $queue = ['open_verifications' => 0, 'ready_to_promote' => 2, 'tasks_pending_workers' => 0, 'backlog_acceptance_items' => 5, 'malformed_count' => 1, 'poison_packets' => 0];

        $verdict = (new AtlasSelfConstructionNextActionSelector)->select($this->readyOrgans(), $this->scopeAllowed(), $this->execMode(), $queue);
        $this->assertSame(AtlasSelfConstructionNextActionSelector::ACTION_REPAIR_QUEUE, $verdict['action']);
    }

    public function test_autonomy_off_still_holds_position_even_with_malformed_queue(): void
    {
        $queue = $this->emptyQueue();
        $queue['malformed_count'] = 5;

        $verdict = (new AtlasSelfConstructionNextActionSelector)->select($this->readyOrgans(), $this->scopeAllowed(), ['mode' => 'off'], $queue);
        $this->assertSame(AtlasSelfConstructionNextActionSelector::ACTION_HOLD_POSITION, $verdict['action']);
        $this->assertContains('autonomy_mode_off', $verdict['reasons']);
    }

    public function test_scope_gate_blocks_even_with_poison_packets(): void
    {
        $queue = $this->emptyQueue();
        $queue['poison_packets'] = 2;

        $verdict = (new AtlasSelfConstructionNextActionSelector)->select($this->readyOrgans(), ['allowed' => false], $this->execMode(), $queue);
        $this->assertSame(AtlasSelfConstructionNextActionSelector::ACTION_HOLD_POSITION, $verdict['action']);
        $this->assertContains('scope_gate_not_allowed', $verdict['reasons']);
    }

    public function test_missing_organ_beats_queue_repair(): void
    {
        $organs = $this->readyOrgans();
        $organs['missing_organs'] = ['native_worker'];
        $queue = $this->emptyQueue();
        $queue['malformed_count'] = 5;

        $verdict = (new AtlasSelfConstructionNextActionSelector)->select($organs, $this->scopeAllowed(), $this->execMode(), $queue);
        $this->assertSame(AtlasSelfConstructionNextActionSelector::ACTION_REPAIR_ORGANS, $verdict['action']);
    }

    public function test_knowledge_sync_degraded_runs_before_execution_work_when_no_queue_poison(): void
    {
        $organs = $this->readyOrgans();
        $organs['degraded_organs'] = [['organ' => 'knowledge_sync', 'reason' => 'stale']];
        $queue = ['open_verifications' => 5, 'ready_to_promote' => 5, 'tasks_pending_workers' => 5, 'backlog_acceptance_items' => 5];

        $verdict = (new AtlasSelfConstructionNextActionSelector)->select($organs, $this->scopeAllowed(), $this->execMode(), $queue);
        $this->assertSame(AtlasSelfConstructionNextActionSelector::ACTION_RUN_KNOWLEDGE_SYNC, $verdict['action']);
    }

    public function test_queue_repair_takes_priority_over_knowledge_sync_degraded(): void
    {
        $organs = $this->readyOrgans();
        $organs['degraded_organs'] = [['organ' => 'knowledge_sync', 'reason' => 'stale']];
        $queue = $this->emptyQueue();
        $queue['malformed_count'] = 2;

        $verdict = (new AtlasSelfConstructionNextActionSelector)->select($organs, $this->scopeAllowed(), $this->execMode(), $queue);
        $this->assertSame(AtlasSelfConstructionNextActionSelector::ACTION_REPAIR_QUEUE, $verdict['action']);
        $this->assertContains('malformed_queue_packets:2', $verdict['reasons']);
    }

    public function test_selector_output_is_facts_only_no_side_effects_in_source(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/SelfConstruction/ControlPlane/AtlasSelfConstructionNextActionSelector.php'));
        foreach (['dispatch(', 'Queue::', 'shell_exec', 'exec(', 'Http::', 'DB::', 'git ', '->ledger', 'Ledger::'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "selector source must not contain {$forbidden}");
        }
    }
}
