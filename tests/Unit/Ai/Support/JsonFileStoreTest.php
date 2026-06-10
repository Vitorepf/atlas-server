<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Support;

use App\Services\Ai\Support\JsonFileStore;
use Tests\TestCase;

final class JsonFileStoreTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/atlas_json_file_store_'.uniqid('', true).'/state.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @rmdir(dirname($this->path));

        parent::tearDown();
    }

    public function test_read_missing_file_returns_null(): void
    {
        $this->assertNull(JsonFileStore::readArray($this->path));
    }

    public function test_write_creates_directory_and_reads_array(): void
    {
        JsonFileStore::write($this->path, ['status' => 'locked', 'count' => 2]);

        $this->assertSame(['status' => 'locked', 'count' => 2], JsonFileStore::readArray($this->path));
    }

    public function test_read_invalid_or_non_array_payload_returns_null(): void
    {
        @mkdir(dirname($this->path), 0775, true);
        file_put_contents($this->path, 'not-json');

        $this->assertNull(JsonFileStore::readArray($this->path));

        file_put_contents($this->path, '"string"');

        $this->assertNull(JsonFileStore::readArray($this->path));
    }

    public function test_write_can_preserve_legacy_json_flags(): void
    {
        JsonFileStore::write($this->path, ['path' => 'a/b'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $this->assertStringContainsString("    \"path\": \"a/b\"", (string) file_get_contents($this->path));
    }

    public function test_write_line_preserves_trailing_newline_for_json_documents(): void
    {
        JsonFileStore::writeLine($this->path, ['path' => 'a/b'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $contents = (string) file_get_contents($this->path);
        $this->assertStringContainsString("    \"path\": \"a/b\"", $contents);
        $this->assertStringEndsWith(PHP_EOL, $contents);
    }

    public function test_write_atomic_replaces_existing_file_without_tmp_leftover(): void
    {
        JsonFileStore::write($this->path, ['status' => 'old']);

        JsonFileStore::writeAtomic($this->path, ['status' => 'new']);

        $this->assertSame(['status' => 'new'], JsonFileStore::readArray($this->path));
        $this->assertSame([], glob($this->path.'.tmp-*') ?: []);
    }

    public function test_write_temporary_sanitizes_prefix_sets_mode_and_deletes_quietly(): void
    {
        $dir = dirname($this->path).'/tmp';

        $path = JsonFileStore::writeTemporary(
            $dir,
            'atlas runtime/manifest',
            ['ok' => true],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            0750,
            0640,
        );

        $this->assertStringStartsWith($dir.'/atlas-runtime-manifest-', $path);
        $this->assertSame(['ok' => true], JsonFileStore::readArray($path));
        $this->assertSame('0640', substr(sprintf('%o', fileperms($path)), -4));

        JsonFileStore::deleteQuietly($path);
        JsonFileStore::deleteQuietly($path);

        $this->assertFileDoesNotExist($path);

        @rmdir($dir);
    }
}
