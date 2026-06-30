<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainTaskFamilyYieldModel;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainTaskFamilyYieldModelTest extends TestCase
{
    private function model(): AtlasExternalBrainTaskFamilyYieldModel
    {
        return new AtlasExternalBrainTaskFamilyYieldModel;
    }

    private function family(array $overrides = []): array
    {
        return array_merge([
            'family_id'                   => 'fam1',
            'accepted_specs'              => 2,
            'resolved_capability_deltas'  => 2,
            'architecture_unlocks'        => 0,
            'verified_wiring_changes'     => 0,
        ], $overrides);
    }

    // ── Output shape ──────────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->model()->model([]);
        $this->assertSame(AtlasExternalBrainTaskFamilyYieldModel::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('family_yields',      $r);
        $this->assertArrayHasKey('ranked_families',    $r);
        $this->assertArrayHasKey('low_yield_families', $r);
        $this->assertArrayHasKey('summary',            $r);
    }

    // ── Yield scoring ─────────────────────────────────────────────────────────

    public function test_family_with_high_delivery_is_high_yield(): void
    {
        // delivery=4, accepted=2 → raw=4/3≈1.33 → clamped 1.0 → high_yield
        $r = $this->model()->model(['families' => [$this->family([
            'accepted_specs'             => 2,
            'resolved_capability_deltas' => 2,
            'architecture_unlocks'       => 2,
        ])]]);
        $this->assertSame('high_yield', $r['family_yields'][0]['classification']);
        $this->assertNull($r['family_yields'][0]['penalty_applied']);
    }

    public function test_family_with_zero_specs_and_one_delta_is_high_yield(): void
    {
        // delivery=1, accepted=0 → raw=1/1=1.0 → high_yield
        $r = $this->model()->model(['families' => [$this->family([
            'accepted_specs'             => 0,
            'resolved_capability_deltas' => 1,
        ])]]);
        $this->assertSame('high_yield', $r['family_yields'][0]['classification']);
    }

    // ── AC2: spec-bulk penalties ──────────────────────────────────────────────

    public function test_spec_factory_zero_delta_penalized(): void
    {
        // 5 specs, 0 deltas → zero_delta_penalty → yield near 0
        $r = $this->model()->model(['families' => [$this->family([
            'family_id'                  => 'spec_factory',
            'accepted_specs'             => 5,
            'resolved_capability_deltas' => 0,
        ])]]);
        $entry = $r['family_yields'][0];
        $this->assertSame('zero_delta_penalty', $entry['penalty_applied']);
        $this->assertLessThan(0.30, $entry['yield_score']);
        $this->assertSame('low_yield', $entry['classification']);
    }

    public function test_low_delta_ratio_penalized(): void
    {
        // 10 specs, 1 delta → ratio=0.10 < 0.20 → low_ratio_penalty
        $r = $this->model()->model(['families' => [$this->family([
            'family_id'                  => 'low_conv',
            'accepted_specs'             => 10,
            'resolved_capability_deltas' => 1,
        ])]]);
        $entry = $r['family_yields'][0];
        $this->assertSame('low_ratio_penalty', $entry['penalty_applied']);
    }

    public function test_3_specs_1_delta_does_not_trigger_any_penalty(): void
    {
        // ratio = 1/3 ≈ 0.33 >= 0.20 AND accepted_specs < SPEC_BULK_THRESHOLD(4) → no penalty
        $r = $this->model()->model(['families' => [$this->family([
            'accepted_specs'             => 3,
            'resolved_capability_deltas' => 1,
        ])]]);
        $this->assertNull($r['family_yields'][0]['penalty_applied']);
    }

    public function test_good_delta_ratio_no_penalty(): void
    {
        // 4 specs, 2 deltas → ratio=0.50 → no penalty
        $r = $this->model()->model(['families' => [$this->family([
            'accepted_specs'             => 4,
            'resolved_capability_deltas' => 2,
        ])]]);
        $this->assertNull($r['family_yields'][0]['penalty_applied']);
    }

    // ── Ranked families ───────────────────────────────────────────────────────

    public function test_families_ranked_by_yield_descending(): void
    {
        $r = $this->model()->model(['families' => [
            $this->family(['family_id' => 'low',  'accepted_specs' => 5, 'resolved_capability_deltas' => 0]),
            $this->family(['family_id' => 'high', 'accepted_specs' => 1, 'resolved_capability_deltas' => 3]),
        ]]);
        $this->assertSame('high', $r['ranked_families'][0]);
        $this->assertSame('low',  $r['ranked_families'][1]);
    }

    // ── low_yield_families ────────────────────────────────────────────────────

    public function test_low_yield_families_listed_with_reason(): void
    {
        $r = $this->model()->model(['families' => [$this->family([
            'family_id'                  => 'bad',
            'accepted_specs'             => 5,
            'resolved_capability_deltas' => 0,
        ])]]);
        $this->assertCount(1, $r['low_yield_families']);
        $this->assertSame('bad', $r['low_yield_families'][0]['family_id']);
        $this->assertNotEmpty($r['low_yield_families'][0]['reason']);
    }

    // ── Summary counts ────────────────────────────────────────────────────────

    public function test_summary_counts_match_classifications(): void
    {
        $r = $this->model()->model(['families' => [
            $this->family(['family_id' => 'h', 'accepted_specs' => 1, 'resolved_capability_deltas' => 3]),
            $this->family(['family_id' => 'm', 'accepted_specs' => 2, 'resolved_capability_deltas' => 1]),
            $this->family(['family_id' => 'l', 'accepted_specs' => 5, 'resolved_capability_deltas' => 0]),
        ]]);
        $s = $r['summary'];
        $this->assertSame(3, $s['total_families']);
        $this->assertSame($s['high_yield'] + $s['moderate_yield'] + $s['low_yield'], 3);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $facts = ['families' => [
            $this->family(['family_id' => 'a', 'accepted_specs' => 3, 'resolved_capability_deltas' => 2]),
            $this->family(['family_id' => 'b', 'accepted_specs' => 6, 'resolved_capability_deltas' => 0]),
        ]];
        $a = $this->model()->model($facts);
        $b = $this->model()->model($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }

    // ── AC1: roi_score, confidence, recommended_action, reasons ──────────────

    public function test_per_family_has_roi_score_confidence_action_reasons(): void
    {
        $r = $this->model()->model(['families' => [$this->family()]]);
        $f = $r['family_yields'][0];

        $this->assertArrayHasKey('roi_score', $f);
        $this->assertArrayHasKey('confidence', $f);
        $this->assertArrayHasKey('recommended_action', $f);
        $this->assertArrayHasKey('reasons', $f);
    }

    public function test_invest_recommended_for_high_roi_family(): void
    {
        $r = $this->model()->model(['families' => [
            $this->family(['accepted_specs' => 2, 'resolved_capability_deltas' => 5, 'architecture_unlocks' => 2]),
        ]]);

        $this->assertSame('invest', $r['family_yields'][0]['recommended_action']);
    }

    public function test_deprioritize_for_high_give_back_family(): void
    {
        $r = $this->model()->model(['families' => [
            $this->family(['give_back_rate' => 0.40, 'resolved_capability_deltas' => 2]),
        ]]);

        $this->assertSame('deprioritize', $r['family_yields'][0]['recommended_action']);
        $this->assertContains('high_give_back_rate', $r['family_yields'][0]['reasons']);
    }

    public function test_confidence_high_when_five_or_more_specs(): void
    {
        $r = $this->model()->model(['families' => [
            $this->family(['accepted_specs' => 6, 'resolved_capability_deltas' => 3]),
        ]]);

        $this->assertSame('high', $r['family_yields'][0]['confidence']);
    }

    public function test_confidence_low_when_fewer_than_two_specs(): void
    {
        $r = $this->model()->model(['families' => [
            $this->family(['accepted_specs' => 1, 'resolved_capability_deltas' => 1]),
        ]]);

        $this->assertSame('low', $r['family_yields'][0]['confidence']);
    }

    // ── AC2: high-spec/low-delta downranked below strong-delivery families ────

    public function test_high_spec_low_delta_ranked_below_smaller_high_delivery(): void
    {
        $specHeavy = $this->family([
            'family_id'                  => 'spec_heavy',
            'accepted_specs'             => 10,
            'resolved_capability_deltas' => 0,  // → zero_delta_penalty
        ]);
        $smallStrong = $this->family([
            'family_id'                  => 'small_strong',
            'accepted_specs'             => 2,
            'resolved_capability_deltas' => 3,
        ]);

        $r = $this->model()->model(['families' => [$specHeavy, $smallStrong]]);

        $this->assertSame('small_strong', $r['ranked_families'][0]);
        $this->assertSame('spec_heavy',   $r['ranked_families'][1]);
    }

    public function test_give_back_rate_reduces_roi_score(): void
    {
        $noGiveBack   = $this->family(['family_id' => 'a', 'resolved_capability_deltas' => 3, 'give_back_rate' => 0.0]);
        $highGiveBack = $this->family(['family_id' => 'b', 'resolved_capability_deltas' => 3, 'give_back_rate' => 0.8]);

        $r = $this->model()->model(['families' => [$noGiveBack, $highGiveBack]]);

        $byId = array_column($r['family_yields'], null, 'family_id');
        $this->assertGreaterThan($byId['b']['roi_score'], $byId['a']['roi_score']);
    }

    public function test_roi_score_within_valid_range(): void
    {
        $r = $this->model()->model(['families' => [
            $this->family(['give_back_rate' => 0.9, 'resolved_capability_deltas' => 0]),
        ]]);

        $this->assertGreaterThanOrEqual(0.0, $r['family_yields'][0]['roi_score']);
        $this->assertLessThanOrEqual(1.0,   $r['family_yields'][0]['roi_score']);
    }
}
