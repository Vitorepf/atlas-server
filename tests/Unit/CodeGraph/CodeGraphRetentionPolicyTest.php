<?php

declare(strict_types=1);

namespace Tests\Unit\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphRetentionPolicy;
use Tests\TestCase;

/**
 * AP-815 · W-8 — contract for the pure retention DECISION.
 *
 * The policy decides which cross-project graphs are stale and eligible for GC; the
 * deletion is a separate executor. We prove the canon invariants: aged graphs are
 * reclaimed with a correct age, fresh graphs are kept, the primary workspace is
 * NEVER reclaimed however old, never-indexed graphs are kept unless explicitly
 * opted in, and the output is deterministic and fail-safe on garbage rows.
 *
 * Extends Tests\TestCase so the `config()` helper (primary id + retention default)
 * resolves; the policy itself touches no DB, no clock and no filesystem.
 */
class CodeGraphRetentionPolicyTest extends TestCase
{
    /** A fixed "now" so every age in the suite is exact and clock-independent. */
    private const NOW = 1_700_000_000; // 2023-11-14T22:13:20Z

    private function policy(): CodeGraphRetentionPolicy
    {
        return new CodeGraphRetentionPolicy;
    }

    private function daysAgo(int $days): int
    {
        return self::NOW - ($days * 86400);
    }

    /**
     * Happy path covering the four canonical cases together: a 200-day-old workspace
     * is stale (with its real age), a 10-day-old one is kept, the primary
     * 'atlas-server' is never stale even when ancient, and a null last_indexed is
     * kept unless gc_never_indexed is set.
     */
    public function test_reports_aged_keeps_fresh_protects_primary_and_keeps_never_indexed(): void
    {
        $workspaces = [
            ['workspace_id' => 'old-project', 'last_indexed_at' => $this->daysAgo(200)],
            ['workspace_id' => 'fresh-project', 'last_indexed_at' => $this->daysAgo(10)],
            ['workspace_id' => 'atlas-server', 'last_indexed_at' => $this->daysAgo(5000)],
            ['workspace_id' => 'never-project', 'last_indexed_at' => null],
        ];

        // Default window = 90 days; gc_never_indexed not set.
        $result = $this->policy()->stale($workspaces, self::NOW);

        $ids = array_column($result, 'workspace_id');
        $this->assertSame(['old-project'], $ids, 'Only the aged, non-protected workspace is stale by default.');

        $this->assertSame(CodeGraphRetentionPolicy::REASON_EXPIRED, $result[0]['reason']);
        $this->assertSame(200, $result[0]['age_days'], 'Age is reported in whole days.');
    }

    /** The 200-day-old workspace standing alone is stale with the correct age + reason. */
    public function test_aged_workspace_is_stale_with_age_days(): void
    {
        $result = $this->policy()->stale(
            [['workspace_id' => 'stale-one', 'last_indexed_at' => $this->daysAgo(200)]],
            self::NOW,
        );

        $this->assertCount(1, $result);
        $this->assertSame('stale-one', $result[0]['workspace_id']);
        $this->assertSame(CodeGraphRetentionPolicy::REASON_EXPIRED, $result[0]['reason']);
        $this->assertSame(200, $result[0]['age_days']);
    }

    /** Edge: a 10-day-old workspace is NOT stale under the 90-day window. */
    public function test_fresh_workspace_is_not_stale(): void
    {
        $result = $this->policy()->stale(
            [['workspace_id' => 'fresh-one', 'last_indexed_at' => $this->daysAgo(10)]],
            self::NOW,
        );

        $this->assertSame([], $result, 'A workspace inside the retention window is kept.');
    }

    /** Edge: the primary 'atlas-server' is NEVER stale, even when absurdly old. */
    public function test_primary_workspace_is_never_stale(): void
    {
        $result = $this->policy()->stale(
            [['workspace_id' => 'atlas-server', 'last_indexed_at' => $this->daysAgo(10000)]],
            self::NOW,
        );

        $this->assertSame([], $result, 'The primary workspace is protected regardless of age.');
    }

    /** Edge: a never-indexed workspace is stale ONLY with gc_never_indexed = true. */
    public function test_never_indexed_is_stale_only_with_opt_in(): void
    {
        $rows = [['workspace_id' => 'never-one', 'last_indexed_at' => null]];

        $kept = $this->policy()->stale($rows, self::NOW);
        $this->assertSame([], $kept, 'Never-indexed is kept by default.');

        $reclaimed = $this->policy()->stale($rows, self::NOW, ['gc_never_indexed' => true]);
        $this->assertCount(1, $reclaimed);
        $this->assertSame('never-one', $reclaimed[0]['workspace_id']);
        $this->assertSame(CodeGraphRetentionPolicy::REASON_NEVER_INDEXED, $reclaimed[0]['reason']);
        $this->assertSame(
            CodeGraphRetentionPolicy::AGE_NEVER_INDEXED,
            $reclaimed[0]['age_days'],
            'Never-indexed age is the documented sentinel, not a misleading number.',
        );
    }

    /** Edge: a custom retention_days via $opts changes the boundary. */
    public function test_opts_retention_days_overrides_window(): void
    {
        $rows = [['workspace_id' => 'mid-age', 'last_indexed_at' => $this->daysAgo(30)]];

        // 30 days old: kept under the default 90, stale under a 20-day window.
        $this->assertSame([], $this->policy()->stale($rows, self::NOW));

        $stale = $this->policy()->stale($rows, self::NOW, ['retention_days' => 20]);
        $this->assertCount(1, $stale);
        $this->assertSame(30, $stale[0]['age_days']);
        $this->assertSame(CodeGraphRetentionPolicy::REASON_EXPIRED, $stale[0]['reason']);
    }

