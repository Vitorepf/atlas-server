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

    public function test_top_up_required_when_active_workers_and_claimable_per_active_worker_below_floor(): void
    {
        $sup = new AtlasNativeWorkerPoolSupervisor;
        $plan = $sup->capacityPlan([
            'active_workers' => 4,
            'stale_workers' => 0,
            'queue_depth' => 0,
            'max_worker_budget' => 5,
            'claimable_per_active_worker' => 1.5,
        ]);

        $this->assertSame('top_up_required', $plan['recommendation']);
        $this->assertSame('worker_floor_low', $plan['reason']);
    }

    public function test_top_up_required_takes_precedence_over_spawn(): void
    {
        $sup = new AtlasNativeWorkerPoolSupervisor;
        $plan = $sup->capacityPlan([
            'active_workers' => 2,
            'stale_workers' => 0,
            'queue_depth' => 10,
            'max_worker_budget' => 5,
            'claimable_per_active_worker' => 1.0,
        ]);

        $this->assertSame('top_up_required', $plan['recommendation']);
    }

    public function test_no_top_up_required_when_claimable_per_active_worker_above_floor(): void
    {
        $sup = new AtlasNativeWorkerPoolSupervisor;
        $plan = $sup->capacityPlan([
            'active_workers' => 4,
            'stale_workers' => 0,
            'queue_depth' => 0,
            'max_worker_budget' => 5,
            'claimable_per_active_worker' => 10.0,
        ]);

        $this->assertSame('hold', $plan['recommendation']);
    }

    public function test_zero_active_workers_does_not_emit_top_up_required_without_no_claimable_task(): void
    {
        $sup = new AtlasNativeWorkerPoolSupervisor;
        $plan = $sup->capacityPlan([
            'active_workers' => 0,
            'stale_workers' => 0,
            'queue_depth' => 0,
            'max_worker_budget' => 5,
        ]);

        $this->assertSame('hold', $plan['recommendation']);
    }

    public function test_zero_active_workers_emits_top_up_required_with_no_claimable_task_evidence(): void
    {
        $sup = new AtlasNativeWorkerPoolSupervisor;
        $plan = $sup->capacityPlan([
            'active_workers' => 0,
            'stale_workers' => 0,
            'queue_depth' => 0,
            'max_worker_budget' => 5,
            'no_claimable_task' => true,
        ]);

        $this->assertSame('top_up_required', $plan['recommendation']);
        $this->assertSame('no_claimable_task', $plan['reason']);
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

    // ── AC: capacityPlanFromNativeSignals() ──────────────────────────────────────

    public function test_scale_up_recommended_when_claimable_exceeds_active_and_pool_has_room(): void
    {
        $plan = (new AtlasNativeWorkerPoolSupervisor)->capacityPlanFromNativeSignals([
            'claimable_depth' => 10,
            'active_leases' => 1,
            'max_pool_size' => 5,
        ]);

        $this->assertSame('scale_up', $plan['recommendation']);
        $this->assertSame(2, $plan['desired_pool_size']);
    }

    public function test_drain_recommended_when_active_leases_exceed_max_pool_size(): void
    {
        $plan = (new AtlasNativeWorkerPoolSupervisor)->capacityPlanFromNativeSignals([
            'claimable_depth' => 1,
            'active_leases' => 10,
            'max_pool_size' => 5,
        ]);

        $this->assertSame('drain', $plan['recommendation']);
    }

    public function test_hold_recommended_when_recoverable_backlog_present_and_queue_dry(): void
    {
        $plan = (new AtlasNativeWorkerPoolSupervisor)->capacityPlanFromNativeSignals([
            'claimable_depth' => 0,
            'active_leases' => 1,
            'recoverable_backlog_count' => 3,
        ]);

        $this->assertSame('hold', $plan['recommendation']);
    }

    public function test_repair_first_recommended_when_malformed_packets_present(): void
    {
        $plan = (new AtlasNativeWorkerPoolSupervisor)->capacityPlanFromNativeSignals([
            'claimable_depth' => 10,
            'active_leases' => 1,
            'malformed_count' => 2,
        ]);

        $this->assertSame('repair_first', $plan['recommendation']);
        $this->assertContains('malformed_packets_present', $plan['blockers']);
    }

    public function test_repair_first_recommended_when_safety_gates_unsafe(): void
    {
        $plan = (new AtlasNativeWorkerPoolSupervisor)->capacityPlanFromNativeSignals([
            'claimable_depth' => 10,
            'active_leases' => 1,
            'queue_health' => 0.1,
        ]);

        $this->assertSame('repair_first', $plan['recommendation']);
        $this->assertContains('queue_health_unsafe', $plan['safety_reasons']);
    }

    public function test_output_includes_all_required_keys_deterministically(): void
    {
        $facts = ['claimable_depth' => 5, 'active_leases' => 1, 'max_pool_size' => 5];
        $sup = new AtlasNativeWorkerPoolSupervisor;

        $a = $sup->capacityPlanFromNativeSignals($facts);
        $b = $sup->capacityPlanFromNativeSignals($facts);

        foreach (['recommendation', 'desired_pool_size', 'blockers', 'safety_reasons', 'next_recheck_interval'] as $key) {
            $this->assertArrayHasKey($key, $a, "Missing key: {$key}");
        }
        $this->assertSame($a, $b);
    }

    // ── AC: run() refuses to start workers when safety gates are unsafe ─────────

    public function test_run_refuses_to_start_when_queue_health_unsafe(): void
    {
        $invoked = false;
        $out = (new AtlasNativeWorkerPoolSupervisor)->run([
            'apply' => true,
            'max_cycles' => 3,
            'queue_health' => 0.05,
            'cycle_callback' => function (int $i) use (&$invoked): array {
                $invoked = true;

                return ['outcome' => 'success'];
            },
        ]);

        $this->assertFalse($invoked);
        $this->assertTrue($out['dry_run']);
        $this->assertSame('unsafe_to_start', $out['stop_reason']);
        $this->assertContains('queue_health_unsafe', $out['safety_reasons']);
    }

    public function test_run_refuses_to_start_when_proof_ledger_unsafe(): void
    {
        $out = (new AtlasNativeWorkerPoolSupervisor)->run([
            'apply' => true,
            'max_cycles' => 1,
            'proof_ledger_ok' => false,
            'cycle_callback' => static fn (int $i): array => ['outcome' => 'success'],
        ]);

        $this->assertContains('proof_ledger_unsafe', $out['safety_reasons']);
        $this->assertSame(0, $out['cycle_count']);
    }

    public function test_run_starts_normally_when_safety_facts_absent(): void
    {
        $out = (new AtlasNativeWorkerPoolSupervisor)->run([
            'apply' => true,
            'max_cycles' => 1,
            'cycle_callback' => static fn (int $i): array => ['outcome' => 'success'],
        ]);

        $this->assertFalse($out['dry_run']);
        $this->assertSame(1, $out['cycle_count']);
    }
}
