<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Autopoiesis\ScopeOrigination\AppendOnlyViolationException;
use App\Services\Ai\AutonomousEvolution\Autopoiesis\ScopeOrigination\AtlasLoopScopeOriginationReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Autopoiesis\ScopeOrigination\ScopeOriginationVerdict;
use PHPUnit\Framework\TestCase;

final class AtlasLoopScopeOriginationReceiptLedgerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/atlas-scope-origination-ledger-'.bin2hex(random_bytes(5));
        mkdir($this->tmpDir, 0775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            exec('rm -rf '.escapeshellarg($this->tmpDir));
        }

        parent::tearDown();
    }

    public function test_record_appends_one_json_line_and_preserves_prior_bytes(): void
    {
        $ledger = $this->ledger('proposal-a', '2026-06-24T12:00:00Z');
        $first = $ledger->record(ScopeOriginationVerdict::operatorApproved('reviewed', '2026-06-24T12:00:00Z'));
        $oldBytes = (string) file_get_contents($ledger->path());
        $oldSize = strlen($oldBytes);

        $second = $ledger->record(ScopeOriginationVerdict::operatorRejected('later_rejected', '2026-06-24T12:01:00Z'));
        $newBytes = (string) file_get_contents($ledger->path());

        $this->assertNotSame((string) $first, (string) $second);
        $this->assertSame($oldBytes, substr($newBytes, 0, $oldSize));
        $this->assertCount(2, array_values(array_filter(explode("\n", trim($newBytes)))));
    }

    public function test_mutation_path_raises_append_only_violation_and_reopened_instance_reads_in_order(): void
    {
        $ledger = $this->ledger('proposal-b', '2026-06-24T12:00:00Z');
        $ledger->record(ScopeOriginationVerdict::operatorApproved('first', '2026-06-24T12:00:00Z'));
        $ledger->record(ScopeOriginationVerdict::operatorRejected('second', '2026-06-24T12:01:00Z'));

        $reopened = $this->ledger('proposal-b', '2026-06-24T12:02:00Z');
        $this->assertSame(['first', 'second'], array_map(
            static fn ($receipt): string => $receipt->reason,
            $reopened->list(),
        ));
        $this->assertSame('second', $reopened->latest()?->reason);

        $this->expectException(AppendOnlyViolationException::class);
        $reopened->delete('anything');
    }

    public function test_get_by_proposal_hash_returns_null_for_absent_records(): void
    {
        $ledger = $this->ledger('proposal-c', '2026-06-24T12:00:00Z');
        $ledger->record(ScopeOriginationVerdict::operatorApproved('present', '2026-06-24T12:00:00Z'));

        $this->assertNotNull($ledger->getByProposalHash('proposal-c'));
        $this->assertNull($ledger->getByProposalHash('missing-proposal'));
    }

    public function test_receipt_id_is_bound_to_the_resolved_timestamp_not_to_null(): void
    {
        $fallback = '2026-06-24T12:00:00Z';
        $ledger = $this->ledger('proposal-ts', $fallback);

        // pending() carries null timestamp — must be resolved to the ledger fallback
        $verdict = ScopeOriginationVerdict::pending('operator_required');
        $receiptId = (string) $ledger->record($verdict);

        // Recompute expected receiptId with the resolved (fallback) timestamp
        $verdictArray = $verdict->toArray();
        $verdictArray['timestamp'] = $fallback;
        $expected = 'scope-origin:'.substr(hash('sha256', json_encode([
            'snapshot_hash' => 'snapshot-abc',
            'proposal_hash' => 'proposal-ts',
            'verdict' => $verdictArray,
            'fact_refs' => [
                ['fact_id' => 'ctx-1', 'source' => 'cortex_meaning', 'snapshot_hash' => 'snapshot-abc'],
                ['fact_id' => 'loop-1', 'source' => 'loop_telemetry', 'snapshot_hash' => 'snapshot-abc'],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 24);

        $this->assertSame($expected, $receiptId);

        // The persisted receipt must also carry the resolved timestamp, not null
        $stored = $ledger->latest();
        $this->assertNotNull($stored);
        $this->assertSame($fallback, $stored->timestamp);
    }

    private function ledger(string $proposalHash, string $timestamp): AtlasLoopScopeOriginationReceiptLedger
    {
        return new AtlasLoopScopeOriginationReceiptLedger(
            path: $this->tmpDir.'/receipts.jsonl',
            snapshotHash: 'snapshot-abc',
            proposalHash: $proposalHash,
            factRefs: [
                ['fact_id' => 'ctx-1', 'source' => 'cortex_meaning', 'snapshot_hash' => 'snapshot-abc'],
                ['fact_id' => 'loop-1', 'source' => 'loop_telemetry', 'snapshot_hash' => 'snapshot-abc'],
            ],
            timestamp: $timestamp,
        );
    }
}
