<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Mutation;

use App\Services\Ai\Programming\AtlasDev\Mutation\MutationTestingAdapter;
use App\Services\Ai\Programming\AtlasDev\Mutation\MutationTestingResult;
use App\Services\Ai\Programming\AtlasDev\Support\Elevations\ElevationConfig;
use PHPUnit\Framework\TestCase;

/**
 * E3 — MutationTestingAdapter scope computation (VAL-E3-001, VAL-E3-008,
 * VAL-E3-011) and off-mode wiring (partial VAL-E3-010 adapter side).
 *
 * The adapter is the structural component that turns a patch's touched files
 * into a scoped infection invocation:
 *   - It computes the SCOPE: touched test files + their covered source files,
 *     with cardinality FAR below the full ~3592-file suite (VAL-E3-001).
 *   - When no test files are touched, it is a NO-OP: skipped with an explicit
 *     reason, no infection invocation, never a false fail (VAL-E3-008).
 *   - The adapter invokes infection through a command runner so the harness
 *     can substitute FakeMutationCommandRunner; the real command produced
 *     carries:
 *       * --filter scoped to the touched SOURCE files (mutation scope)
 *       * --initial-tests-php-options with -d pcov.directory scoped to the
 *         touched directories (coverage instrumentation scope)
 *       * --test-framework-options scoping PHPUnit to the touched TEST files
 *       * --logger-summary-json so the adapter can read the real reported MSI
 *   - The adapter never runs infection when e3.mode=off (byte-identical to
 *     pre-E3): VAL-E3-010 partial — the gate/flag side is the next feature.
 *
 * The adapter does NOT yet apply the MSI threshold/flag: that is the
 * e3-mutation-score-gate feature. Here it only computes scope + drives the
 * scoped infection run (or skips) and surfaces the parsed MSI + scope.
 *
 * VAL-E3-011 is proven by the real-infection manual check (see handoff); the
 * unit test here asserts the produced command carries the pcov.directory
 * scoping (the precondition for a driver-backed scoped MSI).
 */
final class MutationTestingAdapterTest extends TestCase
{
    // -- VAL-E3-001: scope = touched test files + their covered source only ----

    public function test_val_e3_001_scope_is_touched_test_files_plus_covered_source_cardinality_below_3592(): void
    {
        $adapter = new MutationTestingAdapter(
            commandRunner: new FakeMutationCommandRunner(),
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            repoRoot: '/repo',
        );

        $scope = $adapter->computeScope(
            touchedFiles: [
                'tests/Unit/Foo/BarTest.php',
                'app/Services/Foo/Bar.php',
            ],
        );

        // Touched test files are captured.
        $this->assertSame(
            ['tests/Unit/Foo/BarTest.php'],
            $scope->testFiles,
            'VAL-E3-001: touched test files captured',
        );
        // Covered source = the touched source files (the patch's covered source).
        $this->assertContains(
            'app/Services/Foo/Bar.php',
            $scope->sourceFiles,
            'VAL-E3-001: touched source files in scope',
        );
        // No file outside the touched set is in scope.
        foreach ($scope->sourceFiles as $source) {
            $this->assertStringStartsWith(
                'app/',
                $source,
                "VAL-E3-001: source file '{$source}' must be inside the touched app/ set",
            );
        }
        // Cardinality is FAR below the ~3592-file suite.
        $this->assertLessThan(
            100,
            count($scope->sourceFiles) + count($scope->testFiles),
            'VAL-E3-001: scope cardinality is far below 3592',
        );
        $this->assertFalse($scope->isEmpty(), 'VAL-E3-001: scope is non-empty');
    }

    public function test_val_e3_001_multi_test_file_patch_scopes_to_deduped_union(): void
    {
        $adapter = new MutationTestingAdapter(
            commandRunner: new FakeMutationCommandRunner(),
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            repoRoot: '/repo',
        );

        // VAL-E3-012 prep: two test files covering two source files with overlap.
        $scope = $adapter->computeScope(
            touchedFiles: [
                'tests/Unit/Foo/BarTest.php',
                'tests/Unit/Foo/BazTest.php',
                'app/Services/Foo/Bar.php',
                'app/Services/Foo/Baz.php',
                'app/Services/Foo/Shared.php', // shared by both
            ],
        );

        $this->assertCount(2, $scope->testFiles, 'both test files in scope');
        // Deduped union of touched source.
        $this->assertSame(
            [
                'app/Services/Foo/Bar.php',
                'app/Services/Foo/Baz.php',
                'app/Services/Foo/Shared.php',
            ],
            $scope->sourceFiles,
            'VAL-E3-012: deduped union of covered source',
        );
    }

