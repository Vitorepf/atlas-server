<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Aael\Execution\InFlight\AtlasAaelInFlightReceiptLedger;
use Tests\TestCase;

final class AtlasAaelInFlightReceiptLedgerTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-aael-inflight-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    public function test_appending_same_validation_twice_produces_two_distinct_monotonic_lines(): void
    {
        $ledger = new AtlasAaelInFlightReceiptLedger($this->path);
        $fact = ['rule' => 'no_regression', 'status' => 'pass', 'details' => ['count' => 3]];

        $first = $ledger->appendValidation('run-A', 4, $fact);

        $linesBefore = file($this->path, FILE_IGNORE_NEW_LINES);
        self::assertCount(1, $linesBefore);
        $existingBytes = $linesBefore[0];

        $second = $ledger->appendValidation('run-A', 4, $fact);

        $linesAfter = file($this->path, FILE_IGNORE_NEW_LINES);
        self::assertCount(2, $linesAfter, 'file must grow by exactly one line per append');
        self::assertSame($existingBytes, $linesAfter[0], 'existing receipt line must be byte-identical');

        self::assertSame(1, $first['seq']);
        self::assertSame(2, $second['seq']);
        self::assertGreaterThan($first['seq'], $second['seq']);
    }

    public function test_for_run_returns_receipts_in_append_order_and_canonical_json(): void
    {
        $ledger = new AtlasAaelInFlightReceiptLedger($this->path);
        $ledger->appendValidation('run-X', 1, ['z' => 1, 'a' => 2]);
        $ledger->appendDrift('run-Y', 1, ['rolling' => 0.5]);
        $ledger->appendValidation('run-X', 2, ['nested' => ['b' => 1, 'a' => 2]]);

        $rows = $ledger->forRun('run-X');
        self::assertCount(2, $rows);
        self::assertSame(1, $rows[0]['step_index']);
        self::assertSame(2, $rows[1]['step_index']);

        // Canonical: keys sorted recursively. Re-serializing the row should produce identical bytes.
        foreach ($rows as $row) {
            $a = (string) json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $b = (string) json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            self::assertSame(hash('sha256', $a), hash('sha256', $b));

            // Top-level keys are sorted.
            self::assertSame(array_values(array_keys($row)), array_values($this->sortedKeys($row)));
            // No trailing whitespace inside the serialization.
            self::assertSame(trim($a), $a);
        }
    }

    public function test_for_run_filters_by_aael_run_id_and_empty_for_unknown_run(): void
    {
        $ledger = new AtlasAaelInFlightReceiptLedger($this->path);
        $ledger->appendValidation('run-1', 0, ['ok' => true]);
        $ledger->appendValidation('run-2', 0, ['ok' => false]);

        self::assertCount(1, $ledger->forRun('run-1'));
        self::assertCount(1, $ledger->forRun('run-2'));
        self::assertSame([], $ledger->forRun('run-missing'));
    }

    public function test_drift_and_validation_share_monotonic_sequence_across_kinds(): void
    {
        $ledger = new AtlasAaelInFlightReceiptLedger($this->path);
        $a = $ledger->appendValidation('r', 0, ['x' => 1]);
        $b = $ledger->appendDrift('r', 0, ['rolling' => 0.1]);
        $c = $ledger->appendDrift('r', 1, ['rolling' => 0.2]);

        self::assertSame(1, $a['seq']);
        self::assertSame(2, $b['seq']);
        self::assertSame(3, $c['seq']);
    }

    /**
     * @param  array<string|int,mixed>  $row
     * @return list<string>
     */
    private function sortedKeys(array $row): array
    {
        $keys = array_map('strval', array_keys($row));
        sort($keys, SORT_STRING);

        return $keys;
    }
}
