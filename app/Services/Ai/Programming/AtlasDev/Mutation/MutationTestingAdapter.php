<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Mutation;

use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevStringListNormalizer;
use App\Services\Ai\Programming\AtlasDev\Support\Elevations\ElevationConfig;
use Infection\Metrics\Calculator;

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
     *                         infection tmpDir per-run, so concurrent runs
     *                         like best-of-N candidates do not collide on
     *                         coverage-xml / junit artifacts).
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
        $reportPath = $this->reportPath($runId);
        $command = $this->buildCommand(
            runId: $runId,
            scope: $scope,
            summaryPath: $summaryPath,
            reportPath: $reportPath,
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

        // Anti-gaming (VAL-E3-005/006) + m3-e3 scrutiny Defect 2 (BLOCKING):
        // recompute the REAL MSI over the FULL applicable mutant population
        // from the raw summary stats so the gate consumes an honest, anti-
        // gaming value. Infection's own stats.msi divides by
        // (totalMutantsCount - skipped - ignored), so a patch config that
        // marks survivors as IGNORED inflates infection's MSI. The real MSI
        // divides by totalMutantsCount (the full population), immune to
        // skipped/ignored inflation: for an HONEST run realMsi equals
        // infection's reported msi (within rounding, syntaxError/timeout
        // counted on the SAME side infection counts them); for an inflation
        // attempt realMsi diverges DOWNWARD only. Mutator-skipping is defeated
        // structurally: the adapter never passes --mutators= and the per-run
        // config carries no mutators key, so totalMutantsCount IS the full
        // applicable population.
        $rawCounts = $this->extractRawCounts($outcome->summaryPayload);
        $realMsi = $this->computeRealMsi($outcome->summaryPayload);
        if ($realMsi === null) {
            // Defensive: the summary had an MSI but the raw stats could not
            // back it — refuse to fabricate a score (VAL-E3-011 honest ceiling).
            return MutationTestingResult::failed(
                'e3: infection reported an MSI but the raw summary stats could not '
                .'back it (refusing to fabricate a score).',
            );
        }

        // Anti-gaming (VAL-E3-013): per-source-file MSI so a weak file among
        // strong ones is not masked by a high aggregate MSI. A runner may hand
        // the adapter a ready-made typed breakdown ($outcome->perFileStats);
        // otherwise the adapter parses per-file MSI from the full --logger-json
        // report ($outcome->reportPayload). Null when neither is available —
        // the gate treats null as "no per-file check possible" and falls back
        // to the aggregate check only.
        $perFileStats = $outcome->perFileStats
            ?? $this->computePerFileStats($outcome->reportPayload);

        // $msi carries infection's OWN reported MSI (the honest-but-inflatable
        // value), while $realMsi carries the recomputed full-population MSI the
        // gate actually consumes. For an HONEST run the two agree (within
        // rounding); under a survivor-exclusion / mutator-skipping attack
        // infection's reported MSI rises but realMsi stays at the honest floor,
        // so the two diverge — the gate (realMsi ?? msi) is immune to the
        // inflation (VAL-E3-005/006).
        return MutationTestingResult::completed(
            msi: $outcome->summaryMsi,
            summaryPath: $summaryPath,
            scope: $scope,
            realMsi: $realMsi,
            rawCounts: $rawCounts,
            perFileStats: $perFileStats,
        );
    }

    /**
     * Recompute the REAL mutation-score indicator (MSI) from the raw infection
     * summary stats, aligned with Infection's own MSI definition.
     *
     * m3-e3 scrutiny Defect 2 (BLOCKING): the previous read path silently
     * diverged from Infection's reported MSI because it treated syntaxError
     * mutants inconsistently. Infection counts a syntaxError mutant in the
     * DETECTED numerator (it is a mutant that, when applied, broke the code
     * so badly it produced a syntax error — that counts as "killed" for MSI
     * purposes, mirroring {@see Calculator::fromMetrics()}
     * which folds `getSyntaxErrorCount()` into the error count that goes into
     * the MSI numerator). Omitting it under/mis-reports the real MSI.
     *
     * Infection's MSI formula (verified against vendor source):
     *   numerator   = killedCount + errorCount + syntaxErrorCount
     *                 + timeOutCount        (default timeoutsAsEscaped = false)
     *   denominator = totalMutantsCount - skippedCount - ignoredCount
     *   MSI         = 100 * numerator / denominator   (0 when denominator==0)
     *
     * Guarantees:
     *   - HONEST run (no patch-supplied skip/ignore/exclusion): realMsi equals
     *     infection's reported `stats.msi` within rounding, because both
     *     compute over the same population with the same syntaxError/timeout
     *     handling. This is the parity invariant for an honest run.
     *   - ANTI-GAMING (downward-only divergence): a patch that attempts to
     *     inflate MSI via skipped mutators or denominator exclusion would see
     *     its reported `stats.msi` rise (skipped/ignored are excluded from
     *     Infection's denominator), but realMsi is computed over the FULL
     *     applicable mutant population (denominator = totalMutantsCount, never
     *     reduced by patch-supplied skips). Therefore realMsi <= reportedMsi
     *     whenever a patch attempts inflation, and realMsi diverges DOWNWARD
     *     only — never upward. This preserves VAL-E3-005/006/013.
     *
     * @param  ?array<string,mixed>  $payload  the decoded infection summary JSON.
     * @return ?float the real MSI in [0,100], or null when the payload is
     *                missing/empty (the adapter never fabricates a score over
     *                an unevaluable run — VAL-E3-011 honest ceiling).
     */
    public function computeRealMsi(?array $payload): ?float
    {
        if ($payload === null) {
            return null;
        }
        $stats = $payload['stats'] ?? null;
        if (! is_array($stats)) {
            return null;
        }

        $total = $this->intStat($stats, 'totalMutantsCount');
        // ANTI-GAMING: the denominator is the FULL applicable mutant
        // population. Infection's reported MSI reduces the denominator by
        // skippedCount + ignoredCount (those mutants were not evaluated),
        // so a patch that skips mutators to inflate its reported score WOULD
        // see reported MSI rise. realMsi keeps the FULL denominator so it is
        // the honest floor: a patch cannot raise realMsi above the honest
        // score by skipping/excluding mutants. This is the downward-only
        // divergence invariant (VAL-E3-005/006/013).
        if ($total <= 0) {
            return null;
        }

        $killed = $this->intStat($stats, 'killedCount');
        $error = $this->intStat($stats, 'errorCount');
        $syntaxError = $this->intStat($stats, 'syntaxErrorCount');
        $timeout = $this->intStat($stats, 'timeOutCount');

        // Infection's numerator: killed + error + syntaxError (all counted as
        // detected) + timeout (default timeoutsAsEscaped = false => timeout
        // counts as covered/detected in the MSI numerator). See
        // vendor/infection/infection/src/Metrics/Calculator.php.
        $numerator = $killed + $error + $syntaxError + $timeout;

        return round(100.0 * $numerator / $total, 2);
    }

    /**
     * Read an integer stat from the infection summary, defaulting to 0 when
     * missing or non-numeric (defensive: infection's schema is int, but a
     * malformed payload must not crash the recomputation).
     *
     * @param  array<string,mixed>  $stats
     */
    private function intStat(array $stats, string $key): int
    {
        $value = $stats[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
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
     *   - --initial-tests-php-options with a SINGLE -d pcov.directory=<LCA>
     *     (coverage instrumentation scope = lowest common ancestor of every
     *     scoped source dir; pcov.directory is single-valued so a SINGLE LCA
     *     entry spans the whole scope — never one per dir, which would drop
     *     all but the last, m3-e3 scrutiny Defect 1)
     *   - --test-framework-options scoping PHPUnit to the touched test files
     *   - --logger-summary-json=<summaryPath> (real reported MSI source)
     *   - --logger-json=<reportPath> (full mutation report for per-file MSI,
     *     VAL-E3-013: a weak file among strong ones is not masked)
     *   - --no-interaction --no-progress (deterministic CI-friendly run)
     *
     * NOTE: --tmp-dir is NOT a CLI option (it is config-file-only in
     * infection 0.33.x), so per-run tmpDir isolation is achieved via the
     * per-run config file, not via a CLI flag.
     *
     * VAL-E3-005: the command NEVER carries --mutators= and the per-run
     * config carries NO mutators key, so the full default mutator set always
     * runs. A patch cannot reduce the mutator set to inflate the MSI.
     */
    private function buildCommand(string $runId, MutationScope $scope, string $summaryPath, string $reportPath): string
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
            // VAL-E3-013: full mutation report for per-source-file MSI so a
            // weak file among strong ones is not masked by a high aggregate.
            // The JSON report groups mutants by result category
            // (killed/escaped/etc.) and each entry carries
            // mutator.originalFilePath, so per-file MSI is computed by
            // grouping on the source file path.
            '--logger-json='.escapeshellarg($reportPath),
        ];

        // VAL-E3-011 + VAL-E3-001 + m3-e3 Defect 1: scope pcov coverage
        // instrumentation to the SINGLE lowest common ancestor directory of
        // every scoped source file. pcov.directory is a single-valued ini
        // directive, so MutationScope::pcovDirectories() returns exactly ONE
        // directory (the LCA) — emitting multiple entries would silently
        // drop every dir except the last. The LCA is always an app/ subtree
        // (never the bare repo root '.', which would instrument the full
        // ~3592-file tree at ~40s+ unscoped baseline). The MUTATED set stays
        // narrowed to the exact touched source via --filter above, so the
        // wider instrumentation does not widen what infection mutates.
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

    /**
     * Per-run path for the full --logger-json mutation report.
     *
     * The JSON report groups mutants by result category (killed, escaped,
     * errored, syntaxErrors, timeouted, uncovered, ignored) and each entry
     * carries mutator.originalFilePath, so the adapter can compute per-source-
     * file MSI by grouping on the source file path (VAL-E3-013 anti-dilution).
     */
    private function reportPath(string $runId): string
    {
        $safeRunId = preg_replace('/[^A-Za-z0-9_.-]/', '_', $runId) ?: 'run';

        return rtrim($this->repoRoot, '/').'/storage/atlas-dev/mutation/'.$safeRunId.'/infection-report.json';
    }

    /**
     * Extract the raw mutant counts from the infection summary JSON stats
     * block. These are the FULL population counts infection observed (before
     * any skipped/ignored subtraction), surfaced on the result for
     * auditability and anti-gaming evidence (VAL-E3-006: immune to survivor-
     * exclusion inflation). The MSI itself is recomputed by the public
     * {@see computeRealMsi()} from the same summary payload.
     *
     * @param  ?array<string,mixed>  $summaryPayload
     * @return ?array<string,int>
     */
    private function extractRawCounts(?array $summaryPayload): ?array
    {
        if ($summaryPayload === null) {
            return null;
        }
        $stats = $summaryPayload['stats'] ?? null;
        if (! is_array($stats)) {
            return null;
        }

        $pull = static function (string $key) use ($stats): int {
            $val = $stats[$key] ?? 0;

            return is_numeric($val) ? (int) $val : 0;
        };

        return [
            'totalMutantsCount' => $pull('totalMutantsCount'),
            'killedCount' => $pull('killedCount'),
            'escapedCount' => $pull('escapedCount'),
            'errorCount' => $pull('errorCount'),
            'syntaxErrorCount' => $pull('syntaxErrorCount'),
            'skippedCount' => $pull('skippedCount'),
            'ignoredCount' => $pull('ignoredCount'),
            'timeOutCount' => $pull('timeOutCount'),
            'notCoveredCount' => $pull('notCoveredCount'),
        ];
    }

    /**
     * Compute per-source-file MSI from the full --logger-json report's
     * per-status arrays (VAL-E3-013). Each status array (killed, escaped,
     * errored, timeouted, syntaxErrors, ignored, uncovered) carries entries
     * with mutator.originalFilePath. We group by source file and compute the
     * real per-file MSI using the same full-population denominator (total per
     * file, including any ignored/skipped mutants in that file) and the same
     * numerator as the aggregate {@see computeRealMsi()} (killed + error +
     * syntaxError + timeout, all counted as detected).
     *
     * Returns null when the report payload is unavailable. In that case the
     * gate falls back to the aggregate realMsi alone (single-file scope or
     * a degraded run where per-file == aggregate).
     *
     * @param  ?array<string,mixed>  $reportPayload
     * @return ?array<string,array{msi:float,killed:int,escaped:int,total:int}>
     */
    private function computePerFileStats(?array $reportPayload): ?array
    {
        if ($reportPayload === null) {
            return null;
        }

        // Map each status array key to its contribution to the per-file
        // killed / escaped / total counts. The report uses 'killed',
        // 'escaped', 'errored', 'timeouted', 'syntaxErrors', 'ignored',
        // 'uncovered'.
        $statusMap = [
            'killed' => 'killed',
            'escaped' => 'escaped',
            'errored' => 'killed',    // errors count as detected (killed-equivalent)
            'timeouted' => 'killed',  // timeouts count as detected (per Calculator)
            'ignored' => null,        // ignored: counted in total, not in killed
            'uncovered' => null,      // not-covered: counted in total, not in killed
            'syntaxErrors' => 'killed', // syntax errors count as detected
        ];

        /** @var array<string,array{killed:int,escaped:int,total:int}> $perFile */
        $perFile = [];

        foreach ($statusMap as $reportKey => $contribution) {
            $entries = $reportPayload[$reportKey] ?? null;
            if (! is_array($entries)) {
                continue;
            }
            foreach ($entries as $entry) {
                $path = $entry['mutator']['originalFilePath']
                    ?? $entry['mutator']['originalPath']
                    ?? null;
                if (! is_string($path) || $path === '') {
                    continue;
                }
                if (! isset($perFile[$path])) {
                    $perFile[$path] = ['killed' => 0, 'escaped' => 0, 'total' => 0];
                }
                $perFile[$path]['total']++;
                if ($contribution === 'killed') {
                    $perFile[$path]['killed']++;
                } elseif ($contribution === 'escaped') {
                    $perFile[$path]['escaped']++;
                }
            }
        }

        if ($perFile === []) {
            return null;
        }

        $result = [];
        foreach ($perFile as $path => $counts) {
            $total = $counts['total'];
            $msi = $total > 0 ? round(100.0 * $counts['killed'] / $total, 2) : 0.0;
            $result[$path] = [
                'msi' => $msi,
                'killed' => $counts['killed'],
                'escaped' => $counts['escaped'],
                'total' => $total,
            ];
        }

        return $result;
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
