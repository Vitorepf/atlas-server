<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Regression;

use App\Services\Ai\Programming\AtlasDev\Gate\UnsafeCommandPolicy;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationCommandResult;
use App\Services\Ai\Programming\AtlasDev\Regression\RegressionBaselineCache;
use App\Services\Ai\Programming\AtlasDev\Regression\RegressionBaselineRunner;
use App\Services\Ai\Programming\AtlasDev\Regression\RegressionBaselineService;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\TestRun;
use PHPUnit\Framework\TestCase;

/**
 * E5 -- RegressionBaselineService unit tests (red-first).
 *
 * VAL-E5-001 (baseline captured before patch, non-empty),
 * VAL-E5-002 (passed-before/fails-after = regression),
 * VAL-E5-004 (pre-existing failure NOT a regression),
 * VAL-E5-005 (failing->passing = fix, NOT a regression),
 * VAL-E5-012 (baseline captured ONCE, byte-identical across iterations).
 *
 * The RegressionBaselineService is a pure service: it accepts a command
 * list + runner + workspace, runs each command on the clean tree, and
 * records command -> pass/fail into an immutable RegressionBaselineCache.
 * It also computes the regression set (passed-before AND failed-after).
 *
 * The runner is a dedicated contract (RegressionBaselineRunner) so tests
 * inject a fake without spawning real subprocesses.
 */
final class RegressionBaselineServiceTest extends TestCase
{
    // -- VAL-E5-001: baseline captured before patch, non-empty ----------------

    public function test_val_e5_001_capture_baselines_records_command_to_pass_fail_map(): void
    {
        $runner = new FakeRegressionBaselineRunner;
        $runner->queueOk('composer test-foo');
        $runner->queueFail('composer test-bar');
        $service = new RegressionBaselineService($runner);

        $cache = $service->captureBaseline(
            runId: 'run-e5-001',
            commands: ['composer test-foo', 'composer test-bar'],
            workspace: '/workspace',
            captureOrder: 0,
        );

        $this->assertNotEmpty(
            $cache->results,
            'VAL-E5-001: baseline is non-empty when scoped tests exist',
        );
        $this->assertTrue(
            $cache->results['composer test-foo'],
            'VAL-E5-001: passing test recorded as passed',
        );
        $this->assertFalse(
            $cache->results['composer test-bar'],
            'VAL-E5-001: failing test recorded as failed',
        );
        $this->assertSame(2, count($runner->calls), 'both commands run during capture');
        $this->assertSame(0, $cache->captureOrder, 'VAL-E5-001: captureOrder proves before-patch');
        $this->assertNotEmpty($cache->contentHash, 'cache carries a content hash for stability checks');
    }

    public function test_val_e5_001_empty_command_list_yields_empty_baseline_not_crash(): void
    {
        $runner = new FakeRegressionBaselineRunner;
        $service = new RegressionBaselineService($runner);

        $cache = $service->captureBaseline(
            runId: 'run-e5-empty',
            commands: [],
            workspace: '/workspace',
            captureOrder: 0,
        );

        $this->assertSame([], $cache->results, 'empty commands => empty baseline (no crash)');
        $this->assertSame(0, count($runner->calls), 'no commands run');
    }

    // -- VAL-E5-002: passed-before/fails-after = regression -------------------

    public function test_val_e5_002_passed_before_fails_after_is_regression(): void
    {
        $service = $this->makeService();
        $baseline = $this->cache([
            'composer test-foo' => true,   // passed before
            'composer test-bar' => false,  // failed before (pre-existing)
        ]);

        $postPatch = [
            $this->test_run('composer test-foo', ok: false),  // NOW FAILS
            $this->test_run('composer test-bar', ok: false),  // STILL FAILS
        ];

        $regressions = $service->computeRegressions($baseline, $postPatch);

        $this->assertSame(
            ['composer test-foo'],
            $regressions,
            'VAL-E5-002: only the passed-before/fails-after test is a regression',
        );
    }

    public function test_val_e5_002_multiple_regressions_detected(): void
    {
        $service = $this->makeService();
        $baseline = $this->cache([
            'cmd-a' => true,
            'cmd-b' => true,
            'cmd-c' => true,
        ]);

        $postPatch = [
            $this->test_run('cmd-a', ok: false),  // regression
            $this->test_run('cmd-b', ok: true),   // still passing
            $this->test_run('cmd-c', ok: false),  // regression
        ];

        $regressions = $service->computeRegressions($baseline, $postPatch);

        $this->assertSame(['cmd-a', 'cmd-c'], $regressions, 'both regressions listed');
    }

