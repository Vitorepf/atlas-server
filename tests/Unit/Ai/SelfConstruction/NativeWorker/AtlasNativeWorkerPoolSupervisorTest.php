<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\NativeWorker;

use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerPoolSupervisor;
use Tests\TestCase;

final class AtlasNativeWorkerPoolSupervisorTest extends TestCase
{
    public function test_dry_run_returns_planned_envelope_and_does_not_invoke_callback(): void
    {
        $called = 0;
        $sup = new AtlasNativeWorkerPoolSupervisor;
        $out = $sup->run([
            'max_cycles' => 5,
            'max_parallel' => 2,
            'cycle_callback' => function () use (&$called) { $called++; return ['outcome' => 'success']; },
        ]);

        $this->assertSame(AtlasNativeWorkerPoolSupervisor::SCHEMA, $out['schema_version']);
        $this->assertTrue($out['dry_run']);
        $this->assertSame(0, $called);
        $this->assertSame(0, $out['cycle_count']);
        $this->assertSame(5, $out['max_cycles']);
        $this->assertNotEmpty($out['supervisor_hash']);
    }

    public function test_apply_bounded_by_max_cycles_and_counts_outcomes(): void
    {
        $invocations = [];
        $sup = new AtlasNativeWorkerPoolSupervisor;
        $out = $sup->run([
            'apply' => true,
            'max_cycles' => 4,
            'max_parallel' => 2,
            'cycle_callback' => function (int $i) use (&$invocations): array {
                $invocations[] = $i;
                return ['outcome' => $i % 2 === 0 ? 'success' : 'give_back', 'task_id' => 't-'.$i, 'lease_id' => 'l-'.$i];
            },
        ]);

        $this->assertCount(4, $invocations);
        $this->assertSame([0, 1, 2, 3], $invocations);
        $this->assertSame(4, $out['cycle_count']);
        $this->assertSame(2, $out['success_count']);
        $this->assertSame(2, $out['give_back_count']);
        $this->assertSame(0, $out['failed_count']);
        $this->assertFalse($out['safety_stop']);
    }

    public function test_one_failed_cycle_does_not_stop_others_when_stop_on_failed_off(): void
    {
        $sup = new AtlasNativeWorkerPoolSupervisor;
        $out = $sup->run([
            'apply' => true,
            'max_cycles' => 3,
            'cycle_callback' => function (int $i): array {
                if ($i === 1) {
                    throw new \RuntimeException('boom');
                }
                return ['outcome' => 'success'];
            },
        ]);

        $this->assertSame(3, $out['cycle_count']);
        $this->assertSame(2, $out['success_count']);
        $this->assertSame(1, $out['failed_count']);
        $this->assertNull($out['stop_reason']);
    }

    public function test_safety_halt_short_circuits_when_stop_on_safety_halt_enabled(): void
    {
        $sup = new AtlasNativeWorkerPoolSupervisor;
        $out = $sup->run([
            'apply' => true,
            'max_cycles' => 5,
            'cycle_callback' => function (int $i): array {
                return $i === 2 ? ['outcome' => 'safety_halt'] : ['outcome' => 'success'];
            },
        ]);

        $this->assertTrue($out['safety_stop']);
        $this->assertSame(3, $out['cycle_count'], 'must stop right after the safety_halt tick');
        $this->assertSame('safety_halt', $out['stop_reason']);
    }

    public function test_spawn_recommended_when_queue_exceeds_active_and_budget_available(): void
    {
        $sup = new AtlasNativeWorkerPoolSupervisor;
        $plan = $sup->capacityPlan([
            'active_workers' => 2,
            'stale_workers' => 0,
            'queue_depth' => 10,
            'max_worker_budget' => 5,
        ]);

        $this->assertSame('spawn', $plan['recommendation']);
        $this->assertSame('queue_pressure_and_budget_available', $plan['reason']);
    }

    public function test_drain_recommended_when_active_workers_exceed_budget(): void
    {
        $sup = new AtlasNativeWorkerPoolSupervisor;
        $plan = $sup->capacityPlan([
            'active_workers' => 8,
            'stale_workers' => 1,
            'queue_depth' => 3,
            'max_worker_budget' => 4,
        ]);

        $this->assertSame('drain', $plan['recommendation']);
        $this->assertSame('active_exceeds_budget', $plan['reason']);
    }

    public function test_hold_recommended_when_stale_heartbeat_present_and_queue_covered(): void
    {
        $sup = new AtlasNativeWorkerPoolSupervisor;
        $plan = $sup->capacityPlan([
            'active_workers' => 3,
            'stale_workers' => 2,
            'queue_depth' => 2,  // queue_depth <= active_workers → no spawn pressure
            'max_worker_budget' => 5,
        ]);

        $this->assertSame('hold', $plan['recommendation']);
        $this->assertSame('hold_stale_workers_present', $plan['reason']);
    }

    public function test_refused_action_kinds_surface_in_blocked_actions(): void
    {
        $sup = new AtlasNativeWorkerPoolSupervisor;
        $out = $sup->run([
            'apply' => true,
            'max_cycles' => 1,
            'cycle_callback' => static fn (int $i): array => [
                'outcome' => 'success',
                'attempted_actions' => [
                    ['kind' => 'external_provider'],
                    ['kind' => 'operator_handoff'],
                    ['kind' => 'unrestricted_shell'],
                    ['kind' => 'git'],
                    ['kind' => 'native_apply'],
                ],
            ],
        ]);

        $kinds = array_column($out['blocked_actions'], 'kind');
        foreach (AtlasNativeWorkerPoolSupervisor::REFUSED_ACTION_KINDS as $r) {
            $this->assertContains($r, $kinds, "{$r} must be blocked");
        }
        $this->assertNotContains('native_apply', $kinds);
        $this->assertSame(4, $out['blocked_count']);
    }
}