    public function test_val_e3_001_convention_derives_source_from_touched_test_when_source_not_in_patch(): void
    {
        // A test file touched without its source in the patch still scopes
        // the covered source via the convention (the unit-under-test path).
        $adapter = new MutationTestingAdapter(
            commandRunner: new FakeMutationCommandRunner(),
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            repoRoot: '/repo',
        );

        $scope = $adapter->computeScope(
            touchedFiles: ['tests/Unit/CalculatorTest.php'],
        );

        $this->assertSame(['tests/Unit/CalculatorTest.php'], $scope->testFiles);
        $this->assertContains(
            'app/Calculator.php',
            $scope->sourceFiles,
            'convention: tests/Unit/CalculatorTest.php covers app/Calculator.php',
        );
    }

    // -- VAL-E3-008: no test files touched => no-op (skipped, no fail) --------

    public function test_val_e3_008_no_test_files_touched_is_skipped_with_reason_no_flag_no_fail(): void
    {
        $runner = new FakeMutationCommandRunner();
        $adapter = new MutationTestingAdapter(
            commandRunner: $runner,
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            repoRoot: '/repo',
        );

        $result = $adapter->run(
            runId: 'run-e3-008',
            touchedFiles: [
                'app/Services/Foo/Bar.php', // source only, no tests
                'docs/foo.md',
            ],
        );

        $this->assertTrue($result->skipped, 'VAL-E3-008: gate skipped');
        $this->assertNotSame('', $result->skipReason, 'VAL-E3-008: explicit skip reason');
        $this->assertFalse($result->failed, 'VAL-E3-008: never a false fail');
        $this->assertNull($result->msi, 'VAL-E3-008: no MSI produced when skipped');
        // Infection was NEVER invoked.
        $this->assertSame(
            [],
            $runner->calls,
            'VAL-E3-008: infection not invoked over the full suite',
        );
    }

    public function test_val_e3_008_empty_touched_files_is_skipped_with_reason(): void
    {
        $runner = new FakeMutationCommandRunner();
        $adapter = new MutationTestingAdapter(
            commandRunner: $runner,
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            repoRoot: '/repo',
        );

        $result = $adapter->run(runId: 'run-e3-008-empty', touchedFiles: []);

        $this->assertTrue($result->skipped, 'empty touched files => skipped');
        $this->assertNotSame('', $result->skipReason, 'explicit reason');
        $this->assertFalse($result->failed, 'never a false fail');
        $this->assertSame([], $runner->calls, 'infection never invoked');
    }

    // -- VAL-E3-001 + VAL-E3-011: scoped infection invocation -----------------

    public function test_val_e3_001_invoked_command_scopes_filter_to_touched_source_only(): void
    {
        $runner = new FakeMutationCommandRunner();
        $runner->queueOk(msi: 100.0, summaryPath: '/tmp/summary.json');

        $adapter = new MutationTestingAdapter(
            commandRunner: $runner,
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            repoRoot: '/repo',
        );

        $result = $adapter->run(
            runId: 'run-e3-001',
            touchedFiles: [
                'tests/Unit/CalculatorTest.php',
                'app/Calculator.php',
            ],
        );

        $this->assertFalse($result->skipped, 'a touched test file => not skipped');
        $this->assertSame(100.0, $result->msi, 'MSI parsed from infection summary');

        $this->assertCount(1, $runner->calls, 'infection invoked exactly once');
        $command = $runner->calls[0]['command'];

        // --filter scopes MUTATION to touched source only. The value is shell-
        // escaped (escapeshellarg) so we assert the path appears between the
        // --filter= token and the next flag.
        $this->assertMatchesRegularExpression(
            '/--filter=[^ ]*app\/Calculator\.php/',
            $command,
            'VAL-E3-001: --filter scopes mutation to touched source',
        );
        // No untouched file in the command's filter.
        $this->assertDoesNotMatchRegularExpression(
            '/--filter=.*Other\.php/',
            $command,
            'VAL-E3-001: untouched files are not in the mutation scope',
        );
    }

