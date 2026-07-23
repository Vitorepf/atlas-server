<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainComplexityHeatmap;
use Tests\TestCase;

final class AtlasExternalBrainComplexityHeatmapTest extends TestCase
{
    private function heatmap(): AtlasExternalBrainComplexityHeatmap
    {
        return new AtlasExternalBrainComplexityHeatmap;
    }

    private function organ(string $id, array $overrides = []): array
    {
        return array_merge([
            'organ_id' => $id,
            'line_count' => 100,
            'churn' => 5,
            'duplication' => 0.0,
            'coverage' => 1.0,
            'blast_radius' => 0,
        ], $overrides);
    }

    // ── schema + shape ────────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $r = $this->heatmap()->scan([]);
        $this->assertSame(AtlasExternalBrainComplexityHeatmap::SCHEMA, $r['schema']);
    }

    public function test_each_hotspot_has_evidence_fields(): void
    {
        $r = $this->heatmap()->scan([$this->organ('A')]);
        $evidence = $r['hotspots'][0]['evidence'];

        foreach (['line_count', 'churn', 'duplication', 'coverage', 'blast_radius'] as $field) {
            $this->assertArrayHasKey($field, $evidence, "Missing evidence field: {$field}");
        }
        $this->assertArrayHasKey('organ_id', $r['hotspots'][0]);
        $this->assertArrayHasKey('risk_score', $r['hotspots'][0]);
    }

    public function test_empty_input_yields_empty_hotspots(): void
    {
        $r = $this->heatmap()->scan([]);
        $this->assertSame([], $r['hotspots']);
    }

    // ── never recommends edits, only ranked facts ────────────────────────────

    public function test_output_never_contains_action_recommendation_keys(): void
    {
        $r = $this->heatmap()->scan([$this->organ('A', ['line_count' => 2000])]);
        $json = (string) json_encode($r);

        $this->assertDoesNotMatchRegularExpression('/"(action|recommended_action|verdict|next_action)"/i', $json);
    }

    // ── sorted highest risk first ─────────────────────────────────────────────

    public function test_hotspots_sorted_highest_risk_first(): void
    {
        $r = $this->heatmap()->scan([
            $this->organ('low-risk', ['line_count' => 10, 'churn' => 0, 'coverage' => 1.0]),
            $this->organ('high-risk', ['line_count' => 900, 'churn' => 40, 'duplication' => 0.8, 'coverage' => 0.0, 'blast_radius' => 15]),
        ]);

        $this->assertSame('high-risk', $r['hotspots'][0]['organ_id']);
        $this->assertSame('low-risk', $r['hotspots'][1]['organ_id']);
        $this->assertGreaterThan($r['hotspots'][1]['risk_score'], $r['hotspots'][0]['risk_score']);
    }

    // ── each signal independently changes which hotspot ranks first ─────────

    public function test_line_count_alone_changes_hotspot_selection(): void
    {
        $r = $this->heatmap()->scan([
            $this->organ('small'),
            $this->organ('large', ['line_count' => 5000]),
        ]);

        $this->assertSame('large', $r['hotspots'][0]['organ_id']);
    }

    public function test_churn_alone_changes_hotspot_selection(): void
    {
        $r = $this->heatmap()->scan([
            $this->organ('stable', ['churn' => 0]),
            $this->organ('churning', ['churn' => 100]),
        ]);

        $this->assertSame('churning', $r['hotspots'][0]['organ_id']);
    }

    public function test_duplication_alone_changes_hotspot_selection(): void
    {
        $r = $this->heatmap()->scan([
            $this->organ('unique', ['duplication' => 0.0]),
            $this->organ('duplicated', ['duplication' => 1.0]),
        ]);

        $this->assertSame('duplicated', $r['hotspots'][0]['organ_id']);
    }

    public function test_coverage_alone_changes_hotspot_selection(): void
    {
        $r = $this->heatmap()->scan([
            $this->organ('covered', ['coverage' => 1.0]),
            $this->organ('uncovered', ['coverage' => 0.0]),
        ]);

        $this->assertSame('uncovered', $r['hotspots'][0]['organ_id']);
    }

    public function test_blast_radius_alone_changes_hotspot_selection(): void
    {
        $r = $this->heatmap()->scan([
            $this->organ('isolated', ['blast_radius' => 0]),
            $this->organ('widely-consumed', ['blast_radius' => 50]),
        ]);

        $this->assertSame('widely-consumed', $r['hotspots'][0]['organ_id']);
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_scan_is_deterministic(): void
    {
        $organs = [$this->organ('A', ['line_count' => 300]), $this->organ('B', ['churn' => 20])];

        $a = $this->heatmap()->scan($organs);
        $b = $this->heatmap()->scan($organs);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_tiebreak_is_alphabetical_by_organ_id(): void
    {
        $r = $this->heatmap()->scan([
            $this->organ('zeta'),
            $this->organ('alpha'),
        ]);

        $this->assertSame('alpha', $r['hotspots'][0]['organ_id']);
        $this->assertSame('zeta', $r['hotspots'][1]['organ_id']);
    }

    // ── malformed input is skipped, not fatal ────────────────────────────────

    public function test_entries_missing_organ_id_are_skipped(): void
    {
        $r = $this->heatmap()->scan([['line_count' => 100], $this->organ('valid')]);

        $this->assertCount(1, $r['hotspots']);
        $this->assertSame('valid', $r['hotspots'][0]['organ_id']);
    }
}
