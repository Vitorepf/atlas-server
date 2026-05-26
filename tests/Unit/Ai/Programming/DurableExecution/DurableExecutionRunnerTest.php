<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\DurableExecution;

use App\Services\Ai\Programming\DurableExecution\DurableExecutionDecisionContract;
use App\Services\Ai\Programming\DurableExecution\DurableExecutionHandoffPacket;
use App\Services\Ai\Programming\DurableExecution\DurableExecutionPreflight;
use App\Services\Ai\Programming\DurableExecution\DurableExecutionReceipt;
use App\Services\Ai\Programming\DurableExecution\DurableExecutionRunner;
use Tests\TestCase;

final class DurableExecutionRunnerTest extends TestCase
{
    private DurableExecutionRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner = new DurableExecutionRunner(
            new DurableExecutionPreflight,
            new DurableExecutionDecisionContract,
            new DurableExecutionHandoffPacket,
            new DurableExecutionReceipt,
        );
    }

    private function readyish(): array
    {
        return [
            'work_item_id' => 'wi-1',
            'spec_hash' => 'sha:spec',
            'plan_hash' => 'sha:plan',
            'plan_json' => [
                'approval_status' => 'approved',
                'risk_band' => 'medium',
                'tests_to_run' => ['t.php'],
            ],
            'decision_receipt' => ['r' => 1],
        ];
    }

    public function test_plan_chain_executes_when_ready(): void
    {
        $envelope = $this->runner->plan($this->readyish(), 'operator:vitor');

        $this->assertSame('atlas.programming.durable_execution_run.v1', $envelope['schema_version']);
        $this->assertTrue($envelope['ready_to_execute']);
        $this->assertSame('execute_durable', $envelope['decision']['decision']);
        $this->assertIsArray($envelope['handoff']);
        $this->assertSame('durable_runner', $envelope['handoff']['target']);
        $this->assertSame([], $envelope['blocking_reasons']);
    }

    public function test_plan_defers_when_blocked(): void
    {
        $envelope = $this->runner->plan([], 'service:dev-router');

        $this->assertFalse($envelope['ready_to_execute']);
        $this->assertSame('defer_for_replan', $envelope['decision']['decision']);
        $this->assertNull($envelope['handoff']);
        $this->assertNotEmpty($envelope['blocking_reasons']);
    }

    public function test_plan_escalates_to_forge_on_critical_risk(): void
    {
        $snap = $this->readyish();
        $snap['plan_json']['risk_band'] = 'critical';
        $snap['plan_json']['tests_to_run'] = ['t1.php', 't2.php'];

        $envelope = $this->runner->plan($snap, 'service:dev-router');

        $this->assertSame('escalate_to_forge', $envelope['decision']['decision']);
        $this->assertIsArray($envelope['handoff']);
        $this->assertSame('forge_handoff', $envelope['handoff']['target']);
    }

    public function test_actor_override_decision_respected(): void
    {
        $snap = $this->readyish();
        $snap['actor_override_decision'] = 'cancel_runtime';

        $envelope = $this->runner->plan($snap, 'operator:vitor');

        $this->assertSame('cancel_runtime', $envelope['decision']['decision']);
        $this->assertNull($envelope['handoff'], 'cancel emits no handoff');
    }

    public function test_close_with_success_receipt(): void
    {
        $envelope = $this->runner->plan($this->readyish(), 'operator:vitor');
        $receipt = $this->runner->closeWithOutcome('success', $envelope['decision'], [
            'duration_ms' => 3210,
        ]);

        $this->assertSame('atlas.programming.durable_execution_receipt.v1', $receipt['schema_version']);
        $this->assertSame('success', $receipt['outcome']);
        $this->assertSame(3210, $receipt['metrics']['duration_ms']);
        $this->assertSame('wi-1', $receipt['work_item_id']);
        $this->assertSame('execute_durable', $receipt['decision_ref']['decision']);
    }

    public function test_envelope_carries_full_chain(): void
    {
        $envelope = $this->runner->plan($this->readyish(), 'operator:vitor');

        $this->assertArrayHasKey('preflight', $envelope);
        $this->assertArrayHasKey('decision', $envelope);
        $this->assertArrayHasKey('handoff', $envelope);
        $this->assertSame('AP-283', $envelope['preflight']['ap_reference']);
        $this->assertSame('AP-284', $envelope['decision']['ap_reference']);
        $this->assertSame('AP-286', $envelope['handoff']['ap_reference']);
    }
}
