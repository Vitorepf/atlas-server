<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\EngineeringModeExecutionOrderFactory;
use App\Services\Ai\EngineeringKernel\KernelEvidenceAuthority;
use App\Services\Ai\EngineeringKernel\MutativeDecisionBinder;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MutativeDecisionBinderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_07_23_230000_harden_atlas_ledger_chain_and_journey_queries.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        parent::tearDown();
    }

    public function test_decision_event_id_is_ledger_safe_length(): void
    {
        $id = MutativeDecisionBinder::decisionEventId('dev:'.str_repeat('a', 64));
        $this->assertSame(32, strlen($id));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $id);
    }

    public function test_bind_mints_sealed_decision_that_passes_pre_effect_verify(): void
    {
        $order = (new EngineeringModeExecutionOrderFactory)->make([
            'mode' => 'dev',
            'risk_class' => 'R1',
            'work_topology' => 'single',
            'run_hash' => str_repeat('1', 64),
            'run_id' => 'run-mutative-bind',
            'delivery_id' => 'delivery-mutative-bind',
            'duration_regime' => 'interactive',
            'product_intent_verdict_hash' => str_repeat('2', 64),
            'spec_hash' => str_repeat('3', 64),
            'world_model_snapshot_hash' => str_repeat('4', 64),
            'workspace' => base_path(),
            'base_commit' => str_repeat('a', 40),
            'allowed_scope' => ['app/Example.php'],
            'forbidden_scope' => ['.env'],
            'authority_envelope' => ['kind' => 'test', 'authority_hash' => str_repeat('b', 64)],
            'decision_event_id' => MutativeDecisionBinder::decisionEventId('test-bind-1'),
            'mutate' => true,
            'operator_contract' => ['presence' => 'confirmed', 'operator_id' => 'operator-bind'],
            'provider_route' => ['provider' => 'hermes_cli', 'model' => 'kimi-k2.7-code'],
            'idempotency_key' => 'mutative-bind:test-1',
            'experiment_ref' => 'mutative-bind/test-1',
        ]);

        $decisionEventId = MutativeDecisionBinder::decisionEventId('test-bind-1');
        $bound = app(MutativeDecisionBinder::class)->bind(
            $order,
            'dev',
            $decisionEventId,
            'operator-bind',
            ['fixture' => 'mutative_decision_binder_test'],
        );

        $this->assertSame($decisionEventId, $bound->decisionReceipt['decision_event_id'] ?? null);
        $event = app(AtlasEvidenceLedger::class)->eventById($decisionEventId);
        $this->assertNotNull($event);
        $this->assertTrue(app(KernelEvidenceAuthority::class)->verifyEvent($event, 'decision'));
        $this->assertNotSame($order->runId, $bound->runId);
    }
}
