<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCompressionNorthStarScorecard;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCompressionNorthStarScorecardTest extends TestCase
{
    private function svc(): AtlasExternalBrainCompressionNorthStarScorecard
    {
        return new AtlasExternalBrainCompressionNorthStarScorecard;
    }

    private function strongInput(array $overrides = []): array
    {
        return array_merge([
            'fitness_gain' => 1.0,
            'autonomy_gain' => 1.0,
            'deletion_gain' => 1.0,
            'proof_health' => 1.0,
            'regression_rate' => 0.0,
            'blockers' => [],
        ], $overrides);
    }

    // ── AC: accelerate_score_case ──────────────────────────────────────────────

    public function test_accelerate_score_case_all_metrics_maxed(): void
    {
        $r = $this->svc()->score($this->strongInput());

        $this->assertSame(1.0, $r['north_star_score']);
        $this->assertSame(AtlasExternalBrainCompressionNorthStarScorecard::RECOMMENDATION_ACCELERATE, $r['recommendation']);
        $this->assertSame($r['recommendation'], $r['band_recommendation']);
        $this->assertSame([], $r['overrides']);
    }

    public function test_score_computation_weights_all_five_metrics(): void
    {
        $r = $this->svc()->score([
            'fitness_gain' => 1.0,
            'autonomy_gain' => 0.0,
            'deletion_gain' => 0.0,
            'proof_health' => 0.0,
            'regression_rate' => 1.0,
        ]);

        // 1.0*0.30 + 0 + 0 + 0 + (1-1)*0.10 = 0.30
        $this->assertSame(0.3, $r['north_star_score']);
    }

    // ── band thresholds ────────────────────────────────────────────────────────

    public function test_steady_band(): void
    {
        $r = $this->svc()->score($this->strongInput([
            'fitness_gain' => 0.6, 'autonomy_gain' => 0.6, 'deletion_gain' => 0.6, 'proof_health' => 0.6,
        ]));

        // 0.6*(0.30+0.25+0.20+0.15) + 0.10 = 0.6*0.90 + 0.10 = 0.64
        $this->assertSame(AtlasExternalBrainCompressionNorthStarScorecard::RECOMMENDATION_STEADY, $r['band_recommendation']);
    }

    public function test_stop_band_when_score_very_low(): void
    {
        $r = $this->svc()->score([
            'fitness_gain' => 0.0, 'autonomy_gain' => 0.0, 'deletion_gain' => 0.0,
            'proof_health' => 0.0, 'regression_rate' => 1.0,
        ]);

        $this->assertSame(0.0, $r['north_star_score']);
        $this->assertSame(AtlasExternalBrainCompressionNorthStarScorecard::RECOMMENDATION_STOP, $r['recommendation']);
    }

    // ── AC: repair_blocker_case ─────────────────────────────────────────────────

    public function test_repair_blocker_case_blockers_cap_a_high_score_at_repair(): void
    {
        $r = $this->svc()->score($this->strongInput(['blockers' => ['missing_rollback_plan']]));

        $this->assertSame(AtlasExternalBrainCompressionNorthStarScorecard::RECOMMENDATION_ACCELERATE, $r['band_recommendation']);
        $this->assertSame(AtlasExternalBrainCompressionNorthStarScorecard::RECOMMENDATION_REPAIR, $r['recommendation']);
        $this->assertContains('blockers_cap_at_repair', $r['overrides']);
        $this->assertSame(['missing_rollback_plan'], $r['blockers']);
    }

    public function test_blockers_do_not_upgrade_an_already_worse_recommendation(): void
    {
        $r = $this->svc()->score([
            'fitness_gain' => 0.0, 'autonomy_gain' => 0.0, 'deletion_gain' => 0.0,
            'proof_health' => 0.0, 'regression_rate' => 1.0,
            'blockers' => ['x'],
        ]);

        // band is already 'stop', which is more severe than 'repair' -- blockers must not
        // "upgrade" it back to repair.
        $this->assertSame(AtlasExternalBrainCompressionNorthStarScorecard::RECOMMENDATION_STOP, $r['recommendation']);
    }

    // ── regression hard stop overrides everything ─────────────────────────────

    public function test_regression_hard_stop_forces_stop_even_with_perfect_score_and_no_blockers(): void
    {
        $r = $this->svc()->score($this->strongInput(['regression_rate' => 0.5]));

        $this->assertSame(AtlasExternalBrainCompressionNorthStarScorecard::RECOMMENDATION_STOP, $r['recommendation']);
        $this->assertContains('regression_rate_hard_stop', $r['overrides']);
    }

    public function test_regression_hard_stop_takes_priority_over_blocker_cap(): void
    {
        $r = $this->svc()->score($this->strongInput(['regression_rate' => 0.9, 'blockers' => ['x']]));

        $this->assertSame(AtlasExternalBrainCompressionNorthStarScorecard::RECOMMENDATION_STOP, $r['recommendation']);
        $this->assertContains('regression_rate_hard_stop', $r['overrides']);
        $this->assertContains('blockers_cap_at_repair', $r['overrides']);
    }

    // ── clamping ───────────────────────────────────────────────────────────────

    public function test_out_of_range_inputs_are_clamped_to_0_1(): void
    {
        $r = $this->svc()->score([
            'fitness_gain' => 5.0, 'autonomy_gain' => -3.0, 'deletion_gain' => 1.0,
            'proof_health' => 1.0, 'regression_rate' => -1.0,
        ]);

        $this->assertSame(1.0, $r['inputs']['fitness_gain']);
        $this->assertSame(0.0, $r['inputs']['autonomy_gain']);
        $this->assertSame(0.0, $r['inputs']['regression_rate']);
    }

    // ── schema / determinism ───────────────────────────────────────────────────

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->score([]);

        $this->assertSame(AtlasExternalBrainCompressionNorthStarScorecard::SCHEMA, $r['schema']);
    }

    public function test_score_is_deterministic(): void
    {
        $input = $this->strongInput(['blockers' => ['a']]);

        $this->assertSame(
            $this->svc()->score($input),
            $this->svc()->score($input),
        );
    }
}
