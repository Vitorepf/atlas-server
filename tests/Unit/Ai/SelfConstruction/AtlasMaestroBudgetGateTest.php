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
            ->decide('pk-native', 'atlas_native', 'coverage', null, ['cost_cents' => 0]);

        self::assertSame(AtlasMaestroBudgetGate::GATE_ALLOW, $verdict['gate']);
        self::assertSame('atlas_native_zero_cost', $verdict['reason']);
        self::assertSame('native_declared', $verdict['fact_source']);
        self::assertNull($verdict['window']);
        self::assertSame(0, $verdict['overage_cents']);
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
}
