<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Support;

use App\Services\Ai\Support\AppendOnlyJsonlStore;
use PHPUnit\Framework\TestCase;

/**
 * ARBOR-GRAFT LED1 — durable atomic rewrite of the resume ledger (temp + fsync + rename).
 */
final class AppendOnlyJsonlStoreRewriteAtomicTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/atlas-jsonl-'.bin2hex(random_bytes(4));
        @mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir.'/*') as $f) {
            @unlink((string) $f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_rewrite_atomic_writes_all_rows_and_is_readable(): void
    {
        $path = $this->dir.'/ledger.jsonl';
        $rows = [['a' => 1], ['b' => 2], ['c' => 3]];

        AppendOnlyJsonlStore::rewriteAtomic($path, $rows);

        $this->assertSame($rows, AppendOnlyJsonlStore::read($path));
    }

    public function test_rewrite_atomic_leaves_no_temp_file(): void
    {
        $path = $this->dir.'/ledger.jsonl';
        AppendOnlyJsonlStore::rewriteAtomic($path, [['x' => 1]]);

        $temps = glob($this->dir.'/*.tmp.*');
        $this->assertSame([], $temps === false ? [] : $temps, 'no residual .tmp.* file after atomic rewrite');
    }

    public function test_rewrite_atomic_replaces_existing_file_completely(): void
    {
        $path = $this->dir.'/ledger.jsonl';
        AppendOnlyJsonlStore::rewriteAtomic($path, [['old' => 1], ['old' => 2], ['old' => 3]]);
        // A shorter new set must fully replace the old (no leftover tail from the longer previous file).
        AppendOnlyJsonlStore::rewriteAtomic($path, [['new' => 1]]);

        $this->assertSame([['new' => 1]], AppendOnlyJsonlStore::read($path));
    }

    public function test_corruption_tolerance_preserved_after_atomic_rewrite(): void
    {
        $path = $this->dir.'/ledger.jsonl';
        AppendOnlyJsonlStore::rewriteAtomic($path, [['a' => 1], ['b' => 2]]);
        // Simulate a truncated final line appended after the atomic write (interrupted append).
        file_put_contents($path, '{"c":3', FILE_APPEND);

        // read() must skip the corrupt tail, never throw.
        $this->assertSame([['a' => 1], ['b' => 2]], AppendOnlyJsonlStore::read($path));
    }
}