    /** Edge: exactly at the boundary is still fresh (strict greater-than). */
    public function test_exact_boundary_is_fresh(): void
    {
        // Exactly 90 days → age == window, not strictly greater → kept.
        $atBoundary = [['workspace_id' => 'boundary', 'last_indexed_at' => $this->daysAgo(90)]];
        $this->assertSame([], $this->policy()->stale($atBoundary, self::NOW));

        // One second past 90 days → stale.
        $justOver = [['workspace_id' => 'boundary', 'last_indexed_at' => $this->daysAgo(90) - 1]];
        $this->assertCount(1, $this->policy()->stale($justOver, self::NOW));
    }

    /** Edge: a future timestamp (clock skew) is never stale, never negative-aged. */
    public function test_future_timestamp_is_not_stale(): void
    {
        $result = $this->policy()->stale(
            [['workspace_id' => 'from-the-future', 'last_indexed_at' => self::NOW + 100000]],
            self::NOW,
        );

        $this->assertSame([], $result, 'A future last_indexed_at can never be stale.');
    }

    /** Edge: callers can protect extra workspaces (single id or list) via $opts. */
    public function test_opts_protect_shields_extra_workspaces(): void
    {
        $rows = [
            ['workspace_id' => 'keep-me', 'last_indexed_at' => $this->daysAgo(500)],
            ['workspace_id' => 'reclaim-me', 'last_indexed_at' => $this->daysAgo(500)],
        ];

        $single = $this->policy()->stale($rows, self::NOW, ['protect' => 'keep-me']);
        $this->assertSame(['reclaim-me'], array_column($single, 'workspace_id'));

        $list = $this->policy()->stale($rows, self::NOW, ['protect' => ['keep-me', 'reclaim-me']]);
        $this->assertSame([], $list, 'A list of protected ids shields all of them.');
    }

    /** Fail-safety: malformed rows are skipped, never reported, never throw. */
    public function test_malformed_rows_are_skipped(): void
    {
        $rows = [
            'not-an-array',
            42,
            ['no_workspace_id' => true, 'last_indexed_at' => $this->daysAgo(500)],
            ['workspace_id' => '', 'last_indexed_at' => $this->daysAgo(500)],
            ['workspace_id' => '   ', 'last_indexed_at' => $this->daysAgo(500)],
            ['workspace_id' => 'garbage-ts', 'last_indexed_at' => 'not-a-number'],
            ['workspace_id' => 'real-stale', 'last_indexed_at' => $this->daysAgo(500)],
        ];

        $result = $this->policy()->stale($rows, self::NOW, ['gc_never_indexed' => true]);

        // Only the one clean, aged row survives the gauntlet of bad input. The
        // present-but-garbage timestamp is NOT treated as never-indexed.
        $this->assertSame(['real-stale'], array_column($result, 'workspace_id'));
    }

    /** Fail-safety: an invalid retention window falls back to the safe default (keeps). */
    public function test_invalid_retention_days_falls_back_to_default(): void
    {
        $rows = [['workspace_id' => 'forty-days', 'last_indexed_at' => $this->daysAgo(40)]];

        // 0, negative, and non-numeric must all resolve to the 90-day default →
        // a 40-day-old workspace stays fresh (NOT reclaimed-everything).
        foreach ([0, -5, 'banana', null] as $bad) {
            $this->assertSame(
                [],
                $this->policy()->stale($rows, self::NOW, ['retention_days' => $bad]),
                'Invalid retention_days ('.var_export($bad, true).') must not GC a 40-day-old graph.',
            );
        }
    }

    /** Determinism: output is sorted by workspace_id and deduped, regardless of input order. */
    public function test_output_is_sorted_and_deduplicated(): void
    {
        $rows = [
            ['workspace_id' => 'zeta', 'last_indexed_at' => $this->daysAgo(500)],
            ['workspace_id' => 'alpha', 'last_indexed_at' => $this->daysAgo(500)],
            ['workspace_id' => 'mike', 'last_indexed_at' => $this->daysAgo(500)],
            // Duplicate id: first readable occurrence wins, appears once.
            ['workspace_id' => 'alpha', 'last_indexed_at' => $this->daysAgo(999)],
        ];

        $result = $this->policy()->stale($rows, self::NOW);

        $this->assertSame(['alpha', 'mike', 'zeta'], array_column($result, 'workspace_id'));
        // Dedup kept the FIRST alpha (500 days), not the later 999-day one.
        $alpha = $result[array_search('alpha', array_column($result, 'workspace_id'), true)];
        $this->assertSame(500, $alpha['age_days']);
    }

    /** Determinism: identical input yields byte-identical output across runs. */
    public function test_is_deterministic(): void
    {
        $rows = [
            ['workspace_id' => 'b', 'last_indexed_at' => $this->daysAgo(500)],
            ['workspace_id' => 'a', 'last_indexed_at' => null],
            ['workspace_id' => 'c', 'last_indexed_at' => $this->daysAgo(10)],
        ];

        $first = $this->policy()->stale($rows, self::NOW, ['gc_never_indexed' => true]);
        $second = $this->policy()->stale($rows, self::NOW, ['gc_never_indexed' => true]);

        $this->assertSame($first, $second);
    }
}
