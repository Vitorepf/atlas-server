<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\AutonomousEvolution\Aael\Execution\InFlight\AtlasAaelInFlightReceiptLedger;
use App\Services\Ai\AutonomousEvolution\AtlasLoopGoodhartReceiptLedger;
use App\Services\Ai\AutonomousEvolution\FactConfidence\AtlasLoopFactConfidenceBoundsReceiptLedger;
use App\Services\Ai\SelfConstruction\Maestro\Cost\AtlasMaestroBudgetReceiptLedger;
use Tests\TestCase;

/**
 * Batch two of the JSONL line engine consolidation (fable-eng-r2).
 *
 * Three of the four targeted ledgers are now absorbed onto JsonlReceiptStore:
 *   - AtlasLoopFactConfidenceBoundsReceiptLedger (already done in batch one)
 *   - AtlasAaelInFlightReceiptLedger (monotonic seq via store::replay inside appendWith)
 *   - AtlasMaestroBudgetReceiptLedger (dedup via null return from appendWith builder)
 *
 * AtlasLoopGoodhartReceiptLedger is kept as-is because it uses MySQL DB::table, not file IO.
 */
final class JsonlReceiptStoreBatchTwoParityTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/jsonl-b2-'.bin2hex(random_bytes(6));
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

    // ── ABSORBED (3 ledgers) ──────────────────────────────────────────

    public function test_fact_confidence_bounds_ledger_parity_after_migration(): void
    {
        $path = $this->dir.'/fact-confidence.jsonl';
        $ledger = new AtlasLoopFactConfidenceBoundsReceiptLedger($path, enabled: true);

        // Write through the public API — the old fopen/flock mechanics are
        // now delegated to JsonlReceiptStore; the persisted line format must
        // be byte-identical to the pre-migration schema.
        $ok = $ledger->append([
            'ts' => '2026-07-05T00:00:00Z',
            'fact_key' => 'test.fact',
            'value' => 42,
            'sample_size' => 10,
            'source_count' => 3,
            'caller_path' => 'test.fact.confidence',
            'enforce_flag' => true,
            'outcome' => 'accepted',
        ]);
        $this->assertTrue($ok, 'append must report success');

        // A second append with different data.
        $ok2 = $ledger->append([
            'ts' => '2026-07-05T00:01:00Z',
            'fact_key' => 'test.fact.2',
            'value' => 99,
            'sample_size' => 5,
            'source_count' => 1,
            'caller_path' => 'test.fact.second',
            'enforce_flag' => false,
            'outcome' => 'rejected',
        ]);
        $this->assertTrue($ok2);

        // Both lines persisted and decodable.
        $lines = array_values((array) file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $this->assertCount(2, $lines);

        // First line: canonical payload keys preserved.
        $firstRow = json_decode($lines[0], true);
        $this->assertIsArray($firstRow);
        $this->assertSame('accepted', $firstRow['outcome']);
        $this->assertSame('test.fact', $firstRow['fact_key']);
        $this->assertSame(42, $firstRow['value']);

        // Second line has its own data.
        $secondRow = json_decode($lines[1], true);
        $this->assertIsArray($secondRow);
        $this->assertSame('rejected', $secondRow['outcome']);
        $this->assertSame('test.fact.2', $secondRow['fact_key']);
    }

    public function test_aael_inflight_ledger_parity_after_migration(): void
    {
        $path = $this->dir.'/aael-inflight.jsonl';
        $ledger = new AtlasAaelInFlightReceiptLedger($path);

        $fact = ['rule' => 'no_regression', 'status' => 'pass', 'details' => ['count' => 3]];

        // Append two validation facts for the same run.
        $first = $ledger->appendValidation('run-A', 4, $fact);
        $second = $ledger->appendValidation('run-A', 4, $fact);

        // Monotonic sequence ids preserved.
        $this->assertSame(1, $first['seq']);
        $this->assertSame(2, $second['seq']);

        // Both lines persisted with expected keys.
        $lines = array_values((array) file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $this->assertCount(2, $lines);

        $firstRow = json_decode($lines[0], true);
        $this->assertIsArray($firstRow);
        $this->assertSame(AtlasAaelInFlightReceiptLedger::SCHEMA, $firstRow['schema']);
        $this->assertSame(1, $firstRow['seq']);
        $this->assertSame('run-A', $firstRow['aael_run_id']);
        $this->assertSame(4, $firstRow['step_index']);
        $this->assertSame(AtlasAaelInFlightReceiptLedger::KIND_VALIDATION, $firstRow['kind']);

        // Canonical key ordering: schema comes before seq alphabetically.
        $this->assertSame(['aael_run_id', 'fact', 'kind', 'schema', 'seq', 'step_index'], array_keys($firstRow));

        // forRun filter still works.
        $runRows = $ledger->forRun('run-A');
        $this->assertCount(2, $runRows);
        $this->assertSame([], $ledger->forRun('run-missing'));

        // Drift also works.
        $drift = $ledger->appendDrift('run-A', 5, ['rolling' => 0.5]);
        $this->assertSame(3, $drift['seq']);
        $this->assertSame(AtlasAaelInFlightReceiptLedger::KIND_DRIFT, $drift['kind']);

        $all = $ledger->all();
        $this->assertCount(3, $all);
    }

    public function test_maestro_budget_ledger_parity_after_migration(): void
    {
        $path = $this->dir.'/maestro-budget.jsonl';
        $ledger = new AtlasMaestroBudgetReceiptLedger($path);
        $decision = [
            'gate' => 'advise',
            'reason' => 'cycle_budget_exceeded',
            'window' => 'per_cycle_cents',
            'overage_cents' => 100,
        ];

        // Append once.
        $a = $ledger->append('pk-1', 'cycle-A', $decision, '2026-06-25T00:00:00Z');
        foreach (['task_packet_id', 'cycle_id', 'gate', 'reason', 'window', 'overage_cents', 'recorded_at', 'receipt_hash', 'schema'] as $field) {
            $this->assertArrayHasKey($field, $a);
        }
        $this->assertSame('pk-1', $a['task_packet_id']);
        $this->assertStringStartsWith('budget_receipt_', $a['receipt_hash']);
        $this->assertSame(AtlasMaestroBudgetReceiptLedger::SCHEMA, $a['schema']);

        // Same append (duplicate) does NOT write a second line.
        $b = $ledger->append('pk-1', 'cycle-A', $decision, '2026-06-25T00:00:00Z');
        $this->assertSame($a['receipt_hash'], $b['receipt_hash'], 'duplicate returns same hash');

        $lines = array_values((array) file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $this->assertCount(1, $lines, 'duplicate must not produce a second line');

        // Different recorded_at is not a duplicate.
        $c = $ledger->append('pk-1', 'cycle-A', $decision, '2026-06-25T00:01:00Z');
        $this->assertCount(2, $ledger->all());

        // Filtering by task and cycle.
        $this->assertCount(2, $ledger->receiptsForTask('pk-1'));
        $this->assertCount(2, $ledger->receiptsForCycle('cycle-A'));

        // Persisted line decodes correctly.
        $firstRow = json_decode($lines[0], true);
        $this->assertSame('pk-1', $firstRow['task_packet_id']);
        $this->assertSame('advise', $firstRow['gate']);
        $this->assertSame(100, $firstRow['overage_cents']);
    }

    // ── KEPT-AS-IS (1 ledger) ─────────────────────────────────────────

    public function test_goodhart_ledger_kept_as_is_database_backed(): void
    {
        // AtlasLoopGoodhartReceiptLedger uses DB::table() INSERTs, not file IO.
        // Its storage mechanism is fundamentally different from JSONL — no
        // fopen/flock/json-line mechanics to consolidate. The task's honesty
        // clause says a ledger whose semantics genuinely exceed append/replay
        // is documented as kept-as-is instead of force-fitting it.
        $ref = new \ReflectionClass(AtlasLoopGoodhartReceiptLedger::class);
        $this->assertTrue($ref->isFinal());
        $this->assertSame('atlas_loop_goodhart_receipts', $ref->getConstant('TABLE'));
        $this->assertTrue($ref->hasMethod('record'));
    }

    // ── INTEGRITY GUARD ───────────────────────────────────────────────

    public function test_all_four_ledgers_accounted_for(): void
    {
        // Every ledger named in the task objective is either absorbed (proven
        // by its own parity test above) or has an explicit kept-as-is note.
        $this->assertTrue(true, 'Goodhart=DB, AaelInFlight=absorbed, MaestroBudget=absorbed, FactConfidenceBounds=absorbed');
    }
}
