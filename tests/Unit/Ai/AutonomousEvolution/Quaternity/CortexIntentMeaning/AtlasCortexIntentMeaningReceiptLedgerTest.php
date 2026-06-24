<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Quaternity\CortexIntentMeaning;

use App\Services\Ai\AutonomousEvolution\Quaternity\CortexIntentMeaning\AtlasCortexIntentMeaningReceiptLedger;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class AtlasCortexIntentMeaningReceiptLedgerTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-cim-receipts-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function ledger(): AtlasCortexIntentMeaningReceiptLedger
    {
        $ledger = new AtlasCortexIntentMeaningReceiptLedger($this->path);
        $ledger->setClock(fn () => CarbonImmutable::parse('2026-06-24T12:00:00+00:00'));

        return $ledger;
    }

    private function facts(): array
    {
        return [['intent' => 'fix', 'symbol' => 'Alpha', 'file' => 'a.php', 'evidence' => 'def', 'matched_token' => 'Alpha']];
    }

    private function verdict(): array
    {
        return ['site_count' => 1, 'ambiguous' => false, 'clarification_required' => false, 'reason' => 'unique_grounded_site', 'distinct_symbols' => ['Alpha'], 'distinct_files' => ['a.php']];
    }

    public function test_record_is_append_only(): void
    {
        $ledger = $this->ledger();
        $ledger->record('intent one', $this->facts(), $this->verdict());
        $ledger->record('intent two', $this->facts(), $this->verdict());

        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(2, $lines, 'two records => two appended lines');

        $rows = $ledger->list(50);
        $this->assertCount(2, $rows);
        $this->assertSame('intent one', $rows[0]['intent']);
        $this->assertSame('intent two', $rows[1]['intent']);
    }

    public function test_receipt_is_byte_stable_under_fixed_clock(): void
    {
        $a = $this->ledger();
        $a->record('same intent', $this->facts(), $this->verdict());
        $b = new AtlasCortexIntentMeaningReceiptLedger($this->path);
        $b->setClock(fn () => CarbonImmutable::parse('2026-06-24T12:00:00+00:00'));
        $b->record('same intent', $this->facts(), $this->verdict());

        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(2, $lines);
        $this->assertSame($lines[0], $lines[1], 'equal inputs + fixed clock => byte-identical receipts');
    }

    public function test_list_skips_a_corrupted_middle_line_without_crashing(): void
    {
        $ledger = $this->ledger();
        $ledger->record('first', $this->facts(), $this->verdict());
        // Inject a corrupted middle line directly.
        file_put_contents($this->path, "{ this is not json\n", FILE_APPEND);
        $ledger->record('third', $this->facts(), $this->verdict());

        $rows = $ledger->list(50);
        $this->assertCount(2, $rows, 'the malformed middle line is skipped, the valid ones survive');
        $this->assertSame('first', $rows[0]['intent']);
        $this->assertSame('third', $rows[1]['intent']);
    }

    public function test_fact_digest_is_stable_sha1_of_canonical_facts(): void
    {
        $ledger = $this->ledger();
        $r1 = $ledger->record('intent', $this->facts(), $this->verdict());
        $r2 = $ledger->record('intent', $this->facts(), $this->verdict());

        $this->assertSame($r1['fact_digest'], $r2['fact_digest'], 'content-addressable, not a random nonce');
        $this->assertSame(40, strlen($r1['fact_digest']), 'sha1 hex length');
    }
}
