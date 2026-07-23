<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainArchitectureEntropyMeter;
use Tests\TestCase;

final class AtlasExternalBrainArchitectureEntropyMeterTest extends TestCase
{
    private function meter(): AtlasExternalBrainArchitectureEntropyMeter
    {
        return new AtlasExternalBrainArchitectureEntropyMeter;
    }

    private function area(string $id, array $overrides = []): array
    {
        return array_merge([
            'area_id' => $id,
            'responsibility_count' => 1,
            'duplicate_purpose_count' => 0,
            'wrapper_count' => 0,
            'total_class_count' => 10,
            'ownership_change_count' => 0,
        ], $overrides);
    }

    public function test_schema_present(): void
    {
        $r = $this->meter()->scan([]);
        $this->assertSame(AtlasExternalBrainArchitectureEntropyMeter::SCHEMA, $r['schema']);
    }

    public function test_empty_input_yields_empty_areas(): void
    {
        $r = $this->meter()->scan([]);
        $this->assertSame([], $r['areas']);
    }

    public function test_each_area_has_factor_breakdown(): void
    {
        $r = $this->meter()->scan([$this->area('a')]);
        $factors = $r['areas'][0]['factors'];

        foreach (['responsibility_scatter', 'duplicate_purpose_count', 'wrapper_ratio', 'ownership_drift'] as $field) {
            $this->assertArrayHasKey($field, $factors, "Missing factor: {$field}");
        }
        $this->assertArrayHasKey('area_id', $r['areas'][0]);
        $this->assertArrayHasKey('entropy_score', $r['areas'][0]);
    }

    // ── AC: ranks highest-entropy areas first ────────────────────────────────

    public function test_high_entropy_area_ranks_ahead_of_low_entropy_area(): void
    {
        $r = $this->meter()->scan([
            $this->area('clean'),
            $this->area('chaotic', [
                'responsibility_count' => 9,
                'duplicate_purpose_count' => 4,
                'wrapper_count' => 8,
                'total_class_count' => 10,
                'ownership_change_count' => 4,
            ]),
        ]);

        $this->assertSame('chaotic', $r['areas'][0]['area_id']);
        $this->assertSame('clean', $r['areas'][1]['area_id']);
        $this->assertGreaterThan($r['areas'][1]['entropy_score'], $r['areas'][0]['entropy_score']);
    }

    // ── each signal independently changes entropy ranking ────────────────────

    public function test_responsibility_scatter_alone_raises_entropy(): void
    {
        $r = $this->meter()->scan([
            $this->area('focused', ['responsibility_count' => 1]),
            $this->area('scattered', ['responsibility_count' => 10]),
        ]);

        $this->assertSame('scattered', $r['areas'][0]['area_id']);
    }

    public function test_duplicate_purpose_alone_raises_entropy(): void
    {
        $r = $this->meter()->scan([
            $this->area('unique', ['duplicate_purpose_count' => 0]),
            $this->area('duplicated', ['duplicate_purpose_count' => 5]),
        ]);

        $this->assertSame('duplicated', $r['areas'][0]['area_id']);
    }

    public function test_wrapper_ratio_alone_raises_entropy(): void
    {
        $r = $this->meter()->scan([
            $this->area('substantive', ['wrapper_count' => 0, 'total_class_count' => 10]),
            $this->area('all-wrappers', ['wrapper_count' => 10, 'total_class_count' => 10]),
        ]);

        $this->assertSame('all-wrappers', $r['areas'][0]['area_id']);
        $this->assertSame(1.0, $r['areas'][0]['factors']['wrapper_ratio']);
    }

    public function test_ownership_drift_alone_raises_entropy(): void
    {
        $r = $this->meter()->scan([
            $this->area('stable-owner', ['ownership_change_count' => 0]),
            $this->area('drifting-owner', ['ownership_change_count' => 5]),
        ]);

        $this->assertSame('drifting-owner', $r['areas'][0]['area_id']);
    }

    // ── wrapper_ratio never divides by zero ──────────────────────────────────

    public function test_zero_total_class_count_does_not_divide_by_zero(): void
    {
        $r = $this->meter()->scan([$this->area('empty', ['wrapper_count' => 3, 'total_class_count' => 0])]);

        $this->assertSame(0.0, $r['areas'][0]['factors']['wrapper_ratio']);
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_scan_is_deterministic(): void
    {
        $areas = [$this->area('a', ['responsibility_count' => 5]), $this->area('b', ['ownership_change_count' => 3])];

        $x = $this->meter()->scan($areas);
        $y = $this->meter()->scan($areas);

        $this->assertSame(json_encode($x), json_encode($y));
    }

    public function test_tiebreak_is_alphabetical_by_area_id(): void
    {
        $r = $this->meter()->scan([$this->area('zeta'), $this->area('alpha')]);

        $this->assertSame('alpha', $r['areas'][0]['area_id']);
        $this->assertSame('zeta', $r['areas'][1]['area_id']);
    }

    // ── never recommends an action ────────────────────────────────────────────

    public function test_output_never_contains_action_recommendation_keys(): void
    {
        $r = $this->meter()->scan([$this->area('a', ['responsibility_count' => 9])]);
        $json = (string) json_encode($r);

        $this->assertDoesNotMatchRegularExpression('/"(action|recommended_action|verdict|next_action)"/i', $json);
    }

    // ── malformed input is skipped, not fatal ────────────────────────────────

    public function test_entries_missing_area_id_are_skipped(): void
    {
        $r = $this->meter()->scan([['responsibility_count' => 5], $this->area('valid')]);

        $this->assertCount(1, $r['areas']);
        $this->assertSame('valid', $r['areas'][0]['area_id']);
    }
}
