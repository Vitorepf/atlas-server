<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainRankedEvolutionOperatingPolicy;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainRankedEvolutionOperatingPolicyTest extends TestCase
{
    private function policy(): AtlasExternalBrainRankedEvolutionOperatingPolicy
    {
        return new AtlasExternalBrainRankedEvolutionOperatingPolicy();
    }

    private function candidate(array $overrides = []): array
    {
        return array_merge([
            'id'             => 'c-default',
            'category'       => 'task_fabric_brutal_value',
            'impact'         => 0.70,
            'risk'           => 0.20,
            'give_back_risk' => 0.10,
            'is_template_farm' => false,
            'is_duplicate'   => false,
            'is_proxy'       => false,
            'is_saturated'   => false,
        ], $overrides);
    }

    // ── output shape ──────────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->policy()->rank([]);

        $this->assertSame(AtlasExternalBrainRankedEvolutionOperatingPolicy::SCHEMA, $result['schema']);
    }

    public function test_output_has_required_keys(): void
    {
        $result = $this->policy()->rank([]);

        foreach (['schema', 'ordered_decisions', 'summary'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        foreach (['admitted', 'rejected', 'top_blocker_tier', 'top_blocker_category'] as $k) {
            $this->assertArrayHasKey($k, $result['summary']);
        }
    }

    public function test_empty_input_yields_zero_counts(): void
    {
        $result = $this->policy()->rank([]);

        $this->assertSame(0, $result['summary']['admitted']);
        $this->assertSame(0, $result['summary']['rejected']);
        $this->assertSame([], $result['ordered_decisions']);
    }

    // ── AC3: rejection checks ────────────────────────────────────────────────

    public function test_template_farm_is_rejected(): void
    {
        $result = $this->policy()->rank([$this->candidate(['is_template_farm' => true])]);

        $d = $result['ordered_decisions'][0];
        $this->assertSame('reject', $d['decision']);
        $this->assertContains('template_farm', $d['rejection_reasons']);
    }

    public function test_duplicate_is_rejected(): void
    {
        $result = $this->policy()->rank([$this->candidate(['is_duplicate' => true])]);

        $d = $result['ordered_decisions'][0];
        $this->assertSame('reject', $d['decision']);
        $this->assertContains('duplicate', $d['rejection_reasons']);
    }

    public function test_proxy_is_rejected(): void
    {
        $result = $this->policy()->rank([$this->candidate(['is_proxy' => true])]);

        $d = $result['ordered_decisions'][0];
        $this->assertSame('reject', $d['decision']);
        $this->assertContains('proxy', $d['rejection_reasons']);
    }

    public function test_high_give_back_risk_is_rejected(): void
    {
        $result = $this->policy()->rank([$this->candidate(['give_back_risk' => 0.70])]);

        $d = $result['ordered_decisions'][0];
        $this->assertSame('reject', $d['decision']);
        $this->assertContains('give_back_risk_high', $d['rejection_reasons']);
    }

    public function test_give_back_risk_below_ceiling_is_admitted(): void
    {
        $result = $this->policy()->rank([$this->candidate(['give_back_risk' => 0.69])]);

        $this->assertSame('admit', $result['ordered_decisions'][0]['decision']);
    }

    public function test_multiple_rejection_reasons_accumulate(): void
    {
        $result = $this->policy()->rank([$this->candidate([
            'is_template_farm' => true,
            'is_duplicate'     => true,
            'give_back_risk'   => 0.90,
        ])]);

        $reasons = $result['ordered_decisions'][0]['rejection_reasons'];
        $this->assertContains('template_farm',      $reasons);
        $this->assertContains('duplicate',          $reasons);
        $this->assertContains('give_back_risk_high', $reasons);
    }

    public function test_valid_candidate_is_admitted_with_no_rejection_reasons(): void
    {
        $result = $this->policy()->rank([$this->candidate()]);

        $d = $result['ordered_decisions'][0];
        $this->assertSame('admit', $d['decision']);
        $this->assertSame([], $d['rejection_reasons']);
    }

    // ── AC2: tier ordering and blocker constraint ─────────────────────────────

    public function test_tier1_candidate_is_placed_before_tier8(): void
    {
        $result = $this->policy()->rank([
            $this->candidate(['id' => 'tier8', 'category' => 'stop_go_autonomy',         'impact' => 0.99]),
            $this->candidate(['id' => 'tier1', 'category' => 'task_fabric_brutal_value', 'impact' => 0.50]),
        ]);

        $admitted = array_filter($result['ordered_decisions'], fn ($d) => $d['decision'] === 'admit');
        $admitted = array_values($admitted);
        $this->assertSame('tier1', $admitted[0]['id']);
        $this->assertSame('tier8', $admitted[1]['id']);
    }

    public function test_high_impact_tier8_does_not_outrank_unresolved_tier1(): void
    {
        // tier-1 is unresolved (is_saturated=false) — tier-8 must not precede it
        $result = $this->policy()->rank([
            $this->candidate(['id' => 'heavy-tier8', 'category' => 'stop_go_autonomy',         'impact' => 0.99]),
            $this->candidate(['id' => 'light-tier1', 'category' => 'task_fabric_brutal_value', 'impact' => 0.10, 'is_saturated' => false]),
        ]);

        $admitted = array_values(array_filter($result['ordered_decisions'], fn ($d) => $d['decision'] === 'admit'));
        $this->assertSame('light-tier1', $admitted[0]['id']);
        $this->assertSame('heavy-tier8', $admitted[1]['id']);
    }

    public function test_unresolved_tier1_adds_why_blocked_by_to_lower_tiers(): void
    {
        $result = $this->policy()->rank([
            $this->candidate(['id' => 'tier1', 'category' => 'task_fabric_brutal_value', 'is_saturated' => false]),
            $this->candidate(['id' => 'tier8', 'category' => 'stop_go_autonomy']),
        ]);

        $admitted = array_values(array_filter($result['ordered_decisions'], fn ($d) => $d['decision'] === 'admit'));
        $tier8Entry = array_values(array_filter($admitted, fn ($d) => $d['id'] === 'tier8'))[0];

        $this->assertNotNull($tier8Entry['why_blocked_by']);
        $this->assertStringContainsString('tier-1', $tier8Entry['why_blocked_by']);
        $this->assertStringContainsString('task_fabric_brutal_value', $tier8Entry['why_blocked_by']);
    }

    public function test_tier1_saturated_allows_lower_tiers_to_proceed_without_blocker_note(): void
    {
        // is_saturated=true on tier-1: tier-8 is free to proceed without a why_blocked_by from tier-1
        $result = $this->policy()->rank([
            $this->candidate(['id' => 'tier1', 'category' => 'task_fabric_brutal_value', 'is_saturated' => true]),
            $this->candidate(['id' => 'tier8', 'category' => 'stop_go_autonomy',         'is_saturated' => false]),
        ]);

        $admitted = array_values(array_filter($result['ordered_decisions'], fn ($d) => $d['decision'] === 'admit'));
        $tier8Entry = array_values(array_filter($admitted, fn ($d) => $d['id'] === 'tier8'))[0];

        // tier-1 is saturated, so tier-8 should have null why_blocked_by
        $this->assertNull($tier8Entry['why_blocked_by']);
    }

    public function test_top_candidate_never_has_why_blocked_by(): void
    {
        $result = $this->policy()->rank([
            $this->candidate(['id' => 'tier1', 'category' => 'task_fabric_brutal_value']),
        ]);

        $this->assertNull($result['ordered_decisions'][0]['why_blocked_by']);
    }

    // ── same-tier ordering (impact DESC) ─────────────────────────────────────

    public function test_within_same_tier_impact_desc_ordering(): void
    {
        $result = $this->policy()->rank([
            $this->candidate(['id' => 'low',  'category' => 'stop_go_autonomy', 'impact' => 0.30]),
            $this->candidate(['id' => 'high', 'category' => 'stop_go_autonomy', 'impact' => 0.90]),
            $this->candidate(['id' => 'mid',  'category' => 'stop_go_autonomy', 'impact' => 0.60]),
        ]);

        $admitted = array_values(array_filter($result['ordered_decisions'], fn ($d) => $d['decision'] === 'admit'));
        $this->assertSame('high', $admitted[0]['id']);
        $this->assertSame('mid',  $admitted[1]['id']);
        $this->assertSame('low',  $admitted[2]['id']);
    }

    // ── summary counts ────────────────────────────────────────────────────────

    public function test_summary_counts_match_decisions(): void
    {
        $result = $this->policy()->rank([
            $this->candidate(['id' => 'ok1']),
            $this->candidate(['id' => 'ok2', 'category' => 'stop_go_autonomy']),
            $this->candidate(['id' => 'bad', 'is_template_farm' => true]),
        ]);

        $this->assertSame(2, $result['summary']['admitted']);
        $this->assertSame(1, $result['summary']['rejected']);
    }

    public function test_summary_top_blocker_tier_reflects_lowest_unresolved_admitted_tier(): void
    {
        $result = $this->policy()->rank([
            $this->candidate(['category' => 'muscle_outcome_learning', 'is_saturated' => false]),
        ]);

        $this->assertSame(2, $result['summary']['top_blocker_tier']);
        $this->assertSame('muscle_outcome_learning', $result['summary']['top_blocker_category']);
    }

    public function test_summary_top_blocker_null_when_all_saturated(): void
    {
        $result = $this->policy()->rank([
            $this->candidate(['category' => 'task_fabric_brutal_value', 'is_saturated' => true]),
        ]);

        $this->assertNull($result['summary']['top_blocker_tier']);
    }

    // ── position field ────────────────────────────────────────────────────────

    public function test_admitted_candidates_have_sequential_position(): void
    {
        $result = $this->policy()->rank([
            $this->candidate(['id' => 'a', 'category' => 'task_fabric_brutal_value']),
            $this->candidate(['id' => 'b', 'category' => 'stop_go_autonomy']),
        ]);

        $admitted = array_values(array_filter($result['ordered_decisions'], fn ($d) => $d['decision'] === 'admit'));
        $this->assertSame(1, $admitted[0]['position']);
        $this->assertSame(2, $admitted[1]['position']);
    }

    // ── determinism ──────────────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $candidates = [
            $this->candidate(['id' => 'x', 'category' => 'queue_self_healing']),
            $this->candidate(['id' => 'y', 'category' => 'model_amplifier']),
        ];

        $this->assertSame(
            json_encode($this->policy()->rank($candidates)),
            json_encode($this->policy()->rank($candidates)),
        );
    }
}
