<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSurfaceSaturationMeter;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainSurfaceSaturationMeterTest extends TestCase
{
    private AtlasExternalBrainSurfaceSaturationMeter $meter;

    protected function setUp(): void
    {
        $this->meter = new AtlasExternalBrainSurfaceSaturationMeter;
    }

    private function candidate(array $overrides = []): array
    {
        return array_merge([
            'candidate_id' => uniqid('c-'),
            'subsystem' => 'loop',
            'target_path' => 'app/Services/Ai/SelfConstruction/AtlasFoo.php',
            'value_mechanism' => 'wiring',
            'yield' => 0.8,
            'duplicate' => false,
        ], $overrides);
    }

    private function lowYield(array $overrides = []): array
    {
        return $this->candidate(array_merge(['yield' => 0.1, 'duplicate' => false], $overrides));
    }

    private function duplicate(array $overrides = []): array
    {
        return $this->candidate(array_merge(['yield' => 0.1, 'duplicate' => true], $overrides));
    }

    private function allModePasses(): array
    {
        $passes = [];
        foreach (AtlasExternalBrainSurfaceSaturationMeter::REQUIRED_SEARCH_MODES as $mode) {
            $passes[$mode] = ['passed' => true, 'stale' => false];
        }

        return $passes;
    }

    // ── output shape ──────────────────────────────────────────────────────────

    public function test_output_has_all_required_keys(): void
    {
        $r = $this->meter->measure('test-surface', [
            $this->candidate(),
            $this->candidate(),
            $this->candidate(),
        ]);

        foreach (['schema', 'surface_id', 'verdict', 'saturation_score', 'reasoning',
                  'dominant_subsystem', 'duplicate_rate', 'low_yield_rate',
                  'missing_modes', 'next_recommended_mode'] as $key) {
            $this->assertArrayHasKey($key, $r, "output must contain {$key}");
        }
        $this->assertSame(AtlasExternalBrainSurfaceSaturationMeter::SCHEMA, $r['schema']);
        $this->assertSame('test-surface', $r['surface_id']);
    }

    // ── insufficient_data ─────────────────────────────────────────────────────

    public function test_returns_insufficient_data_below_min_candidates(): void
    {
        $r = $this->meter->measure('surf', [$this->candidate(), $this->candidate()]);
        $this->assertSame(AtlasExternalBrainSurfaceSaturationMeter::VERDICT_INSUFFICIENT, $r['verdict']);
    }

    public function test_returns_insufficient_data_for_empty_input(): void
    {
        $r = $this->meter->measure('surf', []);
        $this->assertSame(AtlasExternalBrainSurfaceSaturationMeter::VERDICT_INSUFFICIENT, $r['verdict']);
    }

    // ── exhausted_with_evidence ───────────────────────────────────────────────

    public function test_exhausted_when_both_rates_exceed_threshold(): void
    {
        // 8 duplicates (all low-yield) out of 10 → both rates ≥ 0.7.
        // All five search modes must also be covered.
        $candidates = array_merge(
            array_fill(0, 8, $this->duplicate()),
            [$this->candidate(['yield' => 0.9]), $this->candidate(['yield' => 0.9])],
        );

        $r = $this->meter->measure('surf', $candidates, ['mode_passes' => $this->allModePasses()]);

        $this->assertSame(AtlasExternalBrainSurfaceSaturationMeter::VERDICT_EXHAUSTED, $r['verdict']);
        $this->assertGreaterThanOrEqual(0.7, $r['duplicate_rate']);
        $this->assertGreaterThanOrEqual(0.7, $r['low_yield_rate']);
        $this->assertSame([], $r['missing_modes']);
        $this->assertNull($r['next_recommended_mode']);
    }

    public function test_exhausted_blocked_without_mode_passes_returns_deepen(): void
    {
        // Both rates ≥ threshold but no mode_passes → verdict must stay deepen.
        $candidates = array_merge(
            array_fill(0, 8, $this->duplicate()),
            [$this->candidate(['yield' => 0.9]), $this->candidate(['yield' => 0.9])],
        );

        $r = $this->meter->measure('surf', $candidates);

        $this->assertSame(AtlasExternalBrainSurfaceSaturationMeter::VERDICT_DEEPEN, $r['verdict']);
        $this->assertNotEmpty($r['missing_modes']);
        $this->assertSame('bug-hunt', $r['next_recommended_mode']);
    }

    public function test_exhausted_blocked_with_stale_mode_pass_returns_deepen(): void
    {
        $passes = $this->allModePasses();
        $passes['architecture'] = ['passed' => true, 'stale' => true]; // stale → counts as missing

        $candidates = array_merge(
            array_fill(0, 8, $this->duplicate()),
            [$this->candidate(['yield' => 0.9]), $this->candidate(['yield' => 0.9])],
        );

        $r = $this->meter->measure('surf', $candidates, ['mode_passes' => $passes]);

        $this->assertSame(AtlasExternalBrainSurfaceSaturationMeter::VERDICT_DEEPEN, $r['verdict']);
        $this->assertContains('architecture', $r['missing_modes']);
    }

    public function test_missing_modes_lists_uncovered_modes(): void
    {
        $passes = [
            'bug-hunt'      => ['passed' => true, 'stale' => false],
            'architecture'  => ['passed' => true, 'stale' => false],
            // research, simplification, proof-gap missing
        ];

        $r = $this->meter->measure('surf', array_fill(0, 3, $this->candidate()), ['mode_passes' => $passes]);

        $this->assertContains('research', $r['missing_modes']);
        $this->assertContains('simplification', $r['missing_modes']);
        $this->assertContains('proof-gap', $r['missing_modes']);
        $this->assertNotContains('bug-hunt', $r['missing_modes']);
        $this->assertNotContains('architecture', $r['missing_modes']);
    }

    public function test_next_recommended_mode_follows_required_modes_order(): void
    {
        // Only bug-hunt covered — next should be architecture (second in list).
        $passes = ['bug-hunt' => ['passed' => true, 'stale' => false]];

        $r = $this->meter->measure('surf', array_fill(0, 3, $this->candidate()), ['mode_passes' => $passes]);

        $this->assertSame('architecture', $r['next_recommended_mode']);
    }

    // ── rotate ────────────────────────────────────────────────────────────────

    public function test_rotate_when_duplicate_rate_high_but_yield_acceptable(): void
    {
        // 7 duplicates with HIGH yield (not low), 3 fresh.
        // duplicate_rate ≥ 0.7 but low_yield_rate < 0.7 → rotate.
        $candidates = array_merge(
            array_fill(0, 7, $this->candidate(['duplicate' => true, 'yield' => 0.8])),
            array_fill(0, 3, $this->candidate(['duplicate' => false, 'yield' => 0.9])),
        );

        $r = $this->meter->measure('surf', $candidates);

        $this->assertSame(AtlasExternalBrainSurfaceSaturationMeter::VERDICT_ROTATE, $r['verdict']);
        $this->assertGreaterThanOrEqual(0.7, $r['duplicate_rate']);
    }

    public function test_rotate_description_mentions_rotating_surface(): void
    {
        $candidates = array_fill(0, 8, $this->candidate(['duplicate' => true, 'yield' => 0.9]));
        $candidates[] = $this->candidate();
        $candidates[] = $this->candidate();

        $r = $this->meter->measure('surf', $candidates);
        $this->assertSame(AtlasExternalBrainSurfaceSaturationMeter::VERDICT_ROTATE, $r['verdict']);
        $this->assertStringContainsStringIgnoringCase('rotate', $r['reasoning']);
    }

    // ── consolidate ───────────────────────────────────────────────────────────

    public function test_consolidate_when_low_yield_high_but_not_duplicate(): void
    {
        // 8 unique low-yield candidates, 2 good → low_yield_rate=0.8, duplicate_rate=0.
        $candidates = array_merge(
            array_fill(0, 8, $this->lowYield()),
            [$this->candidate(['yield' => 0.9]), $this->candidate(['yield' => 0.9])],
        );

        $r = $this->meter->measure('surf', $candidates);

        $this->assertSame(AtlasExternalBrainSurfaceSaturationMeter::VERDICT_CONSOLIDATE, $r['verdict']);
        $this->assertGreaterThanOrEqual(0.7, $r['low_yield_rate']);
        $this->assertLessThan(0.7, $r['duplicate_rate']);
    }

    // ── deepen ────────────────────────────────────────────────────────────────

    public function test_deepen_when_surface_still_has_signal(): void
    {
        $candidates = array_fill(0, 5, $this->candidate(['yield' => 0.8, 'duplicate' => false]));

        $r = $this->meter->measure('surf', $candidates);

        $this->assertSame(AtlasExternalBrainSurfaceSaturationMeter::VERDICT_DEEPEN, $r['verdict']);
        $this->assertLessThan(0.7, $r['duplicate_rate']);
        $this->assertLessThan(0.7, $r['low_yield_rate']);
    }

    public function test_fresh_high_yield_surface_gets_deepen_verdict(): void
    {
        $candidates = [
            $this->candidate(['subsystem' => 'external-brain', 'yield' => 0.9]),
            $this->candidate(['subsystem' => 'external-brain', 'yield' => 0.85]),
            $this->candidate(['subsystem' => 'loop', 'yield' => 0.75]),
            $this->candidate(['subsystem' => 'loop', 'yield' => 0.7]),
        ];

        $r = $this->meter->measure('fresh-surface', $candidates);

        $this->assertSame(AtlasExternalBrainSurfaceSaturationMeter::VERDICT_DEEPEN, $r['verdict']);
    }

    // ── dominant_subsystem ────────────────────────────────────────────────────

    public function test_dominant_subsystem_is_most_common(): void
    {
        $candidates = [
            $this->candidate(['subsystem' => 'external-brain']),
            $this->candidate(['subsystem' => 'external-brain']),
            $this->candidate(['subsystem' => 'external-brain']),
            $this->candidate(['subsystem' => 'loop']),
            $this->candidate(['subsystem' => 'loop']),
        ];

        $r = $this->meter->measure('surf', $candidates);
        $this->assertSame('external-brain', $r['dominant_subsystem']);
    }

    public function test_dominant_subsystem_null_when_no_subsystem_info(): void
    {
        $candidates = array_fill(0, 3, ['candidate_id' => 'c', 'yield' => 0.5, 'duplicate' => false]);
        $r = $this->meter->measure('surf', $candidates);
        $this->assertNull($r['dominant_subsystem']);
    }

    // ── custom context ────────────────────────────────────────────────────────

    public function test_custom_yield_floor_changes_low_yield_classification(): void
    {
        // yield=0.5 is above default floor 0.3, so would normally be 'good'.
        // With floor=0.6, yield=0.5 → low yield.
        $candidates = array_fill(0, 4, $this->candidate(['yield' => 0.5]));

        $default = $this->meter->measure('surf', $candidates);
        $strict = $this->meter->measure('surf', $candidates, ['yield_floor' => 0.6, 'saturation_threshold' => 0.5]);

        $this->assertSame(AtlasExternalBrainSurfaceSaturationMeter::VERDICT_DEEPEN, $default['verdict']);
        // With strict floor (all 4 below 0.6) and low threshold (0.5), low_yield_rate=1.0 ≥ 0.5 → consolidate.
        $this->assertSame(AtlasExternalBrainSurfaceSaturationMeter::VERDICT_CONSOLIDATE, $strict['verdict']);
    }

    public function test_custom_min_candidates_for_decision(): void
    {
        // 2 candidates — insufficient by default (min=3) but sufficient with min=2.
        $r = $this->meter->measure('surf', [$this->candidate(), $this->candidate()], ['min_candidates_for_decision' => 2]);
        $this->assertNotSame(AtlasExternalBrainSurfaceSaturationMeter::VERDICT_INSUFFICIENT, $r['verdict']);
    }

    // ── saturation_score is informational ─────────────────────────────────────

    public function test_saturation_score_is_between_zero_and_one(): void
    {
        foreach ([[], array_fill(0, 5, $this->duplicate()), array_fill(0, 5, $this->candidate())] as $c) {
            if ($c === []) {
                continue;
            }
            $r = $this->meter->measure('surf', $c);
            $this->assertGreaterThanOrEqual(0.0, $r['saturation_score']);
            $this->assertLessThanOrEqual(1.0, $r['saturation_score']);
        }
    }
}