    public function test_val_e3_011_command_scopes_pcov_directory_to_touched_dirs_only(): void
    {
        $runner = new FakeMutationCommandRunner();
        $runner->queueOk(msi: 100.0, summaryPath: '/tmp/summary.json');

        $adapter = new MutationTestingAdapter(
            commandRunner: $runner,
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            repoRoot: '/repo',
        );

        $result = $adapter->run(
            runId: 'run-e3-011',
            touchedFiles: [
                'tests/Unit/CalculatorTest.php',
                'app/Calculator.php',
            ],
        );

        $command = $runner->calls[0]['command'];

        // pcov.directory is scoped via -d to a touched directory (never the
        // repo root '.', which would instrument the full 3592-file tree).
        // The robust check is on the SCOPE itself: pcovDirectories() must
        // return real touched directories and never the bare repo root.
        // The command shape check confirms the -d pcov.directory token is
        // present.
        $this->assertMatchesRegularExpression(
            '/pcov\.directory=/',
            $command,
            'VAL-E3-011: pcov.directory token is present in the invocation',
        );
        $pcovDirs = $result->scope->pcovDirectories();
        $this->assertNotEmpty(
            $pcovDirs,
            'VAL-E3-011: scope yields at least one pcov.directory',
        );
        foreach ($pcovDirs as $dir) {
            $this->assertNotSame(
                '.',
                $dir,
                'VAL-E3-011: pcov.directory must NOT be the bare repo root',
            );
            $this->assertNotSame(
                '',
                $dir,
                'VAL-E3-011: pcov.directory must not be empty',
            );
            $this->assertStringStartsWith(
                'app',
                $dir,
                'VAL-E3-011: pcov.directory references a real touched app/ source dir',
            );
        }
    }

    public function test_val_e3_001_invoked_command_scopes_phpunit_to_touched_test_files(): void
    {
        $runner = new FakeMutationCommandRunner();
        $runner->queueOk(msi: 100.0, summaryPath: '/tmp/summary.json');

        $adapter = new MutationTestingAdapter(
            commandRunner: $runner,
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            repoRoot: '/repo',
        );

        $adapter->run(
            runId: 'run-e3-001-tests',
            touchedFiles: [
                'tests/Unit/CalculatorTest.php',
                'app/Calculator.php',
            ],
        );

        $command = $runner->calls[0]['command'];

        // The test framework is scoped to the touched test files via
        // --test-framework-options so PHPUnit does not run the entire suite
        // during the initial coverage run. PHPUnit's --filter is a regex over
        // the test class/method name, so the adapter derives a basename-based
        // pattern from the touched test file paths (tests/Unit/FooTest.php =>
        // "FooTest"). This narrows the initial run to the touched test
        // classes only.
        $this->assertStringContainsString(
            '--test-framework-options=',
            $command,
            'VAL-E3-001: test framework options narrow PHPUnit scope',
        );
        $this->assertStringContainsString(
            'CalculatorTest',
            $command,
            'VAL-E3-001: PHPUnit scoped to the touched CalculatorTest class',
        );
        $this->assertStringNotContainsString(
            'tests/SomeOtherTest.php',
            $command,
            'VAL-E3-001: untouched tests not referenced',
        );
    }

