<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmplifierComplexityBudget;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainAmplifierComplexityBudgetTest extends TestCase
{
    private function budget(): AtlasExternalBrainAmplifierComplexityBudget
    {
        return new AtlasExternalBrainAmplifierComplexityBudget;
    }

    private function comp(array $overrides = []): array
    {
        return array_merge([
            'id'              => 'c1',
            'type'            => 'scaffold',
            'usage_score'     => 0.80,
            'slo_met'         => true,
            'has_value_proof' => true,
        ], $overrides);
    }

    private function comps(int $n, string $type, array $overrides = []): array
    {
        $result = [];
        for ($i = 0; $i < $n; $i++) {
            $result[] = $this->comp(array_merge(['id' => "{$type}_$i", 'type' => $type], $overrides));
        }
        return $result;
    }

    // ── queue-debt complexity charge ────────────────────────────────────────────

    public function test_high_queue_debt_lowers_allowed_complexity_and_retires_component(): void
    {
        $result = $this->budget()->evaluate([
            'components' => [
                $this->comp(['prompt_length' => 1500, 'dependency_count' => 1, 'maintenance_cost' => 0.5, 'measured_lift' => 0.5]),
            ],
            'queue_debt' => [
                'blocked_count' => 40,
                'quarantined_count' => 10,
                'claimable_count' => 10,
            ],
        ]);

        $this->assertGreaterThan(1.0, $result['queue_debt_ratio']);
        $this->assertLessThan(1.0, $result['queue_debt_penalty_factor']);
        $this->assertSame('over_budget', $result['component_budget_evaluations'][0]['budget_status']);
        $this->assertSame('retire', $result['recommended_simplifications'][0]['recommendation']);
    }

    public function test_low_queue_debt_preserves_complexity_allowance_with_strong_proof(): void
    {
        $result = $this->budget()->evaluate([
            'components' => [
                $this->comp(['prompt_length' => 100, 'dependency_count' => 1, 'maintenance_cost' => 0.1, 'measured_lift' => 0.5]),
            ],
            'queue_debt' => [
                'blocked_count' => 1,
                'quarantined_count' => 0,
                'claimable_count' => 20,
            ],
        ]);

        $this->assertSame(1.0, $result['queue_debt_penalty_factor']);
        $this->assertSame('within_budget', $result['component_budget_evaluations'][0]['budget_status']);
        $this->assertSame('c1', $result['preserved_items'][0]);
    }

    // ── AC4: output shape ─────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->budget()->evaluate([]);
        $this->assertSame(AtlasExternalBrainAmplifierComplexityBudget::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('within_budget',               $r);
        $this->assertArrayHasKey('over_budget_dimensions',      $r);
        $this->assertArrayHasKey('recommended_simplifications', $r);
        $this->assertArrayHasKey('preserved_items',             $r);
    }

    // ── AC2: within-budget detection ─────────────────────────────────────────

    public function test_within_budget_when_all_types_below_limit(): void
    {
        $r = $this->budget()->evaluate([
            'components' => $this->comps(3, 'scaffold'),
            'budgets'    => ['scaffold' => 10],
        ]);
        $this->assertTrue($r['within_budget']);
        $this->assertEmpty($r['over_budget_dimensions']);
    }

    public function test_over_budget_flagged_when_type_exceeds_limit(): void
    {
        $r = $this->budget()->evaluate([
            'components' => $this->comps(5, 'gate'),
            'budgets'    => ['gate' => 3],
        ]);
        $this->assertFalse($r['within_budget']);
        $this->assertCount(1, $r['over_budget_dimensions']);
        $dim = $r['over_budget_dimensions'][0];
        $this->assertSame('gate', $dim['type']);
        $this->assertSame(5, $dim['current']);
        $this->assertSame(3, $dim['limit']);
    }

    public function test_multiple_over_budget_types_all_flagged(): void
    {
        $r = $this->budget()->evaluate([
            'components' => array_merge(
                $this->comps(6, 'scaffold'),
                $this->comps(4, 'judge'),
            ),
            'budgets' => ['scaffold' => 5, 'judge' => 3],
        ]);
        $types = array_column($r['over_budget_dimensions'], 'type');
        $this->assertContains('scaffold', $types);
        $this->assertContains('judge',    $types);
    }

    // ── AC3: recommendations ──────────────────────────────────────────────────

    public function test_high_usage_and_slo_met_is_kept(): void
    {
        $r = $this->budget()->evaluate([
            'components' => [$this->comp(['usage_score' => 0.80, 'slo_met' => true])],
        ]);
        $this->assertContains('c1', $r['preserved_items']);
        $this->assertEmpty($r['recommended_simplifications']);
    }

    public function test_low_usage_recommended_retire(): void
    {
        $r = $this->budget()->evaluate([
            'components' => [$this->comp(['id' => 'low', 'usage_score' => 0.10, 'slo_met' => true])],
        ]);
        $sims = $r['recommended_simplifications'];
        $this->assertCount(1, $sims);
        $this->assertSame('retire', $sims[0]['recommendation']);
    }

    public function test_no_slo_no_value_proof_recommended_retire(): void
    {
        $r = $this->budget()->evaluate([
            'components' => [$this->comp([
                'id'              => 'bad',
                'usage_score'     => 0.50,
                'slo_met'         => false,
                'has_value_proof' => false,
            ])],
        ]);
        $this->assertSame('retire', $r['recommended_simplifications'][0]['recommendation']);
    }

    public function test_mid_usage_slo_missed_recommended_consolidate(): void
    {
        $r = $this->budget()->evaluate([
            'components' => [$this->comp([
                'id'              => 'mid',
                'usage_score'     => 0.50,
                'slo_met'         => false,
                'has_value_proof' => true,
            ])],
        ]);
        $this->assertSame('consolidate', $r['recommended_simplifications'][0]['recommendation']);
    }

    public function test_mid_usage_slo_met_recommended_consolidate(): void
    {
        // usage >= 0.20 < 0.70 with slo_met → consolidate.
        $r = $this->budget()->evaluate([
            'components' => [$this->comp(['id' => 'mid2', 'usage_score' => 0.50, 'slo_met' => true])],
        ]);
        $this->assertSame('consolidate', $r['recommended_simplifications'][0]['recommendation']);
    }

    // ── Unknown types ignored ──────────────────────────────────────────────────

    public function test_unknown_component_type_ignored(): void
    {
        $r = $this->budget()->evaluate([
            'components' => [$this->comp(['type' => 'unknown_type'])],
        ]);
        $this->assertEmpty($r['preserved_items']);
        $this->assertEmpty($r['recommended_simplifications']);
    }

    // ── Default budgets ───────────────────────────────────────────────────────

    public function test_default_scaffold_budget_is_10(): void
    {
        // 10 scaffolds should be within default budget.
        $r = $this->budget()->evaluate(['components' => $this->comps(10, 'scaffold')]);
        $types = array_column($r['over_budget_dimensions'], 'type');
        $this->assertNotContains('scaffold', $types);
    }

    public function test_11_scaffolds_exceeds_default_budget(): void
    {
        $r = $this->budget()->evaluate(['components' => $this->comps(11, 'scaffold')]);
        $this->assertFalse($r['within_budget']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $facts = [
            'components' => [
                $this->comp(['id' => 'a', 'usage_score' => 0.9,  'slo_met' => true]),
                $this->comp(['id' => 'b', 'usage_score' => 0.1,  'slo_met' => false]),
                $this->comp(['id' => 'c', 'usage_score' => 0.50, 'slo_met' => true, 'type' => 'gate']),
            ],
            'budgets' => ['scaffold' => 5, 'gate' => 5],
        ];
        $a = $this->budget()->evaluate($facts);
        $b = $this->budget()->evaluate($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }

    // ── complexity scoring / lift-adjusted budget (AC1-AC4) ─────────────────────

    public function test_low_complexity_high_usage_stays_within_budget_and_kept(): void
    {
        $result = $this->budget()->evaluate(['components' => [
            $this->comp(['prompt_length' => 100, 'dependency_count' => 1, 'maintenance_cost' => 0.5, 'measured_lift' => 0.5]),
        ]]);

        $this->assertSame('within_budget', $result['budget_status']);
        $this->assertSame('within_budget', $result['component_budget_evaluations'][0]['budget_status']);
        $this->assertContains('c1', $result['preserved_items']);
    }

    public function test_high_complexity_low_lift_exceeds_budget_and_is_retired(): void
    {
        $result = $this->budget()->evaluate(['components' => [
            $this->comp(['usage_score' => 0.9, 'prompt_length' => 5000, 'dependency_count' => 10, 'maintenance_cost' => 5.0, 'measured_lift' => 0.0]),
        ]]);

        $this->assertSame('over_budget', $result['budget_status']);
        $this->assertSame('over_budget', $result['component_budget_evaluations'][0]['budget_status']);
        $this->assertNotEmpty($result['component_budget_evaluations'][0]['over_budget_reason']);
        $this->assertNotNull($result['component_budget_evaluations'][0]['simplification_hint']);
        $this->assertSame('retire', $result['recommended_simplifications'][0]['recommendation']);
        $this->assertSame('complexity_exceeds_lift_adjusted_limit', $result['recommended_simplifications'][0]['reason']);
        $this->assertNotContains('c1', $result['preserved_items']);
    }

    public function test_high_lift_raises_the_complexity_ceiling(): void
    {
        // Same complexity inputs, but high measured_lift raises the lift-adjusted limit
        // enough to stay within budget — lift must be able to justify complexity.
        $result = $this->budget()->evaluate(['components' => [
            $this->comp(['usage_score' => 0.9, 'prompt_length' => 1500, 'dependency_count' => 2, 'maintenance_cost' => 1.0, 'measured_lift' => 5.0]),
        ]]);

        $this->assertSame('within_budget', $result['component_budget_evaluations'][0]['budget_status']);
    }

    public function test_over_budget_reason_blank_when_within_budget(): void
    {
        $result = $this->budget()->evaluate(['components' => [
            $this->comp(['prompt_length' => 0, 'dependency_count' => 0, 'maintenance_cost' => 0, 'measured_lift' => 0]),
        ]]);

        $this->assertSame([], $result['component_budget_evaluations'][0]['over_budget_reason']);
        $this->assertNull($result['component_budget_evaluations'][0]['simplification_hint']);
    }
}
