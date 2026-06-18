<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Mutation;

use App\Services\Ai\Programming\AtlasDev\Mutation\MutationScope;
use App\Services\Ai\Programming\AtlasDev\Mutation\MutationScoreGate;
use App\Services\Ai\Programming\AtlasDev\Mutation\MutationTestingAdapter;
use App\Services\Ai\Programming\AtlasDev\Mutation\MutationTestingResult;
use App\Services\Ai\Programming\AtlasDev\Support\Elevations\ElevationConfig;
use PHPUnit\Framework\TestCase;

/**
 * E3 — MutationScoreGate verdict matrix (unit).
 *
 * VAL-E3-002, VAL-E3-003, VAL-E3-004, VAL-E3-007, VAL-E3-009, VAL-E3-010.
 *
 * The gate is a PURE function of the real reported MSI vs the configured
 * threshold (VAL-E3-007): it consumes a MutationTestingResult (produced by
 * MutationTestingAdapter::run, which reads the real infection summary JSON)
 * and routes the verdict through exactly one of the two sanctioned channels:
 *
 *   - ADVISORY  => append the honesty flag `mutation_score_below_threshold`
 *                  so the CompletionStateGate auto-downgrades PASSED ->
 *                  needs_review (VAL-E3-002 / VAL-E3-009: never green).
 *   - HARD      => STATUS_FAILED gate channel (VAL-E3-003: never just
 *                  downgrades to needs_review in hard mode).
 *   - OFF       => no flag, no STATUS_FAILED, no infection invoked
 *                  (VAL-E3-010: byte-identical to pre-E3).
 *   - SKIPPED   => no flag, no fail (VAL-E3-008: a no-op result never
 *                  produces a false fail).
 *
 * The gate does NOT apply the threshold itself by inspecting infection: it
 * consumes the structured MutationTestingResult. The verdict matrix here
 * proves the verdict is a pure function of (msi, threshold, mode).
 */
final class MutationScoreGateTest extends TestCase
{
    // -- VAL-E3-007: verdict tracks the real reported MSI across the matrix --

    public function test_val_e3_007_verdict_is_pure_function_of_real_msi_vs_threshold_advisory(): void
    {
        $gate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            threshold: 60.0,
        );

        // Real reported MSI below threshold => tripped verdict (advisory).
        $below = $this->completedResult(msi: 59.9);
        $this->assertTrue(
            $gate->evaluate($below)->tripped,
            'VAL-E3-007: MSI below threshold trips the gate',
        );
        $this->assertContains(
            MutationTestingAdapter::FLAG_MUTATION_SCORE_BELOW_THRESHOLD,
            $gate->evaluate($below)->honestyFlags,
            'VAL-E3-002: advisory appends the honesty flag',
        );

        // Real reported MSI at/above threshold => not tripped.
        $at = $this->completedResult(msi: 60.0);
        $this->assertFalse(
            $gate->evaluate($at)->tripped,
            'VAL-E3-007: MSI == threshold passes (boundary inclusive)',
        );
        $this->assertSame(
            [],
            $gate->evaluate($at)->honestyFlags,
            'VAL-E3-004: no flag when MSI >= threshold',
        );

