<?php

declare(strict_types=1);

namespace Tests\Unit\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphQueryCache;
use App\Services\Engineering\CodeGraph\CodeGraphQueryCacheStore;
use Tests\TestCase;

/**
 * AP-815 · E-9 — Pure unit coverage for the code-graph query cache.
 *
 * No database, no cache driver: the cache is exercised entirely in memory.
 */
class CodeGraphQueryCacheTest extends TestCase
{
    public function test_remember_runs_producer_once_then_hits(): void
    {
        $cache = new CodeGraphQueryCache();
        $calls = 0;
        $producer = function () use (&$calls) {
            $calls++;

            return ['nodes' => 3];
        };

        $first = $cache->remember('ws-a', 'q1', $producer);
        $second = $cache->remember('ws-a', 'q1', $producer);

        $this->assertSame(['nodes' => 3], $first);
        $this->assertSame(['nodes' => 3], $second, 'second call returns the cached value');
        $this->assertSame(1, $calls, 'producer ran exactly once across two remember() calls');

        $stats = $cache->stats();
        $this->assertSame(1, $stats['misses'], 'first remember was a miss');
        $this->assertSame(1, $stats['hits'], 'second remember was a hit');
        $this->assertSame(1, $stats['entries']);
    }

    public function test_invalidate_workspace_clears_only_that_workspace(): void
    {
        $cache = new CodeGraphQueryCache();
        $cache->put('ws-a', 'q1', 'A1');
        $cache->put('ws-a', 'q2', 'A2');
        $cache->put('ws-b', 'q1', 'B1');

        $removed = $cache->invalidateWorkspace('ws-a');

        $this->assertSame(2, $removed, 'returns the number of entries cleared');
        $this->assertFalse($cache->has('ws-a', 'q1'));
        $this->assertFalse($cache->has('ws-a', 'q2'));
        $this->assertTrue($cache->has('ws-b', 'q1'), 'workspace B is untouched');
        $this->assertSame('B1', $cache->get('ws-b', 'q1'));
        $this->assertSame(1, $cache->stats()['entries']);

        // Invalidating an unknown workspace is safe and reports zero.
        $this->assertSame(0, $cache->invalidateWorkspace('ws-missing'));
    }

    public function test_null_is_a_first_class_cached_value(): void
    {
        // Edge case: a producer that legitimately returns null must be cached
        // once (not re-run on every call), and has() must report presence.
        $cache = new CodeGraphQueryCache();
        $calls = 0;
        $producer = function () use (&$calls) {
            $calls++;

            return null;
        };

        $this->assertNull($cache->remember('ws-a', 'empty', $producer));
        $this->assertNull($cache->remember('ws-a', 'empty', $producer));

        $this->assertSame(1, $calls, 'null result is memoized, producer ran once');
        $this->assertTrue($cache->has('ws-a', 'empty'), 'cached null counts as present');
        $this->assertSame(1, $cache->stats()['hits']);
        $this->assertSame(1, $cache->stats()['misses']);
    }

    public function test_blank_keys_degrade_to_a_safe_bucket_without_throwing(): void
    {
        // Edge case: empty / whitespace-only keys must not throw and must
        // address one stable slot (trim-normalized), so '' and '   ' collide.
        $cache = new CodeGraphQueryCache();

        $cache->put('', '', 'safe');
        $this->assertSame('safe', $cache->get('   ', '   '), 'blank + whitespace keys map to the same bucket');
        $this->assertTrue($cache->has('', ''));

        $calls = 0;
        $value = $cache->remember('', '', function () use (&$calls) {
            $calls++;

            return 'replaced-only-if-missing';
        });
        $this->assertSame('safe', $value, 'existing blank-key value is returned, producer skipped');
        $this->assertSame(0, $calls);
    }

    public function test_get_miss_returns_null_and_counts_miss(): void
    {
        $cache = new CodeGraphQueryCache();

        $this->assertNull($cache->get('ws-a', 'nope'));
        $this->assertFalse($cache->has('ws-a', 'nope'), 'has() does not affect stats');

        $stats = $cache->stats();
        $this->assertSame(0, $stats['hits']);
        $this->assertSame(1, $stats['misses'], 'only the get() miss is counted, not has()');
        $this->assertSame(0, $stats['entries']);
    }

    public function test_put_overwrites_and_forget_removes(): void
    {
        $cache = new CodeGraphQueryCache();

        $cache->put('ws-a', 'q1', 'v1');
        $cache->put('ws-a', 'q1', 'v2');
        $this->assertSame('v2', $cache->get('ws-a', 'q1'), 'put overwrites in place');
        $this->assertSame(1, $cache->stats()['entries'], 'overwrite does not duplicate the entry');

        $cache->forget('ws-a', 'q1');
        $this->assertFalse($cache->has('ws-a', 'q1'));
        $this->assertSame(0, $cache->stats()['entries']);

        // forget on an absent key is a safe no-op.
        $cache->forget('ws-a', 'q1');
        $cache->forget('ws-missing', 'whatever');
        $this->assertSame(0, $cache->stats()['entries']);
    }

    public function test_accepts_an_injected_pre_seeded_store(): void
    {
        // The store is an injectable seam: a caller can pre-seed and share it.
        $store = new CodeGraphQueryCacheStore([
            'ws-a' => ['q1' => 'seeded'],
        ]);
        $cache = new CodeGraphQueryCache($store);

        $calls = 0;
        $value = $cache->remember('ws-a', 'q1', function () use (&$calls) {
            $calls++;

            return 'fresh';
        });

        $this->assertSame('seeded', $value, 'seeded value served, producer not run');
        $this->assertSame(0, $calls);
        $this->assertSame(1, $cache->stats()['hits']);
    }

    public function test_malformed_seed_rows_are_ignored_not_fatal(): void
    {
        // Fail-safe: a malformed seed (non-array bucket) is dropped silently.
        /** @phpstan-ignore-next-line intentional malformed input for the safety test */
        $store = new CodeGraphQueryCacheStore([
            'ws-good' => ['q1' => 'ok'],
            'ws-bad' => 'not-an-array',
        ]);

        $this->assertSame('ok', $store->get('ws-good', 'q1'));
        $this->assertFalse($store->has('ws-bad', 'q1'));
        $this->assertSame(1, $store->count(), 'only the well-formed row survived');
    }

    public function test_flush_clears_all_workspaces_and_reports_total(): void
    {
        $cache = new CodeGraphQueryCache();
        $cache->put('ws-a', 'q1', 'A');
        $cache->put('ws-b', 'q1', 'B');
        $cache->put('ws-b', 'q2', 'B2');

        $this->assertSame(3, $cache->flush(), 'flush returns total entries removed');
        $this->assertSame(0, $cache->stats()['entries']);
        $this->assertFalse($cache->has('ws-a', 'q1'));
        $this->assertFalse($cache->has('ws-b', 'q2'));
    }

    public function test_reset_stats_keeps_entries(): void
    {
        $cache = new CodeGraphQueryCache();
        $cache->remember('ws-a', 'q1', fn () => 'v'); // miss
        $cache->get('ws-a', 'q1');                    // hit

        $before = $cache->stats();
        $this->assertSame(1, $before['hits']);
        $this->assertSame(1, $before['misses']);

        $cache->resetStats();

        $after = $cache->stats();
        $this->assertSame(0, $after['hits']);
        $this->assertSame(0, $after['misses']);
        $this->assertSame(1, $after['entries'], 'cached entries are preserved across resetStats');
        $this->assertSame('v', $cache->get('ws-a', 'q1'));
    }
}
