<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\PlanVisible;

use App\Services\Ai\Programming\AtlasDev\PlanVisible\AtlasDevPlanApprovalGate;
use App\Services\Ai\Programming\AtlasDev\Schemas\PlanVisible;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AtlasDevPlanApprovalGateTest extends TestCase
{
    private AtlasDevPlanApprovalGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new AtlasDevPlanApprovalGate;
    }

    public function test_blocks_when_plan_is_missing(): void
    {
        $result = $this->gate->evaluate(null);

        $this->assertSame('provider_blocked', $result['decision']);
        $this->assertSame('no_plan_persisted', $result['block_reason']);
        $this->assertNull($result['plan_hash']);
        $this->assertContains('project_plan_via_AtlasDevPlanProjectionService', $result['operator_actions']);
    }

    public function test_blocks_when_plan_is_pending(): void
    {
        $plan = $this->makePlan(PlanVisible::APPROVAL_STATUS_PENDING);

        $result = $this->gate->evaluate($plan);

        $this->assertSame('provider_blocked', $result['decision']);
        $this->assertSame('plan_pending_operator_approval', $result['block_reason']);
        $this->assertSame('pending', $result['approval_status']);
        $this->assertContains('operator_ship_or_reject', $result['operator_actions']);
    }

    public function test_blocks_when_plan_is_rejected(): void
    {
        $plan = $this->makePlan(PlanVisible::APPROVAL_STATUS_REJECTED);

        $result = $this->gate->evaluate($plan);

        $this->assertSame('provider_blocked', $result['decision']);
        $this->assertSame('plan_rejected_by_operator', $result['block_reason']);
        $this->assertSame('rejected', $result['approval_status']);
    }

    public function test_allows_provider_when_plan_is_approved(): void
    {
        $plan = $this->makePlan(PlanVisible::APPROVAL_STATUS_APPROVED);

        $result = $this->gate->evaluate($plan);

        $this->assertSame('may_fire_provider', $result['decision']);
        $this->assertSame('approved', $result['approval_status']);
        $this->assertNull($result['block_reason']);
        $this->assertSame([], $result['operator_actions']);
    }

    public function test_emits_canonical_schema_version(): void
    {
        $result = $this->gate->evaluate(null);

        $this->assertSame('atlas.dev.plan_approval_decision.v1', $result['schema_version']);
    }

    public function test_apply_operator_decision_approve(): void
    {
        $plan = $this->makePlan(PlanVisible::APPROVAL_STATUS_PENDING);

        $next = $this->gate->applyOperatorDecision($plan, 'approve');

        $this->assertTrue($next->isApproved());
        $this->assertNotSame($plan->hash(), $next->hash());
    }

    public function test_apply_operator_decision_reject(): void
    {
        $plan = $this->makePlan(PlanVisible::APPROVAL_STATUS_PENDING);

        $next = $this->gate->applyOperatorDecision($plan, 'reject');

        $this->assertTrue($next->isRejected());
    }

    public function test_apply_operator_decision_rejects_unknown_command(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("must be 'approve' or 'reject'");

        $this->gate->applyOperatorDecision($this->makePlan(), 'maybe');
    }

    public function test_evaluate_then_apply_then_evaluate_round_trip(): void
    {
        $pending = $this->makePlan(PlanVisible::APPROVAL_STATUS_PENDING);
        $beforeApproval = $this->gate->evaluate($pending);
        $this->assertSame('provider_blocked', $beforeApproval['decision']);

        $approved = $this->gate->applyOperatorDecision($pending, 'approve');
        $afterApproval = $this->gate->evaluate($approved);

        $this->assertSame('may_fire_provider', $afterApproval['decision']);
        $this->assertSame($approved->planHash, $afterApproval['plan_hash']);
    }

    public function test_envelope_shape_is_stable(): void
    {
        $result = $this->gate->evaluate(null);

        $this->assertSame([
            'schema_version',
            'decision',
            'plan_hash',
            'approval_status',
            'risk_band',
            'block_reason',
            'detail',
            'operator_actions',
        ], array_keys($result));
    }

    private function makePlan(string $status = PlanVisible::APPROVAL_STATUS_PENDING): PlanVisible
    {
        return PlanVisible::issue(
            runId: 'run-1',
            taskContractHash: 'sha256:c',
            targetFiles: ['app/Foo.php'],
            testsToRun: ['tests/FooTest.php'],
            riskBand: PlanVisible::RISK_BAND_MEDIUM,
            proposedDiffSummary: 'adds bar()',
            approvalStatus: $status,
        );
    }
}
