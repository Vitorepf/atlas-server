<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\Cost\AtlasMaestroCostLedger;
use Tests\TestCase;

class AtlasMaestroCostLedgerTest extends TestCase
{
    private string $ledgerPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas-maestro-cost-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    private function fact(array $override = []): array
    {
        return array_replace([
            'task_packet_id' => 'pk-1',
            'task_class' => 'refactor',
            'provider' => 'atlas_native',
            'model' => 'atlas-native-1',
            'cycle_id' => 'cycle-A',
            'tokens_in' => 1000,
            'tokens_out' => 500,
            'cost_cents' => 12,
            'recorded_at' => '2026-06-25T00:00:00Z',
        ], $override);
    }

    public function test_append_writes_a_well_formed_row_with_cost_hash(): void
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);
        $row = $ledger->append($this->fact());

        self::assertIsArray($row);
        self::assertStringStartsWith('cost_', $row['cost_hash']);
        self::assertFileExists($this->ledgerPath);
    }

    public function test_query_for_task_returns_only_matching_rows_in_order(): void
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);
        $ledger->append($this->fact(['task_packet_id' => 'pk-1', 'recorded_at' => '2026-06-25T00:00:02Z']));
        $ledger->append($this->fact(['task_packet_id' => 'pk-1', 'recorded_at' => '2026-06-25T00:00:01Z']));
        $ledger->append($this->fact(['task_packet_id' => 'pk-2', 'recorded_at' => '2026-06-25T00:00:03Z']));

        $rows = $ledger->queryForTask('pk-1');
        self::assertCount(2, $rows);
        self::assertSame('2026-06-25T00:00:01Z', $rows[0]['recorded_at']);
        self::assertSame('2026-06-25T00:00:02Z', $rows[1]['recorded_at']);
    }

    public function test_query_for_provider_filters_by_provider(): void
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);
        $ledger->append($this->fact(['provider' => 'atlas_native']));
        $ledger->append($this->fact(['provider' => 'codex']));

        $rows = $ledger->queryForProvider('codex');
        self::assertCount(1, $rows);
        self::assertSame('codex', $rows[0]['provider']);
    }

    public function test_query_for_cycle_filters_by_cycle(): void
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);
        $ledger->append($this->fact(['cycle_id' => 'cycle-A']));
        $ledger->append($this->fact(['cycle_id' => 'cycle-B']));

        self::assertCount(1, $ledger->queryForCycle('cycle-A'));
        self::assertCount(1, $ledger->queryForCycle('cycle-B'));
    }

    public function test_malformed_row_is_skipped_without_throwing(): void
    {
        // Write a malformed line directly + a well-formed line via append.
        file_put_contents($this->ledgerPath, "not valid json\n");
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);
        $appended = $ledger->append($this->fact());

        self::assertNotNull($appended);
        $all = $ledger->all();
        self::assertCount(1, $all); // malformed line skipped, append present
    }

    public function test_append_returns_null_when_fact_missing_required_field(): void
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);
        $bad = $this->fact();
        unset($bad['cost_cents']);
        $r = $ledger->append($bad);

        self::assertNull($r);
        self::assertFileDoesNotExist($this->ledgerPath);
    }

    public function test_cost_hash_is_byte_stable_for_identical_facts(): void
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);
        $a = $ledger->append($this->fact());
        $b = $ledger->append($this->fact());

        self::assertSame($a['cost_hash'], $b['cost_hash']);
    }
}
