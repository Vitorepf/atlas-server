<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTerminalLoopOperationalProofService;
use PHPUnit\Framework\TestCase;

/**
 * Proves the pure evaluate() contract on AgentControlPlaneTerminalLoopOperationalProofService.
 * No I/O, no queue mutation, no provider calls — facts in, verdict out.
 */
final class AgentControlPlaneTerminalLoopOperationalProofServiceTest extends TestCase
{
    private function svc(): AgentControlPlaneTerminalLoopOperationalProofService
    {
        return new AgentControlPlaneTerminalLoopOperationalProofService;
    }

    /** @return array<string,mixed> */
    private function greenFacts(): array
    {
        return [
            'command_receipts' => [['command' => 'atlas:task next', 'status' => 'ok']],
            'queue_health'     => ['claimable_count' => 3],
            'active_leases'    => [],
            'worker_reports'   => [['task_packet_id' => 'task-001', 'outcome' => 'success']],
        ];
    }

    public function test_green_receipts_and_healthy_queue_produce_passed(): void
    {
        $result = $this->svc()->evaluate($this->greenFacts());

        $this->assertSame('passed', $result['verdict']);
        $this->assertSame([], $result['blockers']);
    }

    public function test_missing_command_receipts_produce_insufficient_evidence(): void
    {
        $facts = $this->greenFacts();
        unset($facts['command_receipts']);

        $result = $this->svc()->evaluate($facts);

        $this->assertSame('insufficient_evidence', $result['verdict']);
        $this->assertContains('missing_command_receipts', $result['blockers']);
    }

    public function test_missing_queue_health_produces_insufficient_evidence(): void
    {
        $facts = $this->greenFacts();
        unset($facts['queue_health']);

        $result = $this->svc()->evaluate($facts);

        $this->assertSame('insufficient_evidence', $result['verdict']);
        $this->assertContains('missing_queue_health', $result['blockers']);
    }

    public function test_missing_worker_reports_produce_insufficient_evidence(): void
    {
        $facts = $this->greenFacts();
        unset($facts['worker_reports']);

        $result = $this->svc()->evaluate($facts);

        $this->assertSame('insufficient_evidence', $result['verdict']);
        $this->assertContains('missing_worker_reports', $result['blockers']);
    }

    public function test_empty_facts_emit_all_missing_evidence_blockers(): void
    {
        $result = $this->svc()->evaluate([]);

        $this->assertSame('insufficient_evidence', $result['verdict']);
        $this->assertContains('missing_command_receipts', $result['blockers']);
        $this->assertContains('missing_queue_health', $result['blockers']);
        $this->assertContains('missing_worker_reports', $result['blockers']);
    }

    public function test_dry_queue_with_no_worker_reports_produces_blocked_with_exact_blocker(): void
    {
        $facts = array_replace($this->greenFacts(), [
            'queue_health'   => ['claimable_count' => 0],
            'worker_reports' => [],
        ]);

        $result = $this->svc()->evaluate($facts);

        $this->assertSame('blocked', $result['verdict']);
        $this->assertContains('dry_queue_no_claimable_tasks', $result['blockers']);
    }

    public function test_no_successful_worker_reports_produces_blocked(): void
    {
        $facts = array_replace($this->greenFacts(), [
            'worker_reports' => [['task_packet_id' => 'task-001', 'outcome' => 'failed']],
        ]);

        $result = $this->svc()->evaluate($facts);

        $this->assertSame('blocked', $result['verdict']);
        $this->assertContains('no_successful_worker_reports', $result['blockers']);
    }

    public function test_active_lease_without_matching_worker_report_produces_blocked(): void
    {
        $facts = array_replace($this->greenFacts(), [
            'active_leases'  => [['task_packet_id' => 'task-999', 'lease_id' => 'lease-999']],
            'worker_reports' => [['task_packet_id' => 'task-001', 'outcome' => 'success']],
        ]);

        $result = $this->svc()->evaluate($facts);

        $this->assertSame('blocked', $result['verdict']);
        $this->assertContains('active_lease_without_matching_worker_report', $result['blockers']);
    }

    public function test_active_lease_with_matching_worker_report_does_not_block(): void
    {
        $facts = array_replace($this->greenFacts(), [
            'active_leases'  => [['task_packet_id' => 'task-001', 'lease_id' => 'lease-001']],
            'worker_reports' => [['task_packet_id' => 'task-001', 'outcome' => 'success']],
        ]);

        $result = $this->svc()->evaluate($facts);

        $this->assertSame('passed', $result['verdict']);
        $this->assertNotContains('active_lease_without_matching_worker_report', $result['blockers']);
    }

    public function test_facts_evaluated_counts_are_present_on_passed(): void
    {
        $result = $this->svc()->evaluate($this->greenFacts());

        $this->assertArrayHasKey('facts_evaluated', $result);
        $this->assertSame(1, $result['facts_evaluated']['command_receipts_count']);
        $this->assertSame(1, $result['facts_evaluated']['successful_worker_reports']);
    }
}
