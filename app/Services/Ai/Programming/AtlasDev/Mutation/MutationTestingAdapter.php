<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Mutation;

use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevStringListNormalizer;
use App\Services\Ai\Programming\AtlasDev\Support\Elevations\ElevationConfig;

/**
 * E3 — MutationTestingAdapter.
 *
 * Turns a patch's touched files into a SCOPED infection invocation and
 * parses the real reported MSI. Structural, model-irrelevant: the scope is
 * computed purely from the patch's file list (no provider signal), the
 * invocation is a deterministic subprocess, and the MSI is read from
 * infection's own summary JSON (VAL-E3-007: real reported MSI, no self-
 * declared score).
 *
 * Scope (VAL-E3-001, mission boundary):
 *   - The touched TEST files (tests/...) are captured.
 *   - The covered SOURCE files infection will MUTATE are the deduped union
 *     of the touched source files plus the source files convention-derived
 *     from touched test files (tests/Unit/FooTest.php covers app/Foo.php).
 *   - Cardinality is FAR below the ~3592-file suite. The adapter NEVER runs
 *     mutation/coverage across the full tree.
 *
 * Scoping mechanisms (each visible on the produced command):
 *   - `--filter=<src1.php,src2.php>`: scopes MUTATION to touched source only.
 *   - `--initial-tests-php-options='-d pcov.directory=<dir>'`: scopes pcov
 *      coverage INSTRUMENTATION to the touched source dirs only (never the
 *      repo root '.', which would instrument the full ~3592-file tree).
 *   - `--test-framework-options='--filter <TestClassNamePattern>'`: scopes
 *      PHPUnit's initial coverage run to the touched TEST files only.
 *
 * No-op paths (never a false fail):
 *   - VAL-E3-008: no test files touched (or no files at all) => skipped with
 *     explicit reason, infection NOT invoked, no flag/fail. Source-only
 *     patches do not trigger E3.
 *   - VAL-E3-010: e3.mode=off => infection NOT invoked, byte-identical to
 *     pre-E3. The off-mode reason supersedes the empty-scope reason.
 *
 * Honest-ceiling (VAL-E3-011): if infection fails (non-zero exit) or
 * reports a missing coverage driver, the adapter surfaces failed=true with
 * a non-empty reason and a NULL MSI. It NEVER fabricates an MSI over a
 * missing driver. The downstream gate decides what to do with the failure.
 *
 * The adapter does NOT apply the MSI threshold / honesty flag / STATUS_FAILED.
 * That is the e3-mutation-score-gate feature. This adapter only computes
 * scope + drives the scoped infection run (or skips) and surfaces the
 * parsed MSI + scope.
 *
 * Canonical: mission architecture.md (Atlas Dev Elevation v2, M3 / E3).
 */
final class MutationTestingAdapter
{
    /**
     * The honesty-flag name the E3 gate appends in advisory mode. Shared so
     * the gate (next feature) and tests reference the canonical string.
     */
    public const FLAG_MUTATION_SCORE_BELOW_THRESHOLD = 'mutation_score_below_threshold';

    /**
     * The repo-relative path to the canonical infection config. The adapter
     * passes --configuration=<repoRoot>/<configFile> so infection resolves
     * its config relative to the repo root, not the cwd the subprocess was
     * spawned from (resolving the config-relative path issue).
     */
    public const INFECTION_CONFIG_PATH = 'infection.json5';

    /**
     * Default per-run timeout for the scoped infection invocation. Scoped
     * runs are small (a handful of touched files) so 120s is generous; the
     * gate may override.
     */
    public const DEFAULT_TIMEOUT_SECONDS = 120;

    public function __construct(
        private readonly MutationCommandRunner $commandRunner,
        private readonly ElevationConfig $e3Config,
        private readonly string $repoRoot,
        private readonly int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ) {}

