<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Quaternity\Receipts\AtlasLoopQuaternityCycleReceiptLedger;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Proves the Quaternity cycle receipt ledger: monotonic seq, genesis prev_envelope_hash is 64 hex zeros,
 * tamper-evident chain (mutating an envelope_hash flags the NEXT seq), and the class exposes no public
 * update/delete/truncate path (append-only by reflection).
 */
final class AtlasLoopQuaternityCycleReceiptLedgerTest extends TestCase
{
    private string $ledgerFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerFile = sys_get_temp_dir().'/atlas_quaternity_ledger_'.bin2hex(random_bytes(8)).'.jsonl';
    }

    protected function tearDown(): void
    {
        if (is_file($this->ledgerFile)) {
            @unlink($this->ledgerFile);
        }
        parent::tearDown();
    }

    private function ledger(): AtlasLoopQuaternityCycleReceiptLedger
    {
        return new AtlasLoopQuaternityCycleReceiptLedger($this->ledgerFile);
    }

    /** @return array<string,mixed> */
    private function envelope(string $tag): array
    {
        return [
            'cycle_id' => 'cycle-'.$tag,
            'envelope_hash' => hash('sha256', 'env-'.$tag),
            'signature' => hash('sha256', 'sig-'.$tag),
            'parts' => ['loop' => ['n' => $tag]],
        ];
    }

    public function test_three_appends_return_sequential_seq_and_verify_ok(): void
    {
        $ledger = $this->ledger();
        $this->assertSame(1, $ledger->append($this->envelope('a')));
        $this->assertSame(2, $ledger->append($this->envelope('b')));
        $this->assertSame(3, $ledger->append($this->envelope('c')));

        $rows = $ledger->verify();
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertTrue($row['ok'], 'honest chain must verify');
        }
    }

    public function test_first_line_prev_envelope_hash_is_64_hex_zeros(): void
    {
        $this->ledger()->append($this->envelope('a'));
        $first = (array) json_decode((string) file($this->ledgerFile)[0], true);

        $this->assertSame(str_repeat('0', 64), $first['prev_envelope_hash']);
        $this->assertSame(AtlasLoopQuaternityCycleReceiptLedger::GENESIS_PREV_HASH, $first['prev_envelope_hash']);
    }

    public function test_mutating_line_2_envelope_hash_flags_seq_3(): void
    {
        $ledger = $this->ledger();
        $ledger->append($this->envelope('a'));
        $ledger->append($this->envelope('b'));
        $ledger->append($this->envelope('c'));

        // Mutate line 2's envelope_hash on disk — seq 3 stored the OLD hash as its prev_envelope_hash,
        // so verify() must flag seq 3 as a prev_envelope_hash mismatch.
        $lines = file($this->ledgerFile, FILE_IGNORE_NEW_LINES);
        $line2 = (array) json_decode((string) $lines[1], true);
        $line2['envelope']['envelope_hash'] = str_repeat('f', 64);
        $lines[1] = (string) json_encode($line2, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents($this->ledgerFile, implode("\n", $lines)."\n");

        $rows = $this->ledger()->verify();
        $this->assertTrue($rows[0]['ok']);
        $this->assertTrue($rows[1]['ok'], 'line 2 still self-consistent w/ its own recorded prev');
        $this->assertFalse($rows[2]['ok']);
        $this->assertStringContainsString('prev_envelope_hash', (string) ($rows[2]['reason'] ?? ''));
    }

    public function test_class_exposes_no_public_update_delete_or_truncate_method(): void
    {
        $reflection = new ReflectionClass(AtlasLoopQuaternityCycleReceiptLedger::class);
        $publicMethodNames = array_map(static fn ($m): string => strtolower($m->getName()), $reflection->getMethods(\ReflectionMethod::IS_PUBLIC));

        foreach (['update', 'delete', 'truncate', 'remove', 'edit'] as $banned) {
            $this->assertNotContains($banned, $publicMethodNames, "ledger must not expose public $banned()");
        }
        $this->assertContains('append', $publicMethodNames, 'append is the only writer');
    }
}
