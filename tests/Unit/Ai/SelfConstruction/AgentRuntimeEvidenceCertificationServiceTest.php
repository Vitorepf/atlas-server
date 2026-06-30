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
}
