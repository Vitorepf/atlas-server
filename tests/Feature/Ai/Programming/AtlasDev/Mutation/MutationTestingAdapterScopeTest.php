<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev\Mutation;

use App\Services\Ai\Programming\AtlasDev\Mutation\MutationTestingAdapter;
use App\Services\Ai\Programming\AtlasDev\Support\Elevations\ElevationConfig;
use Tests\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Mutation\FakeMutationCommandRunner;

/**
 * E3 — MutationTestingAdapter integration with the Atlas Dev scoping seam.
 *
 * Drives synthetic diffs (through the file lists the adapter consumes) and
 * proves the E3 scoping behavior end-to-end:
 *
 *   - VAL-E3-001: the adapter scopes coverage (`pcov.directory`) AND the
 *      infection mutated set to EXACTLY the touched test files + their
 *      covered source. Cardinality is far below the full ~3592-file suite.
 *      Infection is never invoked over the full suite.
 *   - VAL-E3-008: a patch touching only non-test source (or no test files)
 *      => the adapter is a NO-OP: skipped with an explicit reason, never a
 *      false fail, infection not invoked.
 *   - VAL-E3-011 prep: the adapter's invocation carries the pcov.directory
 *      scoping so infection collects coverage via the installed pcov scoped
 *      to touched files (the precondition for a real driver-backed MSI). The
 *      real driver-backed MSI is proven by the manual check in the handoff
 *      interactiveChecks (running infection scoped with pcov loaded).
 *
 * This feature test exercises the adapter as a unit WITH the elevation
 * config resolution pattern used in production (ElevationConfig::for /
 * ::fromConfig) and the FakeMutationCommandRunner harness, mirroring the
 * IntentLikelyNotAddressedFlagTest pattern for E1.
 *
 * The MSI-threshold gate (advisory honesty flag / hard STATUS_FAILED) is
 * the e3-mutation-score-gate feature; this feature only ships the adapter +
 * scope + scoping, so the assertions here focus on scope + skip behavior.
 */
final class MutationTestingAdapterScopeTest extends TestCase
{
    // -- VAL-E3-001: scope is touched test files + covered source only --------

    public function test_val_e3_001_single_test_file_patch_scope_is_narrow_and_targeted(): void
    {
        $runner = new FakeMutationCommandRunner;
        $runner->queueOk(msi: 100.0, summaryPath: '/tmp/s.json');

        $adapter = $this->makeAdapter($runner, mode: 'advisory');

        $result = $adapter->run(
            runId: 'run-e3-001-feat',
            touchedFiles: [
                'tests/Unit/Ai/Programming/AtlasDev/Mutation/MyFixtureTest.php',
                'app/Services/Ai/Programming/AtlasDev/Mutation/MyFixture.php',
            ],
        );

        $this->assertFalse($result->skipped, 'a touched test file is not a skip');
        $this->assertFalse($result->failed, 'a successful infection run is not a fail');

        // Scope is exactly the touched test file + its covered source.
        $this->assertSame(
            ['tests/Unit/Ai/Programming/AtlasDev/Mutation/MyFixtureTest.php'],
            $result->scope->testFiles,
        );
        $this->assertContains(
            'app/Services/Ai/Programming/AtlasDev/Mutation/MyFixture.php',
            $result->scope->sourceFiles,
        );

        // Cardinality is far below the ~3592-file suite.
        $this->assertLessThan(
            50,
            count($result->scope->sourceFiles) + count($result->scope->testFiles),
            'VAL-E3-001: scope cardinality far below 3592',
        );

        // No untouched file in scope.
        foreach ($result->scope->sourceFiles as $source) {
            $this->assertStringContainsString(
                'MyFixture',
                $source,
                'VAL-E3-001: source scope is exactly the touched covered source',
            );
        }

        // pcov.directory is scoped to a real touched directory (never '.').
        foreach ($result->scope->pcovDirectories() as $dir) {
            $this->assertNotSame('.', $dir);
            $this->assertNotSame('', $dir);
        }

        // The infection command was issued exactly once and is scoped.
        $this->assertCount(1, $runner->calls, 'infection invoked exactly once');
        $command = $runner->calls[0]['command'];
        $this->assertMatchesRegularExpression(
            '/--filter=[^ ]*MyFixture\.php/',
            $command,
            'VAL-E3-001: --filter scopes mutation to touched source',
        );
        $this->assertStringContainsString(
            'pcov.directory=',
            $command,
            'VAL-E3-011: pcov.directory scope is on the command',
        );
    }

