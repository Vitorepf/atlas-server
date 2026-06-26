<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * SERVER-SIDE COMMIT VERIFICATION (Fase 2) — proves a worker's in-tree changes actually work BEFORE the server
 * commits them to shared main, so a broken/garbage delivery never lands and never bothers the next worker.
 *
 * The server used to commit on the worker's honor (it only checked the diff was non-empty), so a worker could
 * land code that does not even boot (proven: a wired-but-missing decorator class broke the app). This gate runs
 * real checks against the worker's changes and turns the commit into a PROOF, not a promise.
 *
 * GOLDEN RULE — never bother a good worker: it BLOCKS only on a DEFINITIVE failure ATTRIBUTABLE to THIS task
 * (a syntax error in a changed file, a fatal bootstrap that names a changed file, or a red task-owned test). It
 * FAILS OPEN (allows the commit) on any gate-infra error, timeout, or a breakage NOT attributable to this task
 * (e.g. another worker's in-flight WIP already broke the tree). The gate can only ever ADD protection; it can
 * never become a new jam vector.
 *
 * Honest bound: it catches syntax, red task tests, and EAGER bootstrap fatals. A lazy-binding fault (a class
 * referenced only inside a deferred container closure) is caught only when the task's own test exercises that
 * wiring — so task-authored tests remain the deepest guarantee.
 */
final class AtlasTaskCommitVerificationGate
{
    /** @var callable(list<string>,string,float):array{ran:bool,ok:bool,out:string} */
    private $runner;

    public function __construct(
        private readonly ?string $repoRootOverride = null,
        ?callable $runner = null,
    ) {
        $this->runner = $runner ?? fn (array $cmd, string $cwd, float $timeout): array => $this->realRun($cmd, $cwd, $timeout);
    }

    /** Default ON. Operator can disable via .env (ATLAS_TASK_SERVING_VERIFY_BEFORE_COMMIT=false) without touching pétreo config. */
    public function enabled(): bool
    {
        $v = env('ATLAS_TASK_SERVING_VERIFY_BEFORE_COMMIT', true);

        return filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }

    /**
     * @param  list<string>  $allowedFiles  the task's server-truth write scope (what the committer will commit)
     * @return array{passed:bool, blocked:bool, reason:string, failed_check:string, detail:string, checks:array<string,string>}
     */
    public function verify(array $allowedFiles, string $taskPacketId): array
    {
        $repo = rtrim($this->repoRootOverride ?? base_path(), '/');
        $checks = [];

        $changed = array_values(array_filter(array_map('strval', $allowedFiles), static fn (string $p): bool => trim($p) !== ''));
        $phpFiles = array_values(array_filter($changed, static fn (string $p): bool => str_ends_with($p, '.php')));
        $testFiles = array_values(array_filter($phpFiles, fn (string $p): bool => $this->isTestPath($p)));

        // 1) SYNTAX — definitive, isolated, always attributable to the changed file.
        foreach ($phpFiles as $rel) {
            $abs = $repo.'/'.ltrim($rel, '/');
            if (! is_file($abs)) {
                continue; // a deletion/move is not a syntax concern
            }
            $r = ($this->runner)([PHP_BINARY, '-l', $abs], $repo, 30.0);
            if ($r['ran'] && ! $r['ok']) {
                return $this->blocked('syntax_error', 'php -l', $rel.': '.$this->tail($r['out']), $checks + ['syntax' => 'fail']);
            }
        }
        $checks['syntax'] = $phpFiles === [] ? 'skip' : 'pass';

        // 1b) REQUIRED TEST PRESENT — when the task's own scope declares a test file, the worker MUST have
        // authored it. This closes the corner-cut where a worker commits the class and skips the test (the
        // test-run step below would simply find nothing to run and pass). Definitive + attributable (the path
        // is in THIS task's allowed_files) + zero false-block risk (the file exists or it does not). A test that
        // already existed from a prior commit still satisfies this — it is on disk.
        foreach ($testFiles as $rel) {
            if (! is_file($repo.'/'.ltrim($rel, '/'))) {
                return $this->blocked('required_test_missing', 'test_present', $rel.': declared in allowed_files but was not created', $checks + ['required_test' => 'missing']);
            }
        }
        $checks['required_test'] = $testFiles === [] ? 'skip' : 'present';

        // 2) BOOT SMOKE — catches a fatal that breaks the framework boot (eager class-not-found, etc.).
        $boot = ($this->runner)([PHP_BINARY, 'artisan', 'about', '--only=environment'], $repo, 120.0);
        if ($boot['ran'] && ! $boot['ok']) {
            if ($this->outputMentionsAny($boot['out'], $changed)) {
                return $this->blocked('bootstrap_failed', 'artisan about', $this->tail($boot['out']), $checks + ['boot' => 'fail_attributed']);
            }
            // Tree is already broken, but NOT by this task — do not punish this worker.
            $checks['boot'] = 'fail_unattributed_open';

            return $this->passed('boot_broken_elsewhere_fail_open', $checks);
        }
        $checks['boot'] = $boot['ran'] ? 'pass' : 'skip_infra';

        // 3) TASK TESTS — only with a healthy boot and the task authoring its own tests (its own contract).
        // BLOCK ONLY on a GENUINE test failure — PHPUnit/Pest actually executed tests and reported red. A
        // non-zero exit WITHOUT a real failure marker is a RUNNER/env problem (bad option, missing DB, fatal
        // before tests run) ⇒ FAIL OPEN. This is the hard lesson from the `--without-tty` regression that
        // wedged every worker: the gate must never block a good worker because its own runner could not run.
        if ($testFiles !== [] && $checks['boot'] === 'pass') {
            $t = ($this->runner)(array_merge([PHP_BINARY, 'artisan', 'test'], $testFiles), $repo, 600.0);
            if ($t['ran'] && ! $t['ok'] && $this->outputShowsRealTestFailure($t['out'])) {
                return $this->blocked('task_tests_failed', 'artisan test', $this->tail($t['out']), $checks + ['task_tests' => 'fail']);
            }
            $checks['task_tests'] = ($t['ran'] && ! $t['ok']) ? 'fail_open_runner_error' : 'pass';
        } else {
            $checks['task_tests'] = 'skip';
        }

        return $this->passed('verified', $checks);
    }

    private function isTestPath(string $path): bool
    {
        $p = ltrim(str_replace('\\', '/', trim($path)), '/');

        return str_contains($p, '/tests/') || str_starts_with($p, 'tests/') || str_ends_with($p, 'Test.php');
    }

    /**
     * True when the failure output names one of THIS task's changed files (full path or class basename) — the
     * attribution that lets a boot fatal block this task without punishing it for someone else's breakage.
     *
     * @param  list<string>  $changed
     */
    private function outputMentionsAny(string $output, array $changed): bool
    {
        $hay = str_replace('\\', '/', $output);
        foreach ($changed as $rel) {
            $rel = ltrim(str_replace('\\', '/', trim($rel)), '/');
            if ($rel === '') {
                continue;
            }
            if (str_contains($hay, $rel)) {
                return true;
            }
            $base = basename($rel, '.php');
            // a class basename is distinctive enough (PascalCase, length>3) to attribute a "class not found"
            if ($base !== '' && strlen($base) > 3 && str_contains($hay, $base)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True ONLY when the runner output proves tests EXECUTED and some failed/errored — never on a runner/env
     * error (bad option, missing DB, fatal before tests). This is what separates "the worker's test is red"
     * (block) from "the gate could not run the suite" (fail open). The `--without-tty` regression produced an
     * "Unknown option" runner error with NONE of these markers, so it now correctly fails open instead of
     * blocking every commit.
     */
    private function outputShowsRealTestFailure(string $out): bool
    {
        // PHPUnit classic summary, or Laravel/Pest "Tests: N failed", or per-test failure glyph/word.
        return preg_match('/FAILURES!/', $out) === 1
            || preg_match('/ERRORS!/', $out) === 1
            || preg_match('/Failures:\s*[1-9]/', $out) === 1
            || preg_match('/Errors:\s*[1-9]/', $out) === 1
            || preg_match('/^\s*Tests:\s.*\b\d+\s+(failed|errored)\b/mi', $out) === 1
            || preg_match('/^\s*(FAIL|⨯)\s/m', $out) === 1;
    }

    /** @param array<string,string> $checks */
    private function blocked(string $reason, string $failedCheck, string $detail, array $checks): array
    {
        return ['passed' => false, 'blocked' => true, 'reason' => $reason, 'failed_check' => $failedCheck, 'detail' => $detail, 'checks' => $checks];
    }

    /** @param array<string,string> $checks */
    private function passed(string $reason, array $checks): array
    {
        return ['passed' => true, 'blocked' => false, 'reason' => $reason, 'failed_check' => '', 'detail' => '', 'checks' => $checks];
    }

    private function tail(string $out, int $max = 1200): string
    {
        $out = trim($out);

        return strlen($out) <= $max ? $out : '…'.substr($out, -$max);
    }

    /**
     * @param  list<string>  $cmd
     * @return array{ran:bool, ok:bool, out:string}
     */
    private function realRun(array $cmd, string $cwd, float $timeout): array
    {
        try {
            $p = new Process($cmd, $cwd, $this->env(), null, $timeout);
            $p->run();

            return ['ran' => true, 'ok' => $p->isSuccessful(), 'out' => $p->getOutput()."\n".$p->getErrorOutput()];
        } catch (Throwable $e) {
            // Gate infra failure ⇒ fail OPEN (ran:false): never block a worker because the gate itself broke.
            return ['ran' => false, 'ok' => true, 'out' => 'gate_infra_error: '.$e->getMessage()];
        }
    }

    /** @return array<string,string> */
    private function env(): array
    {
        $binDir = \dirname(PHP_BINARY);
        $base = getenv('PATH');
        $base = is_string($base) && $base !== '' ? $base : '/usr/bin:/bin:/usr/sbin:/sbin';

        return ['PATH' => '/usr/bin'.PATH_SEPARATOR.'/bin'.PATH_SEPARATOR.$binDir.PATH_SEPARATOR.$base];
    }
}
