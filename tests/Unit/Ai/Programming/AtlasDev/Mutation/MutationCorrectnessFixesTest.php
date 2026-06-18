<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Mutation;

use App\Services\Ai\Programming\AtlasDev\Mutation\MutationScope;
use App\Services\Ai\Programming\AtlasDev\Mutation\MutationTestingAdapter;
use App\Services\Ai\Programming\AtlasDev\Support\Elevations\ElevationConfig;
use PHPUnit\Framework\TestCase;

/**
 * E3 — Mutation-correctness fixes (m3-e3 scrutiny defects).
 *
 * Three correctness defects surfaced by the m3-e3 scrutiny review, all in
 * the E3 Mutation machinery (app/Services/Ai/Programming/AtlasDev/Mutation/):
 *
 *   (1) BLOCKING — MutationTestingAdapter::buildCommand emits MULTIPLE
 *       `-d pcov.directory=` entries when source files span multiple
 *       directories. pcov.directory is a SINGLE-VALUED ini directive, so
 *       only the LAST entry is honored — coverage/MSI is then wrong for any
 *       patch touching >1 source directory.
 *       Fix: emit a SINGLE pcov.directory equal to the lowest common
 *       ancestor directory of all touched source files in scope.
 *
 *   (2) BLOCKING — the adapter must read the REAL infection-reported MSI,
 *       counting syntax-error mutants on the SAME side Infection counts
 *       them, so that for an HONEST run realMsi equals infection's reported
 *       msi (within rounding). The anti-gaming downward-only divergence on
 *       skipped-mutator / denominator-exclusion patches must still hold.
 *
 *   (3) NON-BLOCKING — MutationScoreGate threshold env coercion silently
 *       converts an invalid/non-numeric threshold to 0.0 (disabling the
 *       gate). Invalid/non-numeric threshold must fall back to 60.0, never
 *       0.0. (Covered in the feature test counterpart.)
 *
 * These are the unit-level red-first tests. The feature counterpart covers
 * the env-coercion path (Defect 3) under the live Laravel kernel.
 */
final class MutationCorrectnessFixesTest extends TestCase
{
    // -- Defect 1: SINGLE pcov.directory = LCA of all scoped source dirs -----

    /**
     * Defect 1 (BLOCKING): a scope spanning TWO source directories must
     * produce a SINGLE pcov.directory entry equal to the lowest common
     * ancestor of both directories (so pcov instruments BOTH dirs, not just
     * the last one emitted on the command line).
     */
    public function test_defect_1_multi_directory_scope_emits_single_pcov_directory_equal_to_lca(): void
    {
        $scope = new MutationScope(
            testFiles: [
                'tests/Unit/ModuleA/FooTest.php',
                'tests/Unit/ModuleB/BarTest.php',
            ],
            sourceFiles: [
                'app/ModuleA/Foo.php',
                'app/ModuleB/Bar.php',
            ],
        );

        $pcovDirs = $scope->pcovDirectories();

        // SINGLE pcov.directory entry (never multiple — pcov.directory is
        // single-valued so only the last would be honored).
        $this->assertCount(
            1,
            $pcovDirs,
            'Defect 1: a multi-dir scope emits a SINGLE pcov.directory (LCA), '
            .'not one per dir (only the last would be honored)',
        );

        // The single entry is the lowest common ancestor of both source dirs.
        // app/ModuleA/Foo.php and app/ModuleB/Bar.php => LCA is app/.
        $this->assertSame(
            'app',
            $pcovDirs[0],
            'Defect 1: pcov.directory is the lowest common ancestor (app) '
            .'so pcov instruments both app/ModuleA and app/ModuleB',
        );
    }

