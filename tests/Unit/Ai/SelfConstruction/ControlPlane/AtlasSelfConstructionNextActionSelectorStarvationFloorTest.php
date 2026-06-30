<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\ControlPlane\AtlasSelfConstructionNextActionSelector;
use Tests\TestCase;

final class AtlasSelfConstructionNextActionSelectorStarvationFloorTest extends TestCase
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

    private function starvedQueue(array $overrides = []): array
    {
        return array_merge([
            'open_verifications' => 0,
            'ready_to_promote' => 0,
            'tasks_pending_workers' => 0,
            'backlog_acceptance_items' => 5,
            'servable_now' => 1,
            'servability_floor' => 3,
            'idle_workers' => 2,
            'claimable_depth' => 1,
            'malformed_count' => 0,
            'poison_packets' => 0,
            'stale_active_leases' => 0,
        ], $overrides);
    }

    public function test_starvation_floor_prefers_create_task_packets_even_with_claimable_tasks(): void
    {
        $verdict = (new AtlasSelfConstructionNextActionSelector)->select(
            $this->readyOrgans(),
            $this->scopeAllowed(),
            $this->execMode(),
            $this->starvedQueue(),
        );

        $this->assertSame(AtlasSelfConstructionNextActionSelector::ACTION_CREATE_TASK_PACKETS, $verdict['action']);
        $this->assertContains('starvation:servable_now_1_below_floor_3', $verdict['reasons']);
    }

    public function test_malformed_queue_still_takes_priority_over_starvation(): void
    {
        $verdict = (new AtlasSelfConstructionNextActionSelector)->select(
            $this->readyOrgans(),
            $this->scopeAllowed(),
            $this->execMode(),
            $this->starvedQueue(['malformed_count' => 2]),
        );

        $this->assertSame(AtlasSelfConstructionNextActionSelector::ACTION_REPAIR_QUEUE, $verdict['action']);
    }

    public function test_open_verifications_still_take_priority_over_starvation(): void
    {
        $verdict = (new AtlasSelfConstructionNextActionSelector)->select(
            $this->readyOrgans(),
            $this->scopeAllowed(),
            $this->execMode(),
            $this->starvedQueue(['open_verifications' => 3]),
        );

        $this->assertSame(AtlasSelfConstructionNextActionSelector::ACTION_VERIFY_CANDIDATES, $verdict['action']);
    }

    public function test_no_starvation_when_servable_now_meets_floor(): void
    {
        $verdict = (new AtlasSelfConstructionNextActionSelector)->select(
            $this->readyOrgans(),
            $this->scopeAllowed(),
            $this->execMode(),
            $this->starvedQueue(['servable_now' => 3]),
        );

        $this->assertSame(AtlasSelfConstructionNextActionSelector::ACTION_SCHEDULE_WORKERS, $verdict['action']);
    }

    public function test_worker_feed_risk_with_zero_backlog_creates_task_packets_in_execute_mode(): void
    {
        $verdict = (new AtlasSelfConstructionNextActionSelector)->select(
            $this->readyOrgans(),
            $this->scopeAllowed(),
            $this->execMode(),
            $this->starvedQueue([
                'backlog_acceptance_items' => 0,
                'idle_workers' => 0,
                'claimable_depth' => 0,
                'servable_now' => 5,
                'servability_floor' => 0,
                'worker_feed_risk' => true,
            ]),
        );

        $this->assertSame(AtlasSelfConstructionNextActionSelector::ACTION_CREATE_TASK_PACKETS, $verdict['action']);
        $this->assertContains('worker_feed_risk', $verdict['reasons']);
    }

    public function test_replenish_soon_with_zero_backlog_creates_task_packets_in_execute_mode(): void
    {
        $verdict = (new AtlasSelfConstructionNextActionSelector)->select(
            $this->readyOrgans(),
            $this->scopeAllowed(),
            $this->execMode(),
            $this->starvedQueue([
                'backlog_acceptance_items' => 0,
                'idle_workers' => 0,
                'claimable_depth' => 0,
                'servable_now' => 5,
                'servability_floor' => 0,
                'replenish_recommendation' => 'replenish_soon',
            ]),
        );

        $this->assertSame(AtlasSelfConstructionNextActionSelector::ACTION_CREATE_TASK_PACKETS, $verdict['action']);
        $this->assertContains('replenish_recommendation_replenish_soon', $verdict['reasons']);
    }

    public function test_observe_mode_holds_position_despite_worker_feed_risk(): void
    {
        $verdict = (new AtlasSelfConstructionNextActionSelector)->select(
            $this->readyOrgans(),
            $this->scopeAllowed(),
            ['mode' => 'observe'],
            $this->starvedQueue([
                'backlog_acceptance_items' => 0,
                'idle_workers' => 0,
                'claimable_depth' => 0,
                'servable_now' => 5,
                'servability_floor' => 0,
                'worker_feed_risk' => true,
            ]),
        );

        $this->assertSame(AtlasSelfConstructionNextActionSelector::ACTION_HOLD_POSITION, $verdict['action']);
    }

    public function test_scope_denied_holds_position_despite_replenish_soon(): void
    {
        $verdict = (new AtlasSelfConstructionNextActionSelector)->select(
            $this->readyOrgans(),
            ['allowed' => false, 'reasons' => ['out_of_scope']],
            $this->execMode(),
            $this->starvedQueue([
                'backlog_acceptance_items' => 0,
                'idle_workers' => 0,
                'claimable_depth' => 0,
                'servable_now' => 5,
                'servability_floor' => 0,
                'replenish_recommendation' => 'replenish_soon',
            ]),
        );

        $this->assertSame(AtlasSelfConstructionNextActionSelector::ACTION_HOLD_POSITION, $verdict['action']);
    }

    public function test_no_risk_signals_and_no_backlog_still_holds_position(): void
    {
        $verdict = (new AtlasSelfConstructionNextActionSelector)->select(
            $this->readyOrgans(),
            $this->scopeAllowed(),
            $this->execMode(),
            $this->starvedQueue([
                'backlog_acceptance_items' => 0,
                'idle_workers' => 0,
                'claimable_depth' => 0,
                'servable_now' => 5,
                'servability_floor' => 0,
            ]),
        );

        $this->assertSame(AtlasSelfConstructionNextActionSelector::ACTION_HOLD_POSITION, $verdict['action']);
    }
}
