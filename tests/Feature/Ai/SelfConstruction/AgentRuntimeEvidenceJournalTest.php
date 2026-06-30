<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentRuntimeEvidenceCertificationService;
use App\Services\Ai\SelfConstruction\AgentRuntimeEvidenceContinuityIndexer;
use App\Services\Ai\SelfConstruction\AgentRuntimeEvidenceJournalRepository;
use App\Services\Ai\SelfConstruction\AgentRuntimeEvidenceReceiptBuilder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AgentRuntimeEvidenceJournalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_journal_appends_and_lists_local_dry_run_evidence(): void
    {
        $repo = new AgentRuntimeEvidenceJournalRepository;
        $result = $repo->append($this->entry(['evidence_type' => 'validation_result']));

        $this->assertSame('journal_entry_recorded', $result['status']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['journal_entry_hash']);
        $this->assertFalse($result['record']['is_canonical_evidence_ledger_entry']);
        $this->assertFalse($result['record']['dispatch_allowed']);
        $this->assertFalse($result['record']['ledger_write_allowed']);

        $list = $repo->list(['evidence_type' => 'validation_result']);
        $this->assertCount(1, $list);
        $this->assertSame('task-1', $list[0]['task_packet_id']);
    }

    public function test_journal_blocks_missing_required_fields_and_unknown_types(): void
    {
        $repo = new AgentRuntimeEvidenceJournalRepository;

        $missingTask = $repo->append($this->entry(['task_packet_id' => '']));
        $this->assertSame('blocked', $missingTask['status']);
        $this->assertSame(['task_packet_id_missing'], $missingTask['blocking_reasons']);

        $unknownType = $repo->append($this->entry(['evidence_type' => 'free_form_chat']));
        $this->assertSame('blocked', $unknownType['status']);
        $this->assertSame(['evidence_type_not_allowed'], $unknownType['blocking_reasons']);
    }

    public function test_receipt_builder_is_stable_and_not_a_ledger_receipt(): void
    {
        $repo = new AgentRuntimeEvidenceJournalRepository;
        $record = $repo->append($this->entry(), ['journal_entry_id' => 'entry-stable'])['record'];
        $builder = new AgentRuntimeEvidenceReceiptBuilder;

        $a = $builder->build($record);
        $b = $builder->build($record);

        $this->assertSame('receipt_ready', $a['status']);
        $this->assertSame($a['receipt_hash'], $b['receipt_hash']);
        $this->assertFalse($a['is_canonical_evidence_ledger_entry']);
        $this->assertFalse($a['is_signed_dispatch_receipt']);
        $this->assertFalse($a['provider_call_allowed']);
    }

    public function test_continuity_index_detects_complete_and_incomplete_sets(): void
    {
        $indexer = new AgentRuntimeEvidenceContinuityIndexer;
        $complete = $indexer->build($this->requiredEntries());
        $incomplete = $indexer->build(array_slice($this->requiredEntries(), 0, 2));

        $this->assertSame('continuity_index_complete', $complete['status']);
        $this->assertSame([], $complete['missing_required_evidence_types']);
        $this->assertSame('continuity_index_incomplete', $incomplete['status']);
        $this->assertContains('validation_result', $incomplete['missing_required_evidence_types']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $complete['continuity_index_hash']);
        $this->assertTrue($complete['runtime_safety']['runtime_safety_all_false']);
    }

    public function test_certification_service_available_and_runtime_safe(): void
    {
        $result = (new AgentRuntimeEvidenceCertificationService)->certify();

        $this->assertSame('available', $result['status']);
        $this->assertTrue($result['invariants_all_true']);
        $this->assertSame(0, $result['violation_count']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['ledger_write_allowed']);
        $this->assertTrue($result['runtime_safety']['runtime_safety_all_false']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['certification_hash']);
    }

    public function test_command_exposes_runtime_evidence_journal_quartet(): void
    {
        foreach ([
            '--agent-control-plane-runtime-evidence-journal-contract' => 'atlas.self_construction_agent_control_plane_runtime_evidence_journal_contract.v1',
            '--agent-control-plane-runtime-evidence-journal-preflight' => 'atlas.self_construction_agent_control_plane_runtime_evidence_journal_preflight.v1',
            '--agent-control-plane-runtime-evidence-journal-implementation-packet' => 'atlas.self_construction_agent_control_plane_runtime_evidence_journal_implementation_packet.v1',
            '--agent-control-plane-runtime-evidence-journal-status' => 'atlas.self_construction_agent_control_plane_runtime_evidence_journal_status.v1',
        ] as $flag => $schema) {
            $exit = Artisan::call('atlas:ai:self-construction', [$flag => true, '--json' => true]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $exit);
            $this->assertSame($schema, $payload['schema_version']);
            $this->assertFalse($payload['execution_allowed']);
            $this->assertFalse($payload['dispatch_allowed']);
            $this->assertFalse($payload['ledger_write_allowed']);
            $this->assertFalse($payload['runtime_write_allowed']);
        }
    }

    public function test_summary_latest_hash_is_newest_not_oldest_entry(): void
    {
        $repo = new AgentRuntimeEvidenceJournalRepository;

        $first  = $repo->append($this->entry(['task_packet_id' => 'task-first',  'evidence_type' => 'scope_lock']));
        $second = $repo->append($this->entry(['task_packet_id' => 'task-second', 'evidence_type' => 'validation_result']));

        $summary = $repo->summary();

        // The journal index is newest-first (array_unshift), so index 0 = $second (newest).
        // Before the fix, latest_journal_entry_hash read $records[count-1] = $first (oldest) — wrong.
        $this->assertSame(
            $second['journal_entry_hash'],
            $summary['latest_journal_entry_hash'],
            'latest_journal_entry_hash must be the newest (last-appended) entry, not the oldest',
        );
        $this->assertNotSame(
            $first['journal_entry_hash'],
            $summary['latest_journal_entry_hash'],
            'latest_journal_entry_hash must NOT be the first (oldest) entry',
        );
        // The journal_summary_hash must embed the corrected latest hash.
        $this->assertNotEmpty($summary['journal_summary_hash']);
    }

    /** @return array<string, mixed> */
    private function entry(array $override = []): array
    {
        return array_merge([
            'task_packet_id' => 'task-1',
            'task_packet_hash' => hash('sha256', 'task-1'),
            'agent_id' => 'agent-1',
            'run_id' => 'run-1',
            'lease_id' => 'lease-1',
            'evidence_type' => 'scope_lock',
            'evidence_ref' => 'local://scope-lock',
            'evidence_hash' => hash('sha256', 'evidence'),
            'summary' => 'local dry-run evidence',
            'payload' => ['ok' => true],
        ], $override);
    }

    /** @return list<array<string, mixed>> */
    private function requiredEntries(): array
    {
        return array_map(
            fn (string $type): array => $this->entry([
                'evidence_type' => $type,
                'journal_entry_id' => 'entry-'.$type,
                'journal_entry_hash' => hash('sha256', 'entry-'.$type),
            ]),
            AgentRuntimeEvidenceContinuityIndexer::REQUIRED_TYPES,
        );
    }
}
