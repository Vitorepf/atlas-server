<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentRuntimeEvidenceCertificationService;
use App\Services\Ai\SelfConstruction\AgentRuntimeEvidenceJournalRepository;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AgentRuntimeEvidenceCertificationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_certify_includes_journal_integrity_and_per_task_continuity_summary(): void
    {
        $service = new AgentRuntimeEvidenceCertificationService;

        $result = $service->certify();

        $this->assertArrayHasKey('journal_integrity', $result);
        $this->assertArrayHasKey('per_task_continuity_summary', $result);
        $this->assertSame('available', $result['status']);
        $this->assertTrue($result['invariants_all_true']);
        foreach (['runtime_execution_allowed', 'dispatch_allowed', 'provider_call_allowed', 'token_spend_allowed', 'self_programming_allowed', 'ledger_write_allowed', 'completion_claim_allowed'] as $flag) {
            $this->assertFalse($result[$flag]);
        }
    }

    public function test_certify_blocks_when_required_evidence_is_stitched_across_different_tasks(): void
    {
        $journal = new AgentRuntimeEvidenceJournalRepository;
        foreach (AgentRuntimeEvidenceCertificationServiceTest::requiredTypes() as $i => $type) {
            $journal->append([
                'task_packet_id' => 'task-'.$i,
                'agent_id' => 'agent-1',
                'evidence_type' => $type,
            ]);
        }

        $service = new AgentRuntimeEvidenceCertificationService(journal: $journal);

        $result = $service->certify();

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['invariants_all_true']);
        $this->assertTrue($result['per_task_continuity_summary']['globally_complete']);
        $this->assertFalse($result['per_task_continuity_summary']['any_task_chain_complete']);
        $this->assertTrue($result['per_task_continuity_summary']['stitched_proxy_detected']);

        foreach (['runtime_execution_allowed', 'dispatch_allowed', 'provider_call_allowed', 'token_spend_allowed', 'self_programming_allowed', 'ledger_write_allowed', 'completion_claim_allowed'] as $flag) {
            $this->assertFalse($result[$flag]);
        }
    }

    // ── AC2: blocked certification includes violation_summary with grouped counts + next_repair_focus ──

    public function test_blocked_certification_includes_violation_summary_with_grouped_counts_and_next_repair_focus(): void
    {
        $journal = new AgentRuntimeEvidenceJournalRepository;
        foreach (AgentRuntimeEvidenceCertificationServiceTest::requiredTypes() as $i => $type) {
            $journal->append([
                'task_packet_id' => 'task-'.$i,
                'agent_id' => 'agent-1',
                'evidence_type' => $type,
            ]);
        }

        $service = new AgentRuntimeEvidenceCertificationService(journal: $journal);
        $result = $service->certify();

        $this->assertArrayHasKey('violation_summary', $result);
        $this->assertArrayHasKey('by_class', $result['violation_summary']);
        $this->assertArrayHasKey('continuity', $result['violation_summary']['by_class']);
        $this->assertGreaterThan(0, $result['violation_summary']['by_class']['continuity']);
        $this->assertSame('continuity', $result['violation_summary']['next_repair_focus']);

        // The single failing invariant here is the stitched-proxy continuity check.
        $this->assertContains('per_task_continuity_not_stitched_proxy', array_column($result['violations'], 'name'));
    }

    // ── AC3: available certification keeps the exact next_action ──────────────

    public function test_available_certification_keeps_next_action_for_local_journal(): void
    {
        $service = new AgentRuntimeEvidenceCertificationService;

        $result = $service->certify();

        $this->assertSame('available', $result['status']);
        $this->assertSame('keep_runtime_evidence_journal_local_until_signed_ledger_promotion_gate', $result['next_action']);
        $this->assertSame(['journal' => 0, 'receipt' => 0, 'continuity' => 0, 'runtime_safety' => 0, 'freshness' => 0], $result['violation_summary']['by_class']);
        $this->assertNull($result['violation_summary']['next_repair_focus']);
    }

    // ── AC4: stitched proxy evidence stays blocked and appears under continuity violations ──

    public function test_stitched_proxy_evidence_remains_blocked_and_appears_under_continuity_violations(): void
    {
        $journal = new AgentRuntimeEvidenceJournalRepository;
        foreach (AgentRuntimeEvidenceCertificationServiceTest::requiredTypes() as $i => $type) {
            $journal->append([
                'task_packet_id' => 'task-'.$i,
                'agent_id' => 'agent-1',
                'evidence_type' => $type,
            ]);
        }

        $service = new AgentRuntimeEvidenceCertificationService(journal: $journal);
        $result = $service->certify();

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['completion_claim_allowed']);
        $this->assertGreaterThanOrEqual(1, $result['violation_summary']['by_class']['continuity']);
    }

    /** @return list<string> */
    private static function requiredTypes(): array
    {
        return [
            'dispatch_plan',
            'claim_lease',
            'scope_lock',
            'validation_result',
            'continuation_summary',
        ];
    }

    // ── AC2: required/observed/missing/stale proof classes ──────────────────────

    public function test_available_certification_reports_proof_class_facts_for_empty_journal(): void
    {
        $service = new AgentRuntimeEvidenceCertificationService;

        $result = $service->certify();

        $this->assertSame(self::requiredTypes(), $result['required_proof_classes']);
        $this->assertSame([], $result['observed_proof_classes']);
        $this->assertSame(self::requiredTypes(), array_values(array_intersect(self::requiredTypes(), $result['missing_proof_classes'])));
        $this->assertSame([], $result['stale_evidence_classes']);
    }

    public function test_observed_and_missing_proof_classes_reflect_real_journal_entries(): void
    {
        $journal = new AgentRuntimeEvidenceJournalRepository;
        $journal->append(['task_packet_id' => 'task-1', 'agent_id' => 'agent-1', 'evidence_type' => 'dispatch_plan']);
        $journal->append(['task_packet_id' => 'task-1', 'agent_id' => 'agent-1', 'evidence_type' => 'claim_lease']);

        $service = new AgentRuntimeEvidenceCertificationService(journal: $journal);
        $result = $service->certify();

        $this->assertSame(['claim_lease', 'dispatch_plan'], $result['observed_proof_classes']);
        $this->assertContains('scope_lock', $result['missing_proof_classes']);
        $this->assertContains('validation_result', $result['missing_proof_classes']);
        $this->assertContains('continuation_summary', $result['missing_proof_classes']);
        $this->assertNotContains('dispatch_plan', $result['missing_proof_classes']);
    }

    public function test_stale_evidence_class_reported_when_all_entries_of_a_required_type_are_stale(): void
    {
        $journal = new AgentRuntimeEvidenceJournalRepository;
        foreach (self::requiredTypes() as $type) {
            $journal->append([
                'task_packet_id' => 'task-1',
                'agent_id' => 'agent-1',
                'evidence_type' => $type,
                'evidence_age_seconds' => $type === 'dispatch_plan' ? 999_999_999 : 0,
            ]);
        }

        $service = new AgentRuntimeEvidenceCertificationService(journal: $journal);
        $result = $service->certify();

        $this->assertSame(['dispatch_plan'], $result['stale_evidence_classes']);
    }

    public function test_required_type_with_at_least_one_fresh_entry_is_not_reported_stale(): void
    {
        $journal = new AgentRuntimeEvidenceJournalRepository;
        $journal->append(['task_packet_id' => 'task-1', 'agent_id' => 'agent-1', 'evidence_type' => 'dispatch_plan', 'evidence_age_seconds' => 999_999_999]);
        $journal->append(['task_packet_id' => 'task-2', 'agent_id' => 'agent-1', 'evidence_type' => 'dispatch_plan', 'evidence_age_seconds' => 0]);

        $service = new AgentRuntimeEvidenceCertificationService(journal: $journal);
        $result = $service->certify();

        $this->assertSame([], $result['stale_evidence_classes']);
    }

    // ── AC3: refuses certification when required evidence lacks freshness ───────

    public function test_certification_blocked_when_required_evidence_class_is_entirely_stale(): void
    {
        $journal = new AgentRuntimeEvidenceJournalRepository;
        foreach (self::requiredTypes() as $i => $type) {
            $journal->append([
                'task_packet_id' => 'task-1',
                'agent_id' => 'agent-1',
                'evidence_type' => $type,
                'evidence_age_seconds' => $i === 0 ? 999_999_999 : 0,
            ]);
        }

        $service = new AgentRuntimeEvidenceCertificationService(journal: $journal);
        $result = $service->certify();

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('required_evidence_not_all_stale', array_column($result['violations'], 'name'));
        $this->assertGreaterThanOrEqual(1, $result['violation_summary']['by_class']['freshness']);
    }

    public function test_fully_fresh_required_evidence_does_not_block_on_freshness(): void
    {
        $journal = new AgentRuntimeEvidenceJournalRepository;
        foreach (self::requiredTypes() as $type) {
            $journal->append([
                'task_packet_id' => 'task-1',
                'agent_id' => 'agent-1',
                'evidence_type' => $type,
                'evidence_age_seconds' => 0,
            ]);
        }

        $service = new AgentRuntimeEvidenceCertificationService(journal: $journal);
        $result = $service->certify();

        $this->assertSame('available', $result['status']);
        $this->assertNotContains('required_evidence_not_all_stale', array_column($result['violations'], 'name'));
    }

    // ── AC4: actionable repair steps instead of a bare failed boolean ───────────

    public function test_blocked_certification_returns_actionable_repair_steps(): void
    {
        $journal = new AgentRuntimeEvidenceJournalRepository;
        foreach (self::requiredTypes() as $i => $type) {
            $journal->append(['task_packet_id' => 'task-'.$i, 'agent_id' => 'agent-1', 'evidence_type' => $type]);
        }

        $service = new AgentRuntimeEvidenceCertificationService(journal: $journal);
        $result = $service->certify();

        $this->assertArrayHasKey('repair_steps', $result);
        $this->assertNotEmpty($result['repair_steps']);
        $step = $result['repair_steps'][0];
        $this->assertArrayHasKey('invariant', $step);
        $this->assertArrayHasKey('repair_hint', $step);
        $this->assertNotSame('', $step['repair_hint']);
    }

    public function test_available_certification_returns_empty_repair_steps(): void
    {
        $service = new AgentRuntimeEvidenceCertificationService;

        $result = $service->certify();

        $this->assertSame([], $result['repair_steps']);
    }
}
