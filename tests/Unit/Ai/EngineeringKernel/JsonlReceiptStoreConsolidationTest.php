<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\AutonomousEvolution\AtlasLoopModelFloorReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Simulation\AtlasLoopSimulationReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Simulation\DryRunReceipt;
use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use App\Services\Ai\EngineeringKernel\ReceiptLedger;
use Tests\TestCase;

/**
 * Consolidation proof for the kernel JSONL line engine (fable-eng-r1). Covers the store contract
 * plus one parity case per MIGRATED ledger — the persisted line written through each ledger's
 * unchanged public API must decode to the same payload keys it wrote before the migration.
 *
 * Kept-as-is (semantics exceed append-only line IO, documented per the task's honesty clause):
 *   - AtlasLoopAnomalyReceiptLedger        — in-memory fact ledger, no file IO to absorb.
 *   - AtlasLoopSelfIntrospectionReceiptLedger — per-file sha-keyed JSON receipts, not JSONL lines.
 *   - AtlasLoopCycleReentryReceiptLedger   — atomic full-file rewrite with torn-tail repair.
 */
final class JsonlReceiptStoreConsolidationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/jsonl-store-test-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir.'/*') as $f) {
            if (is_string($f) && is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_store_implements_kernel_receipt_ledger_contract(): void
    {
        $store = new JsonlReceiptStore($this->dir.'/contract.jsonl');

        $this->assertInstanceOf(ReceiptLedger::class, $store);

        $first = $store->append(['k' => 'v1']);
        $store->append(['k' => 'v2']);

        $this->assertSame($this->dir.'/contract.jsonl', $first['path']);
        $this->assertSame(['k' => 'v1'], json_decode($first['line'], true));

        $replayed = $store->replay();
        $this->assertSame([['k' => 'v1'], ['k' => 'v2']], $replayed, 'replay is oldest-first');
    }

    public function test_store_creates_missing_directories_and_appendwith_sees_the_tail(): void
    {
        $store = new JsonlReceiptStore($this->dir.'/nested/deep/chain.jsonl');

        $seen = [];
        $store->appendWith(function (?string $last) use (&$seen): array {
            $seen[] = $last;

            return ['n' => 1];
        });
        $store->appendWith(function (?string $last) use (&$seen): array {
            $seen[] = $last;

            return ['n' => 2];
        });

        $this->assertNull($seen[0], 'empty ledger hands null to the builder');
        $this->assertSame(['n' => 1], json_decode((string) $seen[1], true), 'second append sees the first line under the lock');
        $this->assertCount(2, $store->rawLines());
    }

    public function test_simulation_ledger_parity_after_migration(): void
    {
        $path = $this->dir.'/simulation.jsonl';
        $ledger = new AtlasLoopSimulationReceiptLedger($path);
        $receipt = new DryRunReceipt(0, 'ok', [], [], 0, 'green', '2026-07-01T00:00:00Z', '2026-07-01T00:00:05Z');

        $idA = $ledger->append($receipt);
        $idB = $ledger->append($receipt);

        $lines = array_values((array) file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $this->assertCount(2, $lines);

        $firstRow = json_decode($lines[0], true);
        $this->assertSame(
            ['prev_line_sha256', 'receipt', 'receipt_id', 'recorded_at', 'schema'],
            array_keys($firstRow),
            'pre-migration payload keys (ksort order) preserved',
        );
        $this->assertSame(AtlasLoopSimulationReceiptLedger::SCHEMA, $firstRow['schema']);
        $this->assertSame($idA, $firstRow['receipt_id']);
        $this->assertSame(str_repeat('0', 64), $firstRow['prev_line_sha256'], 'genesis prev hash preserved');

        $secondRow = json_decode($lines[1], true);
        $this->assertSame(hash('sha256', $lines[0]), $secondRow['prev_line_sha256'], 'hash chain preserved');
        $this->assertSame($idB, $secondRow['receipt_id']);

        $history = $ledger->history();
        $this->assertCount(2, $history);
        $this->assertTrue($history[0]['chain_valid'] && $history[1]['chain_valid'], 'chain verification still green');
        $this->assertSame($idB, $history[0]['receipt_id'], 'history stays newest-first');
    }

    public function test_model_floor_ledger_parity_after_migration(): void
    {
        $path = $this->dir.'/floor.jsonl';
        $ledger = new AtlasLoopModelFloorReceiptLedger($path);

        $first = $ledger->append(['master_enabled' => true, 'anti_farm_verdict' => 'pass']);
        $second = $ledger->append(['master_enabled' => true, 'anti_farm_verdict' => 'pass']);

        $this->assertSame('', $first['prev_sha256'], 'genesis prev preserved');
        $this->assertSame($first['sha256'], $second['prev_sha256'], 'hash chain links through the store');
        $this->assertSame(AtlasLoopModelFloorReceiptLedger::SCHEMA, $first['schema']);
        $this->assertNull($first['frozen_bundle_hash'], 'absent fields still encoded as null, never omitted');

        $this->assertSame('ok', $ledger->verifyChain(), 'tamper chain verification still green');
        $this->assertCount(2, $ledger->entries());

        $head = json_decode((string) file_get_contents($path.'.head'), true);
        $this->assertSame(2, $head['count'], 'head sidecar still tracks the tail');
        $this->assertSame($second['sha256'], $head['head_sha256']);

        file_put_contents($path, str_replace('pass', 'FAKE', (string) file_get_contents($path)));
        $this->assertStringStartsWith('broken_at_line_', $ledger->verifyChain(), 'tampering still detected');
    }
}
