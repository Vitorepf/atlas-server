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

    public function test_duplicate_journal_entry_id_is_reported_deterministically(): void
    {
        $appended = $this->appendEntry('task-dup');
        $journalEntryId = $appended['journal_entry_id'];

        $index = json_decode((string) Storage::disk('local')->get(AgentRuntimeEvidenceJournalRepository::INDEX_PATH), true);
        $index[] = $index[0];
        Storage::disk('local')->put(AgentRuntimeEvidenceJournalRepository::INDEX_PATH, json_encode($index));

        $first = $this->repo->integrityAudit();
        $second = $this->repo->integrityAudit();

        $this->assertSame('drift_detected', $first['status']);
        $codes = array_column($first['issues'], 'issue');
        $this->assertContains('duplicate_journal_entry_id', $codes);
        $this->assertSame($first['issues'], $second['issues']);
    }

    public function test_append_and_list_keep_local_evidence_never_canonical_and_never_ledger_writable(): void
    {
        $appended = $this->appendEntry('task-non-canonical');

        $this->assertFalse($appended['record']['is_canonical_evidence_ledger_entry']);
        $this->assertFalse($appended['ledger_write_allowed']);

        $listed = $this->repo->list(['task_packet_id' => 'task-non-canonical']);
        $this->assertCount(1, $listed);
        $this->assertFalse($listed[0]['is_canonical_evidence_ledger_entry']);
    }

    // ── AC: canonical hash, source_type, freshness_status, duplicate detection ─────────────

    public function test_appended_record_includes_canonical_hash_source_type_and_freshness_status(): void
    {
        $appended = $this->repo->append([
            'task_packet_id' => 'task-meta',
            'agent_id' => 'agent-1',
            'evidence_type' => 'operator_note',
            'evidence_ref' => 'ref-meta',
            'source_type' => 'worker_report',
            'payload' => ['note' => 'x'],
        ]);

        $this->assertSame($appended['record']['evidence_hash'], $appended['record']['canonical_hash']);
        $this->assertSame('worker_report', $appended['record']['source_type']);
        $this->assertSame('fresh', $appended['record']['freshness_status']);
        $this->assertArrayHasKey('recorded_at', $appended['record']);
    }

    public function test_stale_evidence_age_is_reported_as_stale_freshness_status(): void
    {
        $appended = $this->repo->append([
            'task_packet_id' => 'task-stale',
            'agent_id' => 'agent-1',
            'evidence_type' => 'operator_note',
            'evidence_ref' => 'ref-stale',
            'evidence_age_seconds' => AgentRuntimeEvidenceJournalRepository::FRESHNESS_STALE_AFTER_SECONDS + 1,
            'payload' => ['note' => 'x'],
        ]);

        $this->assertSame('stale', $appended['record']['freshness_status']);
    }

    public function test_duplicate_evidence_by_canonical_hash_preserves_strongest_existing_entry(): void
    {
        $weaker = $this->repo->append([
            'task_packet_id' => 'task-dup-strength',
            'agent_id' => 'agent-1',
            'evidence_type' => 'operator_note',
            'evidence_ref' => 'ref-dup',
            'evidence_strength' => 3,
            'payload' => ['note' => 'same'],
        ]);

        $attempt = $this->repo->append([
            'task_packet_id' => 'task-dup-strength',
            'agent_id' => 'agent-1',
            'evidence_type' => 'operator_note',
            'evidence_ref' => 'ref-dup',
            'evidence_strength' => 1,
            'payload' => ['note' => 'same'],
        ]);

        $this->assertSame('duplicate_evidence_strongest_preserved', $attempt['status']);
        $this->assertSame($weaker['journal_entry_id'], $attempt['journal_entry_id']);
        $this->assertSame(3, $attempt['record']['evidence_strength']);

        $listed = $this->repo->list(['task_packet_id' => 'task-dup-strength']);
        $this->assertCount(1, $listed);
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

    // ── AC2: filtering by outcome, proof_class and time window ─────────────────

    public function test_list_filters_by_outcome(): void
    {
        $this->repo->append([
            'task_packet_id' => 'task-outcome-1', 'agent_id' => 'agent-1', 'evidence_type' => 'operator_note',
            'evidence_ref' => 'r1', 'outcome' => 'success', 'payload' => ['note' => 'a'],
        ]);
        $this->repo->append([
            'task_packet_id' => 'task-outcome-2', 'agent_id' => 'agent-1', 'evidence_type' => 'operator_note',
            'evidence_ref' => 'r2', 'outcome' => 'give_back', 'payload' => ['note' => 'b'],
        ]);

        $success = $this->repo->list(['outcome' => 'success']);
        $this->assertCount(1, $success);
        $this->assertSame('task-outcome-1', $success[0]['task_packet_id']);
    }

    public function test_list_filters_by_proof_class(): void
    {
        $this->repo->append([
            'task_packet_id' => 'task-proof-1', 'agent_id' => 'agent-1', 'evidence_type' => 'operator_note',
            'evidence_ref' => 'r1', 'proof_class' => 'tests_or_gates_result', 'payload' => ['note' => 'a'],
        ]);
        $this->repo->append([
            'task_packet_id' => 'task-proof-2', 'agent_id' => 'agent-1', 'evidence_type' => 'operator_note',
            'evidence_ref' => 'r2', 'proof_class' => 'implementation_notes', 'payload' => ['note' => 'b'],
        ]);

        $filtered = $this->repo->list(['proof_class' => 'tests_or_gates_result']);
        $this->assertCount(1, $filtered);
        $this->assertSame('task-proof-1', $filtered[0]['task_packet_id']);
    }

    public function test_list_filters_by_time_window(): void
    {
        $inWindow = $this->appendEntry('task-time-in');
        $recordedAt = $inWindow['record']['recorded_at'];

        $listed = $this->repo->list(['from' => $recordedAt, 'to' => $recordedAt]);
        $this->assertNotEmpty($listed);
        $this->assertContains('task-time-in', array_column($listed, 'task_packet_id'));

        $future = $this->repo->list(['from' => '2999-01-01T00:00:00+00:00']);
        $this->assertSame([], $future);
    }

    public function test_appended_record_defaults_outcome_and_proof_class_when_absent(): void
    {
        $appended = $this->appendEntry('task-default-outcome');

        $this->assertSame('unspecified', $appended['record']['outcome']);
        $this->assertSame('unspecified', $appended['record']['proof_class']);
    }

    // ── AC3: integrity audit — missing hashes, non-provider-safe payload, ordering ──

    public function test_missing_evidence_hash_is_reported(): void
    {
        $appended = $this->appendEntry('task-missing-evidence-hash');
        $path = $this->recordPath($appended['journal_entry_id']);
        $record = json_decode((string) Storage::disk('local')->get($path), true);
        $record['evidence_hash'] = '';
        Storage::disk('local')->put($path, json_encode($record));

        $report = $this->repo->integrityAudit();

        $this->assertSame('drift_detected', $report['status']);
        $this->assertContains('missing_evidence_hash', array_column($report['issues'], 'issue'));
    }

    public function test_missing_journal_entry_hash_is_reported(): void
    {
        $appended = $this->appendEntry('task-missing-journal-hash');
        $path = $this->recordPath($appended['journal_entry_id']);
        $record = json_decode((string) Storage::disk('local')->get($path), true);
        $record['journal_entry_hash'] = '';
        Storage::disk('local')->put($path, json_encode($record));

        $report = $this->repo->integrityAudit();

        $this->assertContains('missing_journal_entry_hash', array_column($report['issues'], 'issue'));
    }

    public function test_secret_looking_summary_is_reported_as_non_provider_safe(): void
    {
        $appended = $this->repo->append([
            'task_packet_id' => 'task-secret-summary', 'agent_id' => 'agent-1', 'evidence_type' => 'operator_note',
            'evidence_ref' => 'r1', 'summary' => 'used sk-abcdefghijklmnopqr to authenticate', 'payload' => ['note' => 'a'],
        ]);

        $report = $this->repo->integrityAudit();

        $this->assertSame('drift_detected', $report['status']);
        $this->assertContains('non_provider_safe_payload_detected', array_column($report['issues'], 'issue'));
    }

    public function test_clean_summary_is_not_reported_as_non_provider_safe(): void
    {
        $this->appendEntry('task-clean-summary');

        $report = $this->repo->integrityAudit();

        $this->assertNotContains('non_provider_safe_payload_detected', array_column($report['issues'], 'issue'));
    }

    public function test_broken_journal_ordering_is_reported_when_sequence_tampered(): void
    {
        $this->appendEntry('task-order-1');
        $second = $this->appendEntry('task-order-2');
        $path = $this->recordPath($second['journal_entry_id']);
        $record = json_decode((string) Storage::disk('local')->get($path), true);
        // Force the newer entry's sequence to be <= the older entry's, simulating tampering.
        $record['sequence'] = 1;
        $record['journal_entry_hash'] = $this->repoStableHashForTest($record);
        Storage::disk('local')->put($path, json_encode($record));

        $report = $this->repo->integrityAudit();

        $this->assertContains('broken_journal_ordering', array_column($report['issues'], 'issue'));
    }

    private function repoStableHashForTest(array $record): string
    {
        unset($record['journal_entry_hash'], $record['recorded_at'], $record['sequence']);
        ksort($record);

        return hash('sha256', (string) json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
