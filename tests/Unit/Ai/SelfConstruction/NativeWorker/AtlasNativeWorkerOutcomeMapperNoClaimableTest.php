<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\NativeWorker;

use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerOutcomeMapper;
use Tests\TestCase;

class AtlasNativeWorkerOutcomeMapperNoClaimableTest extends TestCase
{
    private function envelope(array $override = []): array
    {
        return array_replace([
            'task_packet_id' => 'p1',
            'required_evidence' => ['tests_or_gates_result'],
        ], $override);
    }

    private function execution(array $override = []): array
    {
        return array_replace([
            'command_status' => 'green',
            'patch_status' => 'green',
            'results' => [],
            'evidence_refs' => ['tests_or_gates_result'],
            'blockers' => [],
        ], $override);
    }

    private function verification(array $override = []): array
    {
        return array_replace([
            'passed' => true,
            'blockers' => [],
            'evidence_refs' => [],
        ], $override);
    }

    private function mapper(): AtlasNativeWorkerOutcomeMapper
    {
        return new AtlasNativeWorkerOutcomeMapper;
    }

    public function test_no_claimable_task_maps_to_queue_repair_signal_with_stable_queue_starvation_reason(): void
    {
        $result = $this->mapper()->map(
            $this->envelope(),
            $this->execution(['command_status' => 'no_claimable_task']),
            $this->verification(),
        );

        $this->assertSame('queue_repair_signal', $result['report_outcome']);
        $this->assertSame(AtlasNativeWorkerOutcomeMapper::OUTCOME_QUEUE_REPAIR_SIGNAL, $result['report_outcome']);
        $this->assertSame('queue_starvation:no_claimable_task', $result['report_reason']);
        $this->assertContains('queue_starvation:no_claimable_task', $result['blocking_deficiencies']);
    }

    public function test_no_self_sufficient_task_maps_to_give_back_with_stable_queue_starvation_reason(): void
    {
        $result = $this->mapper()->map(
            $this->envelope(),
            $this->execution(['command_status' => 'no_self_sufficient_task']),
            $this->verification(),
        );

        $this->assertSame('give_back', $result['report_outcome']);
        $this->assertSame('queue_starvation:no_self_sufficient_task', $result['report_reason']);
    }

    public function test_verification_failed_still_returns_failed(): void
    {
        $result = $this->mapper()->map(
            $this->envelope(),
            $this->execution(),
            $this->verification(['passed' => false]),
        );

        $this->assertSame('failed', $result['report_outcome']);
    }

    public function test_impossible_scope_still_returns_give_back(): void
    {
        $result = $this->mapper()->map(
            $this->envelope(['impossible_scope' => true]),
            $this->execution(),
            $this->verification(),
        );

        $this->assertSame('give_back', $result['report_outcome']);
    }

    public function test_no_claimable_task_emits_worker_feed_feedback_facts(): void
    {
        $result = $this->mapper()->map(
            $this->envelope(),
            $this->execution([
                'command_status' => 'no_claimable_task',
                'claimable_depth' => 0,
                'active_worker_count' => 4,
            ]),
            $this->verification(),
        );

        $this->assertSame('queue_repair_signal', $result['report_outcome']);
        $this->assertArrayHasKey('worker_feed_feedback', $result);
        $this->assertSame(1, $result['worker_feed_feedback']['no_claimable_task_incident']);
        $this->assertSame('queue_starvation_observed_by_native_worker', $result['worker_feed_feedback']['suggested_replenish_reason']);
        $this->assertSame(0, $result['worker_feed_feedback']['claimable_depth_at_incident']);
        $this->assertSame(4, $result['worker_feed_feedback']['active_worker_count_at_incident']);
    }

    public function test_no_claimable_task_includes_queue_repair_signal_with_supplied_facts(): void
    {
        $result = $this->mapper()->map(
            $this->envelope(),
            $this->execution([
                'command_status' => 'no_claimable_task',
                'claimable_depth' => 2,
                'active_worker_count' => 5,
                'claimable_per_active_worker' => 0.4,
            ]),
            $this->verification(),
        );

        $this->assertSame('queue_repair_signal', $result['report_outcome']);
        $this->assertArrayHasKey('queue_repair_signal', $result);
        $this->assertSame(2, $result['queue_repair_signal']['claimable_depth']);
        $this->assertSame(5, $result['queue_repair_signal']['active_workers']);
        $this->assertSame(0.4, $result['queue_repair_signal']['claimable_per_active_worker']);
    }

    public function test_denied_command_status_remains_terminal_give_back_not_queue_repair(): void
    {
        $result = $this->mapper()->map(
            $this->envelope(),
            $this->execution(['command_status' => 'denied']),
            $this->verification(),
        );

        $this->assertSame('give_back', $result['report_outcome']);
        $this->assertArrayNotHasKey('queue_repair_signal', $result);
    }

    public function test_impossible_scope_give_back_does_not_emit_worker_feed_feedback(): void
    {
        $result = $this->mapper()->map(
            $this->envelope(['impossible_scope' => true]),
            $this->execution(),
            $this->verification(),
        );

        $this->assertArrayNotHasKey('worker_feed_feedback', $result);
    }
}
