<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Regression;

use App\Services\Ai\Programming\AtlasDev\Gate\AtlasDevVerificationCommandRunnerContract as VerificationCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationCommandResult;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\TestRun;

/**
 * E5 -- RegressionBaselineService.
 *
 * The service that captures the pre-patch regression baseline on the clean
 * tree and computes the regression set (tests that passed before AND fail
 * after).
 *
 * Structural, model-irrelevant: the baseline is a deterministic snapshot of
 * the scoped suite's pass/fail on the clean tree (captured ONCE before the
 * patch is applied), and the regression diff is a pure comparison of the
 * baseline against the post-patch verification gate results. No provider
 * signal, no LLM dependency.
 *
 * CONTRACT (the four transition cases):
 *   - passed-before + failed-after  => REGRESSION (VAL-E5-002).
 *   - failed-before + failed-after  => pre-existing failure, NOT a regression
 *                                       (VAL-E5-004).
 *   - failed-before + passed-after  => a fix, NOT a regression (VAL-E5-005).
 *   - passed-before + passed-after  => still passing, NOT a regression.
 *
 * A command that appears in the post-patch results but NOT in the baseline
 * is NOT a regression (it has no baseline entry to compare against; this is
 * the caller-test selection feature's job to expand into the baseline).
 *
 * VAL-E5-001: the baseline is captured strictly before the patch is applied.
 * The executor calls captureBaseline() before the repair loop and passes
 * captureOrder=0 (the baseline predates the patch which is applied inside
 * the loop at a strictly later point). The cache is persisted via the
 * ReceiptStorage so the capture order / timestamp is durable evidence.
 *
 * VAL-E5-012: the baseline cache is immutable and captured ONCE. The
 * executor reuses the SAME cache object across all M2 repair iterations;
 * computeRegressions() / buildResult() are pure and never mutate the
 * baseline. The contentHash is stable across diff calls.
 *
 * The service is a plain Laravel service resolved via app(...); the runner
 * is injected via the constructor so tests inject a fake
 * {@see RegressionBaselineRunner} without spawning real subprocesses. In
 * production the runner delegates to the verification command runner (the
 * baseline runs the same kind of test commands the gate runs).
 *
 * Canonical: mission architecture.md (Atlas Dev Elevation v2, M4 / E5,
 * e5-regression-baseline feature).
 */
final class RegressionBaselineService
{
    public function __construct(
        private readonly RegressionBaselineRunner $runner,
    ) {}

    /**
     * Capture the pre-patch baseline: run each command on the clean tree
     * and record command -> ok into an immutable cache.
     *
     * VAL-E5-001: the cache is non-empty when scoped tests exist and is
     * captured strictly before the patch is applied (the executor calls
     * this before the repair loop with captureOrder=0).
     *
     * @param  list<string>  $commands  the scoped test commands to run.
     * @param  int  $captureOrder  monotonic counter proving before-patch capture.
     */
    public function captureBaseline(
        string $runId,
        array $commands,
        string $workspace,
        int $captureOrder,
    ): RegressionBaselineCache {
        $results = [];
        foreach ($commands as $command) {
            $command = is_string($command) ? trim($command) : '';
            if ($command === '') {
                continue;
            }

            $result = $this->runner->run($command, $workspace);
            $results[$command] = $result->ok();
        }

        return RegressionBaselineCache::capture($results, $captureOrder);
    }

    /**
     * Compute the regression set: commands that passed before (in the
     * baseline) AND fail after (in the post-patch results).
     *
     * Pure: never mutates the baseline. The same (baseline, postPatchTests)
     * always yields the same regression set.
     *
     * @param  list<TestRun>  $postPatchTests  the verification gate's post-patch results.
     * @return list<string>  the regression commands (passed-before, failed-after).
     */
    public function computeRegressions(
        RegressionBaselineCache $baseline,
        array $postPatchTests,
    ): array {
        $regressions = [];
        foreach ($postPatchTests as $test) {
            if (! $test instanceof TestRun) {
                continue;
            }
            $command = $test->command;
            if (! array_key_exists($command, $baseline->results)) {
                // Not in baseline: cannot be a regression (no before-state).
                continue;
            }

            $passedBefore = $baseline->results[$command];
            $failedAfter = ! $test->ok;

            if ($passedBefore && $failedAfter) {
                // VAL-E5-002: passed-before + fails-after = regression.
                $regressions[] = $command;
            }
            // All other transitions (failed+failed, failed+passed, passed+passed)
            // are NOT regressions (VAL-E5-004 pre-existing, VAL-E5-005 fix).
        }

        return array_values(array_unique($regressions));
    }

    /**
     * Build the structured {@see RegressionBaselineResult} the gate consumes.
     * Convenience wrapper: captures the regression set + bundles the baseline
     * + post-patch tests into the gate's input.
     *
     * @param  list<TestRun>  $postPatchTests
     */
    public function buildResult(
        RegressionBaselineCache $baseline,
        array $postPatchTests,
    ): RegressionBaselineResult {
        return new RegressionBaselineResult(
            baseline: $baseline,
            postPatchTests: $postPatchTests,
            regressions: $this->computeRegressions($baseline, $postPatchTests),
        );
    }
}
