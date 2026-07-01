<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainModelQualitySloLedger;
use Tests\TestCase;

final class AtlasExternalBrainModelQualitySloLedgerTest extends TestCase
{
    private function svc(): AtlasExternalBrainModelQualitySloLedger
    {
        return new AtlasExternalBrainModelQualitySloLedger;
    }

    private function row(
        string $tier,
        string $variant,
        string $class,
        int $sample,
        float $commit = 0.80,
        float $giveBack = 0.10,
        float $valueProof = 0.80,
        float $duplicate = 0.05,
        float $evidence = 0.80,
    ): array {
        return [
            'model_tier' => $tier,
            'scaffold_variant' => $variant,
            'task_class' => $class,
            'sample_size' => $sample,
            'commit_success_rate' => $commit,
            'give_back_rate' => $giveBack,
            'value_proof_rate' => $valueProof,
            'duplicate_rate' => $duplicate,
            'evidence_strength' => $evidence,
        ];
    }

    private function compute(array $rows): array
    {
        return $this->svc()->compute(['outcome_rows' => $rows]);
    }

    // ── green segment ──────────────────────────────────────────────────────────

    public function test_all_slos_passing_gives_green_status(): void
    {
        $r = $this->compute([$this->row('small', 'v1', 'refactor', 20)]);

        $this->assertSame('green', $r['slo_rows'][0]['status']);
        $this->assertSame([], $r['slo_rows'][0]['failing_slos']);
        $this->assertSame([], $r['failing_segments']);
    }

    // ── red segment ────────────────────────────────────────────────────────────

    public function test_commit_below_threshold_gives_red(): void
    {
        $r = $this->compute([$this->row('small', 'v1', 'refactor', 15, commit: 0.60)]);

        $this->assertSame('red', $r['slo_rows'][0]['status']);
        $this->assertContains('commit_success_rate', $r['slo_rows'][0]['failing_slos']);
        $this->assertContains('small:v1:refactor', $r['failing_segments']);
    }

    public function test_give_back_above_threshold_gives_red(): void
    {
        $r = $this->compute([$this->row('small', 'v1', 'refactor', 15, giveBack: 0.25)]);

        $this->assertContains('give_back_rate', $r['slo_rows'][0]['failing_slos']);
    }

    public function test_duplicate_rate_above_threshold_gives_red(): void
    {
        $r = $this->compute([$this->row('small', 'v1', 'refactor', 15, duplicate: 0.15)]);

        $this->assertContains('duplicate_rate', $r['slo_rows'][0]['failing_slos']);
    }

    // ── insufficient_evidence ─────────────────────────────────────────────────

    public function test_sample_below_minimum_is_insufficient_not_green(): void
    {
        $r = $this->compute([$this->row('small', 'v1', 'refactor', 5)]);

        $this->assertSame('insufficient_evidence', $r['slo_rows'][0]['status']);
        $this->assertSame([], $r['failing_segments']);
        $this->assertCount(1, $r['insufficient_segments']);
    }

    public function test_exact_min_sample_is_not_insufficient(): void
    {
        $r = $this->compute([$this->row('small', 'v1', 'refactor', 10)]);

        $this->assertNotSame('insufficient_evidence', $r['slo_rows'][0]['status']);
        $this->assertSame([], $r['insufficient_segments']);
    }

    // ── multiple segments ──────────────────────────────────────────────────────

    public function test_multiple_segments_tracked_independently(): void
    {
        $r = $this->compute([
            $this->row('small', 'v1', 'refactor', 20),              // green
            $this->row('frontier', 'v2', 'feature', 15, commit: 0.50),  // red
            $this->row('mid', 'v3', 'fix', 3),                      // insufficient
        ]);

        $statuses = array_column($r['slo_rows'], 'status');
        $this->assertContains('green', $statuses);
        $this->assertContains('red', $statuses);
        $this->assertContains('insufficient_evidence', $statuses);

        $this->assertCount(1, $r['failing_segments']);
        $this->assertCount(1, $r['insufficient_segments']);
    }

    // ── routing adjustments ───────────────────────────────────────────────────

    public function test_red_segment_gets_routing_adjustment(): void
    {
        $r = $this->compute([$this->row('small', 'v1', 'refactor', 15, commit: 0.60)]);

        $this->assertCount(1, $r['recommended_routing_adjustments']);
        $adj = $r['recommended_routing_adjustments'][0];
        $this->assertSame('small:v1:refactor', $adj['segment']);
        $this->assertArrayHasKey('adjustment', $adj);
    }

    public function test_three_or_more_failing_slos_recommends_downgrade(): void
    {
        // Fail commit, give_back, value_proof, duplicate → 4 failures
        $r = $this->compute([
            $this->row('small', 'v1', 'refactor', 15,
                commit: 0.50, giveBack: 0.30, valueProof: 0.40, duplicate: 0.20),
        ]);

        $this->assertSame('downgrade_tier', $r['recommended_routing_adjustments'][0]['adjustment']);
    }

