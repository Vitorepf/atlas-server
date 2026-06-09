<?php

declare(strict_types=1);

namespace Tests\Feature\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphIncrementalReindexPlanner;
use Tests\TestCase;

/**
 * AP-815 · E-2 — proves the incremental re-index planner turns a git/file diff into
 * the minimal, deterministic, fail-safe re-index plan (reindex/archive/modules/
 * batches/noop). Pure transform: no DB, no IO — extends the base TestCase only for
 * the framework `config()` binding the planner reads its batch size from.
 */
final class CodeGraphIncrementalReindexPlannerTest extends TestCase
{
    private function planner(): CodeGraphIncrementalReindexPlanner
    {
        return new CodeGraphIncrementalReindexPlanner;
    }

    public function test_happy_path_three_changed_one_deleted(): void
    {
        $changed = [
            'app/Services/Foo.php',
            'app/Models/Bar.php',
            'resources/js/widget.tsx',
        ];
        $deleted = [
            'app/Console/OldCommand.php',
        ];

        $plan = $this->planner()->plan($changed, $deleted);

        $this->assertSame(CodeGraphIncrementalReindexPlanner::SCHEMA, $plan['schema_version']);

        // reindex = the 3 changed source files, sorted by path.
        $this->assertSame(
            ['app/Models/Bar.php', 'app/Services/Foo.php', 'resources/js/widget.tsx'],
            $plan['reindex'],
        );

        // archive = the 1 deleted source file.
        $this->assertSame(['app/Console/OldCommand.php'], $plan['archive']);

        // modules_touched = first two segments of every touched file (changed +
        // deleted), deduped, sorted.
        $this->assertSame(
            ['app/Console', 'app/Models', 'app/Services', 'resources/js'],
            $plan['modules_touched'],
        );

        // 4 reindexed files (3 changed) + default batch size 500 → exactly 1 batch.
        $this->assertSame(500, $plan['batch_size']);
        $this->assertSame(1, $plan['batches']);
        $this->assertFalse($plan['noop']);
    }

    public function test_empty_diff_is_a_noop(): void
    {
        $plan = $this->planner()->plan([], []);

        $this->assertTrue($plan['noop']);
        $this->assertSame([], $plan['reindex']);
        $this->assertSame([], $plan['archive']);
        $this->assertSame([], $plan['modules_touched']);
        $this->assertSame(0, $plan['batches']);
        $this->assertSame(CodeGraphIncrementalReindexPlanner::SCHEMA, $plan['schema_version']);

        // A diff that is non-empty but contains only NON-source files also collapses
        // to a noop once the source filter runs. (Note: a '.php' file anywhere — even
        // 'vendor/autoload.php' — IS a source file by extension, so it is deliberately
        // excluded from this all-non-source set.)
        $onlyNonSource = $this->planner()->plan(
            ['composer.lock', 'public/logo.png', 'app/.gitkeep'],
            ['storage/framework/cache.bin', 'public/build/manifest.json'],
        );
        $this->assertTrue($onlyNonSource['noop']);
        $this->assertSame([], $onlyNonSource['reindex']);
        $this->assertSame([], $onlyNonSource['archive']);
    }

    public function test_non_source_files_are_filtered_out(): void
    {
        $changed = [
            'app/Services/Keep.php',     // source — kept
            'composer.json',             // config — dropped
            'composer.lock',             // lockfile — dropped
            'public/img/logo.png',       // binary — dropped
            'resources/views/x.blade.php', // .blade.php → final ext is .php → kept
            'docs/guide.md',             // markdown — kept
            'webpack.config.cjs',        // .cjs — dropped (not in source set)
        ];
        $deleted = [
            'README.adoc',               // not a source ext — dropped
            'app/Old/Gone.ts',           // source — archived
        ];

        $plan = $this->planner()->plan($changed, $deleted);

        $this->assertSame(
            ['app/Services/Keep.php', 'docs/guide.md', 'resources/views/x.blade.php'],
            $plan['reindex'],
        );
        $this->assertSame(['app/Old/Gone.ts'], $plan['archive']);
        // Modules are directories capped at depth 2: docs/guide.md → 'docs'.
        $this->assertSame(
            ['app/Old', 'app/Services', 'docs', 'resources/views'],
            $plan['modules_touched'],
        );
        $this->assertFalse($plan['noop']);
    }

    public function test_all_opt_bypasses_the_source_filter(): void
    {
        $plan = $this->planner()->plan(
            ['composer.lock', 'public/logo.png'],
            ['secrets.env'],
            ['all' => true],
        );

        // With all=true, non-source files are planned too.
        $this->assertSame(['composer.lock', 'public/logo.png'], $plan['reindex']);
        $this->assertSame(['secrets.env'], $plan['archive']);
        $this->assertFalse($plan['noop']);
    }

    public function test_deletion_wins_over_change_for_the_same_path(): void
    {
        // A file reported as BOTH modified and deleted (a delete-then-touch in the
        // same diff window) must be archived, never reindexed — you cannot parse a
        // file that no longer exists.
        $plan = $this->planner()->plan(
            ['app/Services/Conflict.php', 'app/Services/Alive.php'],
            ['app/Services/Conflict.php'],
        );

        $this->assertSame(['app/Services/Alive.php'], $plan['reindex']);
        $this->assertSame(['app/Services/Conflict.php'], $plan['archive']);
        // The module is still touched (via the archive side).
        $this->assertSame(['app/Services'], $plan['modules_touched']);
        $this->assertFalse($plan['noop']);
    }

