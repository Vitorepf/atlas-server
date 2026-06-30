<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSurfaceSaturationMeter;
use Tests\TestCase;

final class AtlasExternalBrainSurfaceSaturationMeterTest extends TestCase
{
    private function svc(): AtlasExternalBrainSurfaceSaturationMeter
    {
        return new AtlasExternalBrainSurfaceSaturationMeter;
    }

    private function allModePasses(): array
    {
        $passes = [];
        foreach (AtlasExternalBrainSurfaceSaturationMeter::REQUIRED_SEARCH_MODES as $mode) {
            $passes[$mode] = ['passed' => true, 'stale' => false];
        }
        return $passes;
    }

    private function wave(int $count, bool $duplicate, float $yield, string $subsystem = 'core'): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = ['candidate_id' => "c{$i}", 'subsystem' => $subsystem, 'duplicate' => $duplicate, 'yield' => $yield];
        }
        return $out;
    }

    // ── AC1: repeated low-yield waves with high duplicate rate → saturated ────

    public function test_ac1_high_duplicate_and_low_yield_marks_surface_saturated(): void
    {
        // Wave 1 + Wave 2: all duplicates, all low-yield
        $candidates = array_merge(
            $this->wave(5, true,  0.10),
            $this->wave(5, true,  0.05),
        );

        $r = $this->svc()->measure('srf_01', $candidates, [
            'saturation_threshold' => 0.7,
            'yield_floor'          => 0.3,
            'mode_passes'          => $this->allModePasses(),
        ]);

        $this->assertSame(AtlasExternalBrainSurfaceSaturationMeter::VERDICT_EXHAUSTED, $r['verdict']);
        $this->assertSame('pivot', $r['recommendation']);
    }

    public function test_ac1_requires_all_mode_passes_before_declaring_exhausted(): void
    {
        // Same high dupe + low yield, but mode_passes missing → NOT exhausted
        $candidates = array_merge(
            $this->wave(5, true, 0.10),
            $this->wave(5, true, 0.05),
        );

        $r = $this->svc()->measure('srf_02', $candidates, [
            'saturation_threshold' => 0.7,
            'yield_floor'          => 0.3,
            // no mode_passes
        ]);

        $this->assertNotSame(AtlasExternalBrainSurfaceSaturationMeter::VERDICT_EXHAUSTED, $r['verdict']);
    }

    public function test_ac1_three_low_yield_waves_with_all_modes_covered_is_saturated(): void
    {
        $candidates = array_merge(
            $this->wave(4, true, 0.05),
            $this->wave(4, true, 0.05),
            $this->wave(4, true, 0.10),
        );

        $r = $this->svc()->measure('srf_03', $candidates, [
            'saturation_threshold' => 0.7,
            'yield_floor'          => 0.3,
            'mode_passes'          => $this->allModePasses(),
        ]);

        $this->assertSame(AtlasExternalBrainSurfaceSaturationMeter::VERDICT_EXHAUSTED, $r['verdict']);
        $this->assertSame('pivot', $r['recommendation']);
        $this->assertGreaterThan(0.7, $r['saturation_score']);
    }

    // ── AC2: diverse, implementable, high-impact → NOT saturated ─────────────

    public function test_ac2_diverse_high_impact_candidates_surface_not_saturated(): void
    {
        $candidates = [
            ['candidate_id' => 'a', 'subsystem' => 'auth',      'duplicate' => false, 'yield' => 0.90],
            ['candidate_id' => 'b', 'subsystem' => 'cache',     'duplicate' => false, 'yield' => 0.85],
            ['candidate_id' => 'c', 'subsystem' => 'reporting', 'duplicate' => false, 'yield' => 0.80],
            ['candidate_id' => 'd', 'subsystem' => 'storage',   'duplicate' => false, 'yield' => 0.95],
            ['candidate_id' => 'e', 'subsystem' => 'api',       'duplicate' => false, 'yield' => 0.88],
        ];

        $r = $this->svc()->measure('srf_04', $candidates, [
            'saturation_threshold' => 0.7,
            'yield_floor'          => 0.3,
        ]);

        $this->assertNotSame(AtlasExternalBrainSurfaceSaturationMeter::VERDICT_EXHAUSTED, $r['verdict']);
        $this->assertNotSame(AtlasExternalBrainSurfaceSaturationMeter::VERDICT_ROTATE, $r['verdict']);
        $this->assertSame('continue', $r['recommendation']);
    }

    public function test_ac2_zero_duplicates_and_high_yield_gives_deepen_verdict(): void
    {
        $candidates = $this->wave(5, false, 0.90, 'infra');

        $r = $this->svc()->measure('srf_05', $candidates);

        $this->assertSame(AtlasExternalBrainSurfaceSaturationMeter::VERDICT_DEEPEN, $r['verdict']);
        $this->assertSame('continue', $r['recommendation']);
        $this->assertSame(0.0, $r['duplicate_rate']);
    }

    // ── AC3: recommendations cover pivot / consolidate / deepen_second_pass / continue ──

    public function test_ac3_rotate_verdict_maps_to_pivot(): void
    {
        // High duplicate rate only (low_yield_rate is fine) → rotate → pivot
        $candidates = array_merge(
            $this->wave(8, true,  0.80),  // 8 duplicates, yield OK
            $this->wave(2, false, 0.80),
        );

        $r = $this->svc()->measure('srf_06', $candidates, ['saturation_threshold' => 0.7, 'yield_floor' => 0.3]);

        $this->assertSame(AtlasExternalBrainSurfaceSaturationMeter::VERDICT_ROTATE, $r['verdict']);
        $this->assertSame('pivot', $r['recommendation']);
    }

    public function test_ac3_consolidate_verdict_maps_to_consolidate(): void
    {
        // Low yield rate only (duplicate_rate is fine) → consolidate
        $candidates = array_merge(
            $this->wave(8, false, 0.05),  // unique but low yield
            $this->wave(2, false, 0.05),
        );

        $r = $this->svc()->measure('srf_07', $candidates, ['saturation_threshold' => 0.7, 'yield_floor' => 0.3]);

        $this->assertSame(AtlasExternalBrainSurfaceSaturationMeter::VERDICT_CONSOLIDATE, $r['verdict']);
        $this->assertSame('consolidate', $r['recommendation']);
    }

    public function test_ac3_under_evidenced_verdict_maps_to_deepen_second_pass(): void
    {
        // Both rates exceed threshold, but modes missing + strict_mode_evidence=true → under_evidenced
        $candidates = array_merge(
            $this->wave(5, true, 0.05),
            $this->wave(5, true, 0.10),
        );

        $r = $this->svc()->measure('srf_08', $candidates, [
            'saturation_threshold' => 0.7,
            'yield_floor'          => 0.3,
            'strict_mode_evidence' => true,
            // mode_passes intentionally absent
        ]);

        $this->assertSame(AtlasExternalBrainSurfaceSaturationMeter::VERDICT_UNDER_EVIDENCED, $r['verdict']);
        $this->assertSame('deepen_second_pass', $r['recommendation']);
    }

    public function test_ac3_deepen_verdict_maps_to_continue(): void
    {
        $candidates = $this->wave(5, false, 0.70);

        $r = $this->svc()->measure('srf_09', $candidates);

        $this->assertSame(AtlasExternalBrainSurfaceSaturationMeter::VERDICT_DEEPEN, $r['verdict']);
        $this->assertSame('continue', $r['recommendation']);
    }

    public function test_ac3_insufficient_data_maps_to_continue(): void
    {
        $r = $this->svc()->measure('srf_10', [$this->wave(1, true, 0.0)[0]]);

        $this->assertSame(AtlasExternalBrainSurfaceSaturationMeter::VERDICT_INSUFFICIENT, $r['verdict']);
        $this->assertSame('continue', $r['recommendation']);
    }

    // ── AC4: deterministic — no quota pressure ────────────────────────────────

    public function test_ac4_identical_input_yields_identical_output(): void
    {
        $candidates = array_merge($this->wave(5, true, 0.10), $this->wave(5, false, 0.80));
        $ctx        = ['saturation_threshold' => 0.6, 'yield_floor' => 0.3];

        $this->assertSame(
            json_encode($this->svc()->measure('srf_11', $candidates, $ctx), JSON_UNESCAPED_SLASHES),
            json_encode($this->svc()->measure('srf_11', $candidates, $ctx), JSON_UNESCAPED_SLASHES),
        );
    }

    public function test_ac4_large_candidate_list_still_saturated_not_continued_for_quota(): void
    {
        // 100 candidates, all duplicates + low yield — large count must NOT flip verdict to continue
        $candidates = $this->wave(100, true, 0.05);

        $r = $this->svc()->measure('srf_12', $candidates, [
            'saturation_threshold' => 0.7,
            'yield_floor'          => 0.3,
            'mode_passes'          => $this->allModePasses(),
        ]);

        $this->assertSame(AtlasExternalBrainSurfaceSaturationMeter::VERDICT_EXHAUSTED, $r['verdict'],
            'A large candidate count must not override saturation — no quota pressure should flip verdict');
        $this->assertSame('pivot', $r['recommendation']);
    }

    public function test_ac4_recommendation_key_always_present(): void
    {
        $r = $this->svc()->measure('srf_13', $this->wave(5, false, 0.90));
        $this->assertArrayHasKey('recommendation', $r);
    }
}