    /**
     * Compute the deduplicated scope (touched test files + their covered
     * source) for a patch's touched file list, WITHOUT invoking infection.
     *
     * Public so the gate / anti-gaming checks / evidence artifacts can
     * inspect the exact scope the run WILL be (or WAS) scoped to
     * (VAL-E3-001, VAL-E3-012, VAL-CROSS-016 evidence resolution).
     *
     * @param  list<string>  $touchedFiles  repo-relative paths in the patch.
     */
    public function computeScope(array $touchedFiles): MutationScope
    {
        $touchedFiles = AtlasDevStringListNormalizer::uniqueTrimmedStrings($touchedFiles);

        $testFiles = [];
        $explicitlyTouchedSources = [];
        foreach ($touchedFiles as $file) {
            if (str_starts_with($file, 'tests/')) {
                $testFiles[] = $file;
            } elseif (str_starts_with($file, 'app/')) {
                $explicitlyTouchedSources[] = $file;
            }
        }

        // VAL-E3-001: covered source = the touched source files (the patch's
        // covered source) PLUS the source files convention-derived from
        // touched test files — but only when no source file matching that
        // basename was already touched. This keeps the scope exact: when the
        // patch touched both a test file and its unit-under-test, the
        // convention derivation would otherwise pollute the scope with
        // not-actually-touched mirror paths.
        $explicitlyTouchedBasenames = [];
        foreach ($explicitlyTouchedSources as $source) {
            $base = pathinfo($source, PATHINFO_FILENAME);
            if ($base !== '') {
                $explicitlyTouchedBasenames[$base] = true;
            }
        }

        $sourceFiles = $explicitlyTouchedSources;
        foreach ($testFiles as $testFile) {
            foreach (self::conventionCoveredSource($testFile) as $candidate) {
                $candidateBase = pathinfo($candidate, PATHINFO_FILENAME);
                // Skip convention candidates whose basename is already
                // covered by an explicitly touched source file.
                if (isset($explicitlyTouchedBasenames[$candidateBase])) {
                    continue;
                }
                $sourceFiles[] = $candidate;
            }
        }

        return new MutationScope(
            testFiles: AtlasDevStringListNormalizer::uniqueStrings($testFiles),
            sourceFiles: AtlasDevStringListNormalizer::uniqueStrings($sourceFiles),
        );
    }

    /**
     * Run a scoped infection invocation for the patch (or skip).
     *
     * @param  string  $runId  the Atlas Dev run id (used to isolate the
     *                          infection tmpDir per-run, so concurrent runs
     *                          like best-of-N candidates do not collide on
     *                          coverage-xml / junit artifacts).
     * @param  list<string>  $touchedFiles  repo-relative paths in the patch.
     */
    public function run(string $runId, array $touchedFiles): MutationTestingResult
    {
        // VAL-E3-010: off mode => byte-identical no-op. The off-mode reason
        // supersedes the empty-scope reason so the elevation is byte-
        // identical regardless of inputs.
        if ($this->e3Config->isOff()) {
            return MutationTestingResult::skipped(
                'e3.mode=off: mutation gate is byte-identical to pre-E3 (infection not invoked)',
            );
        }

        $scope = $this->computeScope($touchedFiles);

        // VAL-E3-008: no test files touched => no-op (skipped with reason,
        // never a false fail). Infection is NOT invoked over the full suite.
        if ($scope->isEmpty()) {
            return MutationTestingResult::skipped(
                'e3: no added/modified test files in the patch; mutation gate is a no-op',
            );
        }

        // If the scope has source files to mutate, run infection scoped to
        // that source. If touched test files exist but no source resolved
        // (rare: test files without a convention-covered unit-under-test),
        // scope.sourceFiles is empty => infection has nothing to mutate =>
        // skip honestly rather than run an empty mutation set.
        if ($scope->sourceFiles === []) {
            return MutationTestingResult::skipped(
                'e3: touched test files have no covered source to mutate; mutation gate is a no-op',
            );
        }

        $summaryPath = $this->summaryPath($runId);
        $command = $this->buildCommand(
            runId: $runId,
            scope: $scope,
            summaryPath: $summaryPath,
        );

        $outcome = $this->commandRunner->run(
            $command,
            $this->repoRoot,
            $this->timeoutSeconds,
        );

        // VAL-E3-011 honest-ceiling: a non-zero exit OR a missing-driver
        // diagnostic is surfaced as a real failure with a NULL MSI. The
        // adapter never fabricates a score over a failed/unevaluable run.
        if (! $outcome->ok()) {
            return MutationTestingResult::failed(
                $this->describeFailure($outcome),
            );
        }
        if ($outcome->indicatesMissingCoverageDriver()) {
            return MutationTestingResult::failed(
                'e3: infection reported a missing coverage driver (pcov/xdebug). '
                .'The gate cannot read a real driver-backed MSI; failing honestly.',
            );
        }
        if ($outcome->summaryMsi === null) {
            return MutationTestingResult::failed(
                'e3: infection completed but no MSI could be parsed from its summary JSON ('
                .$summaryPath.'). Refusing to fabricate a score.',
            );
        }

        return MutationTestingResult::completed(
            msi: $outcome->summaryMsi,
            summaryPath: $summaryPath,
            scope: $scope,
        );
    }

