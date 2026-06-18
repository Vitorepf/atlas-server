<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev\Mutation;

use App\Services\Ai\Programming\AtlasDev\Mutation\MutationScoreGate;
use App\Services\Ai\Programming\AtlasDev\Support\Elevations\ElevationConfig;
use Tests\TestCase;

/**
 * E3 — Mutation-score gate threshold env-coercion safe default.
 *
 * m3-e3 scrutiny Defect 3 (NON-BLOCKING): the config threshold env coercion
 * silently converts an invalid/non-numeric threshold to 0.0 (which disables
 * the gate entirely, because MSI >= 0.0 is always true). Invalid/non-numeric
 * threshold MUST fall back to the safe default (60.0), never 0.0.
 *
 * The defect lives in two cooperating layers:
 *   - config/atlas_dev.php: `(float) env('ATLAS_DEV_ELEVATION_E3_THRESHOLD', 60.0)`
 *     — the (float) cast turns 'banana' into 0.0 BEFORE the gate ever sees it.
 *   - MutationScoreGate::fromConfig(): accepts 0.0 as a valid threshold
 *     (is_numeric(0.0) && 0.0 >= 0.0 && 0.0 <= 100.0), so the silently-zeroed
 *     value reaches the gate and disables it.
 *
 * The fix makes fromConfig robust to BOTH layers: it reads the RAW env value
 * (bypassing the config cast) and validates it, so an invalid env value never
 * silently disables the gate regardless of how the config file casts it.
 *
 * This is the feature-level proof (live Laravel kernel + env). The unit
 * counterpart is in MutationCorrectnessFixesTest.
 */
final class MutationThresholdSafeDefaultTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Ensure a clean baseline: e3 advisory so the gate is active, and no
        // leaked threshold from a prior test or the .env file.
        $_ENV['ATLAS_DEV_ELEVATION_E3_MODE'] = 'advisory';
        putenv('ATLAS_DEV_ELEVATION_E3_MODE=advisory');
    }

    protected function tearDown(): void
    {
        unset($_ENV['ATLAS_DEV_ELEVATION_E3_THRESHOLD']);
        putenv('ATLAS_DEV_ELEVATION_E3_THRESHOLD');
        unset($_ENV['ATLAS_DEV_ELEVATION_E3_MODE']);
        putenv('ATLAS_DEV_ELEVATION_E3_MODE');
        parent::tearDown();
    }

    /**
     * Defect 3 (NON-BLOCKING): an invalid (non-numeric) threshold env value
     * falls back to the safe default 60.0, NEVER 0.0 (which would disable the
     * gate entirely, since MSI >= 0.0 is always true).
     */
    public function test_defect_3_invalid_non_numeric_threshold_falls_back_to_safe_default_never_zero(): void
    {
        $_ENV['ATLAS_DEV_ELEVATION_E3_THRESHOLD'] = 'banana';
        putenv('ATLAS_DEV_ELEVATION_E3_THRESHOLD=banana');

        $gate = MutationScoreGate::fromConfig(
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
        );

        $this->assertSame(
            MutationScoreGate::DEFAULT_THRESHOLD,
            $gate->threshold(),
            'Defect 3: invalid/non-numeric threshold falls back to the safe '
            .'default (60.0), never 0.0 (which would silently disable the gate)',
        );
        $this->assertNotSame(
            0.0,
            $gate->threshold(),
            'Defect 3: threshold is NEVER 0.0 on invalid input',
        );
    }

    /**
     * Defect 3: a threshold env value of the literal string '0' or '0.0' is
     * HONORED as a legitimate operator choice (0.0 is a valid threshold, the
     * operator explicitly disabled the gate on purpose). The safe-default
     * fallback applies ONLY to non-numeric values, not to a numeric zero.
     */
    public function test_defect_3_explicit_numeric_zero_threshold_is_honored_as_operator_choice(): void
    {
        $_ENV['ATLAS_DEV_ELEVATION_E3_THRESHOLD'] = '0';
        putenv('ATLAS_DEV_ELEVATION_E3_THRESHOLD=0');

        $gate = MutationScoreGate::fromConfig(
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
        );

        $this->assertSame(
            0.0,
            $gate->threshold(),
            'Defect 3: an explicit numeric "0" is a valid operator choice (the '
            .'gate is intentionally disabled); only non-numeric falls back',
        );
    }

    /**
     * Defect 3: a valid numeric threshold env value is honored as-is.
     */
    public function test_defect_3_valid_numeric_threshold_is_honored(): void
    {
        $_ENV['ATLAS_DEV_ELEVATION_E3_THRESHOLD'] = '75.5';
        putenv('ATLAS_DEV_ELEVATION_E3_THRESHOLD=75.5');

        $gate = MutationScoreGate::fromConfig(
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
        );

        $this->assertSame(
            75.5,
            $gate->threshold(),
            'Defect 3: a valid numeric threshold is honored',
        );
    }

    /**
     * Defect 3: a missing threshold env value falls back to the safe default
     * 60.0 (the documented production default), never 0.0.
     */
    public function test_defect_3_missing_threshold_env_falls_back_to_safe_default(): void
    {
        // Explicitly clear the env value so the gate sees "missing".
        putenv('ATLAS_DEV_ELEVATION_E3_THRESHOLD');
        unset($_ENV['ATLAS_DEV_ELEVATION_E3_THRESHOLD']);

        $gate = MutationScoreGate::fromConfig(
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
        );

        $this->assertSame(
            MutationScoreGate::DEFAULT_THRESHOLD,
            $gate->threshold(),
            'Defect 3: missing threshold env falls back to the safe default 60.0',
        );
    }

    /**
     * Defect 3: a threshold above 100 (out of valid range) falls back to the
     * safe default, never to 0.0.
     */
    public function test_defect_3_out_of_range_threshold_falls_back_to_safe_default(): void
    {
        $_ENV['ATLAS_DEV_ELEVATION_E3_THRESHOLD'] = '150';
        putenv('ATLAS_DEV_ELEVATION_E3_THRESHOLD=150');

        $gate = MutationScoreGate::fromConfig(
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
        );

        $this->assertSame(
            MutationScoreGate::DEFAULT_THRESHOLD,
            $gate->threshold(),
            'Defect 3: out-of-range threshold falls back to the safe default',
        );
    }

    /**
     * Defect 3: a negative threshold (out of valid range) falls back to the
     * safe default, never to 0.0.
     */
    public function test_defect_3_negative_threshold_falls_back_to_safe_default(): void
    {
        $_ENV['ATLAS_DEV_ELEVATION_E3_THRESHOLD'] = '-5';
        putenv('ATLAS_DEV_ELEVATION_E3_THRESHOLD=-5');

        $gate = MutationScoreGate::fromConfig(
            e3Config: ElevationConfig::for('e3', ['mode' => 'advisory']),
        );

        $this->assertSame(
            MutationScoreGate::DEFAULT_THRESHOLD,
            $gate->threshold(),
            'Defect 3: negative threshold falls back to the safe default',
        );
    }
}
