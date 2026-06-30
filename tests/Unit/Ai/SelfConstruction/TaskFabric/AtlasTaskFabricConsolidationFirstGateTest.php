<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricConsolidationFirstGate;
use Tests\TestCase;

final class AtlasTaskFabricConsolidationFirstGateTest extends TestCase
{
    private function gate(): AtlasTaskFabricConsolidationFirstGate
    {
        return new AtlasTaskFabricConsolidationFirstGate();
    }

    private function task(string $area, float $score = 0.8): array
    {
        return ['id' => 'task-'.$area, 'area' => $area, 'capability_score' => $score];
    }

    private function cleanDebt(): array
    {
        return [
            'duplicate_organs'      => 0,
            'orphaned_integrations' => 0,
            'backlog_cost'          => 0.0,
            'forecast_confidence'   => 1.0,
        ];
    }

    // ── schema + structure ────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->gate()->evaluate([], []);

        $this->assertSame(AtlasTaskFabricConsolidationFirstGate::SCHEMA, $result['schema']);
    }

    public function test_output_has_all_required_keys(): void
    {
        $result = $this->gate()->evaluate([$this->task('core')], ['core' => $this->cleanDebt()]);

        foreach (['schema', 'verdict', 'consolidation_triggers', 'reasons'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
    }

    // ── accept ────────────────────────────────────────────────────────────────

    public function test_clean_area_with_positive_capability_score_yields_accept(): void
    {
        $result = $this->gate()->evaluate(
            [$this->task('memory', 0.75)],
            ['memory' => $this->cleanDebt()],
        );

        $this->assertSame(AtlasTaskFabricConsolidationFirstGate::VERDICT_ACCEPT, $result['verdict']);
        $this->assertSame([], $result['consolidation_triggers']);
    }

    public function test_multiple_clean_areas_with_value_yields_accept(): void
    {
        $result = $this->gate()->evaluate(
            [$this->task('memory', 0.6), $this->task('cortex', 0.5)],
            ['memory' => $this->cleanDebt(), 'cortex' => $this->cleanDebt()],
        );

        $this->assertSame(AtlasTaskFabricConsolidationFirstGate::VERDICT_ACCEPT, $result['verdict']);
    }

    // ── consolidate_first: no value ───────────────────────────────────────────

    public function test_empty_batch_yields_consolidate_first(): void
    {
        $result = $this->gate()->evaluate([], []);

        $this->assertSame(AtlasTaskFabricConsolidationFirstGate::VERDICT_CONSOLIDATE_FIRST, $result['verdict']);
    }

    public function test_batch_with_zero_capability_scores_yields_consolidate_first(): void
    {
        $result = $this->gate()->evaluate(
            [['id' => 'x', 'area' => 'core', 'capability_score' => 0.0]],
            ['core' => $this->cleanDebt()],
        );

        $this->assertSame(AtlasTaskFabricConsolidationFirstGate::VERDICT_CONSOLIDATE_FIRST, $result['verdict']);
    }

    public function test_batch_with_missing_capability_score_yields_consolidate_first(): void
    {
        $result = $this->gate()->evaluate(
            [['id' => 'x', 'area' => 'core']],
            ['core' => $this->cleanDebt()],
        );

        $this->assertSame(AtlasTaskFabricConsolidationFirstGate::VERDICT_CONSOLIDATE_FIRST, $result['verdict']);
    }

    // ── consolidate_first: duplicate organs ───────────────────────────────────

    public function test_duplicate_organs_at_threshold_yields_consolidate_first(): void
    {
        $result = $this->gate()->evaluate(
            [$this->task('memory', 0.8)],
            ['memory' => array_merge($this->cleanDebt(), ['duplicate_organs' => 2])],
        );

        $this->assertSame(AtlasTaskFabricConsolidationFirstGate::VERDICT_CONSOLIDATE_FIRST, $result['verdict']);
        $this->assertContains('duplicate_organs:memory', $result['consolidation_triggers']);
    }

    public function test_duplicate_organs_below_threshold_does_not_trigger(): void
    {
        $result = $this->gate()->evaluate(
            [$this->task('memory', 0.8)],
            ['memory' => array_merge($this->cleanDebt(), ['duplicate_organs' => 1])],
        );

        $this->assertSame(AtlasTaskFabricConsolidationFirstGate::VERDICT_ACCEPT, $result['verdict']);
    }

    // ── consolidate_first: orphaned integrations ──────────────────────────────

    public function test_orphaned_integrations_at_threshold_yields_consolidate_first(): void
    {
        $result = $this->gate()->evaluate(
            [$this->task('core', 0.7)],
            ['core' => array_merge($this->cleanDebt(), ['orphaned_integrations' => 3])],
        );

        $this->assertSame(AtlasTaskFabricConsolidationFirstGate::VERDICT_CONSOLIDATE_FIRST, $result['verdict']);
        $this->assertContains('orphaned_integrations:core', $result['consolidation_triggers']);
    }

    public function test_orphaned_integrations_below_threshold_does_not_trigger(): void
    {
        $result = $this->gate()->evaluate(
            [$this->task('core', 0.7)],
            ['core' => array_merge($this->cleanDebt(), ['orphaned_integrations' => 2])],
        );

        $this->assertSame(AtlasTaskFabricConsolidationFirstGate::VERDICT_ACCEPT, $result['verdict']);
    }

    // ── consolidate_first: high backlog cost ──────────────────────────────────

    public function test_high_backlog_cost_at_threshold_yields_consolidate_first(): void
    {
        $result = $this->gate()->evaluate(
            [$this->task('fabric', 0.9)],
            ['fabric' => array_merge($this->cleanDebt(), ['backlog_cost' => 25.0])],
        );

        $this->assertSame(AtlasTaskFabricConsolidationFirstGate::VERDICT_CONSOLIDATE_FIRST, $result['verdict']);
        $this->assertContains('high_backlog_cost:fabric', $result['consolidation_triggers']);
    }

    public function test_backlog_cost_below_threshold_does_not_trigger(): void
    {
        $result = $this->gate()->evaluate(
            [$this->task('fabric', 0.9)],
            ['fabric' => array_merge($this->cleanDebt(), ['backlog_cost' => 24.9])],
        );

        $this->assertSame(AtlasTaskFabricConsolidationFirstGate::VERDICT_ACCEPT, $result['verdict']);
    }

    // ── consolidate_first: low forecast confidence ────────────────────────────

    public function test_low_forecast_confidence_yields_consolidate_first(): void
    {
        $result = $this->gate()->evaluate(
            [$this->task('brain', 0.8)],
            ['brain' => array_merge($this->cleanDebt(), ['forecast_confidence' => 0.35])],
        );

        $this->assertSame(AtlasTaskFabricConsolidationFirstGate::VERDICT_CONSOLIDATE_FIRST, $result['verdict']);
        $this->assertContains('low_forecast_confidence:brain', $result['consolidation_triggers']);
    }

    public function test_forecast_confidence_at_floor_yields_consolidate_first(): void
    {
        $result = $this->gate()->evaluate(
            [$this->task('brain', 0.8)],
            ['brain' => array_merge($this->cleanDebt(), ['forecast_confidence' => 0.39])],
        );

        $this->assertSame(AtlasTaskFabricConsolidationFirstGate::VERDICT_CONSOLIDATE_FIRST, $result['verdict']);
    }

    public function test_forecast_confidence_at_floor_exactly_still_triggers(): void
    {
        // 0.39 < 0.40 → trigger. 0.40 is the floor, below it is bad.
        $result = $this->gate()->evaluate(
            [$this->task('brain', 0.8)],
            ['brain' => array_merge($this->cleanDebt(), ['forecast_confidence' => 0.40])],
        );

        // 0.40 is exactly the floor — not below it, so no trigger.
        $this->assertSame(AtlasTaskFabricConsolidationFirstGate::VERDICT_ACCEPT, $result['verdict']);
    }

    // ── multiple triggers ──────────────────────────────────────────────────────

    public function test_multiple_triggers_all_appear_in_output(): void
    {
        $result = $this->gate()->evaluate(
            [$this->task('core', 0.8)],
            ['core' => [
                'duplicate_organs'      => 3,
                'orphaned_integrations' => 5,
                'backlog_cost'          => 30.0,
                'forecast_confidence'   => 0.2,
            ]],
        );

        $this->assertSame(AtlasTaskFabricConsolidationFirstGate::VERDICT_CONSOLIDATE_FIRST, $result['verdict']);
        $this->assertContains('duplicate_organs:core', $result['consolidation_triggers']);
        $this->assertContains('orphaned_integrations:core', $result['consolidation_triggers']);
        $this->assertContains('high_backlog_cost:core', $result['consolidation_triggers']);
        $this->assertContains('low_forecast_confidence:core', $result['consolidation_triggers']);
    }

    // ── area not in debt map ──────────────────────────────────────────────────

    public function test_area_missing_from_debt_map_uses_defaults_and_accepts(): void
    {
        // area_debt empty → all debt values default to clean → accept if value present
        $result = $this->gate()->evaluate(
            [$this->task('unknown_area', 0.6)],
            [],
        );

        $this->assertSame(AtlasTaskFabricConsolidationFirstGate::VERDICT_ACCEPT, $result['verdict']);
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_identical_input_produces_identical_output(): void
    {
        $batch = [$this->task('memory', 0.5)];
        $debt  = ['memory' => array_merge($this->cleanDebt(), ['duplicate_organs' => 2])];

        $this->assertSame(
            $this->gate()->evaluate($batch, $debt),
            $this->gate()->evaluate($batch, $debt),
        );
    }
}
