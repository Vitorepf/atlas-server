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
}