        $above = $this->completedResult(msi: 99.9);
        $this->assertFalse(
            $gate->evaluate($above)->tripped,
            'VAL-E3-007: MSI above threshold passes',
        );
    }

    public function test_val_e3_007_verdict_is_pure_function_of_real_msi_vs_threshold_hard(): void
    {
        $gate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => 'hard']),
            threshold: 60.0,
        );

        $below = $this->completedResult(msi: 30.0);
        $this->assertTrue($gate->evaluate($below)->tripped);
        $this->assertTrue(
            $gate->evaluate($below)->shouldFailGate,
            'VAL-E3-003: hard mode forces STATUS_FAILED when MSI below threshold',
        );

        $above = $this->completedResult(msi: 75.0);
        $this->assertFalse($gate->evaluate($above)->tripped);
        $this->assertFalse(
            $gate->evaluate($above)->shouldFailGate,
            'VAL-E3-004: hard mode does not fail when MSI >= threshold',
        );
    }

    // -- VAL-E3-002: advisory => honesty flag (downgrade, never green) -------

    public function test_val_e3_002_advisory_below_threshold_appends_only_honesty_flag_not_status_failed(): void
    {
        $gate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            threshold: 60.0,
        );

        $verdict = $gate->evaluate($this->completedResult(msi: 40.0));

        $this->assertTrue($verdict->tripped, 'advisory trips on below-threshold');
        $this->assertContains(
            MutationTestingAdapter::FLAG_MUTATION_SCORE_BELOW_THRESHOLD,
            $verdict->honestyFlags,
            'VAL-E3-002: the honesty flag is appended',
        );
        $this->assertFalse(
            $verdict->shouldFailGate,
            'VAL-E3-002: advisory never forces STATUS_FAILED for the flag alone',
        );
    }

    // -- VAL-E3-003: hard => STATUS_FAILED gate channel ---------------------

    public function test_val_e3_003_hard_below_threshold_routes_to_status_failed(): void
    {
        $gate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => 'hard']),
            threshold: 60.0,
        );

        $verdict = $gate->evaluate($this->completedResult(msi: 50.0));

        $this->assertTrue($verdict->tripped);
        $this->assertTrue(
            $verdict->shouldFailGate,
            'VAL-E3-003: hard mode routes to STATUS_FAILED (sanctioned hard channel)',
        );
        // The flag is retained for auditability even in hard mode.
        $this->assertContains(
            MutationTestingAdapter::FLAG_MUTATION_SCORE_BELOW_THRESHOLD,
            $verdict->honestyFlags,
            'VAL-E3-003: flag retained for auditability in hard mode',
        );
    }

    // -- VAL-E3-004: robust test passes in both advisory and hard -----------

    public function test_val_e3_004_robust_test_passes_advisory_no_flag_no_block(): void
    {
        $gate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            threshold: 60.0,
        );

        $verdict = $gate->evaluate($this->completedResult(msi: 80.0));

        $this->assertFalse($verdict->tripped, 'VAL-E3-004: robust test does not trip');
        $this->assertSame([], $verdict->honestyFlags, 'no flag on a robust test');
        $this->assertFalse($verdict->shouldFailGate, 'no gate failure on a robust test');
    }

    public function test_val_e3_004_robust_test_passes_hard_no_flag_no_block(): void
    {
        $gate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => 'hard']),
            threshold: 60.0,
        );

        $verdict = $gate->evaluate($this->completedResult(msi: 100.0));

        $this->assertFalse($verdict->tripped);
        $this->assertSame([], $verdict->honestyFlags);
        $this->assertFalse($verdict->shouldFailGate);
    }

    // -- VAL-E3-009: advisory never falsely passes a weak test --------------

    public function test_val_e3_009_advisory_never_falsely_passes_weak_test(): void
    {
        // The verdict MUST trip for any below-threshold MSI in advisory mode,
        // no matter how close to the threshold. There is no advisory path that
        // silently greens a below-threshold result.
        $gate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            threshold: 60.0,
        );

        foreach ([0.0, 1.0, 30.0, 59.0, 59.99] as $weakMsi) {
            $verdict = $gate->evaluate($this->completedResult(msi: $weakMsi));
            $this->assertTrue(
                $verdict->tripped,
                "VAL-E3-009: MSI={$weakMsi} below threshold must trip in advisory",
            );
            $this->assertContains(
                MutationTestingAdapter::FLAG_MUTATION_SCORE_BELOW_THRESHOLD,
                $verdict->honestyFlags,
                "VAL-E3-009: MSI={$weakMsi} carries the honesty flag",
            );
        }
    }

    // -- VAL-E3-010: off => no surfacing (byte-identical) -------------------

    public function test_val_e3_010_off_mode_never_surfaces_even_below_threshold(): void
    {
        $gate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => 'off']),
            threshold: 60.0,
        );

        $verdict = $gate->evaluate($this->completedResult(msi: 10.0));

        $this->assertFalse($verdict->tripped, 'VAL-E3-010: off never trips');
        $this->assertSame([], $verdict->honestyFlags, 'off appends no flag');
        $this->assertFalse($verdict->shouldFailGate, 'off never fails the gate');
        $this->assertTrue($verdict->isNoOp, 'off is a documented no-op');
    }

    // -- VAL-E3-008: skipped result => no-op (never a false fail) -----------

    public function test_val_e3_008_skipped_result_is_no_op_never_false_fail(): void
    {
        $gate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            threshold: 60.0,
        );

        $skipped = MutationTestingResult::skipped(
            'e3: no added/modified test files in the patch; mutation gate is a no-op',
        );

        $verdict = $gate->evaluate($skipped);

        $this->assertFalse($verdict->tripped, 'VAL-E3-008: skipped is never a trip');
        $this->assertFalse($verdict->shouldFailGate, 'VAL-E3-008: never a false fail');
        $this->assertSame([], $verdict->honestyFlags, 'VAL-E3-008: no flag on a skip');
        $this->assertTrue($verdict->isNoOp, 'skipped is a documented no-op');
        $this->assertNotSame('', $verdict->noOpReason, 'explicit no-op reason');
    }

    public function test_skipped_result_in_hard_mode_still_never_fails(): void
    {
        // Even in hard mode, a skipped result (no test files touched) is a
        // no-op: hard only applies when there IS a real below-threshold MSI
        // to fail on. A patch with no test files cannot be failed by E3.
        $gate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => 'hard']),
            threshold: 60.0,
        );

        $verdict = $gate->evaluate(
            MutationTestingResult::skipped('e3: no test files')
        );

        $this->assertFalse($verdict->tripped);
        $this->assertFalse($verdict->shouldFailGate);
        $this->assertTrue($verdict->isNoOp);
    }

    // -- Honest ceiling: failed infection result (no MSI) --------------------

    public function test_failed_infection_result_in_hard_mode_does_not_silently_pass(): void
    {
        // VAL-E3-011 honest ceiling: a failed infection run carries a NULL MSI.
        // In hard mode, that cannot silently pass: the adapter surfaced a real
        // failure (no fabricated MSI). The gate treats a non-skipped failed
        // result as a trip in hard mode (fail-closed) so the pipeline never
        // greens over an unevaluable mutation score.
        $gate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => 'hard']),
            threshold: 60.0,
        );

        $failed = MutationTestingResult::failed('e3: infection invocation failed (exit 1)');

        $verdict = $gate->evaluate($failed);

        $this->assertTrue($verdict->tripped, 'failed infection does not silently pass');
        $this->assertTrue($verdict->shouldFailGate, 'hard fails closed over an unevaluable MSI');
        $this->assertNotSame('', $verdict->reason, 'explicit reason carried for auditability');
    }

    public function test_failed_infection_result_in_advisory_mode_appends_flag_not_status_failed(): void
    {
        $gate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
            threshold: 60.0,
        );

        $verdict = $gate->evaluate(
            MutationTestingResult::failed('e3: infection invocation failed (exit 1)'),
        );

        $this->assertTrue($verdict->tripped);
        $this->assertFalse($verdict->shouldFailGate, 'advisory never forces STATUS_FAILED');
        $this->assertNotEmpty($verdict->honestyFlags, 'advisory surfaces the unevaluable MSI via a flag');
    }

    public function test_failed_infection_result_in_off_mode_is_no_op(): void
    {
        $gate = new MutationScoreGate(
            e3Config: ElevationConfig::for('e3', ['mode' => 'off']),
            threshold: 60.0,
        );

        $verdict = $gate->evaluate(
            MutationTestingResult::failed('e3: infection invocation failed (exit 1)'),
        );

        $this->assertFalse($verdict->tripped, 'off never surfaces');
        $this->assertTrue($verdict->isNoOp);
    }

    // -- Config-driven threshold resolution -----------------------------------

    public function test_threshold_read_from_config_when_not_explicit(): void
    {
        // Production path: the threshold is read from
        // atlas_dev.elevations.e3.threshold (default 60.0). The gate exposes
        // ::fromConfig() so the executor resolves it the same way as the
        // other elevations. In a pure unit TestCase (no Laravel kernel) the
        // config() helper is unavailable, so the gate degrades to the
        // documented default (DEFAULT_THRESHOLD) without crashing — the
        // feature test (MutationScoreGateWiringTest) proves the live kernel
        // path reads the configured threshold.
        $gate = MutationScoreGate::fromConfig(
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
        );

        $this->assertSame(
            MutationScoreGate::DEFAULT_THRESHOLD,
            $gate->threshold(),
            'unit fallback: gate degrades to the default threshold when the kernel is absent',
        );

        $below = $this->completedResult(msi: MutationScoreGate::DEFAULT_THRESHOLD - 0.1);
        $at = $this->completedResult(msi: MutationScoreGate::DEFAULT_THRESHOLD);
        $this->assertTrue($gate->evaluate($below)->tripped, 'default threshold trips below');
        $this->assertFalse($gate->evaluate($at)->tripped, 'default threshold passes at boundary');
    }

    // -- Helpers --------------------------------------------------------------

    private function completedResult(float $msi): MutationTestingResult
    {
        return MutationTestingResult::completed(
            msi: $msi,
            summaryPath: '/tmp/e3-summary.json',
            scope: new MutationScope(
                testFiles: ['tests/Unit/CalculatorTest.php'],
                sourceFiles: ['app/Calculator.php'],
            ),
        );
    }
}
