<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\ProviderNegotiation;

use App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation\AtlasMaestroProviderBidReceiptLedger;
use App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation\LedgerImmutableViolation;
use Tests\TestCase;

final class AtlasMaestroProviderBidReceiptLedgerTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-bid-receipts-'.bin2hex(random_bytes(6));
        @mkdir($this->root, 0o755, true);
        AtlasMaestroProviderBidReceiptLedger::setRootForTesting($this->root);
    }

    protected function tearDown(): void
    {
        AtlasMaestroProviderBidReceiptLedger::setRootForTesting(null);
        foreach ((array) glob($this->root.'/*') as $f) {
            @unlink($f);
        }
        @rmdir($this->root);
        parent::tearDown();
    }

    public function test_append_links_into_hash_chain_and_recall_returns_entry(): void
    {
        $ledger = new AtlasMaestroProviderBidReceiptLedger();
        $entry = $ledger->append(
            taskId: 'task-1',
            envelopeHash: str_repeat('a', 64),
            bidHashes: [str_repeat('b', 64)],
            winnerProviderId: 'codex',
            decisiveCriterion: 'capability',
            criteriaTrace: [['provider_id' => 'gpt', 'eliminated_by' => 'capability', 'cmp' => 1]],
            recordedAtIso: '2026-06-25T12:00:00Z',
        );

        $this->assertSame(str_repeat('0', 64), $entry->prevEntrySha256);
        $this->assertSame(64, strlen($entry->entrySha256));

        $recalled = $ledger->recall('task-1');
        $this->assertNotNull($recalled);
        $this->assertSame('codex', $recalled->winnerProviderId);
    }

    public function test_appending_for_same_task_id_twice_throws_immutable_violation(): void
    {
        $ledger = new AtlasMaestroProviderBidReceiptLedger();
        $ledger->append('task-X', str_repeat('a', 64), [str_repeat('b', 64)], 'codex', 'eligibility', [], '2026-06-25T12:00:00Z');

        $this->expectException(LedgerImmutableViolation::class);
        $ledger->append('task-X', str_repeat('a', 64), [str_repeat('b', 64)], 'gpt', 'eligibility', [], '2026-06-25T12:00:01Z');
    }

    public function test_history_filters_by_provider_and_since(): void
    {
        $ledger = new AtlasMaestroProviderBidReceiptLedger();
        $ledger->append('t-1', str_repeat('a', 64), [], 'codex', 'eligibility', [], '2026-06-20T12:00:00Z');
        $ledger->append('t-2', str_repeat('a', 64), [], 'gpt', 'eligibility', [], '2026-06-22T12:00:00Z');
        $ledger->append('t-3', str_repeat('a', 64), [], 'codex', 'eligibility', [], '2026-06-24T12:00:00Z');

        $rows = $ledger->history('codex');
        $this->assertCount(2, $rows);

        $rowsRecent = $ledger->history('codex', '2026-06-23T00:00:00Z');
        $this->assertCount(1, $rowsRecent);
        $this->assertSame('t-3', $rowsRecent[0]->taskId);
    }

    public function test_subsequent_entry_links_prev_entry_sha_to_previous_one(): void
    {
        $ledger = new AtlasMaestroProviderBidReceiptLedger();
        $first = $ledger->append('a', str_repeat('a', 64), [], 'p1', 'eligibility', [], '2026-06-25T12:00:00Z');
        $second = $ledger->append('b', str_repeat('a', 64), [], 'p1', 'eligibility', [], '2026-06-25T12:00:01Z');

        $this->assertSame($first->entrySha256, $second->prevEntrySha256);
    }

    public function test_selected_and_rejected_bids_are_visible_in_entry(): void
    {
        $ledger = new AtlasMaestroProviderBidReceiptLedger();
        $trace = [
            ['provider_id' => 'worker-b', 'eliminated_by' => 'tier_mismatch', 'cmp' => -1],
            ['provider_id' => 'worker-c', 'eliminated_by' => 'load_saturated', 'cmp' => -1],
        ];
        $entry = $ledger->append('t-sel', str_repeat('a', 64), [str_repeat('b', 64), str_repeat('c', 64)], 'worker-a', 'capability', $trace, '2026-06-30T10:00:00Z');

        $this->assertSame('worker-a', $entry->winnerProviderId);
        $this->assertCount(2, $entry->criteriaTrace);
        $this->assertSame('worker-b', $entry->criteriaTrace[0]['provider_id']);
        $this->assertSame('tier_mismatch', $entry->criteriaTrace[0]['eliminated_by']);
    }

    public function test_outcome_attachment_and_recall(): void
    {
        $ledger = new AtlasMaestroProviderBidReceiptLedger();
        $ledger->append('t-out', str_repeat('a', 64), [], 'codex', 'eligibility', [], '2026-06-30T10:00:00Z');

        $this->assertNull($ledger->recallOutcome('t-out'), 'no outcome before attach');

        $ledger->attachOutcome('t-out', 'success', '2026-06-30T10:05:00Z');
        $this->assertSame('success', $ledger->recallOutcome('t-out'));

        $ledger->attachOutcome('t-other', 'give_back', '2026-06-30T10:06:00Z');
        $this->assertSame('give_back', $ledger->recallOutcome('t-other'));
        $this->assertSame('success', $ledger->recallOutcome('t-out'), 'first outcome unchanged');
    }

    public function test_aggregate_for_worker_counts_wins_and_losses(): void
    {
        $ledger = new AtlasMaestroProviderBidReceiptLedger();
        $rejected = [['provider_id' => 'codex', 'eliminated_by' => 'cost', 'cmp' => -1]];
        $ledger->append('w1', str_repeat('a', 64), [], 'codex', 'capability', [], '2026-06-30T10:00:00Z');
        $ledger->append('w2', str_repeat('a', 64), [], 'codex', 'capability', [], '2026-06-30T10:01:00Z');
        $ledger->append('w3', str_repeat('a', 64), [], 'gpt', 'cost', $rejected, '2026-06-30T10:02:00Z');

        $agg = $ledger->aggregateForWorker('codex');
        $this->assertSame(2, $agg['win_count']);
        $this->assertSame(1, $agg['loss_count']);
        $this->assertSame(3, $agg['total_decisions']);
    }

    public function test_bounded_export_returns_at_most_limit_entries_newest_last(): void
    {
        $ledger = new AtlasMaestroProviderBidReceiptLedger();
        foreach (range(1, 5) as $i) {
            $ledger->append("task-$i", str_repeat('a', 64), [], 'p', 'eligibility', [], "2026-06-30T10:0{$i}:00Z");
        }

        $all = $ledger->export(10);
        $this->assertCount(5, $all);

        $bounded = $ledger->export(3);
        $this->assertCount(3, $bounded);
        $this->assertSame('task-3', $bounded[0]['task_id']);
        $this->assertSame('task-5', $bounded[2]['task_id']);
    }
}