    /**
     * Defect 1 (BLOCKING): the buildCommand invocation must emit exactly ONE
     * `-d pcov.directory=` token even when the scope spans multiple
     * directories. Multiple entries silently drop all but the last.
     */
    public function test_defect_1_build_command_emits_exactly_one_pcov_directory_token_for_multi_dir_scope(): void
    {
        $runner = new FakeMutationCommandRunner;
        $runner->queueOk(msi: 100.0, summaryPath: '/tmp/s.json');

        $adapter = new MutationTestingAdapter(
            commandRunner: $runner,
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            repoRoot: '/repo',
        );

        $adapter->run(
            runId: 'run-defect-1',
            touchedFiles: [
                'tests/Unit/ModuleA/FooTest.php',
                'tests/Unit/ModuleB/BarTest.php',
                'app/ModuleA/Foo.php',
                'app/ModuleB/Bar.php',
            ],
        );

        $command = $runner->calls[0]['command'];

        // Count the pcov.directory occurrences on the command line. MUST be 1.
        $pcovCount = preg_match_all('/pcov\.directory=/', $command);
        $this->assertSame(
            1,
            $pcovCount,
            'Defect 1: exactly ONE pcov.directory token on the command '
            ."(found {$pcovCount}; pcov.directory is single-valued so only "
            .'the last would be honored)',
        );

        // The single pcov.directory is the LCA (app) so pcov instruments
        // BOTH ModuleA and ModuleB, not just the last.
        $this->assertMatchesRegularExpression(
            '/pcov\.directory=[^ ]*app\b/',
            $command,
            'Defect 1: the single pcov.directory is the LCA of both source dirs',
        );
        // Neither per-dir entry leaks onto the command line.
        $this->assertDoesNotMatchRegularExpression(
            '/pcov\.directory=[^ ]*ModuleA/',
            $command,
            'Defect 1: no per-dir ModuleA entry (would be a second pcov.directory)',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/pcov\.directory=[^ ]*ModuleB/',
            $command,
            'Defect 1: no per-dir ModuleB entry (would be a second pcov.directory)',
        );
    }

    /**
     * Defect 1 regression guard: a single-directory scope still produces
     * exactly one pcov.directory entry (the single dir itself, no widening).
     */
    public function test_defect_1_single_directory_scope_still_emits_one_pcov_directory(): void
    {
        $scope = new MutationScope(
            testFiles: ['tests/Unit/Foo/BarTest.php'],
            sourceFiles: ['app/Services/Foo/Bar.php'],
        );

        $pcovDirs = $scope->pcovDirectories();

        $this->assertCount(1, $pcovDirs, 'single-dir scope: one pcov.directory');
        $this->assertSame('app/Services/Foo', $pcovDirs[0]);
    }

    /**
     * Defect 1 LCA: three nested source directories collapse to the smallest
     * shared ancestor that still spans all of them.
     */
    public function test_defect_1_lca_collapses_three_disjoint_dirs_to_shared_ancestor(): void
    {
        $scope = new MutationScope(
            testFiles: [
                'tests/Unit/A/XTest.php',
                'tests/Unit/B/YTest.php',
                'tests/Unit/C/ZTest.php',
            ],
            sourceFiles: [
                'app/Services/A/X.php',
                'app/Services/B/Y.php',
                'app/Services/C/Z.php',
            ],
        );

        $pcovDirs = $scope->pcovDirectories();

        $this->assertCount(1, $pcovDirs, 'three disjoint dirs => one LCA');
        $this->assertSame(
            'app/Services',
            $pcovDirs[0],
            'LCA of app/Services/{A,B,C} is app/Services',
        );
    }

    /**
     * Defect 1 LCA: when one source dir is a prefix of another, the narrower
     * one is the LCA (it already spans both).
     */
    public function test_defect_1_lca_when_one_dir_is_prefix_of_another(): void
    {
        $scope = new MutationScope(
            testFiles: ['tests/Unit/FooTest.php'],
            sourceFiles: [
                'app/Services/Foo/Bar.php',
                'app/Services/Foo/Deep/Baz.php',
            ],
        );

        $pcovDirs = $scope->pcovDirectories();

        $this->assertCount(1, $pcovDirs);
        $this->assertSame(
            'app/Services/Foo',
            $pcovDirs[0],
            'app/Services/Foo is the prefix-LCA of app/Services/Foo and app/Services/Foo/Deep',
        );
    }

    // -- Defect 2: computeRealMsi aligns with Infection's MSI ---------------

    /**
     * Defect 2 (BLOCKING): computeRealMsi must reproduce Infection's own
     * reported MSI for an HONEST run, including syntaxError mutants counted
     * on the SAME side Infection counts them (the numerator, as "detected").
     *
     * Infection's MSI formula (vendor MetricsCalculator + Calculator):
     *   numerator   = killedCount + errorCount + syntaxErrorCount
     *                 (+ timeOutCount unless timeoutsAsEscaped, default off)
     *   denominator = totalMutantsCount - skippedCount - ignoredCount
     *   MSI         = 100 * numerator / denominator
     *
     * The previous code-path omitted syntaxError mutants from the numerator,
     * diverging from Infection's reported MSI whenever a mutant produced a
     * syntax error. The fix counts syntaxError consistently with Infection.
     */
    public function test_defect_2_compute_real_msi_reproduces_infection_reported_msi_including_syntax_errors(): void
    {
        // Honest infection run: 1 killed, 1 syntax-error, 0 skipped/ignored,
        // 0 escaped/timeout/not-covered. Infection's MSI = 100 * (1+0+1+0) / 2 = 100.
        // The bug would compute 100 * (1+0+0+0) / 2 = 50, diverging from the
        // reported 100.
        $stats = $this->honestStats(
            total: 2,
            killed: 1,
            syntaxError: 1,
            escaped: 0,
            error: 0,
            timeout: 0,
            notCovered: 0,
            skipped: 0,
            ignored: 0,
        );
        $reportedMsi = $stats['msi']; // infection's own reported MSI

        $adapter = $this->makeAdapter();
        $realMsi = $adapter->computeRealMsi(['stats' => $stats]);

        $this->assertNotNull($realMsi, 'computeRealMsi returns a real MSI for an honest run');
        $this->assertEqualsWithDelta(
            $reportedMsi,
            $realMsi,
            0.01,
            'Defect 2: realMsi reproduces infection\'s reported MSI '
            .'(syntaxError counted in the numerator, as Infection does)',
        );
    }

