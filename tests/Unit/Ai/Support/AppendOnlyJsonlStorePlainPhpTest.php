<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Support;

use App\Services\Ai\Support\AppendOnlyJsonlStore;
use PHPUnit\Framework\TestCase;

final class AppendOnlyJsonlStorePlainPhpTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/atlas_jsonl_store_plain_'.uniqid('', true).'/events.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @rmdir(dirname($this->path));

        parent::tearDown();
    }

    public function test_append_creates_directory_without_laravel_facade_root(): void
    {
        AppendOnlyJsonlStore::append($this->path, ['kind' => 'plain_php']);

        self::assertSame([['kind' => 'plain_php']], AppendOnlyJsonlStore::read($this->path));
    }
}
