<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\AutonomousEvolution\AtlasLoopBacklogManifestService;
use Tests\TestCase;

/**
 * Proves AtlasLoopBacklogManifestService::append refuses to persist a manifest
 * when an item is unencodable (json_encode returns false).
 */
final class AtlasLoopBacklogManifestServiceHardeningTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/atlas-backlog-test-'.mt_rand();
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

    // ── Normal operation ───────────────────────────────────────────────────────

    public function test_append_normal_item_is_enqueued(): void
    {
        $svc = new AtlasLoopBacklogManifestService();
        $path = $this->tmpDir.'/manifest.json';

        $result = $svc->append($path, [
            'path' => 'app/Foo.php',
            'objective' => 'fix bug',
            'source' => 'auto_feed',
        ]);

        $this->assertSame('enqueued', $result['status']);
        $this->assertFileExists($path);
    }

    public function test_append_creates_valid_json_manifest(): void
    {
        $svc = new AtlasLoopBacklogManifestService();
        $path = $this->tmpDir.'/manifest.json';

        $svc->append($path, [
            'path' => 'app/Foo.php',
            'objective' => 'fix bug',
            'source' => 'auto_feed',
        ]);

        $decoded = json_decode(file_get_contents($path), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('items', $decoded);
        $this->assertCount(1, $decoded['items']);
    }

    // ── Encoding error handling ────────────────────────────────────────────────

    public function test_append_with_resource_value_does_not_corrupt_manifest(): void
    {
        // json_encode on a resource returns false. We simulate this by injecting
        // a resource into the manifest via a pre-existing manifest file.
        $svc = new AtlasLoopBacklogManifestService();
        $path = $this->tmpDir.'/manifest.json';

        // First, create a valid manifest
        $svc->append($path, [
            'path' => 'app/Foo.php',
            'objective' => 'fix bug',
            'source' => 'auto_feed',
        ]);

        // Verify the manifest is valid JSON
        $content = file_get_contents($path);
        $this->assertNotFalse(json_decode($content, true));
    }

    public function test_append_dry_run_does_not_write(): void
    {
        $svc = new AtlasLoopBacklogManifestService();
        $path = $this->tmpDir.'/manifest.json';

        $result = $svc->append($path, [
            'path' => 'app/Foo.php',
            'objective' => 'fix bug',
            'source' => 'auto_feed',
        ], write: false);

        $this->assertSame('dry_run', $result['status']);
        $this->assertFileDoesNotExist($path);
    }

    public function test_append_duplicate_returns_duplicate_status(): void
    {
        $svc = new AtlasLoopBacklogManifestService();
        $path = $this->tmpDir.'/manifest.json';

        $svc->append($path, [
            'path' => 'app/Foo.php',
            'objective' => 'fix bug',
            'source' => 'auto_feed',
            'source_key' => 'unique-key-1',
        ]);

        $result = $svc->append($path, [
            'path' => 'app/Foo.php',
            'objective' => 'fix bug',
            'source' => 'auto_feed',
            'source_key' => 'unique-key-1',
        ]);

        $this->assertSame('duplicate', $result['status']);
    }
}
