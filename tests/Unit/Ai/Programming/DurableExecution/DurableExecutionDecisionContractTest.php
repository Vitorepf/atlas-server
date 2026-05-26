<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\DurableExecution;

use App\Services\Ai\Programming\DurableExecution\DurableExecutionDecisionContract;
use App\Services\Ai\Programming\DurableExecution\DurableExecutionPreflight;
use Tests\TestCase;

final class DurableExecutionDecisionContractTest extends TestCase
{
    private DurableExecutionDecisionContract $contract;

    private DurableExecutionPreflight $preflight;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contract = new DurableExecutionDecisionContract;
        $this->preflight = new DurableExecutionPreflight;
    }

    private function passingPreflight(): array
    {
        return $this->preflight->evaluate([
            'work_item_id' => 'wi-1',
            'spec_hash' => 'sha:spec',
            'plan_hash' => 'sha:plan',
            'plan_json' => [
                'approval_status' => 'approved',
                'risk_band' => 'low',
                'tests_to_run' => ['t.php'],
            ],
            'decision_receipt' => ['r' => 1],
        ]);
    }

    private function blockedPreflight(): array
    {
        return $this->preflight->evaluate([]);
    }

    public function test_execute_decision_when_preflight_passes(): void
    {
        $decision = $this->contract->decide(
            $this->passingPreflight(),
            DurableExecutionDecisionContract::DECISION_EXECUTE,
            'operator:vitor',
        );

        $this->assertSame('atlas.programming.durable_execution_decision.v1', $decision['schema_version']);
        $this->assertSame('AP-284', $decision['ap_reference']);
        $this->assertSame('execute_durable', $decision['decision']);
        $this->assertSame('preflight_passed', $decision['preflight_status']);
        $this->assertSame('DurableExecutionRunner', $decision['forwards_to']);
        $this->assertSame('wi-1', $decision['work_item_id']);
    }

    public function test_execute_blocked_when_preflight_failed(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot decide execute_durable when preflight.may_execute=false');

        $this->contract->decide(
            $this->blockedPreflight(),
            DurableExecutionDecisionContract::DECISION_EXECUTE,
            'operator:vitor',
        );
    }

    public function test_defer_decision_when_preflight_blocked(): void
    {
        $decision = $this->contract->decide(
            $this->blockedPreflight(),
            DurableExecutionDecisionContract::DECISION_DEFER,
            'service:replanner',
        );

        $this->assertSame('defer_for_replan', $decision['decision']);
        $this->assertSame('preflight_blocked', $decision['preflight_status']);
        $this->assertStringContainsString('AtlasDevPlanProjectionService::reproject', $decision['forwards_to']);
    }

    public function test_escalate_forge_decision(): void
    {
        $decision = $this->contract->decide(
            $this->passingPreflight(),
            DurableExecutionDecisionContract::DECISION_ESCALATE_FORGE,
            'service:dev-router',
            'risk classified as obra-grade',
        );

        $this->assertSame('escalate_to_forge', $decision['decision']);
        $this->assertSame('risk classified as obra-grade', $decision['reason']);
        $this->assertSame('AtlasForgeHandoffAdapter::promoteWithPacket', $decision['forwards_to']);
    }

    public function test_cancel_decision_has_no_forwards_to(): void
    {
        $decision = $this->contract->decide(
            $this->blockedPreflight(),
            DurableExecutionDecisionContract::DECISION_CANCEL,
            'operator:vitor',
        );

        $this->assertSame('cancel_runtime', $decision['decision']);
        $this->assertNull($decision['forwards_to']);
    }

    public function test_rejects_unknown_decision(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Decision must be one of');

        $this->contract->decide(
            $this->passingPreflight(),
            'maybe_run',
            'operator:vitor',
        );
    }

    public function test_rejects_malformed_actor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Actor must match');

        $this->contract->decide(
            $this->passingPreflight(),
            DurableExecutionDecisionContract::DECISION_EXECUTE,
            'just-vitor', // missing role prefix
        );
    }

    public function test_actor_accepts_operator_service_agent_prefixes(): void
    {
        foreach (['operator:vitor', 'service:dev-router', 'agent:claude-cli'] as $actor) {
            $decision = $this->contract->decide(
                $this->blockedPreflight(),
                DurableExecutionDecisionContract::DECISION_DEFER,
                $actor,
            );
            $this->assertSame($actor, $decision['actor']);
        }
    }

    public function test_envelope_shape_is_stable(): void
    {
        $decision = $this->contract->decide(
            $this->passingPreflight(),
            DurableExecutionDecisionContract::DECISION_EXECUTE,
            'operator:vitor',
        );

        $this->assertSame([
            'schema_version',
            'ap_reference',
            'decision',
            'decided_at',
            'actor',
            'reason',
            'preflight_status',
            'work_item_id',
            'plan_hash',
            'spec_hash',
            'forwards_to',
        ], array_keys($decision));
    }
}
