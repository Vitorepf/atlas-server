<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Regression;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\TestRun;

/**
 * E5 -- The structured input the {@see RegressionBaselineGate} consumes.
 *
 * Produced by {@see RegressionBaselineService::buildResult()} from an
 * immutable {@see RegressionBaselineCache} (captured pre-patch) and the
 * post-patch {@see TestRun} list (produced by the verification gate).
 *
 * Fields:
 *   - $baseline:       the immutable clean-tree baseline cache (captured
 *                       ONCE, reused byte-identical across iterations).
 *   - $postPatchTests: the verification gate's post-patch TestRun list.
 *   - $regressions:    the computed regression set (commands that passed
 *                       before AND fail after). Pure: derived from
 *                       ($baseline.results, $postPatchTests) via
 *                       RegressionBaselineService::computeRegressions().
 *
 * The gate is a pure function of this result: same regressions + same mode
 * always yields the same verdict.
 */
final class RegressionBaselineResult
{
    /**
     * @param  list<TestRun>  $postPatchTests
     * @param  list<string>  $regressions  commands that regressed (passed-before, failed-after)
     */
    public function __construct(
        public readonly RegressionBaselineCache $baseline,
        public readonly array $postPatchTests,
        public readonly array $regressions,
    ) {}
}
