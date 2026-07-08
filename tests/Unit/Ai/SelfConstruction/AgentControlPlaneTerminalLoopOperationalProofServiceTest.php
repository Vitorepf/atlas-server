<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTerminalLoopOperationalProofService;
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

    // ── classifyLoopOperationalStatus ───────────────────────────────────────────

    public function test_no_evidence_and_no_process_is_insufficient_evidence(): void
    {
        $result = $this->svc()->classifyLoopOperationalStatus([]);

        $this->assertSame('insufficient_evidence', $result['status']);
        $this->assertSame('no_loop_activity_evidence_present', $result['blocking_reason']);
        $this->assertNull($result['evidence_freshness']);
    }

    public function test_process_present_with_no_evidence_is_fake_alive(): void
    {
        $result = $this->svc()->classifyLoopOperationalStatus(['process_name_present' => true]);

        $this->assertSame('fake_alive', $result['status']);
        $this->assertSame('process_present_without_claim_or_report_evidence', $result['blocking_reason']);
    }

    public function test_old_evidence_beyond_threshold_is_stale(): void
    {
        $result = $this->svc()->classifyLoopOperationalStatus([
            'last_success_report_age_seconds' => 5000,
        ]);

        $this->assertSame('stale', $result['status']);
        $this->assertSame('no_recent_claim_or_report_activity', $result['blocking_reason']);
        $this->assertSame(5000, $result['evidence_freshness']);
    }

    public function test_recent_claim_without_followup_report_or_movement_is_stuck(): void
    {
        $result = $this->svc()->classifyLoopOperationalStatus([
            'last_claim_age_seconds' => 60,
            'queue_movement_count' => 0,
        ]);

        $this->assertSame('stuck', $result['status']);
        $this->assertSame('claimed_lease_with_no_followup_report_or_queue_movement', $result['blocking_reason']);
    }

    public function test_recent_claim_with_newer_success_report_is_operational(): void
    {
        $result = $this->svc()->classifyLoopOperationalStatus([
            'last_claim_age_seconds' => 120,
            'last_success_report_age_seconds' => 30,
            'queue_movement_count' => 1,
        ]);

        $this->assertSame('operational', $result['status']);
        $this->assertNull($result['blocking_reason']);
    }

    public function test_recent_claim_with_queue_movement_is_not_stuck(): void
    {
        $result = $this->svc()->classifyLoopOperationalStatus([
            'last_claim_age_seconds' => 60,
            'queue_movement_count' => 3,
        ]);

        $this->assertSame('operational', $result['status']);
    }

    public function test_recent_failed_report_alone_is_operational_not_stale(): void
    {
        $result = $this->svc()->classifyLoopOperationalStatus([
            'last_failed_report_age_seconds' => 100,
        ]);

        $this->assertSame('operational', $result['status']);
    }

    public function test_next_recovery_hint_is_present_for_every_status(): void
    {
        $cases = [
            [],
            ['process_name_present' => true],
            ['last_success_report_age_seconds' => 5000],
            ['last_claim_age_seconds' => 60, 'queue_movement_count' => 0],
            ['last_claim_age_seconds' => 60, 'last_success_report_age_seconds' => 10, 'queue_movement_count' => 1],
        ];

        foreach ($cases as $facts) {
            $result = $this->svc()->classifyLoopOperationalStatus($facts);
            $this->assertNotEmpty($result['next_recovery_hint']);
        }
    }

    public function test_evidence_freshness_is_the_minimum_age_across_signals(): void
    {
        $result = $this->svc()->classifyLoopOperationalStatus([
            'last_claim_age_seconds' => 500,
            'last_success_report_age_seconds' => 50,
            'last_failed_report_age_seconds' => 800,
        ]);

        $this->assertSame(50, $result['evidence_freshness']);
    }
}
