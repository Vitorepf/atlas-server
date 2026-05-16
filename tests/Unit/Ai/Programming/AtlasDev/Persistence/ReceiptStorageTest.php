<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Persistence;

use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ReceiptStorageTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/atlas-dev-receipts-'.bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpDir);
    }

    public function test_write_atomic_creates_artifact_and_returns_path(): void
    {
        $storage = new ReceiptStorage($this->tmpDir);
        $path = $storage->writeAtomic('run-1', 'scope_guard_receipt.json', ['hello' => 'world']);

        $this->assertFileExists($path);
        $this->assertStringEndsWith('scope_guard_receipt.json', $path);
        $this->assertSame(['hello' => 'world'], $storage->read('run-1', 'scope_guard_receipt.json'));
    }

    public function test_write_atomic_refuses_to_overwrite(): void
    {
        $storage = new ReceiptStorage($this->tmpDir);
        $storage->writeAtomic('run-1', 'verification_receipt.json', ['v' => 1]);

        $this->expectException(RuntimeException::class);
        $storage->writeAtomic('run-1', 'verification_receipt.json', ['v' => 2]);
    }

    public function test_invalid_run_id_rejected(): void
    {
        $storage = new ReceiptStorage($this->tmpDir);
        $this->expectException(InvalidArgumentException::class);
        $storage->writeAtomic('run/with/slash', 'a.json', []);
    }

    public function test_invalid_filename_rejected(): void
    {
        $storage = new ReceiptStorage($this->tmpDir);
        $this->expectException(InvalidArgumentException::class);
        $storage->writeAtomic('run-1', '../etc/passwd', []);
    }

    public function test_filename_must_end_in_json(): void
    {
        $storage = new ReceiptStorage($this->tmpDir);
        $this->expectException(InvalidArgumentException::class);
        $storage->writeAtomic('run-1', 'evidence.txt', []);
    }

    public function test_monotonic_versions_allocate_v1_then_v2(): void
    {
        $storage = new ReceiptStorage($this->tmpDir);
        $a = $storage->writeMonotonic('run-1', 'error_ledger', ['n' => 1]);
        $b = $storage->writeMonotonic('run-1', 'error_ledger', ['n' => 2]);

        $this->assertSame(1, $a['version']);
        $this->assertSame(2, $b['version']);
        $this->assertSame([1, 2], $storage->listVersions('run-1', 'error_ledger'));
        $this->assertSame(2, $storage->latestVersion('run-1', 'error_ledger'));
        $this->assertSame(['n' => 2], $storage->readLatestVersion('run-1', 'error_ledger'));
        $this->assertSame(['n' => 1], $storage->readVersion('run-1', 'error_ledger', 1));
    }

    public function test_read_returns_null_when_artifact_missing(): void
    {
        $storage = new ReceiptStorage($this->tmpDir);
        $this->assertNull($storage->read('run-1', 'scope_guard_receipt.json'));
        $this->assertNull($storage->readLatestVersion('run-1', 'error_ledger'));
        $this->assertSame([], $storage->listVersions('run-1', 'error_ledger'));
    }

    public function test_crash_mid_write_leaves_no_partial_artifact(): void
    {
        $storage = new ReceiptStorage($this->tmpDir);
        $runDir = $storage->ensureRunDirectory('run-1');

        // Simulate a previous crashed write by placing an orphaned tmp file.
        $orphan = $runDir.'/verification_receipt.json.tmp.deadbeefcafe';
        file_put_contents($orphan, '{ "partial": ');

        // The actual artifact must not exist.
        $this->assertFalse($storage->exists('run-1', 'verification_receipt.json'));

        // A real write of the canonical filename still succeeds.
        $storage->writeAtomic('run-1', 'verification_receipt.json', ['ok' => true]);
        $this->assertSame(['ok' => true], $storage->read('run-1', 'verification_receipt.json'));

        // Orphan tmp surfaces via listOrphanTempFiles for observability.
        $orphans = $storage->listOrphanTempFiles('run-1');
        $this->assertNotEmpty($orphans);
        foreach ($orphans as $f) {
            $this->assertStringContainsString('.tmp.', $f);
        }
    }

    public function test_read_throws_on_corrupt_json(): void
    {
        $storage = new ReceiptStorage($this->tmpDir);
        $dir = $storage->ensureRunDirectory('run-1');
        file_put_contents($dir.'/scope_guard_receipt.json', 'not-json');

        $this->expectException(RuntimeException::class);
        $storage->read('run-1', 'scope_guard_receipt.json');
    }

    public function test_artifacts_are_written_read_only(): void
    {
        $storage = new ReceiptStorage($this->tmpDir);
        $path = $storage->writeAtomic('run-1', 'scope_guard_receipt.json', ['n' => 1]);
        $perms = fileperms($path) & 0o777;
        // Atlas Dev artifacts must not be silently mutated; verify the chmod sentinel.
        $this->assertSame(0o444, $perms);
    }

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            if (is_dir($path)) {
                $this->rmrf($path);
            } else {
                @chmod($path, 0o644);
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
