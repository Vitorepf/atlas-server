<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainConsolidationFirstCircuitBreaker;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainConsolidationFirstCircuitBreakerTest extends TestCase
{
    private AtlasExternalBrainConsolidationFirstCircuitBreaker $cb;

    protected function setUp(): void
    {
        $this->cb = new AtlasExternalBrainConsolidationFirstCircuitBreaker;
    }

    private function eval(array $metrics): array
    {
        return $this->cb->evaluate($metrics);
    }

    private function actionsOf(array $r): array
    {
        return $r['consolidation_actions'] ?? [];
    }

    private function actionTypes(array $r): array
    {
        return array_column($this->actionsOf($r), 'action');
    }

    // ── Output shape ──────────────────────────────────────────────────────────

    public function test_output_has_required_keys_when_consolidating(): void
    {
        $r = $this->eval(['overlap_score' => 0.6, 'class_growth_count' => 40]);

        $this->assertSame(AtlasExternalBrainConsolidationFirstCircuitBreaker::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('decision', $r);
        $this->assertArrayHasKey('simplification_risk', $r);
        $this->assertArrayHasKey('consolidation_actions', $r);
        $this->assertArrayHasKey('triggers', $r);
    }

    public function test_output_has_required_keys_when_continuing(): void
    {
        $r = $this->eval(['overlap_score' => 0.1, 'capability_gap_count' => 5]);

        $this->assertSame(AtlasExternalBrainConsolidationFirstCircuitBreaker::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('decision', $r);
        $this->assertArrayHasKey('simplification_risk', $r);
        $this->assertArrayHasKey('capability_gaps', $r);
    }

    // ── AC: consolidate_first when overlap + growth both exceeded ─────────────

    public function test_high_overlap_and_growth_triggers_consolidate_first(): void
    {
        $r = $this->eval(['overlap_score' => 0.6, 'class_growth_count' => 40]);

        $this->assertSame(AtlasExternalBrainConsolidationFirstCircuitBreaker::DECISION_CONSOLIDATE, $r['decision']);
    }

    public function test_only_high_overlap_no_growth_does_not_trigger(): void
    {
        $r = $this->eval(['overlap_score' => 0.6, 'class_growth_count' => 5]);

        $this->assertSame(AtlasExternalBrainConsolidationFirstCircuitBreaker::DECISION_CONTINUE, $r['decision']);
    }

    public function test_only_high_growth_no_overlap_does_not_trigger(): void
    {
        $r = $this->eval(['overlap_score' => 0.1, 'class_growth_count' => 40]);

        $this->assertSame(AtlasExternalBrainConsolidationFirstCircuitBreaker::DECISION_CONTINUE, $r['decision']);
    }

    // ── AC: secondary signals (2+) also trigger consolidate_first ────────────

    public function test_two_secondary_signals_trigger_consolidate(): void
    {
        $r = $this->eval([
            'duplicate_capability_names'     => ['cap_a', 'cap_b', 'cap_c'],
            'shallow_scaffold_ratio'         => 0.5,
        ]);

        $this->assertSame(AtlasExternalBrainConsolidationFirstCircuitBreaker::DECISION_CONSOLIDATE, $r['decision']);
    }

    public function test_one_secondary_signal_alone_does_not_trigger(): void
    {
        $r = $this->eval(['duplicate_capability_names' => ['a', 'b', 'c']]);

        $this->assertSame(AtlasExternalBrainConsolidationFirstCircuitBreaker::DECISION_CONTINUE, $r['decision']);
    }

    // ── AC: consolidation actions include merge / retire / simplify ───────────

    public function test_high_overlap_includes_merge_action(): void
    {
        $r = $this->eval(['overlap_score' => 0.6, 'class_growth_count' => 40]);

        $this->assertContains('merge', $this->actionTypes($r));
    }

    public function test_high_scaffold_includes_retire_action(): void
    {
        $r = $this->eval([
            'duplicate_capability_names' => ['a', 'b', 'c'],
            'shallow_scaffold_ratio'     => 0.5,
        ]);

        $this->assertContains('retire', $this->actionTypes($r));
    }

    public function test_high_debt_includes_simplify_action(): void
    {
        $r = $this->eval([
            'duplicate_capability_names'   => ['a', 'b', 'c'],
            'unresolved_simplification_debt' => 6,
        ]);

        $this->assertContains('simplify', $this->actionTypes($r));
    }

    public function test_consolidation_actions_never_empty(): void
    {
        $r = $this->eval(['overlap_score' => 0.6, 'class_growth_count' => 50]);

        $this->assertNotEmpty($this->actionsOf($r));
    }

    public function test_consolidation_actions_contain_only_allowed_types(): void
    {
        $r       = $this->eval(['overlap_score' => 0.6, 'class_growth_count' => 40]);
        $allowed = ['merge', 'retire', 'simplify'];

        foreach ($this->actionTypes($r) as $type) {
            $this->assertContains($type, $allowed, "Unexpected action type: {$type}");
        }
    }

    // ── AC: continue_building reports simplification_risk + capability_gaps ───

    public function test_low_overlap_with_material_gaps_continues_building(): void
    {
        $r = $this->eval(['overlap_score' => 0.1, 'capability_gap_count' => 5]);

        $this->assertSame(AtlasExternalBrainConsolidationFirstCircuitBreaker::DECISION_CONTINUE, $r['decision']);
        $this->assertSame(5, $r['capability_gaps']);
    }

    public function test_continue_building_reports_simplification_risk(): void
    {
        $r = $this->eval(['overlap_score' => 0.2, 'capability_gap_count' => 3]);

        $this->assertIsFloat($r['simplification_risk']);
        $this->assertGreaterThanOrEqual(0.0, $r['simplification_risk']);
        $this->assertLessThanOrEqual(1.0, $r['simplification_risk']);
    }

    // ── Simplification risk ───────────────────────────────────────────────────

    public function test_all_signals_maxed_gives_high_risk(): void
    {
        $r = $this->eval([
            'overlap_score'                  => 1.0,
            'class_growth_count'             => 200,
            'duplicate_capability_names'     => range(1, 10),
            'shallow_scaffold_ratio'         => 1.0,
            'unresolved_simplification_debt' => 10,
        ]);

        $this->assertEqualsWithDelta(1.0, $r['simplification_risk'], 0.0001);
    }

    public function test_zero_signals_gives_zero_risk(): void
    {
        $r = $this->eval([]);

        $this->assertEqualsWithDelta(0.0, $r['simplification_risk'], 0.0001);
    }

    // ── Triggers ─────────────────────────────────────────────────────────────

    public function test_triggers_list_overlapping_signals(): void
    {
        $r = $this->eval(['overlap_score' => 0.6, 'class_growth_count' => 40]);

        $this->assertContains('overlap_exceeded', $r['triggers']);
        $this->assertContains('class_growth_exceeded', $r['triggers']);
    }

    public function test_triggers_empty_when_continuing(): void
    {
        $r = $this->eval(['overlap_score' => 0.1]);

        $this->assertSame([], $r['triggers']);
    }

    // ── Custom thresholds ─────────────────────────────────────────────────────

    public function test_custom_thresholds_respected(): void
    {
        // Default overlap threshold 0.4; use 0.9 → overlap 0.6 won't trigger
        $r = $this->eval([
            'overlap_score'       => 0.6,
            'class_growth_count'  => 40,
            'overlap_threshold'   => 0.9,
            'growth_threshold'    => 100,
        ]);

        $this->assertSame(AtlasExternalBrainConsolidationFirstCircuitBreaker::DECISION_CONTINUE, $r['decision']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $m = ['overlap_score' => 0.5, 'class_growth_count' => 35, 'capability_gap_count' => 2];

        $this->assertSame(json_encode($this->eval($m)), json_encode($this->eval($m)));
    }
}
