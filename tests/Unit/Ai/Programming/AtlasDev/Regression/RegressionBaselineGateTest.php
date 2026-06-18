<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Regression;

use App\Services\Ai\Programming\AtlasDev\Regression\RegressionBaselineCache;
use App\Services\Ai\Programming\AtlasDev\Regression\RegressionBaselineGate;
use App\Services\Ai\Programming\AtlasDev\Regression\RegressionBaselineResult;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\TestRun;
use App\Services\Ai\Programming\AtlasDev\Support\Elevations\ElevationConfig;
use PHPUnit\Framework\TestCase;

/**
 * E5 -- RegressionBaselineGate verdict matrix (unit, red-first).
 *
 * VAL-E5-002 (regression detection), VAL-E5-003 (hard-blocks), VAL-E5-004
 * (pre-existing not regression), VAL-E5-005 (fix not regression).
 *
 * The gate is a PURE function of (regressions, mode): it consumes a
 * RegressionBaselineResult (baseline cache + post-patch TestRun[] produced
 * by RegressionBaselineService::buildResult) and routes the verdict through
 * exactly one of the two sanctioned channels:
 *
 *   - ADVISORY  => append the honesty flag so the CompletionStateGate
 *                  auto-downgrades PASSED -> needs_review (never green).
 *   - HARD      => STATUS_FAILED gate channel (never just downgrades).
 *   - OFF       => no flag, no STATUS_FAILED (byte-identical to pre-E5).
 *
 * Like the E3 MutationScoreGate, the gate does NOT capture the baseline or
 * compute regressions itself: it consumes the structured result. The verdict
 * matrix here proves the verdict is a pure function of (regressions, mode).
 */
final class RegressionBaselineGateTest extends TestCase
{
    // -- VAL-E5-002 + VAL-E5-003: regression detected and routes correctly ---

    public function test_val_e5_002_advisory_regression_trips_appends_flag(): void
    {
        $gate = new RegressionBaselineGate(
            e5Config: ElevationConfig::for('e5', ['mode' => 'advisory']),
        );

        $result = $this->resultWithRegressions(['cmd-regressed']);

        $verdict = $gate->evaluate($result);

        $this->assertTrue($verdict->tripped, 'VAL-E5-002: regression trips the gate');
        $this->assertFalse(
            $verdict->shouldFailGate,
            'advisory never forces STATUS_FAILED for the flag alone',
        );
        $this->assertNotEmpty($verdict->honestyFlags, 'advisory surfaces via a flag');
        $this->assertContains(
            RegressionBaselineGate::FLAG_REGRESSION_DETECTED,
            $verdict->honestyFlags,
            'VAL-E5-002: the regression flag is present in advisory',
        );
        $this->assertSame(['cmd-regressed'], $verdict->regressions, 'regressions listed on verdict');
    }

    public function test_val_e5_003_hard_regression_routes_to_status_failed(): void
    {
        $gate = new RegressionBaselineGate(
            e5Config: ElevationConfig::for('e5', ['mode' => 'hard']),
        );

        $result = $this->resultWithRegressions(['cmd-regressed']);

        $verdict = $gate->evaluate($result);

        $this->assertTrue($verdict->tripped);
        $this->assertTrue(
            $verdict->shouldFailGate,
            'VAL-E5-003: hard mode routes to STATUS_FAILED (sanctioned hard channel)',
        );
        $this->assertContains(
            RegressionBaselineGate::FLAG_REGRESSION_DETECTED,
            $verdict->honestyFlags,
            'VAL-E5-003: flag retained for auditability in hard mode',
        );
        $this->assertSame(['cmd-regressed'], $verdict->regressions);
    }

    // -- VAL-E5-004 + VAL-E5-005: no regressions => gate does not trip -------

    public function test_val_e5_004_advisory_no_regressions_does_not_trip(): void
    {
        $gate = new RegressionBaselineGate(
            e5Config: ElevationConfig::for('e5', ['mode' => 'advisory']),
        );

        // Baseline with pre-existing failure that stays failed => no regression.
        $result = $this->resultWithRegressions([]);

        $verdict = $gate->evaluate($result);

        $this->assertFalse($verdict->tripped, 'VAL-E5-004: no regressions => no trip');
        $this->assertFalse($verdict->shouldFailGate);
        $this->assertSame([], $verdict->honestyFlags, 'no flag when no regressions');
    }

