<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Campaign;

use App\Services\Ai\AutonomousEvolution\Campaign\AtlasLoopCampaignFileStore;
use Tests\TestCase;

class AtlasLoopCampaignFileStoreTest extends TestCase
{
    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempRoot = sys_get_temp_dir().'/atlas_file_store_test_'.uniqid();
        @mkdir($this->tempRoot, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tempRoot);
        parent::tearDown();
    }

    private function rmdirRecursive(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->rmdirRecursive($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    public function test_storage_dir_uses_default_root(): void
    {
        $dir = AtlasLoopCampaignFileStore::storageDir('c1');

        self::assertStringEndsWith('/c1', $dir);
        self::assertStringContainsString('atlas-loop/campaign', $dir);
    }

    public function test_storage_dir_uses_custom_root(): void
    {
        $dir = AtlasLoopCampaignFileStore::storageDir('c1', $this->tempRoot);

        self::assertSame($this->tempRoot.'/c1', $dir);
    }

    public function test_storage_dir_trims_trailing_slash(): void
    {
        self::assertSame(
            $this->tempRoot.'/c1',
            AtlasLoopCampaignFileStore::storageDir('c1', $this->tempRoot.'/'),
        );
    }

    public function test_ensure_storage_creates_directory(): void
    {
        AtlasLoopCampaignFileStore::ensureStorage('c1', $this->tempRoot);

        self::assertDirectoryExists($this->tempRoot.'/c1');
    }

    public function test_ledger_path_returns_jsonl(): void
    {
        $path = AtlasLoopCampaignFileStore::ledgerPath('c1', $this->tempRoot);

        self::assertSame($this->tempRoot.'/c1/ledger.jsonl', $path);
    }

    public function test_kill_switch_path(): void
    {
        self::assertSame($this->tempRoot.'/c1/KILL', AtlasLoopCampaignFileStore::killSwitchPath('c1', $this->tempRoot));
    }

    public function test_pause_path(): void
    {
        self::assertSame($this->tempRoot.'/c1/PAUSE', AtlasLoopCampaignFileStore::pausePath('c1', $this->tempRoot));
    }

    public function test_kill_file_exists_false_when_missing(): void
    {
        self::assertFalse(AtlasLoopCampaignFileStore::killFileExists('c1', $this->tempRoot));
    }

    public function test_kill_file_exists_true_when_present(): void
    {
        AtlasLoopCampaignFileStore::ensureStorage('c1', $this->tempRoot);
        file_put_contents(AtlasLoopCampaignFileStore::killSwitchPath('c1', $this->tempRoot), '1');

        self::assertTrue(AtlasLoopCampaignFileStore::killFileExists('c1', $this->tempRoot));
    }

    public function test_pause_file_exists_true_when_present(): void
    {
        AtlasLoopCampaignFileStore::ensureStorage('c1', $this->tempRoot);
        file_put_contents(AtlasLoopCampaignFileStore::pausePath('c1', $this->tempRoot), '1');

        self::assertTrue(AtlasLoopCampaignFileStore::pauseFileExists('c1', $this->tempRoot));
    }

    public function test_write_heartbeat_creates_file(): void
    {
        AtlasLoopCampaignFileStore::ensureStorage('c1', $this->tempRoot);
        AtlasLoopCampaignFileStore::writeHeartbeat('c1', 1234567890, $this->tempRoot);

        self::assertSame('1234567890', file_get_contents($this->tempRoot.'/c1/heartbeat'));
    }

    public function test_append_ledger_writes_jsonl(): void
    {
        AtlasLoopCampaignFileStore::ensureStorage('c1', $this->tempRoot);
        AtlasLoopCampaignFileStore::appendLedger('c1', ['event' => 'a'], $this->tempRoot);
        AtlasLoopCampaignFileStore::appendLedger('c1', ['event' => 'b'], $this->tempRoot);

        $content = (string) file_get_contents(AtlasLoopCampaignFileStore::ledgerPath('c1', $this->tempRoot));
        self::assertStringContainsString('"event":"a"', $content);
        self::assertStringContainsString('"event":"b"', $content);
    }

    public function test_read_lock_returns_null_when_missing(): void
    {
        self::assertNull(AtlasLoopCampaignFileStore::readLock('c1', $this->tempRoot));
    }

    public function test_acquire_lock_succeeds_when_no_existing(): void
    {
        $now = 1000000;
        AtlasLoopCampaignFileStore::ensureStorage('c1', $this->tempRoot);
        $result = AtlasLoopCampaignFileStore::acquireLock('c1', 3600, $this->tempRoot, $now);

        self::assertTrue($result);
        $lock = AtlasLoopCampaignFileStore::readLock('c1', $this->tempRoot);
        self::assertNotNull($lock);
        self::assertGreaterThan($now, $lock['expires_at']);
    }

    public function test_acquire_lock_fails_when_held_by_live_process(): void
    {
        $now = 1000000;
        AtlasLoopCampaignFileStore::ensureStorage('c1', $this->tempRoot);
        AtlasLoopCampaignFileStore::acquireLock('c1', 3600, $this->tempRoot, $now);

        // Current PID is alive (we're running this test). Use the actual PID.
        $result = AtlasLoopCampaignFileStore::acquireLock('c1', 3600, $this->tempRoot, $now + 10);

        self::assertFalse($result);
    }

    public function test_acquire_lock_steals_expired_lock(): void
    {
        $now = 1000000;
        AtlasLoopCampaignFileStore::ensureStorage('c1', $this->tempRoot);
        AtlasLoopCampaignFileStore::acquireLock('c1', 3600, $this->tempRoot, $now - 10000);

        // Expired lock — should be stealable even from a "live" PID
        $result = AtlasLoopCampaignFileStore::acquireLock('c1', 3600, $this->tempRoot, $now);

        self::assertTrue($result);
    }

    public function test_release_lock_deletes_file(): void
    {
        $now = 1000000;
        AtlasLoopCampaignFileStore::ensureStorage('c1', $this->tempRoot);
        AtlasLoopCampaignFileStore::acquireLock('c1', 3600, $this->tempRoot, $now);
        self::assertNotNull(AtlasLoopCampaignFileStore::readLock('c1', $this->tempRoot));

        AtlasLoopCampaignFileStore::releaseLock('c1', $this->tempRoot);

        self::assertNull(AtlasLoopCampaignFileStore::readLock('c1', $this->tempRoot));
    }

    public function test_lock_process_is_dead_zero_pid(): void
    {
        self::assertTrue(AtlasLoopCampaignFileStore::lockProcessIsDead(0));
    }

    public function test_lock_process_is_dead_negative_pid(): void
    {
        self::assertTrue(AtlasLoopCampaignFileStore::lockProcessIsDead(-1));
    }

    public function test_lock_process_is_dead_current_pid_is_alive(): void
    {
        if (! function_exists('posix_kill')) {
            self::markTestSkipped('posix_kill not available');
        }
        self::assertFalse(AtlasLoopCampaignFileStore::lockProcessIsDead(getmypid()));
    }
}
