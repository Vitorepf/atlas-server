<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Cortex\Diff;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Diff\AtlasCortexSnapshotDiffEngine;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Diff\AtlasCortexSnapshotDiffExporter;
use Tests\TestCase;

final class AtlasCortexSnapshotDiffExporterTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas-diff-exp-'.bin2hex(random_bytes(6));
        @mkdir($this->tmp, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmp)) {
            foreach ((array) scandir($this->tmp) as $f) {
                if ($f === '.' || $f === '..') {
                    continue;
                }
                @unlink($this->tmp.'/'.$f);
            }
            @rmdir($this->tmp);
        }
        parent::tearDown();
    }

    private function inventoryItem(string $relPath, string $fqcn, array $overrides = []): array
    {
        return array_merge([
            'rel_path' => $relPath,
            'fqcn' => $fqcn,
            'public_methods' => ['handle'],
            'is_orphan' => false,
            'is_forbidden' => false,
            'clone_cluster_id' => null,
        ], $overrides);
    }

    private function model(array $inv, array $edges = [], array $orphans = [], array $clones = [], array $forbidden = [], array $docPurposes = [], array $docGaps = [], string $snapshotId = 's1'): AtlasLoopScopeComprehensionModel
    {
        return new AtlasLoopScopeComprehensionModel(
            inventory: array_values($inv),
            edges: $edges,
            orphans: array_values($orphans),
            cloneClusters: array_values($clones),
            forbidden: array_values($forbidden),
            docPurposes: $docPurposes,
            docStatedGaps: array_values($docGaps),
            snapshotId: $snapshotId,
        );
    }

    public function test_schema_constant_is_present(): void
    {
        $this->assertSame('atlas.cortex.snapshot_diff_export.v1', AtlasCortexSnapshotDiffExporter::SCHEMA);
    }

    private function nonEmptyDiff(): array
    {
        $left = $this->model([
            $this->inventoryItem('app/Foo.php', 'App\\Foo', ['is_orphan' => true]),
            $this->inventoryItem('app/Bar.php', 'App\\Bar'),
        ], edges: ['app/Foo.php' => ['app/Caller1.php']], orphans: ['App\\Foo'], snapshotId: 'L');
        $right = $this->model([
            $this->inventoryItem('app/Foo.php', 'App\\Foo', ['is_orphan' => false]),
            $this->inventoryItem('app/Baz.php', 'App\\Baz'),
        ], edges: ['app/Foo.php' => ['app/Caller2.php']], snapshotId: 'R');

        return (new AtlasCortexSnapshotDiffEngine)->diff($left, $right);
    }

    public function test_writes_one_jsonl_line_per_structural_transition(): void
    {
        $diff = $this->nonEmptyDiff();
        $path = $this->tmp.'/out.jsonl';

        $result = (new AtlasCortexSnapshotDiffExporter)->export($diff, $path, 'app/');

        $this->assertGreaterThan(0, $result['exported']);
        $this->assertSame($path, $result['path']);

        $raw = (string) file_get_contents($path);
        $lines = $raw === '' ? [] : explode("\n", rtrim($raw, "\n"));
        $this->assertCount($result['exported'], $lines);

        $expectedSubjectKeys = ['left_snapshot_id', 'right_snapshot_id', 'scope_root', 'category', 'subject', 'detail'];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded, 'each line must be parseable JSON');
            foreach ($expectedSubjectKeys as $key) {
                $this->assertArrayHasKey($key, $decoded, "line missing key {$key}");
            }
            $this->assertSame('L', $decoded['left_snapshot_id']);
            $this->assertSame('R', $decoded['right_snapshot_id']);
            $this->assertSame('app/', $decoded['scope_root']);
        }
    }

    public function test_two_exports_of_same_diff_are_byte_identical(): void
    {
        $diff = $this->nonEmptyDiff();
        $a = $this->tmp.'/a.jsonl';
        $b = $this->tmp.'/b.jsonl';

        $ra = (new AtlasCortexSnapshotDiffExporter)->export($diff, $a, 'app/');
        $rb = (new AtlasCortexSnapshotDiffExporter)->export($diff, $b, 'app/');

        $this->assertSame(file_get_contents($a), file_get_contents($b));
        $this->assertSame($ra['sha256'], $rb['sha256']);
    }

    public function test_prose_export_lands_in_sibling_file_and_does_not_contaminate_structural_export(): void
    {
        $inv = [$this->inventoryItem('app/Foo.php', 'App\\Foo')];
        $left = $this->model($inv, docPurposes: ['App\\Foo' => 'Does X.'], snapshotId: 'L');
        $right = $this->model($inv, docPurposes: ['App\\Foo' => 'Does X (refined).'], snapshotId: 'R');
        $diff = (new AtlasCortexSnapshotDiffEngine)->diff($left, $right);

        $structural = $this->tmp.'/main.jsonl';
        $prose = $structural.'.prose.jsonl';

        $exporter = new AtlasCortexSnapshotDiffExporter;
        $structResult = $exporter->export($diff, $structural, 'app/');
        $proseResult = $exporter->proseExport($diff, $prose, 'app/');

        $this->assertSame(0, $structResult['exported'], 'no structural transitions when only prose changed');
        $this->assertSame(1, $proseResult['exported']);

        $proseBody = (string) file_get_contents($prose);
        $this->assertStringContainsString(AtlasLoopScopeComprehensionModel::PROVENANCE_WRITABLE_PROSE, $proseBody);
        $this->assertStringContainsString('Does X.', $proseBody);
        $this->assertStringContainsString('Does X (refined).', $proseBody);

        $structBody = (string) file_get_contents($structural);
        $this->assertStringNotContainsString(AtlasLoopScopeComprehensionModel::PROVENANCE_WRITABLE_PROSE, $structBody);
        $this->assertStringNotContainsString('Does X (refined).', $structBody);
    }

    public function test_empty_diff_produces_zero_line_file_and_exported_zero(): void
    {
        $m = $this->model([$this->inventoryItem('app/Foo.php', 'App\\Foo')]);
        $diff = (new AtlasCortexSnapshotDiffEngine)->diff($m, $m);

        $path = $this->tmp.'/empty.jsonl';
        $result = (new AtlasCortexSnapshotDiffExporter)->export($diff, $path);

        $this->assertSame(0, $result['exported']);
        $this->assertTrue(is_file($path));
        $this->assertSame(0, filesize($path));
    }
}