    public function test_val_e5_005_hard_no_regressions_does_not_trip(): void
    {
        $gate = new RegressionBaselineGate(
            e5Config: ElevationConfig::for('e5', ['mode' => 'hard']),
        );

        $verdict = $gate->evaluate($this->resultWithRegressions([]));

        $this->assertFalse($verdict->tripped, 'VAL-E5-005: a fix (no regressions) does not trip');
        $this->assertFalse($verdict->shouldFailGate, 'hard does not fail when no regressions');
        $this->assertSame([], $verdict->honestyFlags);
    }

    // -- Off mode is byte-identical no-op -------------------------------------

    public function test_off_mode_regression_is_no_op_never_trips(): void
    {
        $gate = new RegressionBaselineGate(
            e5Config: ElevationConfig::for('e5', ['mode' => 'off']),
        );

        // Even with a real regression, off mode never trips.
        $verdict = $gate->evaluate($this->resultWithRegressions(['cmd-regressed']));

        $this->assertFalse($verdict->tripped, 'off mode: never trips');
        $this->assertFalse($verdict->shouldFailGate, 'off mode: never fails');
        $this->assertTrue($verdict->isNoOp, 'off mode: documented no-op');
        $this->assertSame([], $verdict->honestyFlags, 'off mode: no flag');
        $this->assertNotEmpty($verdict->noOpReason, 'off mode carries an explicit reason');
    }

    public function test_off_mode_empty_result_is_also_no_op(): void
    {
        $gate = new RegressionBaselineGate(
            e5Config: ElevationConfig::for('e5', ['mode' => 'off']),
        );

        $verdict = $gate->evaluate($this->resultWithRegressions([]));

        $this->assertTrue($verdict->isNoOp);
        $this->assertFalse($verdict->tripped);
    }

    // -- Empty baseline / empty post-patch => no-op (no false fail) -----------

    public function test_advisory_empty_baseline_is_no_op_not_false_fail(): void
    {
        $gate = new RegressionBaselineGate(
            e5Config: ElevationConfig::for('e5', ['mode' => 'advisory']),
        );

        $baseline = RegressionBaselineCache::capture([], captureOrder: 0);
        $result = new RegressionBaselineResult(
            baseline: $baseline,
            postPatchTests: [],
            regressions: [],
        );

        $verdict = $gate->evaluate($result);

        $this->assertFalse($verdict->tripped, 'empty baseline: never a false fail');
        $this->assertFalse($verdict->shouldFailGate);
        $this->assertSame([], $verdict->honestyFlags);
    }

    // -- Verdict tracks the regression set across modes -----------------------

    public function test_verdict_regressions_echoed_in_both_modes(): void
    {
        $regressions = ['cmd-a', 'cmd-b'];

        $advisory = (new RegressionBaselineGate(
            ElevationConfig::for('e5', ['mode' => 'advisory']),
        ))->evaluate($this->resultWithRegressions($regressions));

        $hard = (new RegressionBaselineGate(
            ElevationConfig::for('e5', ['mode' => 'hard']),
        ))->evaluate($this->resultWithRegressions($regressions));

        $this->assertSame($regressions, $advisory->regressions, 'advisory lists regressions');
        $this->assertSame($regressions, $hard->regressions, 'hard lists regressions');
        $this->assertTrue($advisory->tripped && $hard->tripped, 'both modes trip on real regressions');
        $this->assertFalse($advisory->shouldFailGate, 'advisory: flag only');
        $this->assertTrue($hard->shouldFailGate, 'hard: STATUS_FAILED');
    }

    // -- Helpers ---------------------------------------------------------------

    private function resultWithRegressions(array $regressions): RegressionBaselineResult
    {
        $results = [];
        foreach ($regressions as $cmd) {
            $results[$cmd] = true;  // passed before
        }
        $baseline = RegressionBaselineCache::capture($results, captureOrder: 0);

        $postPatch = [];
        foreach ($regressions as $cmd) {
            $postPatch[] = new TestRun(
                command: $cmd,
                ok: false,  // fails after => regression
                exitCode: 1,
                durationMs: 0,
                outputHash: hash('sha256', $cmd.'fail'),
                outputPath: null,
            );
        }

        return new RegressionBaselineResult(
            baseline: $baseline,
            postPatchTests: $postPatch,
            regressions: $regressions,
        );
    }
}
