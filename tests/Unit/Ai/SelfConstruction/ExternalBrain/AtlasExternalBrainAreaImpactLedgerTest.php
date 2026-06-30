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
        foreach ([
            'area', 'capability_gain', 'observability_gain', 'scaffolding_risk',
            'total_tasks', 'integration_evidence', 'volume_without_evidence',
            'backlog_pressure', 'compound_impact_score', 'impact_rank',
            'maturity_band', 'risk_level', 'owner_signal', 'next_structural_lever',
            'evidence_age_days', 'evidence_freshness',
        ] as $k) {
            $this->assertArrayHasKey($k, $area, "Missing field: {$k}");
        }
    }

    // ── evidence freshness / staleness ─────────────────────────────────────────

    public function test_evidence_freshness_unknown_when_no_evidence_age_supplied(): void
    {
        $result = $this->ledger->aggregate(['samples' => [$this->sample(['area' => 'noage'])]]);

        $this->assertSame('unknown', $result['areas']['noage']['evidence_freshness']);
        $this->assertNull($result['areas']['noage']['evidence_age_days']);
    }

    public function test_evidence_freshness_fresh_when_age_within_threshold(): void
    {
        $result = $this->ledger->aggregate(['samples' => [
            $this->sample(['area' => 'fresh_area', 'evidence_age_days' => 5]),
        ]]);

        $this->assertSame('fresh', $result['areas']['fresh_area']['evidence_freshness']);
        $this->assertSame(5, $result['areas']['fresh_area']['evidence_age_days']);
    }

    public function test_evidence_freshness_stale_when_age_exceeds_threshold(): void
    {
        $result = $this->ledger->aggregate(['samples' => [
            $this->sample(['area' => 'stale_area', 'evidence_age_days' => 45]),
        ]]);

        $this->assertSame('stale', $result['areas']['stale_area']['evidence_freshness']);
        $this->assertSame(45, $result['areas']['stale_area']['evidence_age_days']);
    }

    public function test_evidence_age_takes_the_max_across_samples_in_the_same_area(): void
    {
        $result = $this->ledger->aggregate(['samples' => [
            $this->sample(['area' => 'mixed', 'evidence_age_days' => 5]),
            $this->sample(['area' => 'mixed', 'evidence_age_days' => 50]),
        ]]);

        $this->assertSame(50, $result['areas']['mixed']['evidence_age_days']);
        $this->assertSame('stale', $result['areas']['mixed']['evidence_freshness']);
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
        $this->assertSame(AtlasExternalBrainAreaImpactLedger::ACTION_REVIEW_SCAFFOLDING, $result['areas']['loop']['next_structural_lever']);
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

    // ── next_structural_lever ─────────────────────────────────────────────────

    public function test_lever_invest_when_capability_gain_3_or_more(): void
    {
        $result = $this->ledger->aggregate(['samples' => [
            $this->sample(['value_class' => 'real_capability', 'integration_evidence' => true]),
            $this->sample(['value_class' => 'real_capability', 'integration_evidence' => true]),
            $this->sample(['value_class' => 'real_capability', 'integration_evidence' => true]),
        ]]);

        $this->assertSame(AtlasExternalBrainAreaImpactLedger::ACTION_INVEST, $result['areas']['loop']['next_structural_lever']);
    }

    public function test_lever_review_scaffolding_when_scaffolding_dominates(): void
    {
        $result = $this->ledger->aggregate(['samples' => [
            $this->sample(['value_class' => 'scaffolding', 'integration_evidence' => false]),
            $this->sample(['value_class' => 'scaffolding', 'integration_evidence' => false]),
            $this->sample(['value_class' => 'observability', 'integration_evidence' => false]),
        ]]);

        $this->assertSame(AtlasExternalBrainAreaImpactLedger::ACTION_REVIEW_SCAFFOLDING, $result['areas']['loop']['next_structural_lever']);
    }

    public function test_lever_investigate_when_volume_without_evidence_and_no_scaffolding_risk(): void
    {
        $result = $this->ledger->aggregate(['samples' => [
            $this->sample(['value_class' => 'observability', 'integration_evidence' => false]),
            $this->sample(['value_class' => 'observability', 'integration_evidence' => false]),
            $this->sample(['value_class' => 'observability', 'integration_evidence' => false]),
        ]]);

        $this->assertTrue($result['areas']['loop']['volume_without_evidence']);
        $this->assertSame(AtlasExternalBrainAreaImpactLedger::ACTION_INVESTIGATE, $result['areas']['loop']['next_structural_lever']);
    }

    public function test_lever_observe_when_observability_dominates(): void
    {
        $result = $this->ledger->aggregate(['samples' => [
            $this->sample(['value_class' => 'observability', 'integration_evidence' => false]),
            $this->sample(['value_class' => 'observability', 'integration_evidence' => false]),
            $this->sample(['value_class' => 'real_capability', 'integration_evidence' => true]),
        ]]);

        $this->assertSame(AtlasExternalBrainAreaImpactLedger::ACTION_OBSERVE, $result['areas']['loop']['next_structural_lever']);
    }

    public function test_lever_consolidate_when_consolidation_count_dominates(): void
    {
        $result = $this->ledger->aggregate(['samples' => [
            $this->sample(['value_class' => 'consolidation', 'integration_evidence' => false]),
            $this->sample(['value_class' => 'consolidation', 'integration_evidence' => false]),
            $this->sample(['value_class' => 'real_capability', 'integration_evidence' => true]),
        ]]);

        $this->assertSame(AtlasExternalBrainAreaImpactLedger::ACTION_CONSOLIDATE, $result['areas']['loop']['next_structural_lever']);
    }

    public function test_lever_monitor_by_default(): void
    {
        $result = $this->ledger->aggregate(['samples' => [
            $this->sample(['value_class' => 'real_capability', 'integration_evidence' => true]),
        ]]);

        $this->assertSame(AtlasExternalBrainAreaImpactLedger::ACTION_MONITOR, $result['areas']['loop']['next_structural_lever']);
    }

    // ── maturity_band ─────────────────────────────────────────────────────────

    public function test_maturity_band_mature_when_high_capability_with_evidence(): void
    {
        $samples = array_fill(0, 3, $this->sample(['value_class' => 'real_capability', 'integration_evidence' => true]));
        $result = $this->ledger->aggregate(['samples' => $samples]);

        $this->assertSame('mature', $result['areas']['loop']['maturity_band']);
    }

    public function test_maturity_band_developing_when_some_capability_with_evidence(): void
    {
        $result = $this->ledger->aggregate(['samples' => [
            $this->sample(['value_class' => 'real_capability', 'integration_evidence' => true]),
        ]]);

        $this->assertSame('developing', $result['areas']['loop']['maturity_band']);
    }

    public function test_maturity_band_stagnant_when_volume_without_evidence(): void
    {
        $result = $this->ledger->aggregate(['samples' => [
            $this->sample(['value_class' => 'observability', 'integration_evidence' => false]),
            $this->sample(['value_class' => 'observability', 'integration_evidence' => false]),
            $this->sample(['value_class' => 'observability', 'integration_evidence' => false]),
        ]]);

        $this->assertSame('stagnant', $result['areas']['loop']['maturity_band']);
    }

    public function test_maturity_band_emerging_by_default(): void
    {
        $result = $this->ledger->aggregate(['samples' => [
            $this->sample(['value_class' => 'observability', 'integration_evidence' => false]),
        ]]);

        $this->assertSame('emerging', $result['areas']['loop']['maturity_band']);
    }

    // ── risk_level ────────────────────────────────────────────────────────────

    public function test_risk_level_high_when_scaffolding_dominates(): void
    {
        $result = $this->ledger->aggregate(['samples' => [
            $this->sample(['value_class' => 'scaffolding', 'integration_evidence' => false]),
            $this->sample(['value_class' => 'scaffolding', 'integration_evidence' => false]),
        ]]);

        $this->assertSame('high', $result['areas']['loop']['risk_level']);
    }

    public function test_risk_level_medium_when_some_scaffolding_but_not_dominant(): void
    {
        $result = $this->ledger->aggregate(['samples' => [
            $this->sample(['value_class' => 'real_capability', 'integration_evidence' => true]),
            $this->sample(['value_class' => 'real_capability', 'integration_evidence' => true]),
            $this->sample(['value_class' => 'scaffolding', 'integration_evidence' => false]),
        ]]);

        $this->assertSame('medium', $result['areas']['loop']['risk_level']);
    }

    public function test_risk_level_low_when_no_scaffolding(): void
    {
        $result = $this->ledger->aggregate(['samples' => [
            $this->sample(['value_class' => 'real_capability', 'integration_evidence' => true]),
        ]]);

        $this->assertSame('low', $result['areas']['loop']['risk_level']);
    }

    // ── owner_signal ──────────────────────────────────────────────────────────

    public function test_owner_signal_proven_when_evidence_and_capability(): void
    {
        $result = $this->ledger->aggregate(['samples' => [
            $this->sample(['value_class' => 'real_capability', 'integration_evidence' => true]),
        ]]);

        $this->assertSame('proven', $result['areas']['loop']['owner_signal']);
    }

    public function test_owner_signal_claimed_when_evidence_but_no_capability(): void
    {
        $result = $this->ledger->aggregate(['samples' => [
            $this->sample(['value_class' => 'observability', 'integration_evidence' => true]),
        ]]);

        $this->assertSame('claimed', $result['areas']['loop']['owner_signal']);
    }

    public function test_owner_signal_unowned_when_no_evidence(): void
    {
        $result = $this->ledger->aggregate(['samples' => [
            $this->sample(['value_class' => 'observability', 'integration_evidence' => false]),
        ]]);

        $this->assertSame('unowned', $result['areas']['loop']['owner_signal']);
    }

    // ── backlog_pressure ──────────────────────────────────────────────────────

    public function test_backlog_pressure_zero_when_only_proven_capability(): void
    {
        $result = $this->ledger->aggregate(['samples' => [
            $this->sample(['value_class' => 'real_capability', 'integration_evidence' => true]),
        ]]);

        $this->assertSame(0.0, $result['areas']['loop']['backlog_pressure']);
    }

    public function test_backlog_pressure_high_when_mostly_scaffolding(): void
    {
        $result = $this->ledger->aggregate(['samples' => [
            $this->sample(['value_class' => 'scaffolding', 'integration_evidence' => false]),
            $this->sample(['value_class' => 'scaffolding', 'integration_evidence' => false]),
            $this->sample(['value_class' => 'real_capability', 'integration_evidence' => true]),
        ]]);

        $this->assertGreaterThan(0.5, $result['areas']['loop']['backlog_pressure']);
    }

    // ── compound_impact_score and ranking (AC3) ───────────────────────────────

    public function test_capable_area_ranks_above_scaffolded_area(): void
    {
        $result = $this->ledger->aggregate(['samples' => [
            $this->sample(['area' => 'capable',  'value_class' => 'real_capability', 'integration_evidence' => true,  'task_count' => 3]),
            $this->sample(['area' => 'scaffold', 'value_class' => 'scaffolding',     'integration_evidence' => false, 'task_count' => 3]),
        ]]);

        $this->assertLessThan(
            $result['areas']['scaffold']['impact_rank'],
            $result['areas']['capable']['impact_rank'],
            'capable area must have a lower (better) rank number than scaffold-heavy area',
        );
    }

    public function test_impact_rank_starts_at_one(): void
    {
        $result = $this->ledger->aggregate(['samples' => [$this->sample()]]);

        $this->assertSame(1, $result['areas']['loop']['impact_rank']);
    }

    // ── next_leverage_candidate (AC2) ────────────────────────────────────────

    public function test_next_leverage_candidate_is_null_when_no_areas(): void
    {
        $result = $this->ledger->aggregate(['samples' => []]);

        $this->assertNull($result['next_leverage_candidate']);
    }

    public function test_next_leverage_candidate_points_to_top_nonmonitor_area(): void
    {
        $result = $this->ledger->aggregate(['samples' => [
            // 'risky' has scaffolding_risk≥2, capability_gain=0 → review_scaffolding lever
            $this->sample(['area' => 'risky', 'value_class' => 'scaffolding', 'integration_evidence' => false, 'task_count' => 2]),
            // 'growing' has only 1 capability → monitor lever
            $this->sample(['area' => 'growing', 'value_class' => 'real_capability', 'integration_evidence' => true]),
        ]]);

        $candidate = $result['next_leverage_candidate'];
        $this->assertNotNull($candidate);
        $this->assertNotSame(
            AtlasExternalBrainAreaImpactLedger::ACTION_MONITOR,
            $result['areas'][$candidate]['next_structural_lever'],
            'next_leverage_candidate must not point to a monitor area when a non-monitor area exists',
        );
    }

    // ── Multi-area isolation ──────────────────────────────────────────────────

    public function test_samples_are_isolated_per_area(): void
    {
        $result = $this->ledger->aggregate(['samples' => [
            $this->sample(['area' => 'loop',    'value_class' => 'real_capability', 'integration_evidence' => true]),
            $this->sample(['area' => 'maestro', 'value_class' => 'scaffolding',     'integration_evidence' => false]),
        ]]);

        $this->assertSame(2, $result['area_count']);
        $this->assertSame(1, $result['areas']['loop']['capability_gain']);
        $this->assertSame(0, $result['areas']['maestro']['capability_gain']);
        $this->assertSame(1, $result['areas']['maestro']['scaffolding_risk']);
    }
}