    public function test_val_e5_002_command_not_in_baseline_is_not_regression(): void
    {
        $service = $this->makeService();
        $baseline = $this->cache(['cmd-known' => true]);

        $postPatch = [
            $this->test_run('cmd-known', ok: false),   // in baseline: regression
            $this->test_run('cmd-new', ok: false),     // NOT in baseline: not comparable
        ];

        $regressions = $service->computeRegressions($baseline, $postPatch);

        $this->assertSame(['cmd-known'], $regressions, 'only baseline-known commands can regress');
    }

    // -- VAL-E5-004: pre-existing failure NOT a regression --------------------

    public function test_val_e5_004_pre_existing_failure_is_not_a_regression(): void
    {
        $service = $this->makeService();
        $baseline = $this->cache([
            'cmd-red-before' => false,  // was failing
            'cmd-green-before' => true, // was passing
        ]);

        $postPatch = [
            $this->test_run('cmd-red-before', ok: false),   // still failing
            $this->test_run('cmd-green-before', ok: true),  // still passing
        ];

        $regressions = $service->computeRegressions($baseline, $postPatch);

        $this->assertSame(
            [],
            $regressions,
            'VAL-E5-004: a test already failing pre-patch is NOT a regression',
        );
    }

    public function test_val_e5_004_only_pre_existing_failures_no_regression_reported(): void
    {
        $service = $this->makeService();
        $baseline = $this->cache([
            'cmd-x' => false,
            'cmd-y' => false,
        ]);

        $postPatch = [
            $this->test_run('cmd-x', ok: false),
            $this->test_run('cmd-y', ok: false),
        ];

        $this->assertSame(
            [],
            $service->computeRegressions($baseline, $postPatch),
            'VAL-E5-004: if the only failures are pre-existing, no regression',
        );
    }

    // -- VAL-E5-005: failing->passing = fix, NOT regression -------------------

    public function test_val_e5_005_failing_to_passing_is_a_fix_not_regression(): void
    {
        $service = $this->makeService();
        $baseline = $this->cache([
            'cmd-was-red' => false,   // was failing
            'cmd-was-green' => true,  // was passing
        ]);

        $postPatch = [
            $this->test_run('cmd-was-red', ok: true),    // NOW PASSES (fix!)
            $this->test_run('cmd-was-green', ok: true),  // still passes
        ];

        $regressions = $service->computeRegressions($baseline, $postPatch);

        $this->assertSame(
            [],
            $regressions,
            'VAL-E5-005: failing->passing is a fix, not a regression',
        );
    }

    public function test_val_e5_005_fix_plus_regression_only_reports_the_regression(): void
    {
        $service = $this->makeService();
        $baseline = $this->cache([
            'cmd-fix' => false,        // was failing
            'cmd-regress' => true,     // was passing
        ]);

        $postPatch = [
            $this->test_run('cmd-fix', ok: true),      // fixed
            $this->test_run('cmd-regress', ok: false), // regressed
        ];

        $regressions = $service->computeRegressions($baseline, $postPatch);

        $this->assertSame(
            ['cmd-regress'],
            $regressions,
            'VAL-E5-005: fix excluded; only the regression reported',
        );
    }

    // -- VAL-E5-012: baseline captured ONCE, byte-identical across iterations -

    public function test_val_e5_012_baseline_hash_stable_across_multiple_diff_calls(): void
    {
        $service = $this->makeService();
        $baseline = $this->cache([
            'cmd-a' => true,
            'cmd-b' => false,
        ]);

        // The baseline cache is immutable; its contentHash MUST be stable
        // no matter how many times computeRegressions is called (the method
        // is pure and never mutates the baseline).
        $postPatch1 = [$this->test_run('cmd-a', ok: false)];
        $postPatch2 = [$this->test_run('cmd-a', ok: true)];
        $postPatch3 = [$this->test_run('cmd-b', ok: true), $this->test_run('cmd-a', ok: false)];

        $hashBefore = $baseline->contentHash;

        $service->computeRegressions($baseline, $postPatch1);
        $service->computeRegressions($baseline, $postPatch2);
        $service->computeRegressions($baseline, $postPatch3);

        $this->assertSame(
            $hashBefore,
            $baseline->contentHash,
            'VAL-E5-012: baseline hash is byte-identical across diff calls',
        );
    }

