<?php

namespace Tests\Unit;

use App\Services\Semantic\VaultFileStore;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

class AtlasVaultFileStoreTest extends TestCase
{
    private string $vault;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vault = sys_get_temp_dir().'/atlas-vault-store-test-'.bin2hex(random_bytes(4));
        config()->set('atlas.semantic_memory.vault_path', $this->vault);
    }

    protected function tearDown(): void
    {
        if (is_link($this->vault.'/LinkedOutside')) {
            unlink($this->vault.'/LinkedOutside');
        }
        if (is_link($this->vault.'/LinkedFile.md')) {
            unlink($this->vault.'/LinkedFile.md');
        }
        File::deleteDirectory($this->vault);

        parent::tearDown();
    }

    public function test_absolute_path_can_resolve_without_creating_missing_vault_root(): void
    {
        $path = (new VaultFileStore)->absolutePath('Atlas\\Memory//note.md', ensureRoot: false);

        $this->assertSame($this->vault.'/Atlas/Memory/note.md', $path);
        $this->assertFalse(File::isDirectory($this->vault));
    }

    public function test_absolute_path_blocks_parent_traversal(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('parent traversal');

        (new VaultFileStore)->absolutePath('../outside.md', ensureRoot: false);
    }

    public function test_absolute_path_blocks_symlinked_directory_outside_vault(): void
    {
        if (! function_exists('symlink')) {
            $this->markTestSkipped('symlink is unavailable on this platform.');
        }

        $outside = sys_get_temp_dir().'/atlas-vault-store-outside-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->vault);
        File::ensureDirectoryExists($outside);

        if (! @symlink($outside, $this->vault.'/LinkedOutside')) {
            File::deleteDirectory($outside);
            $this->markTestSkipped('symlink creation failed on this platform.');
        }

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Unsafe vault path');

            (new VaultFileStore)->absolutePath('LinkedOutside/unsafe.md');
        } finally {
            if (is_link($this->vault.'/LinkedOutside')) {
                unlink($this->vault.'/LinkedOutside');
            }
            File::deleteDirectory($outside);
        }
    }

    public function test_absolute_path_blocks_symlinked_file_outside_vault(): void
    {
        if (! function_exists('symlink')) {
            $this->markTestSkipped('symlink is unavailable on this platform.');
        }

        $outside = sys_get_temp_dir().'/atlas-vault-store-outside-file-'.bin2hex(random_bytes(4)).'.md';
        File::ensureDirectoryExists($this->vault);
        File::put($outside, 'outside');

        if (! @symlink($outside, $this->vault.'/LinkedFile.md')) {
            File::delete($outside);
            $this->markTestSkipped('symlink creation failed on this platform.');
        }

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Unsafe vault path');

            (new VaultFileStore)->absolutePath('LinkedFile.md');
        } finally {
            if (is_link($this->vault.'/LinkedFile.md')) {
                unlink($this->vault.'/LinkedFile.md');
            }
            File::delete($outside);
        }
    }
}
