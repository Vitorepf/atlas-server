<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\Cost\AtlasMaestroBudgetGate;
use App\Services\Ai\SelfConstruction\Maestro\Cost\AtlasMaestroCostAggregator;
use App\Services\Ai\SelfConstruction\Maestro\Cost\AtlasMaestroCostLedger;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class AtlasMaestroBudgetGateTest extends TestCase
{
    private string $ledgerPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas-budget-gate-'.bin2hex(random_bytes(6)).'.jsonl';
        Config::set('atlas.maestro.cost.budgets', null);
        Config::set('atlas.maestro.cost.budget_gate_enforce', null);
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    private function fact(array $override = []): array
    {
        return array_replace([
            'task_packet_id' => 'pk-1',
            'task_class' => 'refactor',
            'provider' => 'atlas_native',
            'model' => 'atlas-native-1',
            'cycle_id' => 'cycle-A',
            'tokens_in' => 100,
            'tokens_out' => 50,
            'cost_cents' => 10,
            'recorded_at' => '2026-06-25T00:00:00Z',
        ], $override);
    }

    private function ledgerWithFacts(int $rows, int $costEach, array $overrides = []): AtlasMaestroCostLedger
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);
        for ($i = 0; $i < $rows; $i++) {
            $ledger->append($this->fact(array_replace(['cost_cents' => $costEach, 'recorded_at' => '2026-06-25T00:00:0'.$i.'Z'], $overrides)));
        }

        return $ledger;
    }

    public function test_advisory_default_returns_advise_when_per_cycle_budget_exceeded(): void
    {
        Config::set('atlas.maestro.cost.budgets', ['per_cycle_cents' => 50]);
        $ledger = $this->ledgerWithFacts(3, 30); // sum = 90

        $verdict = (new AtlasMaestroBudgetGate(new AtlasMaestroCostAggregator($ledger)))
            ->decide('pk-1', 'atlas_native', 'refactor', 'cycle-A');

        self::assertSame(AtlasMaestroBudgetGate::GATE_ADVISE, $verdict['gate']);
        self::assertSame(AtlasMaestroBudgetGate::WINDOW_PER_CYCLE, $verdict['window']);
        self::assertGreaterThan(0, $verdict['overage_cents']);
    }

    public function test_enforce_mode_returns_refuse_when_provider_day_budget_exceeded(): void
    {
        Config::set('atlas.maestro.cost.budgets', ['per_provider_per_day_cents' => 20]);
        Config::set('atlas.maestro.cost.budget_gate_enforce', true);
        $ledger = $this->ledgerWithFacts(5, 10); // sum = 50 for provider atlas_native

        $verdict = (new AtlasMaestroBudgetGate(new AtlasMaestroCostAggregator($ledger)))
            ->decide('pk-1', 'atlas_native', 'refactor', null);

        self::assertSame(AtlasMaestroBudgetGate::GATE_REFUSE, $verdict['gate']);
        self::assertSame(AtlasMaestroBudgetGate::WINDOW_PER_PROVIDER_PER_DAY, $verdict['window']);
        self::assertGreaterThan(0, $verdict['overage_cents']);
    }

    public function test_empty_ledger_returns_allow_no_facts_and_never_throws(): void
    {
        // No budgets config set; empty ledger.
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);
        $verdict = (new AtlasMaestroBudgetGate(new AtlasMaestroCostAggregator($ledger)))
            ->decide('pk-x', 'atlas_native', 'refactor', 'cycle-x');

        self::assertSame(AtlasMaestroBudgetGate::GATE_ALLOW, $verdict['gate']);
        self::assertSame('no_facts', $verdict['reason']);
    }

    public function test_under_budget_returns_allow_within_budget(): void
    {
        Config::set('atlas.maestro.cost.budgets', ['per_cycle_cents' => 10000]);
        $ledger = $this->ledgerWithFacts(2, 5);

        $verdict = (new AtlasMaestroBudgetGate(new AtlasMaestroCostAggregator($ledger)))
            ->decide('pk-1', 'atlas_native', 'refactor', 'cycle-A');

        self::assertSame(AtlasMaestroBudgetGate::GATE_ALLOW, $verdict['gate']);
        self::assertSame('within_budget', $verdict['reason']);
    }

    public function test_advisory_never_returns_refuse_even_for_huge_overage(): void
    {
        Config::set('atlas.maestro.cost.budgets', ['per_cycle_cents' => 1]);
        Config::set('atlas.maestro.cost.budget_gate_enforce', false);
        $ledger = $this->ledgerWithFacts(10, 10000);

        $verdict = (new AtlasMaestroBudgetGate(new AtlasMaestroCostAggregator($ledger)))
            ->decide('pk-1', 'atlas_native', 'refactor', 'cycle-A');

        self::assertSame(AtlasMaestroBudgetGate::GATE_ADVISE, $verdict['gate']);
        self::assertNotSame(AtlasMaestroBudgetGate::GATE_REFUSE, $verdict['gate']);
    }

    public function test_atlas_native_zero_cost_is_allowed_even_when_provider_budget_exceeded(): void
    {
        Config::set('atlas.maestro.cost.budgets', ['per_provider_per_day_cents' => 20]);
        Config::set('atlas.maestro.cost.budget_gate_enforce', true);
        $ledger = $this->ledgerWithFacts(5, 10); // sum = 50, exceeds 20

        $verdict = (new AtlasMaestroBudgetGate(new AtlasMaestroCostAggregator($ledger)))
            ->decide('pk-native', 'atlas_native', 'coverage', null, ['cost_cents' => 0, 'atlas_native_capability_proof' => 'receipt:pk-native']);

        self::assertSame(AtlasMaestroBudgetGate::GATE_ALLOW, $verdict['gate']);
        self::assertSame('atlas_native_zero_cost', $verdict['reason']);
        self::assertSame('native_declared', $verdict['fact_source']);
        self::assertNull($verdict['window']);
        self::assertSame(0, $verdict['overage_cents']);
    }

    public function test_atlas_native_zero_cost_without_capability_proof_does_not_use_zero_cost_exemption(): void
    {
        Config::set('atlas.maestro.cost.budgets', ['per_provider_per_day_cents' => 20]);
        Config::set('atlas.maestro.cost.budget_gate_enforce', true);
        $ledger = $this->ledgerWithFacts(5, 10); // sum = 50, exceeds 20

        $verdict = (new AtlasMaestroBudgetGate(new AtlasMaestroCostAggregator($ledger)))
            ->decide('pk-native', 'atlas_native', 'coverage', null, ['cost_cents' => 0]);

        self::assertNotSame('atlas_native_zero_cost', $verdict['reason']);
    }

    public function test_every_decision_envelope_includes_required_audit_fields(): void
    {
        Config::set('atlas.maestro.cost.budgets', ['per_cycle_cents' => 50]);
        $ledger = $this->ledgerWithFacts(3, 30); // advise

        $verdict = (new AtlasMaestroBudgetGate(new AtlasMaestroCostAggregator($ledger)))
            ->decide('audit-pk', 'codex', 'refactor', 'cycle-A');

        foreach (['task_packet_id', 'provider', 'task_class', 'window', 'overage_cents', 'fact_source'] as $field) {
            self::assertArrayHasKey($field, $verdict, "envelope must include '{$field}'");
        }
        self::assertSame('audit-pk', $verdict['task_packet_id']);
        self::assertSame('codex', $verdict['provider']);
        self::assertSame('refactor', $verdict['task_class']);
    }

    public function test_source_has_zero_direct_db_calls(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/SelfConstruction/Maestro/Cost/AtlasMaestroBudgetGate.php'));
        foreach (['DB::', 'Schema::', 'Eloquent', 'QueryBuilder'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $src, "budget gate must not contain {$forbidden}");
        }
    }

    // ── AC: waste-aware routing ────────────────────────────────────────────────

    private function gate(): AtlasMaestroBudgetGate
    {
        return new AtlasMaestroBudgetGate(new AtlasMaestroCostAggregator(new AtlasMaestroCostLedger($this->ledgerPath)));
    }

    public function test_blocks_budget_expansion_when_retry_loop_rate_or_wasted_token_rate_exceeds_threshold(): void
    {
        $retryLoop = $this->gate()->evaluateWasteAwareRouting(['retry_loop_rate' => 0.9]);
        self::assertSame(AtlasMaestroBudgetGate::DECISION_BLOCK, $retryLoop['budget_decision']);
        self::assertContains('retry_loop_rate_exceeded', $retryLoop['blocked_reasons']);

        $wastedTokens = $this->gate()->evaluateWasteAwareRouting(['wasted_token_rate' => 0.9]);
        self::assertSame(AtlasMaestroBudgetGate::DECISION_BLOCK, $wastedTokens['budget_decision']);
        self::assertContains('wasted_token_rate_exceeded', $wastedTokens['blocked_reasons']);
    }

    public function test_allows_high_leverage_repairs_under_reduced_budget_with_waste_reduction_proof(): void
    {
        $verdict = $this->gate()->evaluateWasteAwareRouting([
            'retry_loop_rate' => 0.9,
            'high_leverage_repair' => true,
            'verified_waste_reduction_proof' => true,
        ]);

        self::assertSame(AtlasMaestroBudgetGate::DECISION_ALLOW_REDUCED, $verdict['budget_decision']);
        self::assertLessThan(1.0, $verdict['budget_multiplier']);
        self::assertGreaterThan(0.0, $verdict['budget_multiplier']);
        self::assertNotNull($verdict['allowed_exception_reason']);
    }

    public function test_high_leverage_claim_alone_without_proof_does_not_bypass_block(): void
    {
        $verdict = $this->gate()->evaluateWasteAwareRouting([
            'retry_loop_rate' => 0.9,
            'high_leverage_repair' => true,
        ]);

        self::assertSame(AtlasMaestroBudgetGate::DECISION_BLOCK, $verdict['budget_decision']);
        self::assertNull($verdict['allowed_exception_reason']);
    }

    public function test_waste_aware_output_includes_budget_decision_multiplier_blocked_reasons_and_exception_reason(): void
    {
        $verdict = $this->gate()->evaluateWasteAwareRouting([]);

        foreach (['budget_decision', 'budget_multiplier', 'blocked_reasons', 'allowed_exception_reason'] as $key) {
            self::assertArrayHasKey($key, $verdict, "Missing key: {$key}");
        }
        self::assertSame(AtlasMaestroBudgetGate::DECISION_ALLOW, $verdict['budget_decision']);
        self::assertSame(1.0, $verdict['budget_multiplier']);
    }

    // ── AC2: low structural value never earns the benefit of the doubt on waste ──

    public function test_low_structural_value_with_mild_waste_signal_blocks(): void
    {
        $verdict = $this->gate()->evaluateWasteAwareRouting([
            'retry_loop_rate' => 0.05,
            'structural_value_score' => 0.05,
        ]);

        self::assertSame(AtlasMaestroBudgetGate::DECISION_BLOCK, $verdict['budget_decision']);
        self::assertContains('low_structural_value_with_waste_signal', $verdict['blocked_reasons']);
    }

    public function test_default_structural_value_does_not_block_when_waste_is_below_threshold(): void
    {
        $verdict = $this->gate()->evaluateWasteAwareRouting(['retry_loop_rate' => 0.05]);

        self::assertSame(AtlasMaestroBudgetGate::DECISION_ALLOW, $verdict['budget_decision']);
        self::assertNotContains('low_structural_value_with_waste_signal', $verdict['blocked_reasons']);
    }

    public function test_high_structural_value_does_not_block_mild_waste_signal(): void
    {
        $verdict = $this->gate()->evaluateWasteAwareRouting([
            'retry_loop_rate' => 0.05,
            'structural_value_score' => 0.9,
        ]);

        self::assertNotContains('low_structural_value_with_waste_signal', $verdict['blocked_reasons']);
    }

    // ── AC3: high-cost routes only allowed through with verified proof_demand/criticality/unlock ──

    public function test_budget_overage_blocked_by_default_even_with_high_scores_without_verified_proof(): void
    {
        Config::set('atlas.maestro.cost.budgets', ['per_cycle_cents' => 50]);
        $ledger = $this->ledgerWithFacts(3, 30);

        $verdict = (new AtlasMaestroBudgetGate(new AtlasMaestroCostAggregator($ledger)))->decide(
            'pk-1', 'atlas_native', 'refactor', 'cycle-A',
            ['proof_demand_score' => 0.9, 'criticality_score' => 0.9, 'expected_unlock_score' => 0.9],
        );

        self::assertSame(AtlasMaestroBudgetGate::GATE_ADVISE, $verdict['gate']);
        self::assertNotSame('high_value_route_justified', $verdict['reason']);
    }

    public function test_budget_overage_allowed_when_high_value_route_is_verified(): void
    {
        Config::set('atlas.maestro.cost.budgets', ['per_cycle_cents' => 50]);
        $ledger = $this->ledgerWithFacts(3, 30);

        $verdict = (new AtlasMaestroBudgetGate(new AtlasMaestroCostAggregator($ledger)))->decide(
            'pk-1', 'atlas_native', 'refactor', 'cycle-A',
            [
                'proof_demand_score' => 0.9,
                'criticality_score' => 0.9,
                'expected_unlock_score' => 0.9,
                'verified_high_value_route_proof' => true,
            ],
        );

        self::assertSame(AtlasMaestroBudgetGate::GATE_ALLOW, $verdict['gate']);
        self::assertSame('high_value_route_justified', $verdict['reason']);
    }

    public function test_verified_proof_alone_without_all_three_high_scores_does_not_justify_high_cost_route(): void
    {
        Config::set('atlas.maestro.cost.budgets', ['per_cycle_cents' => 50]);
        $ledger = $this->ledgerWithFacts(3, 30);

        $verdict = (new AtlasMaestroBudgetGate(new AtlasMaestroCostAggregator($ledger)))->decide(
            'pk-1', 'atlas_native', 'refactor', 'cycle-A',
            [
                'proof_demand_score' => 0.9,
                'criticality_score' => 0.1, // fails the floor
                'expected_unlock_score' => 0.9,
                'verified_high_value_route_proof' => true,
            ],
        );

        self::assertSame(AtlasMaestroBudgetGate::GATE_ADVISE, $verdict['gate']);
    }

    // ── AC4: provider class, cost band, waste reason, fallback route ───────────

    public function test_envelope_includes_provider_class_cost_band_waste_reason_and_fallback_route(): void
    {
        Config::set('atlas.maestro.cost.budgets', ['per_cycle_cents' => 50]);
        $ledger = $this->ledgerWithFacts(3, 30);

        $verdict = (new AtlasMaestroBudgetGate(new AtlasMaestroCostAggregator($ledger)))
            ->decide('pk-1', 'codex', 'refactor', 'cycle-A');

        foreach (['provider_class', 'cost_band', 'waste_reason', 'fallback_route'] as $key) {
            self::assertArrayHasKey($key, $verdict, "Missing key: {$key}");
        }
        self::assertSame('paid_provider', $verdict['provider_class']);
        self::assertSame('cycle_budget_exceeded', $verdict['waste_reason']);
        self::assertSame('atlas_native', $verdict['fallback_route']);
    }

    public function test_atlas_native_provider_class_is_zero_cost(): void
    {
        $verdict = $this->gate()->decide('pk-1', 'atlas_native', 'refactor', null);

        self::assertSame('zero_cost', $verdict['provider_class']);
    }

    public function test_allowed_envelope_has_null_waste_reason_and_fallback_route(): void
    {
        $verdict = $this->gate()->decide('pk-1', 'atlas_native', 'refactor', null);

        self::assertSame(AtlasMaestroBudgetGate::GATE_ALLOW, $verdict['gate']);
        self::assertNull($verdict['waste_reason']);
        self::assertNull($verdict['fallback_route']);
        self::assertSame('none', $verdict['cost_band']);
    }

    public function test_cost_band_reflects_overage_size(): void
    {
        Config::set('atlas.maestro.cost.budgets', ['per_cycle_cents' => 10]);
        $ledger = $this->ledgerWithFacts(1, 10010); // overage = 10000 cents -> high band

        $verdict = (new AtlasMaestroBudgetGate(new AtlasMaestroCostAggregator($ledger)))
            ->decide('pk-1', 'atlas_native', 'refactor', 'cycle-A');

        self::assertSame('high', $verdict['cost_band']);
    }
}
