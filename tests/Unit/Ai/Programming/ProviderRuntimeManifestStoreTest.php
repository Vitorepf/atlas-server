<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming;

use App\Services\Ai\Programming\ProviderRuntimeManifestStore;
use Tests\TestCase;

final class ProviderRuntimeManifestStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/atlas_provider_manifest_store_'.uniqid('', true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir.'/*.json') as $file) {
            if (is_string($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function test_write_creates_random_pretty_json_manifest(): void
    {
        $first = ProviderRuntimeManifestStore::write($this->dir, ['provider' => 'cursor', 'allowed_files' => ['app/Foo.php']]);
        $second = ProviderRuntimeManifestStore::write($this->dir, ['provider' => 'cursor']);

        $this->assertFileExists($first);
        $this->assertFileExists($second);
        $this->assertNotSame($first, $second);
        $this->assertStringContainsString("\n    \"allowed_files\": [", (string) file_get_contents($first));
        $this->assertSame('cursor', json_decode((string) file_get_contents($first), true)['provider'] ?? null);
    }
}
