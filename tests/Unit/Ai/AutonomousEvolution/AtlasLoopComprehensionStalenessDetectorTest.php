<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopComprehensionStalenessDetector;
use Tests\TestCase;

final class AtlasLoopComprehensionStalenessDetectorTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->paths) as $path) {
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                foreach ((array) scandir($path) as $entry) {
                    if ($entry !== '.' && $entry !== '..') {
                        @unlink($path.'/'.$entry);
                    }
                }
                @rmdir($path);
            }
        }

        parent::tearDown();
    }

    public function test_fresh_snapshot_is_not_stale(): void
    {
        $now = 1_782_320_000;
        $path = $this->snapshotFile($now - 10);

        $result = (new AtlasLoopComprehensionStalenessDetector(1800, static fn (): int => $now))->detect($path);

        $this->assertFalse($result['is_stale']);
        $this->assertSame('fresh', $result['reason']);
        $this->assertSame(1800, $result['threshold_seconds']);
        $this->assertGreaterThanOrEqual(9, $result['snapshot_age_seconds']);
        $this->assertLessThanOrEqual(11, $result['snapshot_age_seconds']);
    }

    public function test_old_snapshot_is_stale(): void
    {
        $now = 1_782_320_000;
        $path = $this->snapshotFile($now - 3600);

        $result = (new AtlasLoopComprehensionStalenessDetector(1800, static fn (): int => $now))->detect($path);

        $this->assertTrue($result['is_stale']);
        $this->assertSame('older_than_threshold', $result['reason']);
        $this->assertSame(3600, $result['snapshot_age_seconds']);
    }

    public function test_missing_snapshot_returns_no_snapshot_without_throwing(): void
    {
        $result = (new AtlasLoopComprehensionStalenessDetector(1800, static fn (): int => 1_782_320_000))
            ->detect(sys_get_temp_dir().'/atlas-loop-missing-'.bin2hex(random_bytes(4)).'.json');

        $this->assertTrue($result['is_stale']);
        $this->assertSame('no_snapshot', $result['reason']);
        $this->assertNull($result['snapshot_age_seconds']);
        $this->assertSame(1800, $result['threshold_seconds']);
    }

    public function test_directory_uses_latest_serializer_snapshot_filename(): void
    {
        $now = 1_782_320_000;
        $dir = $this->tmpDir();
        file_put_contents($dir.'/not-a-snapshot.json', '{}');
        $old = $dir.'/snapshot-20260624150000-aaaaaaaaaaaa.json';
        $fresh = $dir.'/snapshot-20260624153000-bbbbbbbbbbbb.json';
        file_put_contents($old, '{}');
        file_put_contents($fresh, '{}');
        touch($old, $now - 3600);
        touch($fresh, $now - 20);

        $result = (new AtlasLoopComprehensionStalenessDetector(1800, static fn (): int => $now))->detect($dir);

        $this->assertFalse($result['is_stale']);
        $this->assertSame('fresh', $result['reason']);
        $this->assertSame(20, $result['snapshot_age_seconds']);
    }

    private function snapshotFile(int $mtime): string
    {
        $path = sys_get_temp_dir().'/snapshot-20260624153000-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($path, '{}');
        touch($path, $mtime);
        $this->paths[] = $path;

        return $path;
    }

    private function tmpDir(): string
    {
        $dir = sys_get_temp_dir().'/atlas-loop-staleness-'.bin2hex(random_bytes(4));
        mkdir($dir, 0o755, true);
        $this->paths[] = $dir;

        return $dir;
    }
}
