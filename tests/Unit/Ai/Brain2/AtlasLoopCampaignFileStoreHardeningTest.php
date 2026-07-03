<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\AutonomousEvolution\Campaign\AtlasLoopCampaignFileStore;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasLoopCampaignFileStore::readLock treats a corrupt lock.json as
 * locked (fail closed) rather than free.
 */
final class AtlasLoopCampaignFileStoreHardeningTest extends TestCase
{
    private string $tmpDir;
    private string $campaignId = 'test-campaign';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/atlas-campaign-test-'.mt_rand();
        // Ensure the campaign subdirectory exists
        $dir = $this->tmpDir.'/'.$this->campaignId;
        mkdir($dir, 0o755, true);
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

    private function lockPath(): string
    {
        return $this->tmpDir.'/'.$this->campaignId.'/lock.json';
    }

    // ── Corrupt lock is treated as held ────────────────────────────────────────

    public function test_corrupt_lock_json_is_detected(): void
    {
        file_put_contents($this->lockPath(), '{"pid": 123'); // truncated JSON

        $this->assertTrue(AtlasLoopCampaignFileStore::isLockCorrupt($this->campaignId, $this->tmpDir));
    }

    public function test_corrupt_lock_prevents_acquire(): void
    {
        file_put_contents($this->lockPath(), 'not-json-at-all');

        $this->assertFalse(
            AtlasLoopCampaignFileStore::acquireLock($this->campaignId, 60, $this->tmpDir),
            'Corrupt lock must block acquisition (fail closed)',
        );
    }

    public function test_corrupt_lock_readLock_returns_null(): void
    {
        file_put_contents($this->lockPath(), '{"pid": 123'); // truncated

        $this->assertNull(
            AtlasLoopCampaignFileStore::readLock($this->campaignId, $this->tmpDir),
            'readLock returns null for corrupt data',
        );
    }

    public function test_missing_lock_is_not_corrupt(): void
    {
        $this->assertFalse(
            AtlasLoopCampaignFileStore::isLockCorrupt($this->campaignId, $this->tmpDir),
            'Missing lock file is not corrupt',
        );
    }

    public function test_valid_lock_is_not_corrupt(): void
    {
        file_put_contents($this->lockPath(), json_encode(['pid' => 123, 'expires_at' => time() + 60]));

        $this->assertFalse(
            AtlasLoopCampaignFileStore::isLockCorrupt($this->campaignId, $this->tmpDir),
            'Valid lock file is not corrupt',
        );
    }

    public function test_empty_lock_file_is_corrupt(): void
    {
        file_put_contents($this->lockPath(), '');

        $this->assertTrue(
            AtlasLoopCampaignFileStore::isLockCorrupt($this->campaignId, $this->tmpDir),
            'Empty lock file is corrupt',
        );
    }

    public function test_null_json_is_corrupt(): void
    {
        file_put_contents($this->lockPath(), 'null');

        $this->assertTrue(
            AtlasLoopCampaignFileStore::isLockCorrupt($this->campaignId, $this->tmpDir),
            'JSON null is corrupt (not an array)',
        );
    }
}