    public function test_val_e3_001_invoked_command_writes_summary_json_for_real_msi_parsing(): void
    {
        $runner = new FakeMutationCommandRunner();
        // The fake simulates infection writing MSI=73.5 to whichever summary
        // path the adapter told infection to use. The adapter chooses the
        // deterministic per-run path; the fake's reported MSI is the parsed
        // value from that path.
        $runner->queueOk(msi: 73.5, summaryPath: '/tmp/ignored-by-adapter.json');

        $adapter = new MutationTestingAdapter(
            commandRunner: $runner,
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            repoRoot: '/repo',
        );

        $result = $adapter->run(
            runId: 'run-e3-msi',
            touchedFiles: [
                'tests/Unit/CalculatorTest.php',
                'app/Calculator.php',
            ],
        );

        $command = $runner->calls[0]['command'];

        $this->assertStringContainsString(
            '--logger-summary-json=',
            $command,
            'VAL-E3-007 prep: --logger-summary-json so the adapter reads the real reported MSI',
        );
        $this->assertSame(73.5, $result->msi, 'MSI parsed from the infection summary');
        // The adapter chooses the summary path (under storage/atlas-dev/
        // mutation/<runId>/) so the artifact is reproducible per-run
        // (VAL-CROSS-016: every emitted evidenceRef resolves to a concrete
        // reproducible path). The fake's queued summaryPath is informational.
        $this->assertStringContainsString(
            'run-e3-msi',
            $result->summaryPath ?? '',
            'summary path scoped under the runId',
        );
        $this->assertStringContainsString(
            'infection-summary.json',
            $result->summaryPath ?? '',
            'summary path is the infection summary JSON',
        );
        $this->assertFalse($result->failed, 'a successful infection run is not a fail');
    }

    public function test_val_e3_011_pcov_is_loaded_precondition_is_respected(): void
    {
        // The adapter does NOT silently fabricate an MSI when no driver is
        // loaded. If pcov is missing, the adapter reports the infection
        // invocation outcome honestly (failed=true) so the gate (next
        // feature) cannot green over a missing-driver false-pass.
        $runner = new FakeMutationCommandRunner();
        $runner->queueFailure(stdout: 'no coverage driver available');

        $adapter = new MutationTestingAdapter(
            commandRunner: $runner,
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            repoRoot: '/repo',
        );

        $result = $adapter->run(
            runId: 'run-e3-nodriver',
            touchedFiles: [
                'tests/Unit/CalculatorTest.php',
                'app/Calculator.php',
            ],
        );

        $this->assertFalse($result->skipped, 'not skipped (test files were touched)');
        $this->assertNull($result->msi, 'no fabricated MSI without a real run');
        $this->assertTrue($result->failed, 'a real infection failure is surfaced honestly');
        $this->assertNotSame('', (string) $result->failureReason, 'explicit failure reason');
    }

    // -- VAL-E3-010 adapter side: off mode => infection not invoked -----------