    /**
     * Build the scoped infection command line. Visible to tests via the
     * FakeMutationCommandRunner so the harness can assert each scoping
     * mechanism is on the produced command (VAL-E3-001, VAL-E3-011).
     *
     * The command is the canonical infection binary (resolved by the runner
     * workspace's vendor/bin/infection) with:
     *   - --configuration=<per-run-config> (a per-run infection.json5 written
     *     by {@see writePerRunConfig()} that pins phpUnit.configDir to the repo
     *     root, sets source.directories=app, and overrides tmpDir to a per-run
     *     isolated path so concurrent runs do not collide on coverage-xml /
     *     junit artifacts). The per-run config also resolves the config-
     *     relative path issue: phpUnit.configDir = "." (the per-run config's
     *     own directory's parent is the repo root).
     *   - --filter=<src1.php,src2.php> (mutation scope = touched source only)
     *   - --initial-tests-php-options with -d pcov.directory=<dir> per touched dir
     *     (coverage instrumentation scope = touched dirs only, never repo root)
     *   - --test-framework-options scoping PHPUnit to the touched test files
     *   - --logger-summary-json=<summaryPath> (real reported MSI source)
     *   - --no-interaction --no-progress (deterministic CI-friendly run)
     *
     * NOTE: --tmp-dir is NOT a CLI option (it is config-file-only in
     * infection 0.33.x), so per-run tmpDir isolation is achieved via the
     * per-run config file, not via a CLI flag.
     */
    private function buildCommand(string $runId, MutationScope $scope, string $summaryPath): string
    {
        $php = '/opt/homebrew/bin/php';
        $infection = $this->repoRoot.'/vendor/bin/infection';
        $perRunConfig = $this->writePerRunConfig($runId);

        $parts = [
            $php,
            escapeshellarg($infection),
            '--no-interaction',
            '--no-progress',
            '--configuration='.escapeshellarg($perRunConfig),
            // VAL-E3-001: mutation scope = touched source only.
            '--filter='.escapeshellarg(implode(',', $scope->sourceFiles)),
            // VAL-E3-007: read the REAL reported MSI from the summary JSON.
            '--logger-summary-json='.escapeshellarg($summaryPath),
        ];

        // VAL-E3-011 + VAL-E3-001: scope pcov coverage instrumentation to the
        // touched source directories ONLY. One -d pcov.directory=<dir> entry
        // per scoped directory; never '.' (the repo root), which would
        // instrument the full ~3592-file tree (~40s+ unscoped baseline).
        $phpOptions = [];
        foreach ($scope->pcovDirectories() as $dir) {
            $phpOptions[] = '-d';
            $phpOptions[] = 'pcov.directory='.escapeshellarg($dir);
        }
        if ($phpOptions !== []) {
            $parts[] = '--initial-tests-php-options='.escapeshellarg(implode(' ', $phpOptions));
        }

        // VAL-E3-001: scope PHPUnit's initial coverage run to the touched
        // TEST files only. PHPUnit resolves --filter as a regex over test
        // method names, so we pass the test class basenames (without the
        // Test.php suffix) which uniquely select the touched test files
        // without coupling to a specific method name.
        $testFilterPattern = $this->buildTestFrameworkFilterPattern($scope->testFiles);
        if ($testFilterPattern !== '') {
            $parts[] = '--test-framework-options='.escapeshellarg(
                '--filter '.escapeshellarg($testFilterPattern)
            );
        }

        return implode(' ', $parts);
    }

    /**
     * Write a per-run infection.json5 that pins the canonical settings
     * (phpUnit.configDir = repo root, source.directories = app) and overrides
     * tmpDir to a per-run isolated path so concurrent runs do not collide on
     * infection's coverage-xml / junit artifacts.
     *
     * IMPORTANT: paths in the per-run config are ABSOLUTE. Infection resolves
     * `source.directories` and `phpUnit.configDir` relative to the per-run
     * CONFIG FILE's directory (NOT the repo root), so a per-run config under
     * storage/atlas-dev/mutation/<runId>/ would otherwise point at non-
     * existent `storage/atlas-dev/mutation/<runId>/app` instead of the real
     * repo-root `app/`. Using absolute paths is the config-relative path fix
     * (VAL-E3-011 / VAL-E3-001 precondition).
     *
     * The per-run config inherits the canonical {@see INFECTION_CONFIG_PATH}
     * semantically (same phpUnit.configDir = repo root, same source base)
     * so the only per-run variance is tmpDir. Keeping the per-run config
     * minimal (no mutators, no minMsi — the gate applies those) preserves
     * the canonical config as the source of truth for global defaults.
     *
     * Returns the absolute path to the written per-run config.
     */
    private function writePerRunConfig(string $runId): string
    {
        $dir = dirname($this->summaryPath($runId));
        if (! is_dir($dir) && ! @mkdir($dir, 0o775, true) && ! is_dir($dir)) {
            // Fall back to the canonical config if the per-run directory
            // cannot be created (the runner will surface the failure
            // honestly rather than fabricate a score).
            return rtrim($this->repoRoot, '/').'/'.self::INFECTION_CONFIG_PATH;
        }

        $path = $dir.'/infection.json5';
        $repoRoot = rtrim($this->repoRoot, '/');
        $tmpDir = $this->tmpDir($runId);
        $config = [
            '$schema' => 'vendor/infection/infection/resources/schema.json',
            // ABSOLUTE path so infection resolves the source base at the
            // repo root regardless of the per-run config file's location.
            'source' => ['directories' => [$repoRoot.'/app']],
            // ABSOLUTE path: PHPUnit's configDir is the repo root.
            'phpUnit' => ['configDir' => $repoRoot],
            'tmpDir' => $tmpDir,
            'threads' => 1,
        ];
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        if (@file_put_contents($path, $json) === false) {
            return rtrim($this->repoRoot, '/').'/'.self::INFECTION_CONFIG_PATH;
        }

        return $path;
    }

