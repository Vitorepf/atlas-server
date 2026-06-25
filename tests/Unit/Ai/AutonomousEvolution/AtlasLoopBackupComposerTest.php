<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Recovery\AtlasLoopBackupComposer;
use App\Services\Ai\AutonomousEvolution\Recovery\AtlasLoopBackupComposerException;
use Tests\TestCase;

final class AtlasLoopBackupComposerTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-backup-composer-'.bin2hex(random_bytes(6));
        @mkdir($this->root, 0o755, true);
        @mkdir($this->root.'/ledgers', 0o755, true);
        @mkdir($this->root.'/backups', 0o755, true);
    }

    protected function tearDown(): void
    {
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

    private function seedLedgers(): array
    {
        $a = $this->root.'/ledgers/attempt_ledger.jsonl';
        $b = $this->root.'/ledgers/impact_receipts.jsonl';
        $c = $this->root.'/ledgers/projection_outcomes.jsonl';
        file_put_contents($a, "{\"k\":1}\n{\"k\":2}\n");
        file_put_contents($b, "{\"i\":3}\n");
        file_put_contents($c, "{\"o\":4}\n");
        // Stable mtimes so byte-identical comparison is robust.
        touch($a, 1700000001);
        touch($b, 1700000002);
        touch($c, 1700000003);

        return [$a, $b, $c];
    }

    private function extractManifestFromTar(string $tarPath): array
    {
        $raw = (string) file_get_contents($tarPath);
        // First block: header for manifest.json
        $name = trim(substr($raw, 0, 100), "\0");
        $this->assertSame('manifest.json', $name);
        $sizeOctal = trim(substr($raw, 124, 12), "\0 ");
        $size = octdec($sizeOctal);
        $contents = substr($raw, 512, (int) $size);

        return json_decode($contents, true);
    }

    public function test_two_compose_calls_yield_identical_archive_sha256(): void
    {
        $files = $this->seedLedgers();
        $composer = new AtlasLoopBackupComposer($files, $this->root.'/backups');

        $first = $composer->compose($this->root.'/backups/first.tar');
        $second = $composer->compose($this->root.'/backups/second.tar');

        $mf1 = $this->extractManifestFromTar($first);
        $mf2 = $this->extractManifestFromTar($second);
        $this->assertSame($mf1['archive_sha256'], $mf2['archive_sha256']);
    }

    public function test_missing_ledger_path_throws_typed_exception_and_writes_no_tar(): void
    {
        $files = $this->seedLedgers();
        $files[] = $this->root.'/ledgers/does_not_exist.jsonl';
        $composer = new AtlasLoopBackupComposer($files, $this->root.'/backups');
        $destPath = $this->root.'/backups/partial.tar';

        try {
            $composer->compose($destPath);
            $this->fail('expected AtlasLoopBackupComposerException');
        } catch (AtlasLoopBackupComposerException $e) {
            $this->assertStringContainsString('does_not_exist.jsonl', $e->getMessage());
        }
        $this->assertFileDoesNotExist($destPath, 'no partial tar must be written on failure');
    }

    public function test_manifest_contains_per_file_sha_size_mtime_and_archive_sha_matches(): void
    {
        $files = $this->seedLedgers();
        $composer = new AtlasLoopBackupComposer($files, $this->root.'/backups');
        $dest = $composer->compose($this->root.'/backups/out.tar');

        $manifest = $this->extractManifestFromTar($dest);
        $this->assertCount(3, $manifest['files']);
        foreach ($manifest['files'] as $row) {
            $this->assertArrayHasKey('sha256', $row);
            $this->assertArrayHasKey('size', $row);
            $this->assertArrayHasKey('mtime', $row);
            $this->assertSame(64, strlen((string) $row['sha256']));
        }
        $recomputed = hash('sha256', implode("\0", array_map(
            static fn (array $f): string => $f['path'].'|'.$f['sha256'],
            $manifest['files'],
        )));
        $this->assertSame($manifest['archive_sha256'], $recomputed);
    }
}