    /**
     * Defect 2 (BLOCKING): computeRealMsi must equal infection's reported MSI
     * within rounding for an honest run with ZERO syntax-error mutants too
     * (no regression on the no-syntax-error path).
     */
    public function test_defect_2_compute_real_msi_matches_reported_msi_when_no_syntax_errors(): void
    {
        // 3 killed, 1 escaped, 0 syntax-error, 0 skipped. MSI = 100*(3+0+0+0)/4 = 75.
        $stats = $this->honestStats(
            total: 4,
            killed: 3,
            syntaxError: 0,
            escaped: 1,
            error: 0,
            timeout: 0,
            notCovered: 0,
            skipped: 0,
            ignored: 0,
        );

        $adapter = $this->makeAdapter();
        $realMsi = $adapter->computeRealMsi(['stats' => $stats]);

        $this->assertNotNull($realMsi);
        $this->assertEqualsWithDelta(
            $stats['msi'],
            $realMsi,
            0.01,
            'Defect 2: realMsi == reported MSI on the no-syntax-error path',
        );
    }

    /**
     * Defect 2 (BLOCKING): timeout mutants are counted in the numerator the
     * SAME way Infection does (default: timeoutsAsEscaped = false => timeout
     * counts as covered/detected in the MSI numerator).
     */
    public function test_defect_2_timeout_mutants_counted_same_side_as_infection(): void
    {
        // 1 killed, 1 timeout, 0 syntax-error, 0 escaped, 0 skipped.
        // Infection MSI (default timeoutsAsEscaped=false) = 100*(1+0+0+1)/2 = 100.
        $stats = $this->honestStats(
            total: 2,
            killed: 1,
            syntaxError: 0,
            escaped: 0,
            error: 0,
            timeout: 1,
            notCovered: 0,
            skipped: 0,
            ignored: 0,
        );

        $adapter = $this->makeAdapter();
        $realMsi = $adapter->computeRealMsi(['stats' => $stats]);

        $this->assertEqualsWithDelta(
            $stats['msi'],
            $realMsi ?? -1,
            0.01,
            'Defect 2: timeout mutants counted in the numerator (Infection default)',
        );
    }

    /**
     * Defect 2 (BLOCKING): anti-gaming invariant — when a patch attempts to
     * inflate MSI via skipped mutators, computeRealMsi must NOT honor the
     * patch-supplied skip (the denominator excludes skipped, but the patch
     * cannot raise realMsi above infection's reported MSI by skipping
     * mutators). realMsi diverges DOWNWARD only, never upward.
     *
     * Infection's reported MSI already excludes skipped/ignored from the
     * denominator, so a patch that skips mutators to inflate its score would
     * see its reported MSI rise — but computeRealMsi must compute over the
     * FULL applicable mutant population (no patch-supplied denominator
     * exclusion), so realMsi <= reportedMsi whenever a skip inflates the
     * reported score. The anti-gaming invariant holds: realMsi never exceeds
     * the score an honest run would report.
     */
    public function test_defect_2_real_msi_diverges_downward_only_on_skipped_mutator_inflation(): void
    {
        // Patch attempts inflation: 2 total mutants, 1 killed, 1 skipped.
        // Infection's REPORTED MSI (skipped excluded) = 100*(1+0+0+0)/(2-1-0) = 100.
        // The HONEST MSI over the FULL population (no skip) = 100*(1)/(2) = 50.
        // computeRealMsi must report <= reported (downward-only divergence),
        // never honor the inflation.
        $stats = [
            'totalMutantsCount' => 2,
            'killedCount' => 1,
            'notCoveredCount' => 0,
            'escapedCount' => 0,
            'errorCount' => 0,
            'syntaxErrorCount' => 0,
            'skippedCount' => 1, // patch-supplied skip attempting inflation
            'ignoredCount' => 0,
            'timeOutCount' => 0,
            // infection's REPORTED msi excludes the skipped mutant from the denominator
            'msi' => 100.0,
            'mutationCodeCoverage' => 100.0,
            'coveredCodeMsi' => 100.0,
        ];

        $adapter = $this->makeAdapter();
        $realMsi = $adapter->computeRealMsi(['stats' => $stats]);

        $this->assertNotNull($realMsi);
        $this->assertLessThanOrEqual(
            $stats['msi'],
            $realMsi,
            'Defect 2 anti-gaming: realMsi <= reported MSI (downward-only divergence)',
        );
        $this->assertLessThan(
            $stats['msi'],
            $realMsi,
            'Defect 2 anti-gaming: realMsi diverges DOWNWARD when a patch skips mutators to inflate',
        );
    }

