<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Contracts\BroaderRegressionGateContract;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * THE BROADER REGRESSION GATE — the load-bearing safety piece of obra-auto-merge.
 *
 * Rationale (proven this session): the Loop's per-target/per-node canary does NOT run the
 * full suite. A change to ONE engine file broke a DIFFERENT test (AtlasLoopQualityPersistence
 * Test). Auto-merging a whole obra without a broader gate would push such cross-test
 * regressions straight to main. This gate is what makes "no operator review" SAFE.
 *
 * Given the obra's CHANGED files (relative paths on the merged tree), it:
 *   1. MAPS each changed php file to its AFFECTED test modules via a path/namespace → test
 *      directory map (e.g. app/Services/Ai/AutonomousEvolution/* → tests/Feature/Loop +
 *      tests/Unit/Ai/AutonomousEvolution), plus the convention sibling test for the file.
 *   2. ALWAYS includes the NEVER-MERGE invariant test (AtlasLoopAutoMergeServiceTest) — the
 *      door's own guard regression net runs on every gate, no matter what changed.
 *   3. RUNS those test suites in the repo, plus a BOOT-SMOKE (`php artisan about`) and a
 *      `php -l` on every changed php file.
 *   4. Returns a fail-CLOSED verdict: ANY red (a failing suite, a boot failure, a syntax
 *      error, or an inability to run the gate at all) => passed=false. A green gate requires
 *      every selected suite GREEN, boot-smoke GREEN and every php -l GREEN.
 *
 * This gate NEVER touches git/main — it runs read-only checks against the supplied repo (the
 * caller is responsible for applying the obra branch to a throwaway/working state before
 * calling, and for reverting on a red verdict). It is bounded (per-suite + overall timeout)
 * and degrade-safe only in the syntactic sense: an environment with no vendor/artisan cannot
 * prove safety, so it fails CLOSED (never silently green).
 */
final class AtlasLoopBroaderRegressionGate implements BroaderRegressionGateContract
{
    public const SCHEMA_VERSION = 'atlas.ai.loop_broader_regression_gate.v1';

    /**
     * The NEVER-MERGE invariant test — runs on EVERY gate pass, no matter what changed. This
     * is the regression net for the governed door itself (never-merge default, governed
     * scope, net-direction throttle). If a change ever weakened the door, this goes red and
     * the merge is BLOCKED.
     */
    public const NEVER_MERGE_INVARIANT_TEST = 'tests/Feature/Loop/AtlasLoopAutoMergeServiceTest.php';

    /**
     * Path/namespace → affected test modules. Each entry maps an app subtree PREFIX (relative
     * to repo root) to the test dirs that exercise it. The most-specific (longest) matching
     * prefix wins; a file under a mapped subtree pulls in every listed test dir. This is the
     * "blast radius" map: a change to the loop engine runs the loop feature tests AND the
     * unit tests for that namespace, not just the file's own sibling.
     *
     * @var array<string,list<string>>
     */
    private const TEST_MODULE_MAP = [
        'app/Services/Ai/AutonomousEvolution' => [
            'tests/Feature/Loop',
            'tests/Unit/Ai/AutonomousEvolution',
        ],
        'app/Services/Ai/Obra' => [
            'tests/Feature/Ai/Obra',
        ],
        'app/Services/Ai/Programming/Forge' => [
            'tests/Feature/Ai/Programming',
            'tests/Unit/Ai/Programming',
        ],
        'app/Services/Ai/RealExecution' => [
            'tests/Feature/Ai/RealExecution',
        ],
        'app/Models' => [
            'tests/Feature/Loop',
        ],
    ];

    /**
     * Run the broader regression gate over the obra's changed files.
     *
     * @param  string  $repoRoot  the repo where the obra change is applied to the working/merged tree
     * @param  list<string>  $changedFiles  obra changed files, relative to repo root
     * @return array{schema_version:string, passed:bool, reason:?string, suites:list<array<string,mixed>>, boot_smoke:array<string,mixed>, php_lint:array<string,mixed>, selected_tests:list<string>}
     */
    public function evaluate(string $repoRoot, array $changedFiles): array
    {
        $repoRoot = rtrim($repoRoot, '/');
        $base = [
            'schema_version' => self::SCHEMA_VERSION,
            'passed' => false,
            'reason' => null,
            'suites' => [],
            'boot_smoke' => ['ran' => false, 'passed' => null],
            'php_lint' => ['ran' => false, 'passed' => null, 'failed_file' => null],
            'selected_tests' => [],
        ];

        if (! is_dir($repoRoot.'/.git')) {
            return array_merge($base, ['reason' => 'repo_root_not_a_git_tree']);
        }
        // FAIL-CLOSED on a non-runnable environment: a gate that cannot run the suite cannot
        // prove safety, so it must NOT pass. (The single-file auto-merger degrades boot-smoke
        // to true when vendor is absent because php -l already covered syntax there; the
        // broader gate is the LAST line before a big multi-file obra reaches main, so it
        // refuses to green a tree it cannot actually exercise.)
        if (! is_file($repoRoot.'/vendor/autoload.php') || ! is_file($repoRoot.'/artisan')) {
            return array_merge($base, ['reason' => 'gate_environment_not_runnable (no vendor/artisan; cannot prove safety)']);
        }

        $changed = $this->normalizeChanged($changedFiles);

        // 1. php -l on every changed php file (syntax never reaches main).
        $lint = $this->phpLint($repoRoot, $changed);
        $base['php_lint'] = $lint;
        if (! ($lint['passed'] ?? false)) {
            return array_merge($base, ['reason' => 'php_lint_failed:'.(string) ($lint['failed_file'] ?? '?')]);
        }

        // 2. Boot-smoke: the whole app boots with the change applied (a logic error that
        //    breaks boot would otherwise crash-loop the supervisor after a merge).
        $boot = $this->bootSmoke($repoRoot);
        $base['boot_smoke'] = $boot;
        if (! ($boot['passed'] ?? false)) {
            return array_merge($base, ['reason' => 'boot_smoke_failed (app boot would break)']);
        }

        // 2b. FAIL-CLOSED on UNCOVERED files (adversarial-audit fix): a big obra may auto-merge
        //     to main ONLY when every changed app/*.php source file is actually EXERCISED by a
        //     real test we run — a mapped module test dir OR an existing convention sibling. A
        //     file in an unmapped subtree (Context/*, Aaeos/*, …) with no sibling test cannot be
        //     proven safe; without this guard it would slip to main with only the never-merge
        //     invariant run (a behavior change reaching main unguarded). No coverage => the gate
        //     refuses (route to operator review). The coverage flywheel expands the
        //     auto-mergeable set as the loop adds tests; until then, uncovered work is never
        //     auto-merged. (This is the SAME fail-closed principle as the rest of the gate.)
        $uncovered = $this->uncoveredFiles($repoRoot, $changed);
        if ($uncovered !== []) {
            $base['uncovered_files'] = $uncovered;

            return array_merge($base, ['reason' => 'no_test_coverage_for_changed_file:'.$uncovered[0]]);
        }

        // 3. Select + run the affected test modules + the never-merge invariant test.
        $selected = $this->selectTestPaths($repoRoot, $changed);
        $base['selected_tests'] = $selected;

        $suites = [];
        $allGreen = true;
        $firstRed = null;
        foreach ($selected as $path) {
            $suite = $this->runSuite($repoRoot, $path);
            $suites[] = $suite;
            if (! ($suite['passed'] ?? false)) {
                $allGreen = false;
                $firstRed ??= $path;
            }
        }
        $base['suites'] = $suites;

        if (! $allGreen) {
            return array_merge($base, ['reason' => 'regression_suite_red:'.(string) $firstRed]);
        }

        return array_merge($base, ['passed' => true, 'reason' => null]);
    }

    /**
     * Resolve the full set of test paths to run: the never-merge invariant test (always),
     * every test dir mapped from the changed files' subtrees, and each changed file's
     * convention sibling test. Existing paths only (a mapped dir/sibling that does not exist
     * in this repo is skipped — never a phantom red). De-duplicated, stable order.
     *
     * Public for testability: the changed-files → test-modules mapping is the load-bearing
     * blast-radius decision and is unit-tested directly (so the map is provable without
     * spawning the whole suite).
     *
     * @param  list<string>  $changed
     * @return list<string>
     */
    public function selectTestPaths(string $repoRoot, array $changed): array
    {
        $changed = $this->normalizeChanged($changed);
        $selected = [];
        $add = function (string $rel) use (&$selected, $repoRoot): void {
            $rel = trim($rel);
            if ($rel === '' || isset($selected[$rel])) {
                return;
            }
            if (file_exists($repoRoot.'/'.$rel)) {
                $selected[$rel] = true;
            }
        };

        // The never-merge invariant test is ALWAYS in the set (the door's own guard net).
        $add(self::NEVER_MERGE_INVARIANT_TEST);

        foreach ($changed as $file) {
            // Test files that changed run themselves.
            if (str_starts_with($file, 'tests/') && str_ends_with($file, 'Test.php')) {
                $add($file);

                continue;
            }
            foreach ($this->mappedTestDirs($file) as $dir) {
                $add($dir);
            }
            foreach ($this->siblingTestCandidates($file) as $sibling) {
                $add($sibling);
            }
        }

        return array_keys($selected);
    }

    /**
     * Changed app/*.php SOURCE files that have NO test exercising them — neither a mapped
     * module test dir that EXISTS nor an existing convention sibling test. The gate fails
     * CLOSED on these: a source change we cannot prove with a real test must never
     * auto-merge to main (it routes to operator review instead). Non-source files (docs,
     * config, migrations) and changed test files are not gated here.
     *
     * @param  list<string>  $changed
     * @return list<string>
     */
    private function uncoveredFiles(string $repoRoot, array $changed): array
    {
        $repoRoot = rtrim($repoRoot, '/');
        $uncovered = [];

        foreach ($this->normalizeChanged($changed) as $file) {
            // A changed test file covers itself; only app/*.php source needs behavioral proof.
            if (str_starts_with($file, 'tests/') && str_ends_with($file, 'Test.php')) {
                continue;
            }
            if (! str_starts_with($file, 'app/') || ! str_ends_with($file, '.php')) {
                continue;
            }

            $covered = false;
            foreach ($this->mappedTestDirs($file) as $dir) {
                if (file_exists($repoRoot.'/'.$dir)) {
                    $covered = true;
                    break;
                }
            }
            if (! $covered) {
                foreach ($this->siblingTestCandidates($file) as $sibling) {
                    if (file_exists($repoRoot.'/'.$sibling)) {
                        $covered = true;
                        break;
                    }
                }
            }
            if (! $covered) {
                $uncovered[] = $file;
            }
        }

        return $uncovered;
    }

    /**
     * The mapped test dirs for one changed file — the longest matching subtree prefix wins.
     *
     * @return list<string>
     */
    private function mappedTestDirs(string $file): array
    {
        $bestPrefix = null;
        foreach (self::TEST_MODULE_MAP as $prefix => $_dirs) {
            if (str_starts_with($file, $prefix.'/') || $file === $prefix) {
                if ($bestPrefix === null || strlen($prefix) > strlen($bestPrefix)) {
                    $bestPrefix = $prefix;
                }
            }
        }

        return $bestPrefix === null ? [] : self::TEST_MODULE_MAP[$bestPrefix];
    }

    /**
     * Convention sibling test candidates for app/Foo/Bar.php → tests/{Unit,Feature}/Foo/BarTest.php
     * (mirror layout). Only the basename-derived candidates; the gate keeps the ones that
     * actually exist.
     *
     * @return list<string>
     */
    private function siblingTestCandidates(string $file): array
    {
        if (! str_ends_with($file, '.php') || ! str_starts_with($file, 'app/')) {
            return [];
        }
        $relInApp = substr($file, strlen('app/'));
        $relTest = preg_replace('/\.php$/', 'Test.php', $relInApp);
        if (! is_string($relTest) || $relTest === '') {
            return [];
        }

        return [
            'tests/Unit/'.$relTest,
            'tests/Feature/'.$relTest,
        ];
    }

    /**
     * @param  list<string>  $changed
     * @return array{ran:bool, passed:bool, failed_file:?string, checked:int}
     */
    private function phpLint(string $repoRoot, array $changed): array
    {
        $checked = 0;
        foreach ($changed as $file) {
            if (! str_ends_with($file, '.php')) {
                continue;
            }
            $abs = $repoRoot.'/'.$file;
            if (! is_file($abs)) {
                continue; // a deleted file has nothing to lint
            }
            $checked++;
            $p = new Process([PHP_BINARY, '-l', $abs], null, null, null, 30.0);
            $p->run();
            if (! $p->isSuccessful()) {
                return ['ran' => true, 'passed' => false, 'failed_file' => $file, 'checked' => $checked];
            }
        }

        return ['ran' => true, 'passed' => true, 'failed_file' => null, 'checked' => $checked];
    }

    /**
     * Boot-smoke via `php artisan about` — the whole app must boot (all providers register)
     * with the change applied. Bounded; a non-zero exit / no output => failed (fail-closed).
     *
     * @return array{ran:bool, passed:bool, exit_code:?int}
     */
    private function bootSmoke(string $repoRoot): array
    {
        try {
            $p = new Process([PHP_BINARY, '-d', 'memory_limit=512M', 'artisan', 'about', '--only=environment'], $repoRoot, null, null, 120.0);
            $p->run();

            return ['ran' => true, 'passed' => $p->isSuccessful(), 'exit_code' => $p->getExitCode()];
        } catch (Throwable) {
            return ['ran' => true, 'passed' => false, 'exit_code' => null];
        }
    }

    /**
     * Run one test path (a file or a dir) via `php artisan test <path>`. Bounded. Any
     * non-zero exit => the suite is RED (the gate blocks the merge).
     *
     * @return array{path:string, ran:bool, passed:bool, exit_code:?int}
     */
    private function runSuite(string $repoRoot, string $path): array
    {
        try {
            // ACDE lever #2b — running whole module directories via `artisan test` raises the documented
            // exit-255 autoloader-redeclare odds (a spurious RED would retire a good proposal). When armed
            // alongside the live gate, run the suites on ./vendor/bin/phpunit instead. Default OFF =>
            // `artisan test` => byte-identical for the existing (obra) consumer + its tests.
            $argv = (bool) config('atlas.loop.broader_regression_gate_phpunit', false)
                ? [PHP_BINARY, '-d', 'memory_limit=2048M', './vendor/bin/phpunit', $path]
                : [PHP_BINARY, '-d', 'memory_limit=2048M', 'artisan', 'test', $path];
            $p = new Process(
                $argv,
                $repoRoot,
                null,
                null,
                900.0,
            );
            $p->run();

            return ['path' => $path, 'ran' => true, 'passed' => $p->isSuccessful(), 'exit_code' => $p->getExitCode()];
        } catch (Throwable) {
            // Fail-closed: a suite we could not run cannot be assumed green.
            return ['path' => $path, 'ran' => false, 'passed' => false, 'exit_code' => null];
        }
    }

    /**
     * @param  list<string>  $changedFiles
     * @return list<string>
     */
    private function normalizeChanged(array $changedFiles): array
    {
        $out = [];
        foreach ($changedFiles as $file) {
            if (! is_string($file)) {
                continue;
            }
            $file = trim(str_replace('\\', '/', $file));
            $file = ltrim($file, '/');
            if ($file !== '') {
                $out[$file] = true;
            }
        }

        return array_keys($out);
    }
}