    public function test_batches_computed_from_batch_size_opt(): void
    {
        // 5 changed source files, batch_size 2 → ceil(5/2) = 3 batches.
        $changed = [
            'a/one.php', 'a/two.php', 'a/three.php', 'a/four.php', 'a/five.php',
        ];

        $plan = $this->planner()->plan($changed, [], ['batch_size' => 2]);

        $this->assertSame(2, $plan['batch_size']);
        $this->assertSame(3, $plan['batches']);
        $this->assertCount(5, $plan['reindex']);

        // An exact multiple divides cleanly: 4 files / batch 2 = 2 batches.
        $exact = $this->planner()->plan(['a/1.php', 'a/2.php', 'a/3.php', 'a/4.php'], [], ['batch_size' => 2]);
        $this->assertSame(2, $exact['batches']);
    }

    public function test_garbage_batch_size_clamps_to_one_and_never_divides_by_zero(): void
    {
        foreach ([0, -5, 'abc', null, NAN, INF] as $bad) {
            $plan = $this->planner()->plan(['a/x.php', 'a/y.php', 'a/z.php'], [], ['batch_size' => $bad]);
            $this->assertGreaterThanOrEqual(1, $plan['batch_size'], 'batch_size must clamp to a safe floor');
            // 3 files at the clamped floor (1) → 3 batches; never a division error.
            $this->assertSame($plan['batch_size'] === 1 ? 3 : (int) ceil(3 / $plan['batch_size']), $plan['batches']);
        }
    }

    public function test_dedup_and_path_normalisation(): void
    {
        // Duplicates, OS backslashes, leading './' and '/', and repeated slashes all
        // collapse to a single canonical path; the result is deduped and sorted.
        $changed = [
            'app/Services/Foo.php',
            './app/Services/Foo.php',     // same after normalisation
            'app\\Services\\Foo.php',     // windows separators → same
            '/app/Services/Foo.php',      // leading slash → same
            'app//Services//Bar.php',     // repeated slashes → app/Services/Bar.php
            '   app/Services/Baz.php   ', // surrounding whitespace trimmed
        ];

        $plan = $this->planner()->plan($changed, []);

        $this->assertSame(
            ['app/Services/Bar.php', 'app/Services/Baz.php', 'app/Services/Foo.php'],
            $plan['reindex'],
        );
        $this->assertSame(['app/Services'], $plan['modules_touched']);
    }

    public function test_blank_and_non_string_entries_are_ignored(): void
    {
        $changed = [
            'app/Real.php',
            '',
            '   ',
            42,
            null,
            ['nested' => 'array'],
            true,
        ];
        $deleted = [
            false,
            'app/Gone.php',
            "\t\n",
        ];

        $plan = $this->planner()->plan($changed, $deleted);

        $this->assertSame(['app/Real.php'], $plan['reindex']);
        $this->assertSame(['app/Gone.php'], $plan['archive']);
        $this->assertFalse($plan['noop']);
    }

    public function test_module_derivation_dir_capped_with_root_and_deep_paths(): void
    {
        $plan = $this->planner()->plan([
            'index.php',                  // root-level file → (root) sentinel
            'app/Bootstrap.php',          // depth-1 file → dir 'app'
            'app/Services/Deep.php',      // depth-2 dir → 'app/Services'
            'app/Services/Sub/Nested.php', // deep tree → rolls up to 'app/Services'
        ], []);

        // reindex is sorted SORT_STRING (so 'app/...' precedes 'index.php').
        $this->assertSame(
            ['app/Bootstrap.php', 'app/Services/Deep.php', 'app/Services/Sub/Nested.php', 'index.php'],
            $plan['reindex'],
        );
        // Modules: 'app', 'app/Services' (Deep + Nested dedup to depth-2), '(root)'.
        $this->assertSame(
            [CodeGraphIncrementalReindexPlanner::ROOT_MODULE, 'app', 'app/Services'],
            $plan['modules_touched'],
        );
    }

    public function test_output_is_deterministic_regardless_of_input_order(): void
    {
        $changedA = ['z/m.php', 'a/b.ts', 'm/x.jsx', 'a/a.php'];
        $deletedA = ['q/gone.md', 'a/old.php'];

        // Same logical content, shuffled order, with a duplicate thrown in.
        $changedB = ['a/a.php', 'm/x.jsx', 'a/b.ts', 'z/m.php', 'a/a.php'];
        $deletedB = ['a/old.php', 'q/gone.md'];

        $planner = $this->planner();
        $planA = $planner->plan($changedA, $deletedA);
        $planB = $planner->plan($changedB, $deletedB);

        $this->assertSame($planA, $planB);
        $this->assertSame(['a/a.php', 'a/b.ts', 'm/x.jsx', 'z/m.php'], $planA['reindex']);
        $this->assertSame(['a/old.php', 'q/gone.md'], $planA['archive']);
        $this->assertSame(['a', 'm', 'q', 'z'], $planA['modules_touched']);
    }
}
