<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Support;

use App\Services\Ai\Support\AppendOnlyJsonlStore;
use Tests\TestCase;

final class AppendOnlyJsonlStoreTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/atlas_jsonl_store_'.uniqid('', true).'/events.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @rmdir(dirname($this->path));

        parent::tearDown();
    }

    public function test_read_missing_file_returns_empty_list(): void
    {
        $this->assertSame([], AppendOnlyJsonlStore::read($this->path));
    }

    public function test_append_creates_directory_and_reads_valid_rows(): void
    {
        AppendOnlyJsonlStore::append($this->path, ['kind' => 'first']);
        AppendOnlyJsonlStore::append($this->path, ['kind' => 'second']);

        $this->assertSame([
            ['kind' => 'first'],
            ['kind' => 'second'],
        ], AppendOnlyJsonlStore::read($this->path));
    }

    public function test_read_ignores_invalid_or_non_array_lines(): void
    {
        AppendOnlyJsonlStore::append($this->path, ['kind' => 'valid']);
        file_put_contents($this->path, "not-json\n\"string\"\n", FILE_APPEND | LOCK_EX);

        $this->assertSame([
            ['kind' => 'valid'],
        ], AppendOnlyJsonlStore::read($this->path));
    }

    public function test_read_where_with_rejected_count_preserves_shape_filtering(): void
    {
        AppendOnlyJsonlStore::append($this->path, ['id' => 'ok']);
        AppendOnlyJsonlStore::append($this->path, ['id' => 123]);
        file_put_contents($this->path, "not-json\n", FILE_APPEND | LOCK_EX);

        [$rows, $rejected] = AppendOnlyJsonlStore::readWhereWithRejectedCount(
            $this->path,
            static fn (array $row): bool => isset($row['id']) && is_string($row['id']),
        );

        $this->assertSame([['id' => 'ok']], $rows);
        $this->assertSame(2, $rejected);
    }

    public function test_jsonl_files_in_directory_returns_only_jsonl_files(): void
    {
        $dir = dirname($this->path);
        @mkdir($dir, 0775, true);
        file_put_contents($dir.'/a.jsonl', '');
        file_put_contents($dir.'/b.txt', '');
        file_put_contents($dir.'/c.jsonl', '');

        $files = array_map('basename', AppendOnlyJsonlStore::jsonlFilesInDirectory($dir));
        sort($files);

        $this->assertSame(['a.jsonl', 'c.jsonl'], $files);
    }

    public function test_append_can_preserve_legacy_json_flags(): void
    {
        AppendOnlyJsonlStore::append(
            $this->path,
            ['text' => 'ação'],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        $this->assertStringContainsString('\\u00e7\\u00e3', (string) file_get_contents($this->path));
    }

    public function test_append_using_file_put_contents_preserves_legacy_flags(): void
    {
        AppendOnlyJsonlStore::appendUsingFilePutContents($this->path, ['text' => 'ação'], JSON_UNESCAPED_SLASHES);

        $this->assertStringContainsString('\\u00e7\\u00e3', (string) file_get_contents($this->path));
        $this->assertSame([['text' => 'ação']], AppendOnlyJsonlStore::read($this->path));
    }

    public function test_rewrite_replaces_existing_rows_with_locked_writer(): void
    {
        AppendOnlyJsonlStore::append($this->path, ['kind' => 'old']);

        AppendOnlyJsonlStore::rewrite($this->path, [
            ['kind' => 'new-1'],
            ['kind' => 'new-2'],
        ]);

        $this->assertSame([
            ['kind' => 'new-1'],
            ['kind' => 'new-2'],
        ], AppendOnlyJsonlStore::read($this->path));
    }

    public function test_append_using_file_put_contents_allows_legacy_write_flags(): void
    {
        AppendOnlyJsonlStore::appendUsingFilePutContents(
            $this->path,
            ['text' => 'ação'],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            FILE_APPEND,
            0o755,
        );

        $this->assertStringContainsString('ação', (string) file_get_contents($this->path));
        $this->assertSame([['text' => 'ação']], AppendOnlyJsonlStore::read($this->path));
    }

    public function test_append_silently_writes_when_possible(): void
    {
        AppendOnlyJsonlStore::appendSilently(
            $this->path,
            ['text' => 'ação'],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        $this->assertStringContainsString('ação', (string) file_get_contents($this->path));
        $this->assertSame([['text' => 'ação']], AppendOnlyJsonlStore::read($this->path));
    }

    public function test_append_encoded_line_silently_preserves_pre_encoded_payload(): void
    {
        AppendOnlyJsonlStore::appendEncodedLineSilently(
            $this->path,
            '{"kind":"pre_encoded"}',
            FILE_APPEND,
            0o755,
        );

        $this->assertSame([['kind' => 'pre_encoded']], AppendOnlyJsonlStore::read($this->path));
    }

    public function test_append_rows_using_file_put_contents_writes_one_batch(): void
    {
        AppendOnlyJsonlStore::appendRowsUsingFilePutContents(
            $this->path,
            [['kind' => 'first'], ['kind' => 'second']],
            static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );

        $this->assertSame([
            ['kind' => 'first'],
            ['kind' => 'second'],
        ], AppendOnlyJsonlStore::read($this->path));
    }
}
