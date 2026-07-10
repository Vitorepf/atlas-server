<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Core\BundleManifest;
use App\Services\Ai\Rivals\Support\RunPaths;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BundleManifestTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/rivals_bundle_manifest_'.uniqid();
        config()->set('atlas_rivals.storage_root', $this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function test_portable_bundle_detects_any_file_tamper(): void
    {
        $runId = '20260709_000000_abcdef12';
        RunPaths::ensureDir(RunPaths::runDir($runId));
        file_put_contents(RunPaths::planPath($runId), '{"plan":true}');
        file_put_contents(RunPaths::receiptsPath($runId), "{\"receipt\":true}\n");

        $manifest = (new BundleManifest)->build($runId);
        $this->assertNotEmpty($manifest['bundle_hash']);
        $this->assertTrue((new BundleManifest)->verify(RunPaths::runDir($runId))['verified']);

        file_put_contents(RunPaths::planPath($runId), '{"plan":false}');
        $verify = (new BundleManifest)->verify(RunPaths::runDir($runId));
        $this->assertFalse($verify['verified']);
        $this->assertContains('bundle_file_hash_mismatch:plan.json', $verify['failures']);
    }
}
