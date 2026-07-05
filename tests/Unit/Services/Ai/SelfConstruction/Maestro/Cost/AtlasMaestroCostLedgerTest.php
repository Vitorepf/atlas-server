<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Maestro\Cost;

use App\Services\Ai\SelfConstruction\Maestro\Cost\AtlasMaestroCostLedger;
use Tests\TestCase;

/**
 * Focused contract test: proves malformed facts are skipped, negative numeric fields are
 * rejected, sensitive provider fields are never stored, duplicate cost_hash rows are
 * idempotent (no second JSONL line), and the query methods return deterministic ordered rows.
 */
final class AtlasMaestroCostLedgerTest extends TestCase
{
    private string $ledgerPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas-maestro-cost-r104-'.bin2hex(random_bytes(6)).'.jsonl';
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

    public function test_malformed_facts_missing_required_fields_are_skipped(): void
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);

        $missingProvider = $this->fact();
        unset($missingProvider['provider']);
        self::assertNull($ledger->append($missingProvider));

        $missingRecordedAt = $this->fact();
        unset($missingRecordedAt['recorded_at']);
        self::assertNull($ledger->append($missingRecordedAt));

        self::assertFileDoesNotExist($this->ledgerPath);
    }

    public function test_negative_numeric_fields_are_rejected(): void
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);

        self::assertNull($ledger->append($this->fact(['tokens_in' => -1])));
        self::assertNull($ledger->append($this->fact(['tokens_out' => -1])));
        self::assertNull($ledger->append($this->fact(['cost_cents' => -1])));
        self::assertFileDoesNotExist($this->ledgerPath);
    }

    public function test_sensitive_fields_are_never_persisted(): void
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);
        $row = $ledger->append($this->fact([
            'api_key' => 'sk-live-secret',
            'authorization' => 'Bearer token-value',
            'raw_payload' => '{"prompt":"..."}',
            'raw_response' => '{"choices":[...]}',
            'prompt_text' => 'do the thing',
            'provider_trace_id' => 'trace-xyz',
            'internal_model_id' => 'internal-model-42',
        ]));

        self::assertIsArray($row);
        foreach (['api_key', 'authorization', 'raw_payload', 'raw_response', 'prompt_text', 'provider_trace_id', 'internal_model_id'] as $sensitive) {
            self::assertArrayNotHasKey($sensitive, $row, "sensitive field {$sensitive} must never be persisted");
        }

        // Confirm the raw JSONL line on disk also never carries the sensitive fields.
        $onDisk = trim((string) file_get_contents($this->ledgerPath));
        foreach (['sk-live-secret', 'Bearer token-value', 'trace-xyz', 'internal-model-42'] as $secretValue) {
            self::assertStringNotContainsString($secretValue, $onDisk);
        }
    }

    public function test_duplicate_cost_hash_append_is_idempotent_and_does_not_append_a_new_line(): void
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);
        $first = $ledger->append($this->fact());
        $lineCountAfterFirst = count(array_filter(explode("\n", (string) file_get_contents($this->ledgerPath))));

        $second = $ledger->append($this->fact());
        $lineCountAfterSecond = count(array_filter(explode("\n", (string) file_get_contents($this->ledgerPath))));

        self::assertSame($first['cost_hash'], $second['cost_hash']);
        self::assertSame(1, $lineCountAfterFirst);
        self::assertSame($lineCountAfterFirst, $lineCountAfterSecond, 'duplicate cost_hash must not append a second JSONL line');
    }

    public function test_query_methods_return_deterministic_ordered_rows(): void
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);
        $ledger->append($this->fact(['task_packet_id' => 'pk-1', 'provider' => 'atlas_native', 'cycle_id' => 'cycle-A', 'recorded_at' => '2026-06-25T00:00:02Z']));
        $ledger->append($this->fact(['task_packet_id' => 'pk-1', 'provider' => 'codex', 'cycle_id' => 'cycle-A', 'recorded_at' => '2026-06-25T00:00:01Z']));
        $ledger->append($this->fact(['task_packet_id' => 'pk-2', 'provider' => 'atlas_native', 'cycle_id' => 'cycle-B', 'recorded_at' => '2026-06-25T00:00:03Z']));

        $byTask = $ledger->queryForTask('pk-1');
        self::assertCount(2, $byTask);
        self::assertSame('2026-06-25T00:00:01Z', $byTask[0]['recorded_at']);
        self::assertSame('2026-06-25T00:00:02Z', $byTask[1]['recorded_at']);

        self::assertCount(1, $ledger->queryForProvider('codex'));
        self::assertCount(1, $ledger->queryForCycle('cycle-B'));
        self::assertCount(3, $ledger->all());

        // Determinism: repeated calls yield byte-identical ordering.
        self::assertSame(
            json_encode($ledger->all()),
            json_encode($ledger->all()),
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AC2: append() rejects non-numeric cost/tokens and rejects sensitive fields
    // ═══════════════════════════════════════════════════════════════════════

    public function test_non_numeric_cost_cents_is_rejected(): void
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);

        $this->assertNull($ledger->append($this->fact(['cost_cents' => 'not-a-number'])));
        $this->assertFileDoesNotExist($this->ledgerPath);
    }

    public function test_non_numeric_tokens_in_is_rejected(): void
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);

        $this->assertNull($ledger->append($this->fact(['tokens_in' => 'non-numeric'])));
        $this->assertFileDoesNotExist($this->ledgerPath);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AC3: append() is idempotent by cost_hash — repeated same fact returns
    // existing record without duplicating JSONL line
    // ═══════════════════════════════════════════════════════════════════════

    public function test_idempotent_append_returns_existing_row_on_repeat(): void
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);
        $first = $ledger->append($this->fact());

        $repeat = $ledger->append($this->fact());

        $this->assertSame($first['cost_hash'], $repeat['cost_hash']);
        $this->assertSame($first['recorded_at'], $repeat['recorded_at']);
        $lines = array_filter(explode("\n", (string) file_get_contents($this->ledgerPath)));
        $this->assertCount(1, $lines, 'duplicate cost_hash must not append a second line');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AC4: query methods ignore invalid JSON lines and return ordered records
    // ═══════════════════════════════════════════════════════════════════════

    public function test_query_methods_ignore_invalid_json_lines(): void
    {
        // Write a line of garbage before ledger operations.
        file_put_contents($this->ledgerPath, "this is not json\n", FILE_APPEND);
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);
        $ledger->append($this->fact(['task_packet_id' => 'pk-x', 'cycle_id' => 'cycle-x']));

        $byCycle = $ledger->queryForCycle('cycle-x');
        $this->assertCount(1, $byCycle, 'invalid JSON lines must be silently skipped');
        $this->assertSame('pk-x', $byCycle[0]['task_packet_id']);
    }
}
