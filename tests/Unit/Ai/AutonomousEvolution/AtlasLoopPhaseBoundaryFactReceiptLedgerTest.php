<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\LiveCycle\FactPassing\AtlasLoopPhaseBoundaryFactReceiptLedger;
use App\Services\Ai\AutonomousEvolution\LiveCycle\FactPassing\AtlasLoopPhaseBoundaryFactValidator;
use Tests\TestCase;

final class AtlasLoopPhaseBoundaryFactReceiptLedgerTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-fact-receipt-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function ledger(): AtlasLoopPhaseBoundaryFactReceiptLedger
    {
        return new AtlasLoopPhaseBoundaryFactReceiptLedger(new AtlasLoopPhaseBoundaryFactValidator(), $this->path);
    }

    /**
     * @return array<string,mixed>
     */
    private function validFact(): array
    {
        return [
            'scope_snapshot' => ['root' => 'app/Demo'],
            'surface_inventory' => ['Foo'],
            'target_signal' => 'compile_clean',
            'emitted_by_phase' => 'orient',
            'emitted_at' => '2026-06-25T12:00:00Z',
            'cycle_id' => 'cyc-1',
        ];
    }

    public function test_record_appends_one_jsonl_line_with_deterministic_fact_hash(): void
    {
        $a = $this->ledger()->record('cyc-1', 'orient->comprehend', $this->validFact());

        // Single-line file.
        $contents = (string) file_get_contents($this->path);
        $this->assertSame(1, substr_count($contents, "\n"));

        // Read back and verify.
        $line = trim($contents);
        $row = json_decode($line, true);
        $this->assertSame('cyc-1', $row['cycle_id']);
        $this->assertSame('orient->comprehend', $row['boundary']);
        $this->assertSame($a['fact_hash'], $row['fact_hash']);
        $this->assertTrue($row['validation_ok']);
        $this->assertSame([], $row['missing_keys']);

        // Second record with identical payload → same fact_hash.
        $b = $this->ledger()->record('cyc-1', 'orient->comprehend', $this->validFact());
        $this->assertSame($a['fact_hash'], $b['fact_hash']);
    }

    public function test_invalid_fact_records_with_validation_ok_false_and_missing_keys(): void
    {
        $fact = $this->validFact();
        unset($fact['target_signal']);

        $row = $this->ledger()->record('cyc-1', 'orient->comprehend', $fact);
        $this->assertFalse($row['validation_ok']);
        $this->assertContains('target_signal', $row['missing_keys']);
    }

    public function test_read_since_returns_only_rows_for_the_supplied_cycle_id(): void
    {
        $ledger = $this->ledger();
        $ledger->record('cyc-1', 'orient->comprehend', $this->validFact());
        $ledger->record('cyc-2', 'orient->comprehend', $this->validFact());
        $ledger->record('cyc-1', 'orient->comprehend', array_replace($this->validFact(), ['target_signal' => 'other']));

        $rows = $ledger->readSince('cyc-1');
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame('cyc-1', $row['cycle_id']);
        }
    }
}
