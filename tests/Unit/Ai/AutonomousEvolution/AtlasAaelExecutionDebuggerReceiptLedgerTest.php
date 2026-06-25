<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Aael\Execution\Debugger\AtlasAaelExecutionDebuggerReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Aael\Execution\Debugger\DebuggerReceiptEntry;
use App\Services\Ai\AutonomousEvolution\Aael\Execution\Debugger\HashChainBrokenException;
use Tests\TestCase;

final class AtlasAaelExecutionDebuggerReceiptLedgerTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-dbgr-'.bin2hex(random_bytes(6));
        @mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->root.'/*') as $f) {
            @unlink((string) $f);
        }
        @rmdir($this->root);
        parent::tearDown();
    }

    public function test_chain_is_hash_linked_and_replay_iterates_all(): void
    {
        $l = new AtlasAaelExecutionDebuggerReceiptLedger($this->root);
        $events = [
            ['pause_armed', []],
            ['pause_hit', []],
            ['inspect_pre', ['snapshot_digest_pre' => str_repeat('a', 64)]],
            ['step_advanced', ['operator_action' => 'step']],
            ['resumed', []],
        ];
        $ts = '2026-06-25T05:00:00Z';
        foreach ($events as [$ev, $extra]) {
            $l->append('R1', 0, $ev, $ts, $extra);
        }

        $rows = $l->replay('R1');
        $this->assertCount(5, $rows);

        // Verify hash-linkage manually.
        $expected = AtlasAaelExecutionDebuggerReceiptLedger::GENESIS_PREV_HASH;
        $path = $this->root.'/R1.receipts.jsonl';
        foreach ((array) file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $decoded = json_decode((string) $line, true);
            $this->assertSame($expected, $decoded['prev_entry_sha256']);
            $expected = hash('sha256', (string) $line);
        }
    }

    public function test_tampering_a_middle_entry_makes_replay_throw(): void
    {
        $l = new AtlasAaelExecutionDebuggerReceiptLedger($this->root);
        $ts = '2026-06-25T05:00:00Z';
        $l->append('R2', 0, 'pause_armed', $ts);
        $l->append('R2', 0, 'pause_hit', $ts);
        $l->append('R2', 1, 'step_advanced', $ts, ['operator_action' => 'step']);

        $path = $this->root.'/R2.receipts.jsonl';
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        // Tamper the middle entry's step_index (1 byte change keeps JSON valid).
        $lines[1] = str_replace('"step_index":0', '"step_index":9', $lines[1]);
        file_put_contents($path, implode("\n", $lines)."\n");

        $this->expectException(HashChainBrokenException::class);
        $l->replay('R2');
    }

    public function test_no_forbidden_keys_in_persisted_entries(): void
    {
        $l = new AtlasAaelExecutionDebuggerReceiptLedger($this->root);
        $l->append('R3', 0, 'pause_armed', '2026-06-25T05:00:00Z');
        $l->append('R3', 0, 'inspect_pre', '2026-06-25T05:00:01Z', ['snapshot_digest_pre' => 'abcdef']);

        $path = $this->root.'/R3.receipts.jsonl';
        foreach ((array) file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $decoded = json_decode((string) $line, true);
            foreach (DebuggerReceiptEntry::FORBIDDEN_KEYS as $banned) {
                $this->assertArrayNotHasKey($banned, $decoded);
            }
        }
    }

    public function test_concurrent_appends_via_two_ledger_instances_produce_well_formed_chain(): void
    {
        $a = new AtlasAaelExecutionDebuggerReceiptLedger($this->root);
        $b = new AtlasAaelExecutionDebuggerReceiptLedger($this->root);
        $ts = '2026-06-25T05:00:00Z';
        // Interleaved appends — each open obtains LOCK_EX, second waits.
        $a->append('R4', 0, 'pause_armed', $ts);
        $b->append('R4', 0, 'pause_hit', $ts);
        $a->append('R4', 0, 'inspect_pre', $ts, ['snapshot_digest_pre' => 'h1']);
        $b->append('R4', 1, 'step_advanced', $ts, ['operator_action' => 'step']);
        $a->append('R4', 1, 'resumed', $ts);

        $rows = $a->replay('R4');
        $this->assertCount(5, $rows);

        // Confirm no interleaved partial lines: every line decodes to an array.
        $path = $this->root.'/R4.receipts.jsonl';
        foreach ((array) file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $this->assertIsArray(json_decode((string) $line, true));
        }
    }
}
