<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAreaImpactLedger;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainAreaImpactLedgerTest extends TestCase
{
    private AtlasExternalBrainAreaImpactLedger $ledger;

    protected function setUp(): void
    {
        $this->ledger = new AtlasExternalBrainAreaImpactLedger;
    }

    private function sample(array $overrides = []): array
    {
        return array_merge([
            'area'                => 'loop',
            'value_class'         => AtlasExternalBrainAreaImpactLedger::VALUE_REAL_CAPABILITY,
            'integration_evidence' => true,
            'task_count'          => 1,
        ], $overrides);
    }

    // ── Schema / envelope ────────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->ledger->aggregate(['samples' => [$this->sample()]]);

        foreach (['schema', 'areas', 'area_count', 'sample_count'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainAreaImpactLedger::SCHEMA, $result['schema']);
    }

    public function test_empty_samples_returns_zero_areas(): void
    {
        $result = $this->ledger->aggregate(['samples' => []]);

        $this->assertSame(0, $result['area_count']);
        $this->assertSame(0, $result['sample_count']);
        $this->assertSame([], $result['areas']);
    }

    public function test_area_entry_has_required_fields(): void
    {
        $result = $this->ledger->aggregate(['samples' => [$this->sample(['area' => 'maestro'])]]);

        $area = $result['areas']['maestro'];
        foreach (['area', 'capability_gain', 'observability_gain', 'scaffolding_risk', 'total_tasks', 'has_integration_evidence', 'volume_without_evidence', 'next_action'] as $k) {
            $this->assertArrayHasKey($k, $area, "Missing field: {$k}");
        }
    }

    // ── AC1: per-area aggregation ─────────────────────────────────────────────

    public function test_capability_gain_counts_real_capability_with_evidence(): void
    {
        $result = $this->ledger->aggregate(['samples' => [
            $this->sample(['area' => 'loop', 'value_class' => 'real_capability', 'integration_evidence' => true]),
            $this->sample(['area' => 'loop', 'value_class' => 'real_capability', 'integration_evidence' => true]),
        ]]);

        $this->assertSame(2, $result['areas']['loop']['capability_gain']);
    }

    public function test_observability_gain_counts_observability_class(): void
    {
        $result = $this->ledger->aggregate(['samples' => [
            $this->sample(['area' => 'loop', 'value_class' => 'observability', 'integration_evidence' => false]),
            $this->sample(['area' => 'loop', 'value_class' => 'observability', 'integration_evidence' => false]),
        ]]);

        $this->assertSame(2, $result['areas']['loop']['observability_gain']);
        $this->assertSame(0, $result['areas']['loop']['capability_gain']);
    }

    public function test_scaffolding_risk_counts_scaffolding_class(): void
    {
        $result = $this->ledger->aggregate(['samples' => [
            $this->sample(['area' => 'brain', 'value_class' => 'scaffolding', 'integration_evidence' => false]),
        ]]);

        $this->assertSame(1, $result['areas']['brain']['scaffolding_risk']);
    }

    // ── AC2: scaffolding NOT counted as capability_gain ───────────────────────

    public function test_scaffolding_never_counted_as_capability_gain(): void
    {
        $result = $this->ledger->aggregate(['samples' => [
            $this->sample(['value_class' => 'scaffolding', 'integration_evidence' => true]),
            $this->sample(['value_class' => 'scaffolding', 'integration_evidence' => false]),
        ]]);

        $this->assertSame(0, $result['areas']['loop']['capability_gain']);
        $this->assertSame(2, $result['areas']['loop']['scaffolding_risk']);
    }

    public function test_real_capability_without_integration_evidence_not_counted_as_capability_gain(): void
    {
        $result = $this->ledger->aggregate(['samples' => [
            $this->sample(['value_class' => 'real_capability', 'integration_evidence' => false]),
        ]]);

        // No integration evidence → treated as observability (unproven capability)
        $this->assertSame(0, $result['areas']['loop']['capability_gain']);
    }

    // ── AC2: volume_without_evidence flag ────────────────────────────────────

    public function test_volume_without_evidence_flagged_when_3_tasks_zero_capability(): void
    {
        $result = $this->ledger->aggregate(['samples' => [
            $this->sample(['value_class' => 'scaffolding', 'integration_evidence' => false]),
            $this->sample(['value_class' => 'scaffolding', 'integration_evidence' => false]),
            $this->sample(['value_class' => 'observability', 'integration_evidence' => false]),
        ]]);

        $this->assertTrue($result['areas']['loop']['volume_without_evidence']);
        $this->assertSame(AtlasExternalBrainAreaImpactLedger::ACTION_REVIEW_SCAFFOLDING, $result['areas']['loop']['next_action']);
    }

    public function test_volume_without_evidence_not_flagged_when_capability_exists(): void
    {
        $result = $this->ledger->aggregate(['samples' => [
            $this->sample(['value_class' => 'real_capability', 'integration_evidence' => true]),
            $this->sample(['value_class' => 'scaffolding']),
            $this->sample(['value_class' => 'scaffolding']),
        ]]);

        $this->assertFalse($result['areas']['loop']['volume_without_evidence']);
    }

    // ── next_action ───────────────────────────────────────────────────────────

    public function test_next_action_invest_when_capability_gain_3_or_more(): void
    {
        $result = $this->ledger->aggregate(['samples' => [
            $this->sample(['value_class' => 'real_capability', 'integration_evidence' => true]),
            $this->sample(['value_class' => 'real_capability', 'integration_evidence' => true]),
            $this->sample(['value_class' => 'real_capability', 'integration_evidence' => true]),
        ]]);

        $this->assertSame(AtlasExternalBrainAreaImpactLedger::ACTION_INVEST, $result['areas']['loop']['next_action']);
    }

    public function test_next_action_observe_when_observability_dominates(): void
    {
        $result = $this->ledger->aggregate(['samples' => [
            $this->sample(['value_class' => 'observability', 'integration_evidence' => false]),
            $this->sample(['value_class' => 'observability', 'integration_evidence' => false]),
            $this->sample(['value_class' => 'real_capability', 'integration_evidence' => true]),
        ]]);

        $this->assertSame(AtlasExternalBrainAreaImpactLedger::ACTION_OBSERVE, $result['areas']['loop']['next_action']);
    }

    public function test_next_action_monitor_by_default(): void
    {
        $result = $this->ledger->aggregate(['samples' => [
            $this->sample(['value_class' => 'real_capability', 'integration_evidence' => true]),
        ]]);

        $this->assertSame(AtlasExternalBrainAreaImpactLedger::ACTION_MONITOR, $result['areas']['loop']['next_action']);
    }

    // ── Multi-area isolation ──────────────────────────────────────────────────

    public function test_samples_are_isolated_per_area(): void
    {
        $result = $this->ledger->aggregate(['samples' => [
            $this->sample(['area' => 'loop',   'value_class' => 'real_capability', 'integration_evidence' => true]),
            $this->sample(['area' => 'maestro', 'value_class' => 'scaffolding',    'integration_evidence' => false]),
        ]]);

        $this->assertSame(2, $result['area_count']);
        $this->assertSame(1, $result['areas']['loop']['capability_gain']);
        $this->assertSame(0, $result['areas']['maestro']['capability_gain']);
        $this->assertSame(1, $result['areas']['maestro']['scaffolding_risk']);
    }
}