    public function test_val_e3_001_multi_file_patch_scopes_to_deduped_union(): void
    {
        $runner = new FakeMutationCommandRunner;
        $runner->queueOk(msi: 80.0, summaryPath: '/tmp/s.json');

        $adapter = $this->makeAdapter($runner, mode: 'advisory');

        $result = $adapter->run(
            runId: 'run-e3-012-feat',
            touchedFiles: [
                'tests/Unit/ModuleA/FooTest.php',
                'tests/Unit/ModuleB/BarTest.php',
                'app/ModuleA/Foo.php',
                'app/ModuleB/Bar.php',
            ],
        );

        $this->assertFalse($result->skipped);
        $this->assertSame(
            [
                'tests/Unit/ModuleA/FooTest.php',
                'tests/Unit/ModuleB/BarTest.php',
            ],
            $result->scope->testFiles,
            'both touched test files in scope',
        );
        // The deduped union of covered source.
        $this->assertSame(
            ['app/ModuleA/Foo.php', 'app/ModuleB/Bar.php'],
            $result->scope->sourceFiles,
            'VAL-E3-012: deduped union of touched covered source',
        );
    }

    // -- VAL-E3-008: no test files touched => no-op --------------------------

    public function test_val_e3_008_patch_touching_only_source_skips_with_reason_no_flag_no_fail(): void
    {
        $runner = new FakeMutationCommandRunner;
        $adapter = $this->makeAdapter($runner, mode: 'advisory');

        $result = $adapter->run(
            runId: 'run-e3-008-feat',
            touchedFiles: [
                'app/Services/SomeCode.php',
                'docs/some-doc.md',
            ],
        );

        $this->assertTrue($result->skipped, 'VAL-E3-008: gate skipped');
        $this->assertNotSame('', $result->skipReason, 'explicit skip reason');
        $this->assertFalse($result->failed, 'never a false fail');
        $this->assertNull($result->msi, 'no MSI produced when skipped');
        $this->assertSame(
            [],
            $runner->calls,
            'VAL-E3-008: infection not invoked (no full-suite run)',
        );
    }

    public function test_val_e3_008_empty_touched_files_skips_with_reason(): void
    {
        $runner = new FakeMutationCommandRunner;
        $adapter = $this->makeAdapter($runner, mode: 'advisory');

        $result = $adapter->run(runId: 'run-e3-008-empty-feat', touchedFiles: []);

        $this->assertTrue($result->skipped);
        $this->assertNotSame('', $result->skipReason);
        $this->assertFalse($result->failed);
        $this->assertSame([], $runner->calls, 'infection never invoked');
    }

    public function test_val_e3_008_skip_reason_cites_no_test_files_explicitly(): void
    {
        $adapter = $this->makeAdapter(new FakeMutationCommandRunner, mode: 'advisory');

        $result = $adapter->run(
            runId: 'run-e3-008-reason',
            touchedFiles: ['app/Foo.php'],
        );

        $this->assertTrue($result->skipped);
        $this->assertStringContainsString(
            'no',
            strtolower($result->skipReason),
            'VAL-E3-008: skip reason is explicit',
        );
        $this->assertStringContainsString(
            'test',
            strtolower($result->skipReason),
            'VAL-E3-008: skip reason references test files',
        );
    }

    // -- VAL-E3-010 adapter side: off mode => byte-identical no-op ------------

    public function test_val_e3_010_off_mode_is_byte_identical_no_op_infection_not_invoked(): void
    {
        $runner = new FakeMutationCommandRunner;
        $adapter = $this->makeAdapter($runner, mode: 'off');

        $result = $adapter->run(
            runId: 'run-e3-off-feat',
            touchedFiles: [
                'tests/Unit/SomeTest.php',
                'app/Some.php',
            ],
        );

        $this->assertTrue($result->skipped, 'off mode: adapter is a no-op');
        $this->assertNotSame('', $result->skipReason);
        $this->assertFalse($result->failed, 'off mode: never fails');
        $this->assertNull($result->msi, 'off mode: no MSI produced');
        $this->assertSame(
            [],
            $runner->calls,
            'VAL-E3-010: infection NOT invoked when e3.mode=off',
        );
        $this->assertStringContainsString(
            'off',
            $result->skipReason,
            'VAL-E3-010: skip reason cites the off mode',
        );
    }

