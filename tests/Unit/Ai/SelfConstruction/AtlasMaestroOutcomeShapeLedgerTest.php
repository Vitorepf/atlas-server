<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\ClosedLoop\AtlasMaestroOutcomeShapeLedger;
use Tests\TestCase;

final class AtlasMaestroOutcomeShapeLedgerTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-maestro-shape-ledger-'.bin2hex(random_bytes(5)).'.jsonl';
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path.'.lock'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_record_appends_factual_line_and_is_idempotent_per_task_outcome(): void
    {
        $ledger = $this->ledger();

        $ledger->record('packet-1', $this->shapeFacts(), 'give_back');
        $ledger->record('packet-1', $this->shapeFacts(['score' => 99, 'quality' => 'high']), 'give_back');

        $lines = file($this->path, FILE_IGNORE_NEW_LINES);
        $this->assertCount(1, $lines);
        $row = json_decode($lines[0], true);
        $this->assertSame('packet-1', $row['task_packet_id']);
        $this->assertSame('give_back', $row['outcome']);
        $this->assertSame(2, $row['allowed_files_count']);
        $this->assertForbiddenKeysAbsent($row);
    }

    public function test_stream_yields_entries_in_append_order_without_forbidden_proxy_keys(): void
    {
        $ledger = $this->ledger();
        $ledger->record('packet-1', $this->shapeFacts(['wave_bucket' => 'w1']), 'delivered');
        $ledger->record('packet-2', $this->shapeFacts(['wave_bucket' => 'w2']), 'rejected');
        $ledger->record('packet-3', $this->shapeFacts(['wave_bucket' => 'w3']), 'stale');

        $rows = iterator_to_array($ledger->stream());

        $this->assertSame(['packet-1', 'packet-2', 'packet-3'], array_column($rows, 'task_packet_id'));
        $this->assertSame(['w1', 'w2', 'w3'], array_column($rows, 'wave_bucket'));
        foreach ($rows as $row) {
            $this->assertForbiddenKeysAbsent($row);
        }
    }

    private function ledger(): AtlasMaestroOutcomeShapeLedger
    {
        return new AtlasMaestroOutcomeShapeLedger($this->path);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function shapeFacts(array $overrides = []): array
    {
        return $overrides + [
            'origin_kind' => 'orphan',
            'allowed_files_count' => 2,
            'scope_in_size' => 2,
            'acceptance_criteria_count' => 3,
            'required_evidence_count' => 1,
            'has_tests_path' => true,
            'wave_bucket' => 'w0',
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function assertForbiddenKeysAbsent(array $row): void
    {
        foreach (['score', 'quality', 'rank', 'ranking'] as $key) {
            $this->assertArrayNotHasKey($key, $row);
        }
    }
}