    public function test_val_e5_012_identical_baselines_have_identical_hashes(): void
    {
        $service = $this->makeService();

        $baselineA = $this->cache(['cmd-x' => true, 'cmd-y' => false]);
        $baselineB = $this->cache(['cmd-x' => true, 'cmd-y' => false]);

        $this->assertSame(
            $baselineA->contentHash,
            $baselineB->contentHash,
            'VAL-E5-012: identical baseline contents => identical hash',
        );
    }

    public function test_val_e5_012_different_baselines_have_different_hashes(): void
    {
        $baselineA = $this->cache(['cmd-x' => true]);
        $baselineB = $this->cache(['cmd-x' => false]);

        $this->assertNotSame(
            $baselineA->contentHash,
            $baselineB->contentHash,
            'VAL-E5-012: different baseline contents => different hash',
        );
    }

    public function test_val_e5_012_baseline_recorded_calls_are_proof_of_single_capture(): void
    {
        $runner = new FakeRegressionBaselineRunner;
        $runner->queueOk('cmd-once');
        $service = new RegressionBaselineService($runner);

        $cache = $service->captureBaseline(
            runId: 'run-e5-once',
            commands: ['cmd-once'],
            workspace: '/ws',
            captureOrder: 0,
        );

        // The service ran each command exactly once during capture.
        $this->assertSame(1, count($runner->calls), 'each command run once during capture');
        $this->assertSame(['cmd-once'], array_column($runner->calls, 'command'));
    }

    // -- Helpers ---------------------------------------------------------------

    private function makeService(): RegressionBaselineService
    {
        // The service requires a runner for captureBaseline(); computeRegressions()
        // and buildResult() are pure and never touch the runner. We pass a
        // throwaway fake so the pure-diff tests don't need to set one up.
        return new RegressionBaselineService(
            new FakeRegressionBaselineRunner,
        );
    }

    /**
     * @param  array<string,bool>  $results
     */
    private function cache(array $results): RegressionBaselineCache
    {
        return RegressionBaselineCache::capture($results, captureOrder: 0);
    }

    private function test_run(string $command, bool $ok): object
    {
        return new TestRun(
            command: $command,
            ok: $ok,
            exitCode: $ok ? 0 : 1,
            durationMs: 0,
            outputHash: hash('sha256', $command.($ok ? 'ok' : 'fail')),
            outputPath: null,
        );
    }
}

/**
 * In-memory runner for RegressionBaselineService unit tests. Mirrors the
 * FakeCommandRunner / FakeMutationCommandRunner pattern.
 */
final class FakeRegressionBaselineRunner implements RegressionBaselineRunner
{
    /** @var list<VerificationCommandResult> */
    private array $queue = [];

    /** @var list<array{command:string,workspace:string}> */
    public array $calls = [];

    public function queueOk(string $command): void
    {
        $this->queue[] = new VerificationCommandResult(
            command: $command,
            exitCode: 0,
            stdout: 'ok',
            stderr: '',
            durationMs: 0,
        );
    }

    public function queueFail(string $command): void
    {
        $this->queue[] = new VerificationCommandResult(
            command: $command,
            exitCode: 1,
            stdout: '',
            stderr: 'failed',
            durationMs: 0,
        );
    }

    public function run(string $command, string $workspace): VerificationCommandResult
    {
        $this->calls[] = ['command' => $command, 'workspace' => $workspace];

        $rejected = UnsafeCommandPolicy::reasonIfUnsafe($command);
        if ($rejected !== null) {
            return new VerificationCommandResult(
                command: $command,
                exitCode: 126,
                stdout: '',
                stderr: 'rejected: '.$rejected,
                durationMs: 0,
                rejectedReason: $rejected,
            );
        }

        if ($this->queue === []) {
            return new VerificationCommandResult(
                command: $command,
                exitCode: 0,
                stdout: 'ok (default)',
                stderr: '',
                durationMs: 0,
            );
        }

        return array_shift($this->queue);
    }
}