    public function test_val_e3_010_off_mode_supersedes_empty_scope_reason(): void
    {
        // Even with empty touched files, the off-mode reason is the one
        // surfaced: the elevation is byte-identical regardless of inputs.
        $adapter = $this->makeAdapter(new FakeMutationCommandRunner, mode: 'off');

        $result = $adapter->run(runId: 'run-e3-off-empty-feat', touchedFiles: []);

        $this->assertTrue($result->skipped);
        $this->assertStringContainsString('off', $result->skipReason);
    }

    // -- VAL-E3-011 honest ceiling: missing driver surfaced honestly ---------

    public function test_val_e3_011_missing_coverage_driver_is_surfaced_as_failure_never_fabricated_msi(): void
    {
        $runner = new FakeMutationCommandRunner;
        $runner->queueFailure(stdout: 'PCOV is not installed; no coverage driver available');

        $adapter = $this->makeAdapter($runner, mode: 'advisory');

        $result = $adapter->run(
            runId: 'run-e3-011-feat',
            touchedFiles: [
                'tests/Unit/SomeTest.php',
                'app/Some.php',
            ],
        );

        $this->assertFalse($result->skipped, 'not skipped (test files were touched)');
        $this->assertTrue(
            $result->failed,
            'VAL-E3-011: missing driver surfaced as a real failure',
        );
        $this->assertNull(
            $result->msi,
            'VAL-E3-011: no fabricated MSI without a real driver-backed run',
        );
        $this->assertNotSame('', $result->failureReason, 'explicit failure reason');
    }

    public function test_val_e3_011_failed_infection_run_is_surfaced_honestly(): void
    {
        $runner = new FakeMutationCommandRunner;
        $runner->queueFailure(stdout: 'some unrelated failure during mutation');

        $adapter = $this->makeAdapter($runner, mode: 'advisory');

        $result = $adapter->run(
            runId: 'run-e3-failed',
            touchedFiles: [
                'tests/Unit/SomeTest.php',
                'app/Some.php',
            ],
        );

        $this->assertTrue($result->failed, 'a failed infection run surfaces honestly');
        $this->assertNull($result->msi, 'no fabricated MSI');
    }

    // -- Wiring: production-style ElevationConfig::for is honored -------------

    public function test_e3_config_resolution_via_elevationconfig_for_off_advisory_hard(): void
    {
        // The adapter honors the canonical ElevationConfig the same way the
        // other elevations (E1, E2) do, so the mission's flag tri-state
        // governs E3 uniformly (VAL-CROSS-009 per-elevation independence).
        foreach (['off', 'advisory', 'hard'] as $mode) {
            $runner = new FakeMutationCommandRunner;
            $runner->queueOk(msi: 100.0, summaryPath: '/tmp/s.json');

            $adapter = $this->makeAdapter($runner, mode: $mode);

            $result = $adapter->run(
                runId: 'run-e3-mode-'.$mode,
                touchedFiles: [
                    'tests/Unit/SomeTest.php',
                    'app/Some.php',
                ],
            );

            if ($mode === 'off') {
                $this->assertTrue(
                    $result->skipped,
                    "[{$mode}] off => adapter no-op (infection not invoked)",
                );
                $this->assertSame([], $runner->calls, "[{$mode}] infection not invoked");
            } else {
                $this->assertFalse(
                    $result->skipped,
                    "[{$mode}] non-off mode with touched test files => not skipped",
                );
                $this->assertCount(1, $runner->calls, "[{$mode}] infection invoked");
            }
        }
    }

    // -- Helpers -------------------------------------------------------------

    private function makeAdapter(FakeMutationCommandRunner $runner, string $mode): MutationTestingAdapter
    {
        return new MutationTestingAdapter(
            commandRunner: $runner,
            e3Config: ElevationConfig::for('e3', ['mode' => $mode]),
            repoRoot: '/repo',
        );
    }
}