    public function test_fewer_than_three_failing_slos_recommends_extra_validation(): void
    {
        $r = $this->compute([$this->row('small', 'v1', 'refactor', 15, commit: 0.60)]);

        $this->assertSame('add_extra_validation', $r['recommended_routing_adjustments'][0]['adjustment']);
    }

    // ── empty + schema ─────────────────────────────────────────────────────────

    public function test_empty_rows_returns_empty_output(): void
    {
        $r = $this->svc()->compute([]);

        $this->assertSame([], $r['slo_rows']);
        $this->assertSame([], $r['failing_segments']);
        $this->assertSame([], $r['insufficient_segments']);
        $this->assertSame([], $r['recommended_routing_adjustments']);
    }

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->compute([]);

        $this->assertSame(AtlasExternalBrainModelQualitySloLedger::SCHEMA, $r['schema_version']);
    }

    // ── AC1: task_family, quality_floor, minimum_evidence, escalation_threshold, observed_quality ──

    public function test_row_carries_task_family_quality_floor_minimum_evidence_escalation_threshold(): void
    {
        $row = array_merge($this->row('small', 'v1', 'refactor', 20), [
            'task_family' => 'engineering_refactor',
            'quality_floor' => 0.75,
            'minimum_evidence' => 8,
            'escalation_threshold' => 2,
        ]);

        $r = $this->compute([$row]);
        $entry = $r['slo_rows'][0];

        $this->assertSame('engineering_refactor', $entry['task_family']);
        $this->assertSame(0.75, $entry['quality_floor']);
        $this->assertSame(8, $entry['minimum_evidence']);
        $this->assertSame(2, $entry['escalation_threshold']);
        $this->assertArrayHasKey('observed_quality', $entry);
    }

    public function test_task_family_defaults_to_task_class_when_absent(): void
    {
        $r = $this->compute([$this->row('small', 'v1', 'refactor', 20)]);

        $this->assertSame('refactor', $r['slo_rows'][0]['task_family']);
    }

    public function test_observed_quality_computed_from_normalized_slo_metrics_when_absent(): void
    {
        $r = $this->compute([$this->row('small', 'v1', 'refactor', 20,
            commit: 1.0, giveBack: 0.0, valueProof: 1.0, duplicate: 0.0, evidence: 1.0)]);

        $this->assertSame(1.0, $r['slo_rows'][0]['observed_quality']);
    }

    // ── AC2: enforcement_action — block / scaffold / escalate ──────────────────

    public function test_green_segment_has_null_enforcement_action(): void
    {
        $r = $this->compute([$this->row('small', 'v1', 'refactor', 20)]);

        $this->assertNull($r['slo_rows'][0]['enforcement_action']);
    }

    public function test_insufficient_evidence_segment_is_blocked(): void
    {
        $r = $this->compute([$this->row('small', 'v1', 'refactor', 5)]);

        $this->assertSame(AtlasExternalBrainModelQualitySloLedger::ENFORCEMENT_BLOCK, $r['slo_rows'][0]['enforcement_action']);
    }

    public function test_red_below_escalation_threshold_is_scaffolded(): void
    {
        $r = $this->compute([$this->row('small', 'v1', 'refactor', 15, commit: 0.60)]);

        $this->assertSame(AtlasExternalBrainModelQualitySloLedger::ENFORCEMENT_SCAFFOLD, $r['slo_rows'][0]['enforcement_action']);
    }

    public function test_red_at_or_above_escalation_threshold_is_escalated(): void
    {
        $r = $this->compute([$this->row('small', 'v1', 'refactor', 15,
            commit: 0.50, giveBack: 0.30, valueProof: 0.40, duplicate: 0.20)]);

        $this->assertSame(AtlasExternalBrainModelQualitySloLedger::ENFORCEMENT_ESCALATE, $r['slo_rows'][0]['enforcement_action']);
    }

    // ── AC3: rejects provider-name-only quality claims ──────────────────────────

    public function test_provider_name_only_claim_is_rejected_and_blocked_even_when_green(): void
    {
        $row = array_merge($this->row('small', 'v1', 'refactor', 20), [
            'quality_claim_basis' => 'provider_name_only',
        ]);

        $r = $this->compute([$row]);

        $this->assertSame('green', $r['slo_rows'][0]['status']);
        $this->assertSame(AtlasExternalBrainModelQualitySloLedger::ENFORCEMENT_BLOCK, $r['slo_rows'][0]['enforcement_action']);
        $this->assertCount(1, $r['rejected_provider_name_only_claims']);
        $this->assertSame('small:v1:refactor', $r['rejected_provider_name_only_claims'][0]['segment']);
    }

    public function test_accepted_quality_claim_basis_does_not_reject(): void
    {
        $row = array_merge($this->row('small', 'v1', 'refactor', 20), [
            'quality_claim_basis' => 'evidence',
        ]);

        $r = $this->compute([$row]);

        $this->assertSame([], $r['rejected_provider_name_only_claims']);
        $this->assertNull($r['slo_rows'][0]['enforcement_action']);
    }

    public function test_absent_quality_claim_basis_does_not_reject(): void
    {
        $r = $this->compute([$this->row('small', 'v1', 'refactor', 20)]);

        $this->assertSame([], $r['rejected_provider_name_only_claims']);
    }

    // ── AC2/AC4: degraded cost ─────────────────────────────────────────────────

    public function test_cost_above_ceiling_gives_red_with_routing_hint(): void
    {
        $row = array_merge($this->row('small', 'v1', 'refactor', 20), ['cost' => 5.0]);

        $r = $this->compute([$row]);

        $this->assertContains('cost', $r['slo_rows'][0]['failing_slos']);
        $this->assertSame('red', $r['slo_rows'][0]['status']);
        $this->assertArrayHasKey('routing_hint', $r['recommended_routing_adjustments'][0]);
        $this->assertNotEmpty($r['recommended_routing_adjustments'][0]['routing_hint']);
    }

    public function test_latency_above_ceiling_gives_red(): void
    {
        $row = array_merge($this->row('small', 'v1', 'refactor', 20), ['latency' => 500.0]);

        $r = $this->compute([$row]);

        $this->assertContains('latency', $r['slo_rows'][0]['failing_slos']);
    }

    // ── AC2/AC4: exhausted regression budget ──────────────────────────────────

    public function test_exhausted_regression_budget_gives_red(): void
    {
        $row = array_merge($this->row('small', 'v1', 'refactor', 20), ['regression_budget_remaining' => 0]);

        $r = $this->compute([$row]);

        $this->assertContains('regression_budget', $r['slo_rows'][0]['failing_slos']);
        $this->assertSame('red', $r['slo_rows'][0]['status']);
    }

    public function test_positive_regression_budget_does_not_fail(): void
    {
        $row = array_merge($this->row('small', 'v1', 'refactor', 20), ['regression_budget_remaining' => 5]);

        $r = $this->compute([$row]);

        $this->assertNotContains('regression_budget', $r['slo_rows'][0]['failing_slos']);
        $this->assertSame('green', $r['slo_rows'][0]['status']);
    }

    // ── AC3: routing_hint and evidence_refs on degraded segments ──────────────

    public function test_degraded_segment_carries_routing_hint_and_evidence_refs(): void
    {
        $row = array_merge($this->row('small', 'v1', 'refactor', 15, commit: 0.60), [
            'evidence_refs' => ['gate:phpunit_run_42'],
        ]);

        $r = $this->compute([$row]);
        $adj = $r['recommended_routing_adjustments'][0];

        $this->assertArrayHasKey('routing_hint', $adj);
        $this->assertNotEmpty($adj['routing_hint']);
        $this->assertArrayHasKey('evidence_refs', $adj);
        $this->assertContains('gate:phpunit_run_42', $adj['evidence_refs']);
        $this->assertContains('slo_ledger_segment:small:v1:refactor', $adj['evidence_refs']);
    }

    // ── AC4: task-family-specific SLO ─────────────────────────────────────────

    public function test_task_family_specific_quality_floor_is_independent_per_family(): void
    {
        $strict = array_merge($this->row('small', 'v1', 'refactor', 20), [
            'task_family' => 'security_sensitive',
            'quality_floor' => 0.95,
        ]);
        $lenient = array_merge($this->row('small', 'v1', 'docs', 20), [
            'task_family' => 'documentation',
            'quality_floor' => 0.50,
        ]);

        $r = $this->compute([$strict, $lenient]);

        $byFamily = [];
        foreach ($r['slo_rows'] as $row) {
            $byFamily[$row['task_family']] = $row;
        }

        $this->assertSame(0.95, $byFamily['security_sensitive']['quality_floor']);
        $this->assertSame(0.50, $byFamily['documentation']['quality_floor']);
    }

    // ── healthy SLO (all new dimensions healthy) ──────────────────────────────

    public function test_healthy_slo_with_cost_latency_and_regression_budget_reported(): void
    {
        $row = array_merge($this->row('small', 'v1', 'refactor', 20), [
            'cost' => 0.20,
            'latency' => 30.0,
            'regression_budget_remaining' => 10,
        ]);

        $r = $this->compute([$row]);

        $this->assertSame('green', $r['slo_rows'][0]['status']);
        $this->assertNull($r['slo_rows'][0]['enforcement_action']);
    }
}
