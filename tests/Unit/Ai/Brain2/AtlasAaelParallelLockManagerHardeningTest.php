<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\AutonomousEvolution\Aael\Parallel\AtlasAaelParallelLockManager;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasAaelParallelLockManager::acquire does not grant a held lock when
 * the lock-file read fails (stream_get_contents returns false).
 */
final class AtlasAaelParallelLockManagerHardeningTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/atlas-lock-test-'.mt_rand();
        mkdir($this->tmpDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir.'/'.$file;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ── Normal operation still works ───────────────────────────────────────────

    public function test_acquire_succeeds_on_empty_ledger(): void
    {
        $manager = new AtlasAaelParallelLockManager($this->tmpDir.'/ledger.json');
        $handle = $manager->acquire('step-1', ['app/Foo.php']);

        $this->assertNotNull($handle);
        $this->assertSame('step-1', $handle->stepId);
    }

    public function test_acquire_refuses_overlapping_lock(): void
    {
        $manager = new AtlasAaelParallelLockManager($this->tmpDir.'/ledger.json');
        $manager->acquire('step-1', ['app/Foo.php']);

        $handle = $manager->acquire('step-2', ['app/Foo.php']);

        $this->assertNull($handle, 'Overlapping lock must be refused');
    }

    // ── Read failure is fail-closed ────────────────────────────────────────────

    public function test_acquire_fails_when_ledger_file_is_unreadable(): void
    {
        $ledgerPath = $this->tmpDir.'/unreadable-ledger.json';
        // Create a ledger with a valid lock
        file_put_contents($ledgerPath, json_encode(['locks' => [
            ['lock_id' => 'abc', 'step_id' => 'step-1', 'write_set' => ['app/Foo.php'], 'acquired_at_unix' => time()],
        ]]));
        // Make it unreadable
        chmod($ledgerPath, 0o000);

        $manager = new AtlasAaelParallelLockManager($ledgerPath);

        // This should fail closed — not grant a lock that overlaps with the held one
        $handle = $manager->acquire('step-2', ['app/Bar.php']);

        // Restore permissions for cleanup
        chmod($ledgerPath, 0o644);

        // The acquire should return null (fail closed) because it can't read the ledger
        $this->assertNull($handle, 'Acquire must fail closed when ledger is unreadable');
    }

    public function test_acquire_succeeds_when_ledger_is_absent(): void
    {
        $manager = new AtlasAaelParallelLockManager($this->tmpDir.'/nonexistent.json');
        $handle = $manager->acquire('step-1', ['app/Foo.php']);

        $this->assertNotNull($handle);
    }

    public function test_acquire_fails_on_malformed_ledger(): void
    {
        $ledgerPath = $this->tmpDir.'/malformed.json';
        file_put_contents($ledgerPath, 'not-json');

        $manager = new AtlasAaelParallelLockManager($ledgerPath);
        $handle = $manager->acquire('step-1', ['app/Foo.php']);

        $this->assertNull($handle, 'Malformed ledger must fail closed');
    }
}