    public function test_val_e3_010_off_mode_does_not_invoke_infection_byte_identical(): void
    {
        $runner = new FakeMutationCommandRunner();
        $adapter = new MutationTestingAdapter(
            commandRunner: $runner,
            e3Config: ElevationConfig::for('e3', ['mode' => 'off']),
            repoRoot: '/repo',
        );

        $result = $adapter->run(
            runId: 'run-e3-off',
            touchedFiles: [
                'tests/Unit/CalculatorTest.php',
                'app/Calculator.php',
            ],
        );

        $this->assertTrue($result->skipped, 'off mode: adapter is a no-op');
        $this->assertNotSame('', $result->skipReason, 'off mode: explicit reason');
        $this->assertFalse($result->failed, 'off mode: never fails');
        $this->assertNull($result->msi, 'off mode: no MSI');
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

    public function test_off_mode_supersedes_no_test_files_skip_reason(): void
    {
        // When both conditions hold (off mode AND no test files), the off
        // mode reason is the one surfaced: the elevation is byte-identical
        // regardless of inputs (VAL-E3-010), not "skipped because no tests".
        $adapter = new MutationTestingAdapter(
            commandRunner: new FakeMutationCommandRunner(),
            e3Config: ElevationConfig::for('e3', ['mode' => 'off']),
            repoRoot: '/repo',
        );

        $result = $adapter->run(runId: 'run-e3-off-empty', touchedFiles: []);

        $this->assertTrue($result->skipped);
        $this->assertStringContainsString('off', $result->skipReason);
    }

    // -- Workspace / configuration wiring ------------------------------------

    public function test_invoked_command_uses_repo_root_infection_config_with_phputil_configdir(): void
    {
        // The adapter writes a per-run infection.json5 that pins
        // phpUnit.configDir to the repo root (resolving the config-relative
        // path issue) and inherits the canonical source.directories. The
        // adapter passes --configuration pointing at the per-run config.
        $repoRoot = sys_get_temp_dir().'/e3-cfg-test-'.uniqid();
        @mkdir($repoRoot, 0o775, true);

        $runner = new FakeMutationCommandRunner();
        $runner->queueOk(msi: 100.0, summaryPath: '/tmp/summary.json');

        $adapter = new MutationTestingAdapter(
            commandRunner: $runner,
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            repoRoot: $repoRoot,
        );

        $adapter->run(
            runId: 'run-e3-cfg',
            touchedFiles: [
                'tests/Unit/CalculatorTest.php',
                'app/Calculator.php',
            ],
        );

        $command = $runner->calls[0]['command'];

        $this->assertStringContainsString(
            '--configuration=',
            $command,
            'adapter points infection at a config file',
        );
        $this->assertStringContainsString(
            'infection.json5',
            $command,
            'adapter uses an infection.json5 config',
        );

        // The per-run config file was written and pins phpUnit.configDir to
        // the repo root, resolving the config-relative path issue.
        $perRunConfig = $this->extractConfigurationPath($command);
        $this->assertFileExists($perRunConfig, 'per-run config was written');
        $decoded = json_decode((string) file_get_contents($perRunConfig), true);
        $this->assertSame(
            $repoRoot,
            $decoded['phpUnit']['configDir'] ?? null,
            'per-run config pins phpUnit.configDir to the repo root',
        );
        $this->assertSame(
            ['app'],
            $decoded['source']['directories'] ?? null,
            'per-run config inherits the canonical source.directories',
        );
    }

    public function test_invoked_command_uses_scoped_tmp_dir_under_run_id(): void
    {
        // Each E3 run uses an isolated tmpDir so concurrent runs (e.g. best-
        // of-N candidates, VAL-E5-013 shared baseline pattern) do not collide
        // on infection's coverage-xml / junit artifacts. The tmpDir is set
        // in the per-run config file (NOT a CLI flag; --tmp-dir does not
        // exist on infection 0.33.x).
        $repoRoot = sys_get_temp_dir().'/e3-tmp-test-'.uniqid();
        @mkdir($repoRoot, 0o775, true);

        $runner = new FakeMutationCommandRunner();
        $runner->queueOk(msi: 100.0, summaryPath: '/tmp/summary.json');

        $adapter = new MutationTestingAdapter(
            commandRunner: $runner,
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            repoRoot: $repoRoot,
        );

        $adapter->run(
            runId: 'run-e3-tmpdir',
            touchedFiles: [
                'tests/Unit/CalculatorTest.php',
                'app/Calculator.php',
            ],
        );

        $command = $runner->calls[0]['command'];
        $perRunConfig = $this->extractConfigurationPath($command);
        $this->assertFileExists($perRunConfig);
        $decoded = json_decode((string) file_get_contents($perRunConfig), true);
        $this->assertStringContainsString(
            'run-e3-tmpdir',
            $decoded['tmpDir'] ?? '',
            'tmpDir is scoped under the runId (per-run isolation)',
        );
    }

    private function extractConfigurationPath(string $command): string
    {
        if (preg_match('/--configuration=([^\s]+)/', $command, $m) !== 1) {
            return '';
        }
        $value = $m[1];
        if (strlen($value) >= 2 && $value[0] === "'" && substr($value, -1) === "'") {
            $value = substr($value, 1, -1);
            $value = str_replace("'\\''", "'", $value);
        }

        return $value;
    }

    public function test_result_carries_scope_for_evidence_and_anti_gaming_checks(): void
    {
        $runner = new FakeMutationCommandRunner();
        $runner->queueOk(msi: 80.0, summaryPath: '/tmp/summary.json');

        $adapter = new MutationTestingAdapter(
            commandRunner: $runner,
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            repoRoot: '/repo',
        );

        $result = $adapter->run(
            runId: 'run-e3-scope-evidence',
            touchedFiles: [
                'tests/Unit/CalculatorTest.php',
                'app/Calculator.php',
            ],
        );

        // The result carries the scope (source + test files) so downstream
        // checks (anti-gaming: VAL-E3-005/006, multi-file: VAL-E3-012/013)
        // can assert the gate is reasoning over exactly this scope and
        // nothing wider. VAL-CROSS-016: every evidenceRef must resolve.
        $this->assertNotNull($result->scope);
        $this->assertSame(['tests/Unit/CalculatorTest.php'], $result->scope->testFiles);
        $this->assertContains('app/Calculator.php', $result->scope->sourceFiles);
    }
}
