<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\Adapters\AgentExecutionProviderPortAdapter;
use App\Services\Ai\EngineeringKernel\Adapters\MaestroCostBudgetMeterAdapter;
use App\Services\Ai\EngineeringKernel\BudgetMeter;
use App\Services\Ai\EngineeringKernel\ProviderPort;
use App\Services\Ai\SelfConstruction\Maestro\Cost\AtlasMaestroCostAggregator;
use App\Services\Ai\SelfConstruction\Maestro\Cost\AtlasMaestroCostLedger;
use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\AgentExecutionProviderPortService;
use Tests\TestCase;

final class KernelProviderPortBudgetMeterAdaptersTest extends TestCase
{
    private string $ledgerPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas-ek-cost-ledger-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    // ── ProviderPort adapter ────────────────────────────────────────────────────

    public function test_provider_port_adapter_implements_interface(): void
    {
        $adapter = new AgentExecutionProviderPortAdapter(new AgentExecutionProviderPortService);

        $this->assertInstanceOf(ProviderPort::class, $adapter);
    }

    public function test_provider_port_adapter_invoke_returns_same_normalized_envelope_as_underlying_service(): void
    {
        $service = new AgentExecutionProviderPortService;
        $adapter = new AgentExecutionProviderPortAdapter($service);

        $payload = [
            'provider_id' => 'claude_code',
            'model_family' => 'sonnet',
            'provider_invoked' => true,
        ];

        $this->assertSame($service->normalize($payload), $adapter->invoke($payload));
    }

    // ── BudgetMeter adapter ─────────────────────────────────────────────────────

    private function costFact(array $overrides = []): array
    {
        return array_merge([
            'task_packet_id' => 'pkt-1',
            'task_class' => 'engineering',
            'provider' => 'claude_code',
            'model' => 'sonnet',
            'cycle_id' => 'cycle-1',
            'tokens_in' => 100,
            'tokens_out' => 50,
            'cost_cents' => 10,
            'recorded_at' => '2026-07-01T00:00:00+00:00',
        ], $overrides);
    }

    public function test_budget_meter_adapter_implements_interface(): void
    {
        $adapter = new MaestroCostBudgetMeterAdapter(
            new AtlasMaestroCostLedger($this->ledgerPath),
            new AtlasMaestroCostAggregator,
        );

        $this->assertInstanceOf(BudgetMeter::class, $adapter);
    }

    public function test_measure_records_through_the_cost_ledger(): void
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);
        $adapter = new MaestroCostBudgetMeterAdapter($ledger, new AtlasMaestroCostAggregator($ledger));

        $fact = $this->costFact();
        $result = $adapter->measure($fact);

        $this->assertSame('pkt-1', $result['task_packet_id']);
        $this->assertArrayHasKey('cost_hash', $result);

        $ledgerRows = $ledger->all();
        $this->assertCount(1, $ledgerRows);
        $this->assertSame($result, $ledgerRows[0]);
    }

    public function test_measure_returns_empty_array_for_malformed_fact_instead_of_throwing(): void
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);
        $adapter = new MaestroCostBudgetMeterAdapter($ledger, new AtlasMaestroCostAggregator($ledger));

        $result = $adapter->measure(['task_packet_id' => 'pkt-1']);

        $this->assertSame([], $result);
        $this->assertSame([], $ledger->all());
    }

    public function test_summarize_aggregates_through_the_aggregator_by_task_class(): void
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);
        $adapter = new MaestroCostBudgetMeterAdapter($ledger, new AtlasMaestroCostAggregator($ledger));

        $adapter->measure($this->costFact());
        $adapter->measure($this->costFact(['task_packet_id' => 'pkt-2', 'cost_cents' => 5]));

        $summary = $adapter->summarize(['by' => 'task_class']);
        $direct = (new AtlasMaestroCostAggregator($ledger))->aggregateByTaskClass();

        $this->assertSame($direct, $summary);
        $this->assertSame(15, $summary['engineering']['sum_cost_cents']);
    }

    public function test_summarize_aggregates_by_provider_when_requested(): void
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);
        $adapter = new MaestroCostBudgetMeterAdapter($ledger, new AtlasMaestroCostAggregator($ledger));

        $adapter->measure($this->costFact());

        $summary = $adapter->summarize(['by' => 'provider']);
        $direct = (new AtlasMaestroCostAggregator($ledger))->aggregateByProvider();

        $this->assertSame($direct, $summary);
    }

    public function test_summarize_filters_by_cycle_id(): void
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);
        $adapter = new MaestroCostBudgetMeterAdapter($ledger, new AtlasMaestroCostAggregator($ledger));

        $adapter->measure($this->costFact(['cycle_id' => 'cycle-a']));
        $adapter->measure($this->costFact(['task_packet_id' => 'pkt-2', 'cycle_id' => 'cycle-b']));

        $summary = $adapter->summarize(['by' => 'task_class', 'cycle_id' => 'cycle-a']);

        $this->assertSame(1, $summary['engineering']['count_records']);
    }
}
