<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\MemoryIntegration\AtlasCortexMemoryReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Quaternity\CortexIntentMeaning\AtlasCortexIntentMeaningReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Quaternity\IntentIngest\AtlasLoopOperatorIntentReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Quaternity\LoopIntentDrift\AtlasLoopIntentDriftReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Receipts\AtlasLoopCycleReceiptLedger;
use App\Services\Ai\AutonomousEvolution\SymbolicAnchoring\PerPhase\AtlasLoopAnchorGatePerPhaseReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Trinity\AntiDecoupling\AtlasLoopTrinityContractReceiptLedger;
use Tests\TestCase;

/**
 * Characterization fixture: every receipt-ledger reader tolerates a corrupted JSON line, a
 * whitespace-only line, an empty line and a literal `null` line by SKIPPING them — never throwing,
 * never emitting a partial row. Frozen BEFORE delegating the read paths to the shared JSONL stores
 * so the delegation is provably behavior-identical.
 */
final class ReceiptLedgerCorruptLineToleranceTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/atlas-ledger-corrupt-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /**
     * @param  array<string,mixed>  $first
     * @param  array<string,mixed>  $second
     */
    private function seedFixture(string $path, array $first, array $second): void
    {
        $lines = [
            json_encode($first, JSON_UNESCAPED_SLASHES),
            '{"broken":',      // corrupted JSON
            '   ',             // whitespace-only line
            '',                // empty line
            'null',            // decodes, but not an array
            json_encode($second, JSON_UNESCAPED_SLASHES),
        ];
        file_put_contents($path, implode("\n", $lines)."\n");
    }

    public function test_cycle_receipt_ledger_all_skips_bad_lines(): void
    {
        $path = $this->dir.'/cycle.jsonl';
        $this->seedFixture($path, ['seq' => 1, 'chain_hash' => 'a'], ['seq' => 2, 'chain_hash' => 'b']);

        $rows = iterator_to_array((new AtlasLoopCycleReceiptLedger(path: $path))->all(), false);

        $this->assertSame([['seq' => 1, 'chain_hash' => 'a'], ['seq' => 2, 'chain_hash' => 'b']], $rows);
    }

    public function test_anchor_gate_per_phase_reader_skips_bad_lines(): void
    {
        $this->seedFixture($this->dir.'/2026-07-06.jsonl',
            ['loop_cycle_id' => 'c1', 'phase' => 'p1'],
            ['loop_cycle_id' => 'c1', 'phase' => 'p2'],
        );

        $ledger = new AtlasLoopAnchorGatePerPhaseReceiptLedger($this->dir);

        $this->assertSame(
            [['loop_cycle_id' => 'c1', 'phase' => 'p1'], ['loop_cycle_id' => 'c1', 'phase' => 'p2']],
            $ledger->recentForCycle('c1'),
        );
    }

    public function test_trinity_contract_replay_skips_bad_lines(): void
    {
        $this->seedFixture($this->dir.'/2026-07-06.ndjson',
            ['seq' => 1, 'kind' => 'audit'],
            ['seq' => 2, 'kind' => 'drift'],
        );

        $rows = (new AtlasLoopTrinityContractReceiptLedger($this->dir))->replay();

        $this->assertSame([['seq' => 1, 'kind' => 'audit'], ['seq' => 2, 'kind' => 'drift']], $rows);
    }

    public function test_cortex_memory_all_skips_bad_lines(): void
    {
        $path = $this->dir.'/cortex-memory.jsonl';
        $this->seedFixture($path,
            ['timestamp' => 't1', 'action' => 'a1', 'memoryEntryId' => 'm1', 'groundingStatus' => 'g', 'approvalTokenHash' => null, 'outcome' => 'ok'],
            ['timestamp' => 't2', 'action' => 'a2', 'memoryEntryId' => 'm2', 'groundingStatus' => 'g', 'approvalTokenHash' => 'h', 'outcome' => 'ok'],
        );

        $rows = (new AtlasCortexMemoryReceiptLedger($path))->all();

        $this->assertCount(2, $rows);
        $this->assertSame(['m1', 'm2'], array_column($rows, 'memoryEntryId'));
        $this->assertSame([null, 'h'], array_column($rows, 'approvalTokenHash'));
    }

    public function test_cortex_intent_meaning_list_skips_bad_lines(): void
    {
        $path = $this->dir.'/intent-meaning.jsonl';
        $this->seedFixture($path, ['intent' => 'i1'], ['intent' => 'i2']);

        $rows = (new AtlasCortexIntentMeaningReceiptLedger($path))->list();

        $this->assertSame([['intent' => 'i1'], ['intent' => 'i2']], $rows);
    }

    public function test_intent_drift_all_skips_bad_lines(): void
    {
        $path = $this->dir.'/intent-drift.jsonl';
        $this->seedFixture($path,
            ['receipt_id' => 'r1', 'recorded_at' => 't1', 'detector_fact' => ['f' => 1], 'recalibration' => [], 'content_hash' => 'h1'],
            ['receipt_id' => 'r2', 'recorded_at' => 't2', 'detector_fact' => ['f' => 2], 'recalibration' => [], 'content_hash' => 'h2'],
        );

        $receipts = (new AtlasLoopIntentDriftReceiptLedger($path))->all();

        $this->assertCount(2, $receipts);
        $this->assertSame(['r1', 'r2'], array_map(static fn ($r): string => $r->receiptId, $receipts));
        $this->assertSame(['h1', 'h2'], array_map(static fn ($r): string => $r->contentHash, $receipts));
    }

    public function test_operator_intent_history_skips_bad_lines(): void
    {
        $path = $this->dir.'/operator-intent.jsonl';
        $this->seedFixture($path,
            ['receipt_id' => 'r1', 'fact_id' => 'f1', 'schema' => 's', 'recorded_at' => 't1', 'decision' => 'accepted', 'decision_reason' => '', 'downstream_ref' => null],
            ['receipt_id' => 'r2', 'fact_id' => 'f2', 'schema' => 's', 'recorded_at' => 't2', 'decision' => 'rejected', 'decision_reason' => '', 'downstream_ref' => 'd'],
        );

        $ledger = new AtlasLoopOperatorIntentReceiptLedger($path);

        $this->assertSame(['r1', 'r2'], array_map(static fn ($r): string => $r->receiptId, $ledger->history()));
        $this->assertSame(['f2'], array_map(static fn ($r): string => $r->factId, $ledger->history('f2')));
    }
}