    /**
     * The test-framework filter pattern that selects exactly the touched
     * PHPUnit test files. PHPUnit's --filter is a regex over the test name
     * (which includes the class name); we join the basenames (without
     // Test.php) with `|` to select all touched test classes in one run.
     *
     * @param  list<string>  $testFiles
     */
    private function buildTestFrameworkFilterPattern(array $testFiles): string
    {
        $basenames = [];
        foreach ($testFiles as $testFile) {
            $base = pathinfo($testFile, PATHINFO_FILENAME);
            if ($base === '') {
                continue;
            }
            // Strip a trailing Test suffix so the pattern matches the test
            // class regardless of whether the file is FooTest.php (covers
            // Foo) or Foo.php (a test class named Foo). Restoring the Test
            // suffix is necessary because PHPUnit test class names end with
            // Test; without it the regex would over-match.
            if (str_ends_with($base, 'Test')) {
                $basenames[] = $base;
            } else {
                $basenames[] = $base.'Test';
            }
        }
        $basenames = array_values(array_unique($basenames));

        return $basenames === [] ? '' : implode('|', $basenames);
    }

    /**
     * Convention-derived covered source files for a touched test file.
     *
     * tests/Unit/Foo/BarTest.php  => app/Foo/Bar.php (and app/Bar.php)
     * tests/Feature/Foo/BarTest.php => app/Foo/Bar.php (and app/Bar.php)
     *
     * The convention covers the common case (test mirrors source path under
     * app/) and degrades gracefully: if the convention-derived path does not
     * exist, the caller still has the touched source files in scope. The
     * convention never widens scope outside app/.
     *
     * @return list<string>
     */
    private static function conventionCoveredSource(string $testFile): array
    {
        $base = pathinfo($testFile, PATHINFO_FILENAME);
        if ($base === '') {
            return [];
        }
        if (str_ends_with($base, 'Test')) {
            $base = substr($base, 0, -strlen('Test'));
        }
        if ($base === '') {
            return [];
        }

        // Strip the leading tests/Unit|tests/Feature|tests/<...> segment.
        $relative = $testFile;
        if (str_starts_with($relative, 'tests/')) {
            $relative = substr($relative, strlen('tests/'));
        }
        $segments = explode('/', $relative);
        // Drop the first segment (Unit / Feature / Integration / ...) and
        // the filename segment; the middle segments are mirrored under app/.
        if (count($segments) >= 2) {
            array_shift($segments);
            array_pop($segments);
            $middle = implode('/', $segments);
            $middlePrefix = $middle === '' ? '' : $middle.'/';
        } else {
            $middlePrefix = '';
        }

        return [
            'app/'.$middlePrefix.$base.'.php',
            'app/'.$base.'.php',
        ];
    }

    private function tmpDir(string $runId): string
    {
        $safeRunId = preg_replace('/[^A-Za-z0-9_.-]/', '_', $runId) ?: 'run';

        return rtrim($this->repoRoot, '/').'/storage/atlas-dev/mutation/'.$safeRunId.'/.infection-tmp';
    }

    private function summaryPath(string $runId): string
    {
        $safeRunId = preg_replace('/[^A-Za-z0-9_.-]/', '_', $runId) ?: 'run';

        return rtrim($this->repoRoot, '/').'/storage/atlas-dev/mutation/'.$safeRunId.'/infection-summary.json';
    }

    private function describeFailure(MutationCommandOutcome $outcome): string
    {
        $tail = trim($outcome->stdout) !== ''
            ? substr($outcome->stdout, -400)
            : trim($outcome->stderr);

        return 'e3: infection invocation failed (exit '
            .$outcome->exitCode.'): '
            .($tail !== '' ? $tail : '(no output)');
    }
}
