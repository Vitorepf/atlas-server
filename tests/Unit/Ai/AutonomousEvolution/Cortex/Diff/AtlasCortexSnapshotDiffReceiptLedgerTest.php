<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Cortex\Diff;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Diff\AtlasCortexSnapshotDiffReceiptLedger;
use Tests\TestCase;

final class AtlasCortexSnapshotDiffReceiptLedgerTest extends TestCase
{
    private string $root = '';

    private string $productionRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-cortex-diff-receipts-'.bin2hex(random_bytes(6));
        @mkdir($this->root, 0o755, true);
        AtlasCortexSnapshotDiffReceiptLedger::setRootForTesting($this->root);
        $this->productionRoot = storage_path('app/atlas/loop/cortex/diff/receipts');
    }

    protected function tearDown(): void
    {
        AtlasCortexSnapshotDiffReceiptLedger::setRootForTesting(null);
        $this->rrmdir($this->root);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir.'/'.$entry;
            if (is_dir($full)) {
                $this->rrmdir($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($dir);
    }

    private function sampleDiff(array $proseOverride = []): array
    {
        return [
            'inventory' => ['added' => ['a.php'], 'removed' => [], 'shape_changed' => []],
            'orphans' => ['appeared' => [], 'resolved' => []],
            'prose' => $proseOverride,
        ];
    }

    public function test_schema_constant_is_present(): void
    {
        $this->assertSame('atlas.cortex.snapshot_diff_receipt.v1', AtlasCortexSnapshotDiffReceiptLedger::SCHEMA);
    }

    public function test_record_writes_one_jsonl_line_with_canonical_fields(): void
    {
        $ledger = new AtlasCortexSnapshotDiffReceiptLedger();
        $verdict = $ledger->record('snap-a', 'snap-b', 'app/', $this->sampleDiff(), ['inventory_added' => 1]);

        $this->assertTrue($verdict['recorded']);
        $this->assertFileExists($verdict['path']);
        $contents = (string) file_get_contents($verdict['path']);
        $this->assertSame(1, substr_count($contents, "\n"));
        $decoded = json_decode(trim($contents), true);
        $this->assertSame('snap-a', $decoded['left_snapshot_id']);
        $this->assertSame('snap-b', $decoded['right_snapshot_id']);
        $this->assertSame('app/', $decoded['scope_root']);
        $this->assertSame(['inventory_added' => 1], $decoded['summary']);
        $this->assertSame(64, strlen((string) $decoded['structural_diff_hash']));
        $this->assertSame('atlas.cortex.snapshot_diff_receipt.v1', $decoded['schema']);
    }

    public function test_recording_same_pair_twice_yields_exactly_one_line(): void
    {
        $ledger = new AtlasCortexSnapshotDiffReceiptLedger();
        $first = $ledger->record('snap-a', 'snap-b', 'app/', $this->sampleDiff(), ['inventory_added' => 1]);
        $second = $ledger->record('snap-a', 'snap-b', 'app/', $this->sampleDiff(), ['inventory_added' => 1]);

        $this->assertTrue($second['recorded']);
        $this->assertTrue($second['cached']);
        $contents = (string) file_get_contents($first['path']);
        $this->assertSame(1, substr_count($contents, "\n"));

        $seen = $ledger->seen('snap-a', 'snap-b');
        $this->assertNotNull($seen);
        $this->assertSame('snap-a', $seen['left_snapshot_id']);
    }

    public function test_structural_diff_hash_is_byte_identical_when_only_prose_differs(): void
    {
        $ledger = new AtlasCortexSnapshotDiffReceiptLedger();
        $hashA = $ledger->structuralHash($this->sampleDiff(['added' => ['a-prose']]));
        $hashB = $ledger->structuralHash($this->sampleDiff(['added' => ['b-prose', 'c-prose']]));
        $this->assertSame($hashA, $hashB, 'structural hash must NOT depend on prose');
    }

    public function test_structural_hash_excludes_doc_purposes_prose_real_engine_key(): void
    {
        $ledger = new AtlasCortexSnapshotDiffReceiptLedger();
        $diffA = [
            'inventory' => ['added' => ['a.php'], 'removed' => [], 'shape_changed' => []],
            'orphans' => ['appeared' => [], 'resolved' => []],
            'doc_purposes_prose' => ['added' => ['prose-A']],
        ];
        $diffB = [
            'inventory' => ['added' => ['a.php'], 'removed' => [], 'shape_changed' => []],
            'orphans' => ['appeared' => [], 'resolved' => []],
            'doc_purposes_prose' => ['added' => ['prose-B', 'prose-C']],
        ];
        $hashA = $ledger->structuralHash($diffA);
        $hashB = $ledger->structuralHash($diffB);
        $this->assertSame($hashA, $hashB, 'structural hash must NOT depend on doc_purposes_prose');
    }

    public function test_redirected_root_is_used_and_production_path_is_untouched(): void
    {
        // Snapshot whether the production root exists before this test.
        $productionExistedBefore = is_dir($this->productionRoot);

        $ledger = new AtlasCortexSnapshotDiffReceiptLedger();
        $verdict = $ledger->record('snap-x', 'snap-y', 'app/', $this->sampleDiff(), []);

        $this->assertStringStartsWith($this->root, $verdict['path']);
        if (! $productionExistedBefore) {
            $this->assertDirectoryDoesNotExist($this->productionRoot, 'production path must NOT be created during the test');
        }
    }
}