    /**
     * Defect 2 (BLOCKING): anti-gaming invariant — a patch attempting to
     * exclude surviving mutants from the score cannot raise realMsi above
     * the honest MSI. realMsi diverges DOWNWARD only.
     */
    public function test_defect_2_real_msi_diverges_downward_on_denominator_exclusion_attempt(): void
    {
        // 3 mutants: 1 killed, 2 escaped. Honest MSI = 100*(1)/3 = 33.33.
        // Patch-supplied report claims msi=66.67 (excluded one escaped from
        // the denominator). computeRealMsi over the full population = 33.33,
        // which is BELOW the inflated 66.67.
        $stats = [
            'totalMutantsCount' => 3,
            'killedCount' => 1,
            'notCoveredCount' => 0,
            'escapedCount' => 2,
            'errorCount' => 0,
            'syntaxErrorCount' => 0,
            'skippedCount' => 0,
            'ignoredCount' => 0,
            'timeOutCount' => 0,
            'msi' => 66.67, // inflated (one escaped excluded)
            'mutationCodeCoverage' => 100.0,
            'coveredCodeMsi' => 33.33,
        ];

        $adapter = $this->makeAdapter();
        $realMsi = $adapter->computeRealMsi(['stats' => $stats]);

        $this->assertNotNull($realMsi);
        $this->assertLessThan(
            $stats['msi'],
            $realMsi,
            'Defect 2 anti-gaming: realMsi diverges DOWNWARD from an inflated reported MSI',
        );
    }

    /**
     * Defect 2 (BLOCKING): null input (missing stats) yields null — the
     * adapter never fabricates a score over an unevaluable run.
     */
    public function test_defect_2_null_payload_yields_null_msi_never_fabricated(): void
    {
        $adapter = $this->makeAdapter();

        $this->assertNull($adapter->computeRealMsi(null), 'no payload => no fabricated MSI');
        $this->assertNull($adapter->computeRealMsi([]), 'no stats => no fabricated MSI');
        $this->assertNull(
            $adapter->computeRealMsi(['stats' => []]),
            'empty stats => no fabricated MSI',
        );
    }

    // -- Helpers (Defect 2) ---------------------------------------------------

    private function makeAdapter(): MutationTestingAdapter
    {
        return new MutationTestingAdapter(
            commandRunner: new FakeMutationCommandRunner,
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            repoRoot: '/repo',
        );
    }

    /**
     * Build an honest infection summary stats block with the given counts.
     * The MSI is recomputed from the counts so the payload is internally
     * consistent (matches what infection would actually report).
     *
     * @return array<string, float|int>
     */
    private function honestStats(
        int $total,
        int $killed,
        int $syntaxError,
        int $escaped,
        int $error,
        int $timeout,
        int $notCovered,
        int $skipped,
        int $ignored,
    ): array {
        // Infection MSI: numerator = killed + error + syntaxError + timeout
        // (default timeoutsAsEscaped=false); denominator = total - skipped - ignored.
        $denominator = $total - $skipped - $ignored;
        $numerator = $killed + $error + $syntaxError + $timeout;
        $msi = $denominator > 0 ? 100.0 * $numerator / $denominator : 0.0;

        return [
            'totalMutantsCount' => $total,
            'killedCount' => $killed,
            'notCoveredCount' => $notCovered,
            'escapedCount' => $escaped,
            'errorCount' => $error,
            'syntaxErrorCount' => $syntaxError,
            'skippedCount' => $skipped,
            'ignoredCount' => $ignored,
            'timeOutCount' => $timeout,
            'msi' => round($msi, 2),
            'mutationCodeCoverage' => 100.0,
            'coveredCodeMsi' => round($msi, 2),
        ];
    }
}
