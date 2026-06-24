<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Cortex;

use App\Services\Ai\AutonomousEvolution\Cortex\AtlasCortexAdoptionReceiptLedger;
use InvalidArgumentException;
use ReflectionClass;
use Tests\TestCase;

/**
 * Proves the Cortex adoption receipt ledger: two records produce two JSONL lines with the nine canonical
 * fields and list() returns them sorted ascending; a facts blob failing universal schema validation is
 * REFUSED with InvalidArgumentException and the file size is unchanged; the implementation source contains
 * ZERO truncating-write patterns and ZERO unlink calls (append-only by grep).
 */
final class AtlasCortexAdoptionReceiptLedgerTest extends TestCase
{
    private string $tmpRoot;

    private AtlasCortexAdoptionReceiptLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir().'/atlas_cortex_adoption_'.bin2hex(random_bytes(6));
        mkdir($this->tmpRoot, 0775, true);
        $this->ledger = new AtlasCortexAdoptionReceiptLedger;
        $this->ledger->setRootForTesting($this->tmpRoot);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpRoot)) {
            @unlink($this->tmpRoot.'/adoption.jsonl');
            @rmdir($this->tmpRoot);
        }
        parent::tearDown();
    }

    private function validFacts(string $snapshot = 'snap-1'): array
    {
        return [
            'snapshot_id' => $snapshot,
            'inventory' => [],
            'orphans' => [],
            'clone_clusters' => [],
            'forbidden' => [],
            'doc_stated_gaps' => [],
        ];
    }

    public function test_two_records_produce_two_lines_and_list_is_sorted_ascending(): void
    {
        $a = $this->ledger->record($this->validFacts('A'), '/path/facts-a.json');
        // Force a deterministic time gap by adjusting recorded_at_unix in the second write — the ledger
        // stamps time() but we test sort order; since both lands within the same second on a fast box, we
        // backdate the first line on disk to ensure ordering is asserted on real values.
        $path = $this->tmpRoot.'/adoption.jsonl';
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $row = (array) json_decode((string) $lines[0], true);
        $row['recorded_at_unix'] = (int) $row['recorded_at_unix'] - 60;
        file_put_contents($path, (string) json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        $b = $this->ledger->record($this->validFacts('B'), '/path/facts-b.json');

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(2, $lines);

        $list = $this->ledger->list();
        $this->assertCount(2, $list);
        $this->assertLessThanOrEqual($list[1]['recorded_at_unix'], $list[0]['recorded_at_unix'], 'records sorted ascending');

        foreach ($list as $row) {
            foreach (['recorded_at_unix', 'repo_root', 'scope_root', 'schema_id', 'snapshot_id', 'facts_path', 'units_count', 'orphans_count', 'clones_count'] as $field) {
                $this->assertArrayHasKey($field, $row, "missing canonical field: {$field}");
            }
            $this->assertSame('atlas.cortex.facts.v1', $row['schema_id']);
        }
    }

    public function test_record_throws_on_invalid_facts_and_leaves_file_size_unchanged(): void
    {
        // Seed one valid record so the ledger file exists with a known size.
        $this->ledger->record($this->validFacts('seed'), '/path/facts-seed.json');
        $path = $this->tmpRoot.'/adoption.jsonl';
        $sizeBefore = (int) @filesize($path);
        $this->assertGreaterThan(0, $sizeBefore, 'sanity: seed write succeeded');

        $invalid = [
            'snapshot_id' => 'bad',
            'inventory' => [],
            'orphans' => [],
            'clone_clusters' => [],
            'forbidden' => [],
            'doc_stated_gaps' => [],
            'units' => ['App\\Foo' => ['score' => 0.42]], // forbidden by pétreo: never a score
        ];

        try {
            $this->ledger->record($invalid, '/path/bad.json');
            $this->fail('expected InvalidArgumentException for invalid facts');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('schema validation', $e->getMessage());
        }

        clearstatcache(true, $path);
        $sizeAfter = (int) @filesize($path);
        $this->assertSame($sizeBefore, $sizeAfter, 'ledger file size unchanged after refused adoption');
    }

    public function test_append_only_source_grep_proves_no_truncating_write_or_unlink(): void
    {
        $reflection = new ReflectionClass(AtlasCortexAdoptionReceiptLedger::class);
        $source = (string) file_get_contents($reflection->getFileName());

        // Forbidden patterns:
        //   - fopen($path, 'w')   — truncating open
        //   - file_put_contents($path, ...) WITHOUT a FILE_APPEND flag in the same call
        //   - unlink(             — record deletion
        $this->assertStringNotContainsString("fopen(\$path, 'w'", $source, 'no truncating fopen');
        $this->assertStringNotContainsString("fopen(\$path, 'wb'", $source, 'no truncating fopen (binary)');
        $this->assertStringNotContainsString('unlink(', $source, 'no unlink calls');
        // Every file_put_contents call must include FILE_APPEND in its argument list.
        preg_match_all('/file_put_contents\(([^;]*?)\);/s', $source, $matches);
        foreach (($matches[1] ?? []) as $args) {
            $this->assertStringContainsString('FILE_APPEND', (string) $args, 'every file_put_contents must use FILE_APPEND, got: '.$args);
        }
    }

    public function test_ledger_default_path_lives_under_storage_path(): void
    {
        // Without setRootForTesting() the default is storage_path('app/atlas/cortex/adoption.jsonl').
        $defaultLedger = new AtlasCortexAdoptionReceiptLedger;
        $this->assertStringEndsWith('app/atlas/cortex/adoption.jsonl', $defaultLedger->ledgerPath());
    }
}
