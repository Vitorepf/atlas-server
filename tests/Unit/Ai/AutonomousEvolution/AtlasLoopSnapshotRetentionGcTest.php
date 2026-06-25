<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Retention\AtlasLoopSnapshotRetentionGc;
use App\Services\Ai\AutonomousEvolution\Retention\AtlasLoopSnapshotRetentionPolicy;
use Tests\TestCase;

class AtlasLoopSnapshotRetentionGcTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/atlas-loop-retention-gc-'.bin2hex(random_bytes(6));
        @mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    private function seedSnapshots(int $count): array
    {
        $ids = [];
        for ($i = $count; $i >= 1; $i--) {
            $id = sprintf('snap-%03d', $i);
            file_put_contents($this->tempDir.'/'.$id.'.json', '{}');
            $ids[] = $id;
        }

        return $ids; // newest first (highest number first)
    }

    public function test_scan_emits_advisory_with_kept_and_prune_candidate_ids(): void
    {
        $ids = $this->seedSnapshots(8);

        $gc = new AtlasLoopSnapshotRetentionGc(
            policy: new AtlasLoopSnapshotRetentionPolicy(keepLastN: 3, keepEveryMth: 2),
            snapshotIndexSource: fn (): array => $ids,
            clock: fn (): string => '2026-06-25T00:00:00Z',
        );

        $advisory = $gc->scan();

        self::assertSame(AtlasLoopSnapshotRetentionGc::SCHEMA, $advisory['schema_version']);
        self::assertSame('2026-06-25T00:00:00Z', $advisory['scanned_at']);
        self::assertStringStartsWith('policy_', $advisory['policy_fingerprint']);
        self::assertSame(8, count($advisory['kept_ids']) + count($advisory['prune_candidate_ids']));
        // First 3 ids are kept by within_keep_last_n
        self::assertSame(['snap-008', 'snap-007', 'snap-006'], array_slice($advisory['kept_ids'], 0, 3));
        self::assertSame(count($advisory['kept_ids']), $advisory['totals']['kept']);
        self::assertSame(count($advisory['prune_candidate_ids']), $advisory['totals']['prune_candidate']);
    }

    public function test_scan_is_read_only_filesystem_count_unchanged_before_and_after(): void
    {
        $ids = $this->seedSnapshots(6);
        $countBefore = count(glob($this->tempDir.'/*') ?: []);
        $mtimesBefore = [];
        foreach (glob($this->tempDir.'/*') ?: [] as $f) {
            $mtimesBefore[$f] = filemtime($f);
        }

        $gc = new AtlasLoopSnapshotRetentionGc(
            new AtlasLoopSnapshotRetentionPolicy(2, 2),
            fn (): array => $ids,
            fn (): string => '2026-06-25T00:00:00Z',
        );
        $gc->scan();

        $countAfter = count(glob($this->tempDir.'/*') ?: []);
        self::assertSame($countBefore, $countAfter, 'scan() must NOT delete files');
        foreach ($mtimesBefore as $f => $m) {
            self::assertFileExists($f);
            self::assertSame($m, filemtime($f), "scan() must NOT touch mtime of {$f}");
        }
    }

    public function test_gc_source_does_not_reference_any_destructive_token(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/AutonomousEvolution/Retention/AtlasLoopSnapshotRetentionGc.php'));
        foreach (['unlink(', 'rmdir(', 'File::delete', 'DB::delete', 'Storage::delete', 'rename(', '->delete('] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $src, "GC must not contain destructive token {$forbidden}");
        }
    }

    public function test_empty_index_yields_empty_advisory(): void
    {
        $gc = new AtlasLoopSnapshotRetentionGc(
            new AtlasLoopSnapshotRetentionPolicy(3, 5),
            fn (): array => [],
            fn (): string => '2026-06-25T00:00:00Z',
        );

        $advisory = $gc->scan();
        self::assertSame([], $advisory['kept_ids']);
        self::assertSame([], $advisory['prune_candidate_ids']);
        self::assertSame(['kept' => 0, 'prune_candidate' => 0], $advisory['totals']);
    }

    public function test_advisory_is_deterministic_for_identical_input(): void
    {
        $ids = $this->seedSnapshots(5);
        $gc = new AtlasLoopSnapshotRetentionGc(
            new AtlasLoopSnapshotRetentionPolicy(2, 2),
            fn (): array => $ids,
            fn (): string => '2026-06-25T00:00:00Z',
        );
        $a = $gc->scan();
        $b = $gc->scan();

        self::assertSame($a, $b);
    }
}
