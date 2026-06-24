<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Autopoiesis\SelfExtension\AtlasLoopAutopoieticBootstrapReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Autopoiesis\SelfExtension\LedgerSequenceViolation;
use Tests\TestCase;

final class AtlasLoopAutopoieticBootstrapReceiptLedgerTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-bootstrap-receipts-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function ledger(): AtlasLoopAutopoieticBootstrapReceiptLedger
    {
        return new AtlasLoopAutopoieticBootstrapReceiptLedger($this->path);
    }

    private function fields(int $seq): array
    {
        return [
            'atomic_seq' => $seq,
            'scope_id' => 'scope-'.$seq,
            'manifest_sha256' => hash('sha256', 'manifest-'.$seq),
            'verifier_ok' => true,
            'verifier_violations' => [],
            'operator_intent_digest' => hash('sha256', 'operator-intent-'.$seq),
        ];
    }

    public function test_append_three_then_verify_chain_is_intact(): void
    {
        $ledger = $this->ledger();
        $ledger->append($this->fields(1));
        $ledger->append($this->fields(2));
        $ledger->append($this->fields(3));

        $report = $ledger->verifyChain();
        $this->assertTrue($report['ok']);
        $this->assertSame(3, $report['chain_length']);
        $this->assertNull($report['first_break_at']);
    }

    public function test_mutated_byte_breaks_chain_at_that_line_index(): void
    {
        $ledger = $this->ledger();
        $ledger->append($this->fields(1));
        $ledger->append($this->fields(2));
        $ledger->append($this->fields(3));

        // Corrupt the SECOND line (index 1) by flipping a byte in its JSON.
        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines[1] = str_replace('scope-2', 'scope-X', $lines[1]);
        file_put_contents($this->path, implode("\n", $lines)."\n");

        $report = $ledger->verifyChain();
        $this->assertFalse($report['ok']);
        $this->assertSame(1, $report['first_break_at']);
    }

    public function test_rewound_sequence_raises_and_writes_nothing(): void
    {
        $ledger = $this->ledger();
        $ledger->append($this->fields(1));
        $ledger->append($this->fields(2));
        $sizeBefore = filesize($this->path);

        try {
            $ledger->append($this->fields(2)); // seq 2 <= last (2) => rewind
            $this->fail('expected LedgerSequenceViolation');
        } catch (LedgerSequenceViolation) {
            // expected
        }

        clearstatcache(true, $this->path);
        $this->assertSame($sizeBefore, filesize($this->path), 'a sequence violation must write nothing');
        $this->assertTrue($ledger->verifyChain()['ok']);
    }

    public function test_this_receipt_hash_equals_sha256_of_prev_plus_canonical_payload(): void
    {
        $ledger = $this->ledger();
        $first = $ledger->append($this->fields(1));
        $second = $ledger->append($this->fields(2));

        // First receipt chains off GENESIS.
        $payload1 = $first;
        unset($payload1['this_receipt_hash']);
        $this->assertSame(
            $ledger->chainHash(AtlasLoopAutopoieticBootstrapReceiptLedger::GENESIS_HASH, $payload1),
            $first['this_receipt_hash'],
        );

        // Second chains off the first's hash.
        $payload2 = $second;
        unset($payload2['this_receipt_hash']);
        $this->assertSame($first['this_receipt_hash'], $second['prev_receipt_hash']);
        $this->assertSame(
            $ledger->chainHash($first['this_receipt_hash'], $payload2),
            $second['this_receipt_hash'],
        );
    }
}
