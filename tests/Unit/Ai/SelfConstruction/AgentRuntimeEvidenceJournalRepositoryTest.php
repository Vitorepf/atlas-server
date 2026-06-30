<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentRuntimeEvidenceJournalRepository;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AgentRuntimeEvidenceJournalRepositoryTest extends TestCase
{
    private AgentRuntimeEvidenceJournalRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->repo = new AgentRuntimeEvidenceJournalRepository;
    }

    private function appendEntry(string $taskPacketId): array
    {
        return $this->repo->append([
            'task_packet_id' => $taskPacketId,
            'agent_id' => 'agent-1',
            'evidence_type' => 'operator_note',
            'evidence_ref' => 'ref-1',
            'payload' => ['note' => 'fixture'],
        ]);
    }

    private function recordPath(string $journalEntryId): string
    {
        return AgentRuntimeEvidenceJournalRepository::STORAGE_PREFIX.'/entries/'.$journalEntryId.'.json';
    }

    public function test_clean_journal_reports_ok(): void
    {
        $this->appendEntry('task-1');
        $this->appendEntry('task-2');

        $report = $this->repo->integrityAudit();

        $this->assertSame('ok', $report['status']);
        $this->assertSame(0, $report['issue_count']);
        $this->assertSame(2, $report['checked_count']);
    }

    public function test_missing_record_file_is_reported(): void
    {
        $appended = $this->appendEntry('task-missing');
        $journalEntryId = $appended['journal_entry_id'];
        Storage::disk('local')->delete($this->recordPath($journalEntryId));

        $report = $this->repo->integrityAudit();

        $this->assertSame('drift_detected', $report['status']);
        $this->assertSame('missing_record_file', $report['issues'][0]['issue']);
        $this->assertSame($journalEntryId, $report['issues'][0]['journal_entry_id']);
    }

    public function test_corrupt_record_json_is_reported(): void
    {
        $appended = $this->appendEntry('task-corrupt');
        $journalEntryId = $appended['journal_entry_id'];
        Storage::disk('local')->put($this->recordPath($journalEntryId), '{not valid json');

        $report = $this->repo->integrityAudit();

        $this->assertSame('drift_detected', $report['status']);
        $this->assertSame('corrupt_record_json', $report['issues'][0]['issue']);
    }

    public function test_journal_entry_hash_mismatch_is_reported(): void
    {
        $appended = $this->appendEntry('task-hash-mismatch');
        $journalEntryId = $appended['journal_entry_id'];
        $path = $this->recordPath($journalEntryId);
        $record = json_decode((string) Storage::disk('local')->get($path), true);
        $record['journal_entry_hash'] = 'tampered-hash';
        Storage::disk('local')->put($path, json_encode($record));

        $report = $this->repo->integrityAudit();

        $this->assertSame('drift_detected', $report['status']);
        $codes = array_column($report['issues'], 'issue');
        $this->assertContains('journal_entry_hash_mismatch', $codes);
    }

    public function test_fake_canonical_ledger_entry_is_reported(): void
    {
        $appended = $this->appendEntry('task-fake-canonical');
        $journalEntryId = $appended['journal_entry_id'];
        $path = $this->recordPath($journalEntryId);
        $record = json_decode((string) Storage::disk('local')->get($path), true);
        $record['is_canonical_evidence_ledger_entry'] = true;
        Storage::disk('local')->put($path, json_encode($record));

        $report = $this->repo->integrityAudit();

        $this->assertSame('drift_detected', $report['status']);
        $codes = array_column($report['issues'], 'issue');
        $this->assertContains('non_local_evidence_claims_canonical_ledger_entry', $codes);
    }

    public function test_audit_does_not_mutate_records_or_index_and_is_idempotent(): void
    {
        $this->appendEntry('task-readonly');
        $indexBefore = (string) Storage::disk('local')->get(AgentRuntimeEvidenceJournalRepository::INDEX_PATH);

        $first = $this->repo->integrityAudit();
        $second = $this->repo->integrityAudit();

        $indexAfter = (string) Storage::disk('local')->get(AgentRuntimeEvidenceJournalRepository::INDEX_PATH);

        $this->assertSame($indexBefore, $indexAfter);
        $this->assertSame($first['status'], $second['status']);
        $this->assertSame($first['issue_count'], $second['issue_count']);
        $this->assertSame($first['checked_count'], $second['checked_count']);

        foreach (['runtime_execution_allowed', 'dispatch_allowed', 'provider_call_allowed', 'token_spend_allowed', 'self_programming_allowed', 'ledger_write_allowed', 'completion_claim_allowed'] as $flag) {
            $this->assertFalse($first[$flag]);
        }
    }
}
