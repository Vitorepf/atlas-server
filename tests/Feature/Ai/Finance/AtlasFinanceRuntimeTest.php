<?php

namespace Tests\Feature\Ai\Finance;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Finance\AtlasFinanceOrchestrator;
use App\Services\Ai\Finance\AtlasFinanceRuntime;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasFinanceRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createLedgerTable();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_every_finance_flow_returns_analysis_only_without_market_actions(): void
    {
        $orchestrator = app(AtlasFinanceOrchestrator::class);

        foreach ($orchestrator->supportedFlows() as $flow) {
            $result = $orchestrator->executeFlow($flow, ['subject' => 'test subject']);

            $this->assertContains($result['status'], ['analysis_ready', 'needs_human_approval'], $flow);
            $this->assertSame('analysis_review_only', $result['output_mode'], $flow);
            $this->assertTrue($result['analysis_only'], $flow);
            $this->assertFalse($result['market_execution_allowed'], $flow);
            $this->assertSame([], $result['executable_market_actions'], $flow);
            $this->assertNull($result['trade_order_payload'], $flow);
            $this->assertSame([], $result['broker_instructions'], $flow);
            $this->assertContains('finance_compliance_review', data_get($result, 'review_packet.required_gates'), $flow);
            $this->assertFalse((bool) data_get($result, 'non_execution_contract.order_generation_allowed'), $flow);
            $this->assertFalse((bool) data_get($result, 'non_execution_contract.broker_connection_allowed'), $flow);
        }
    }

    public function test_compliance_gate_is_recorded_in_evidence_ledger(): void
    {
        $result = app(AtlasFinanceOrchestrator::class)->executeFlow('risk_review');
        $envelopeId = 'finance_review:'.data_get($result, 'plan.plan_id');

        $this->assertTrue(AtlasLedgerEvent::query()
            ->where('envelope_id', $envelopeId)
            ->where('event_type', LedgerEventType::GateEvaluated->value)
            ->where('emitter_stage', 'atlas.finance')
            ->exists());

        $this->assertFalse(AtlasLedgerEvent::query()
            ->where('envelope_id', $envelopeId)
            ->where('event_type', LedgerEventType::ToolInvoked->value)
            ->exists());
    }

    public function test_requested_market_execution_is_blocked_and_audited(): void
    {
        $result = app(AtlasFinanceOrchestrator::class)->executeFlow('trade_thesis', [
            'requested_action' => 'place order for 100 shares',
        ]);
        $envelopeId = 'finance_review:'.data_get($result, 'plan.plan_id');

        $this->assertSame('blocked_for_market_execution_request', $result['status']);
        $this->assertSame(['place_order'], $result['blocked_requested_actions']);
        $this->assertSame('blocked_market_execution_request_cannot_be_approved_by_finance_runtime', $result['approval_status']);
        $this->assertContains('ledger:'.$envelopeId.':market_execution_forbidden', $result['evidence_refs']);
        $this->assertFalse($result['market_execution_allowed']);
        $this->assertSame([], $result['executable_market_actions']);
        $this->assertNull($result['trade_order_payload']);

        $this->assertTrue(AtlasLedgerEvent::query()
            ->where('envelope_id', $envelopeId)
            ->where('event_type', LedgerEventType::GateBlocked->value)
            ->where('emitter_stage', 'atlas.finance')
            ->exists());
    }

    public function test_natural_language_trade_request_is_blocked_without_order_payload(): void
    {
        $result = app(AtlasFinanceOrchestrator::class)->executeFlow('portfolio_analysis', [
            'requested_action' => 'comprar 25 acoes e rebalancear minha carteira',
        ]);

        $this->assertSame('blocked_for_market_execution_request', $result['status']);
        $this->assertSame(['place_order', 'rebalance_account'], $result['blocked_requested_actions']);
        $this->assertFalse($result['market_execution_allowed']);
        $this->assertSame([], $result['broker_instructions']);
        $this->assertSame([], $result['executable_market_actions']);
        $this->assertNull($result['trade_order_payload']);
        $this->assertSame('blocked_market_execution_request', data_get($result, 'compliance.status'));
    }

    public function test_runtime_is_safe_when_ledger_table_is_absent(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        $result = app(AtlasFinanceOrchestrator::class)->executeFlow('market_research');

        $this->assertSame('analysis_ready', $result['status']);
        $this->assertFalse($result['market_execution_allowed']);
        $this->assertSame([], $result['executable_market_actions']);
    }

    public function test_runtime_blocks_malformed_plan_that_bypasses_orchestrator(): void
    {
        $result = app(AtlasFinanceRuntime::class)->review([
            'plan_id' => 'malformed',
            'domain' => 'finance',
            'flow' => 'finance.risk_review',
            'output_mode' => 'execution',
            'market_execution_allowed' => true,
            'destructive_actions_allowed' => true,
            'compliance_gate_required' => false,
            'required_gates' => [],
        ]);

        $this->assertSame('blocked_for_finance_compliance', $result['status']);
        $this->assertFalse($result['market_execution_allowed']);
        $this->assertFalse((bool) data_get($result, 'compliance.passed'));
        $this->assertContains('output_mode_must_be_analysis_review_only', data_get($result, 'compliance.reasons'));
        $this->assertContains('market_execution_must_be_disabled', data_get($result, 'compliance.reasons'));
        $this->assertContains('finance_compliance_gate_required', data_get($result, 'compliance.reasons'));
        $this->assertSame([], $result['executable_market_actions']);
        $this->assertNull($result['trade_order_payload']);

        $this->assertTrue(AtlasLedgerEvent::query()
            ->where('envelope_id', 'finance_review:malformed')
            ->where('event_type', LedgerEventType::GateBlocked->value)
            ->exists());
    }

    public function test_finance_forge_stops_at_human_approval_review(): void
    {
        $result = app(AtlasFinanceOrchestrator::class)->executeFlow('finance.forge');

        $this->assertSame('needs_human_approval', $result['status']);
        $this->assertTrue($result['human_approval_required']);
        $this->assertSame('required_before_any_downstream_action', $result['approval_status']);
        $this->assertFalse($result['market_execution_allowed']);
        $this->assertSame([], $result['executable_market_actions']);
    }

    private function createLedgerTable(): void
    {
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 32)->primary();
            $table->string('schema_version', 40)->default('atlas.ledger_event.v1');
            $table->string('tenant_id', 120)->index();
            $table->string('operator_id', 120)->index();
            $table->string('envelope_id', 80)->index();
            $table->string('receipt_id', 80)->nullable()->index();
            $table->uuid('trace_id')->nullable()->index();
            $table->string('correlation_id', 120)->index();
            $table->string('causation_id', 80)->nullable()->index();
            $table->string('event_type', 80)->index();
            $table->string('emitter_stage', 120)->index();
            $table->string('emitter_version', 80);
            $table->json('payload');
            $table->string('payload_hash', 64)->index();
            $table->timestampTz('occurred_at')->index();
            $table->timestampsTz();
        });
    }
}
