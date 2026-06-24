<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Cortex;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Insights\AtlasCortexInsightReceiptLedger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class AtlasCortexInsightReceiptLedgerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/atlas-cortex-insight-ledger-'.bin2hex(random_bytes(6));
        @mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach (glob($this->root.DIRECTORY_SEPARATOR.'*.jsonl') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->root);
    }

    public function test_writing_duplicate_snapshot_axis_and_witnesses_dedupes_to_one_line(): void
    {
        $ledger = new AtlasCortexInsightReceiptLedger($this->root);
        $observation = $this->observation('orphan_spike', '2026-06-24T12:00:00Z', ['App\\Ghost\\One'], ['prior_orphan_fqcns' => [], 'current_orphan_fqcns' => ['App\\Ghost\\One']]);

        $ledger->append('snapshot-001', $observation);
        $ledger->append('snapshot-001', $observation);

        $history = $ledger->history('orphan_spike', 10);
        $lines = $this->jsonLines($this->root.'/snapshot-001.jsonl');

        $this->assertCount(1, $history);
        $this->assertCount(1, $lines);
    }

    public function test_by_snapshot_range_reads_rows_from_two_snapshot_files_in_deterministic_order(): void
    {
        $ledger = new AtlasCortexInsightReceiptLedger($this->root);

        $ledger->append('snapshot-001', $this->observation('intent_drift', '2026-06-24T12:00:00Z', ['App\\Unit\\A'], ['matched_units' => ['App\\Unit\\A']]));
        $ledger->append('snapshot-002', $this->observation('recurrence_cluster', '2026-06-24T12:05:00Z', ['App\\Unit\\B'], ['recurring_units' => ['App\\Unit\\B' => 3]]));

        $paths = array_map('basename', glob($this->root.DIRECTORY_SEPARATOR.'*.jsonl') ?: []);
        sort($paths, SORT_STRING);

        $rows = $ledger->bySnapshotRange('snapshot-001', 'snapshot-002');

        $this->assertSame(['snapshot-001.jsonl', 'snapshot-002.jsonl'], $paths);
        $this->assertSame(['snapshot-001', 'snapshot-002'], array_column($rows, 'snapshot_id'));
    }

    public function test_persisted_lines_round_trip_with_exact_schema_and_no_forbidden_keys(): void
    {
        $ledger = new AtlasCortexInsightReceiptLedger($this->root);
        $ledger->append('snapshot-100', $this->observation('intent_drift', '2026-06-24T12:00:00Z', ['App\\Unit\\A'], [
            'matched_units' => ['App\\Unit\\A'],
            'merge_gate_pair' => true,
        ]));

        $row = $this->jsonLines($this->root.'/snapshot-100.jsonl')[0];
        $expectedKeys = [
            'snapshot_id' => true,
            'axis_id' => true,
            'fact_keys_consumed' => true,
            'witnesses' => true,
            'emitted_at' => true,
            'witness_hash' => true,
        ];
        $forbiddenKeys = [
            'score' => true,
            'rank' => true,
            'level' => true,
            'weight' => true,
            'priority' => true,
        ];

        $this->assertSame([], array_diff_key($row, $expectedKeys));
        $this->assertSame([], array_diff_key($expectedKeys, $row));
        $this->assertSame([], array_intersect_key($row, $forbiddenKeys));
        $this->assertSame(['matched_units', 'merge_gate_pair'], $row['fact_keys_consumed']);
    }

    public function test_append_uses_an_exclusive_lock_and_keeps_lines_whole_under_concurrent_writes(): void
    {
        $lockProbeOutput = null;

        $ledger = new AtlasCortexInsightReceiptLedger($this->root, function (string $path) use (&$lockProbeOutput): void {
            $probeScript = <<<'PHP'
$handle = fopen($argv[1], 'c+');
if ($handle === false) {
    fwrite(STDOUT, 'open_failed');
    exit(2);
}
$locked = flock($handle, LOCK_EX | LOCK_NB);
fwrite(STDOUT, $locked ? 'acquired' : 'blocked');
if ($locked) {
    flock($handle, LOCK_UN);
}
fclose($handle);
PHP;

            $probe = new Process(['/opt/homebrew/bin/php', '-r', $probeScript, $path], $this->repoRoot());
            $probe->mustRun();
            $lockProbeOutput = trim($probe->getOutput());
        });

        $ledger->append('snapshot-200', $this->observation('orphan_spike', '2026-06-24T12:01:00Z', ['App\\Unit\\Parent'], [
            'current_orphan_fqcns' => ['App\\Unit\\Parent'],
        ]));
        $ledger->append('snapshot-200', $this->observation('recurrence_cluster', '2026-06-24T12:02:00Z', ['App\\Unit\\Child'], [
            'recurring_units' => ['App\\Unit\\Child' => 3],
        ]));

        $rows = $this->jsonLines($this->root.'/snapshot-200.jsonl');

        $this->assertSame('blocked', $lockProbeOutput);
        $this->assertCount(2, $rows);
        $this->assertSame(
            ['orphan_spike', 'recurrence_cluster'],
            array_column($rows, 'axis_id')
        );
    }

    /**
     * @param  list<string>  $witnesses
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    private function observation(string $axisId, string $noticedAt, array $witnesses, array $facts): array
    {
        return [
            'axis_id' => $axisId,
            'noticed_at' => $noticedAt,
            'facts' => $facts,
            'witnesses' => $witnesses,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function jsonLines(string $path): array
    {
        $contents = file_get_contents($path);
        $this->assertIsString($contents);

        $rows = [];
        foreach (array_filter(explode("\n", trim($contents))) as $line) {
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded, 'expected a decode-able jsonl row');
            $rows[] = $decoded;
        }

        return $rows;
    }

    private function repoRoot(): string
    {
        return dirname(__DIR__, 6);
    }
}
