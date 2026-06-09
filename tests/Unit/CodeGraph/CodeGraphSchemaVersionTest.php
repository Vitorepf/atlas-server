<?php

declare(strict_types=1);

namespace Tests\Unit\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphSchemaVersion;
use Tests\TestCase;

/**
 * AP-815 · W-9 — contract for the per-workspace code-graph schema version tracker.
 *
 * Pure (no DB): the tracker is a deterministic in-memory record. We prove the core
 * invariant `needsReindex ⇔ recordedFor !== current` across never-indexed, freshly
 * marked, stale (old version), and future-version states, plus per-workspace isolation
 * and fail-safe handling of malformed input.
 */
final class CodeGraphSchemaVersionTest extends TestCase
{
    private function tracker(array $store = []): CodeGraphSchemaVersion
    {
        return new CodeGraphSchemaVersion($store);
    }

    /**
     * Happy path: a never-marked workspace needs a re-index; marking it at the current
     * version clears that; and re-marking it at an OLDER version (a schema bump made it
     * stale again) flips needsReindex back to true.
     */
    public function test_mark_clears_reindex_and_an_old_version_bump_flips_it_back(): void
    {
        $t = $this->tracker();
        $ws = 'atlas-server';

        // Never recorded → stale, no recorded version.
        $this->assertNull($t->recordedFor($ws));
        $this->assertTrue($t->needsReindex($ws));

        // Indexed at the live version → fresh.
        $t->mark($ws); // defaults to CURRENT
        $this->assertSame(CodeGraphSchemaVersion::CURRENT, $t->recordedFor($ws));
        $this->assertFalse($t->needsReindex($ws));

        // Schema bumped: the workspace is now recorded under an OLD version → stale.
        $t->mark($ws, CodeGraphSchemaVersion::CURRENT - 1);
        $this->assertSame(CodeGraphSchemaVersion::CURRENT - 1, $t->recordedFor($ws));
        $this->assertTrue($t->needsReindex($ws));
    }

    /**
     * current() reflects the public constant and mark() with no version argument stamps
     * exactly that value.
     */
    public function test_current_matches_constant_and_is_the_mark_default(): void
    {
        $t = $this->tracker();
        $this->assertSame(CodeGraphSchemaVersion::CURRENT, $t->current());
        $this->assertSame(3, $t->current()); // pin the documented value

        $t->mark('w');
        $this->assertSame($t->current(), $t->recordedFor('w'));
    }

    /**
     * Edge case — per-workspace isolation: marking one workspace must not touch another.
     */
    public function test_workspaces_are_isolated(): void
    {
        $t = $this->tracker();

        $t->mark('primary'); // CURRENT
        $t->mark('secondary', 1); // stale on purpose

        $this->assertFalse($t->needsReindex('primary'));
        $this->assertTrue($t->needsReindex('secondary'));

        // A third, untouched workspace is independently "never indexed".
        $this->assertNull($t->recordedFor('tertiary'));
        $this->assertTrue($t->needsReindex('tertiary'));

        // Re-marking secondary up to current must not disturb primary.
        $t->mark('secondary');
        $this->assertFalse($t->needsReindex('secondary'));
        $this->assertFalse($t->needsReindex('primary'));
    }

    /**
     * Edge case — an injected store seeds prior records, and a workspace recorded under
     * a FUTURE version (e.g. after a binary downgrade) is treated as stale because the
     * running readers cannot interpret it.
     */
    public function test_injected_store_seeds_records_and_future_version_is_stale(): void
    {
        $t = $this->tracker([
            'already-current' => CodeGraphSchemaVersion::CURRENT,
            'old' => 1,
            'from-the-future' => CodeGraphSchemaVersion::CURRENT + 5,
        ]);

        $this->assertFalse($t->needsReindex('already-current'));
        $this->assertTrue($t->needsReindex('old'));
        $this->assertTrue($t->needsReindex('from-the-future'));

        $this->assertSame(CodeGraphSchemaVersion::CURRENT, $t->recordedFor('already-current'));
        $this->assertSame(CodeGraphSchemaVersion::CURRENT + 5, $t->recordedFor('from-the-future'));
    }

    /**
     * Edge case — fail-safe input handling: a blank/whitespace workspace id is a no-op
     * for mark() and reads as never-recorded; whitespace around a real id is trimmed to
     * the same key (a marked "ws" is visible as " ws ").
     */
    public function test_blank_id_is_noop_and_ids_are_trimmed(): void
    {
        $t = $this->tracker();

        $t->mark('   '); // no-op
        $t->mark('');    // no-op
        $this->assertSame([], $t->all());
        $this->assertNull($t->recordedFor('   '));
        $this->assertTrue($t->needsReindex(''));

        $t->mark('  ws  '); // trims to 'ws'
        $this->assertSame(CodeGraphSchemaVersion::CURRENT, $t->recordedFor('ws'));
        $this->assertFalse($t->needsReindex(' ws ')); // same key after trim
        $this->assertArrayHasKey('ws', $t->all());
    }

    /**
     * Edge case — malformed seed values are discarded (so they read as never-recorded →
     * stale), while integer-valued numeric strings/floats are adopted as clean ints.
     */
    public function test_malformed_seed_values_are_dropped_and_numeric_ones_coerced(): void
    {
        $t = $this->tracker([
            'good-string' => '2',     // adopted as int 2
            'good-float' => 3.0,      // adopted as int 3
            'fractional' => 3.5,      // dropped
            'word' => 'v3',           // dropped
            'empty' => '',            // dropped
            'arr' => [3],             // dropped
            'null' => null,           // dropped
            7 => 3,                   // non-string key dropped
        ]);

        $this->assertSame(2, $t->recordedFor('good-string'));
        $this->assertSame(3, $t->recordedFor('good-float'));
        $this->assertFalse($t->needsReindex('good-float')); // 3 === CURRENT

        foreach (['fractional', 'word', 'empty', 'arr', 'null'] as $junk) {
            $this->assertNull($t->recordedFor($junk), "{$junk} should not be recorded");
            $this->assertTrue($t->needsReindex($junk));
        }

        // Only the two well-formed records survived (non-string-key '7' dropped too).
        $this->assertSame(['good-float', 'good-string'], array_keys($t->all()));
    }

    /**
     * all() returns a deterministic, key-sorted snapshot that callers cannot use to
     * mutate internal state.
     */
    public function test_all_is_sorted_snapshot_and_immutable(): void
    {
        $t = $this->tracker();
        $t->mark('zebra', 1);
        $t->mark('alpha', 2);

        $snapshot = $t->all();
        $this->assertSame(['alpha', 'zebra'], array_keys($snapshot)); // sorted

        // Mutating the returned array must not change the tracker.
        $snapshot['alpha'] = 999;
        $snapshot['injected'] = 5;
        $this->assertSame(2, $t->recordedFor('alpha'));
        $this->assertNull($t->recordedFor('injected'));
    }
}
