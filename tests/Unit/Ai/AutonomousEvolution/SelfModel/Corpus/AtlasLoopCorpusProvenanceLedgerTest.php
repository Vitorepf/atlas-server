<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\SelfModel\Corpus;

use App\Services\Ai\AutonomousEvolution\SelfModel\Corpus\AtlasLoopCorpusProvenanceLedger;
use PHPUnit\Framework\TestCase;

/**
 * Proves the in-memory corpus provenance ledger: a valid build appends + round-trips in insertion order, a
 * build with no corpus_hash is rejected (appends nothing), and record() returns the stored hash on success.
 */
final class AtlasLoopCorpusProvenanceLedgerTest extends TestCase
{
    private function ledger(): AtlasLoopCorpusProvenanceLedger
    {
        return new AtlasLoopCorpusProvenanceLedger;
    }

    /** @return array<string,mixed> */
    private function build(string $hash, array $deliveryIds = ['d1', 'd2']): array
    {
        return ['corpus_hash' => $hash, 'example_count' => count($deliveryIds), 'delivery_ids' => $deliveryIds, 'window_from' => '2026-06-01', 'window_to' => '2026-06-24'];
    }

    public function test_records_a_valid_build_and_returns_the_hash(): void
    {
        $ledger = $this->ledger();

        $result = $ledger->record($this->build('hash-abc'));

        $this->assertSame('ok', $result['status']);
        $this->assertSame('hash-abc', $result['corpus_hash']);

        $entries = $ledger->entries();
        $this->assertCount(1, $entries);
        $this->assertSame('hash-abc', $entries[0]['corpus_hash']);
        $this->assertSame(['d1', 'd2'], $entries[0]['delivery_ids']);
        $this->assertSame('atlas.loop.corpus_provenance.v1', $entries[0]['schema']);
    }

    public function test_rejects_build_without_corpus_hash_and_appends_nothing(): void
    {
        $ledger = $this->ledger();

        $result = $ledger->record($this->build(''));

        $this->assertSame('rejected', $result['status']);
        $this->assertSame('corpus_hash_missing', $result['reason']);
        $this->assertCount(0, $ledger->entries(), 'a rejected build appends nothing');
    }

    public function test_two_records_preserve_insertion_order(): void
    {
        $ledger = $this->ledger();

        $ledger->record($this->build('hash-1'));
        $ledger->record($this->build('hash-2'));

        $entries = $ledger->entries();
        $this->assertCount(2, $entries);
        $this->assertSame('hash-1', $entries[0]['corpus_hash']);
        $this->assertSame('hash-2', $entries[1]['corpus_hash']);
    }
}
