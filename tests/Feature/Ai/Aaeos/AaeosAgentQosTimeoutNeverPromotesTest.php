<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Services\Ai\EngineeringKernel\AgentQosExcellenceLaw;
use App\Services\Ai\EngineeringKernel\EliteExecutorKernel;
use App\Services\Ai\EngineeringKernel\EngineeringModeExecutionOrderFactory;
use Tests\TestCase;

/**
 * P2g-QOS: timeout/budget exhausted never promotes.
 */
final class AaeosAgentQosTimeoutNeverPromotesTest extends TestCase
{
    public function test_timeout_exhausted_blocks_promote(): void
    {
        $blockers = AgentQosExcellenceLaw::blockers([
            'timeout_exhausted' => true,
            'promote_requested' => true,
            'request_class' => AgentQosExcellenceLaw::CLASS_IMPL,
        ]);

        $this->assertContains('timeout_never_promotes', $blockers);
        $this->assertFalse(AgentQosExcellenceLaw::evaluate([
            'timeout_exhausted' => true,
            'promote_requested' => true,
        ])['accepted']);
    }

    public function test_budget_exhausted_blocks_promote(): void
    {
        $blockers = AgentQosExcellenceLaw::blockers([
            'budget_exhausted' => true,
            'promote_requested' => true,
        ]);

        $this->assertContains('timeout_never_promotes', $blockers);
    }

    public function test_timeout_without_promote_request_is_not_promote_blocker(): void
    {
        $blockers = AgentQosExcellenceLaw::blockers([
            'timeout_exhausted' => true,
            'promote_requested' => false,
            'mutate' => false,
        ]);

        $this->assertNotContains('timeout_never_promotes', $blockers);
    }

    public function test_kernel_blocks_mutative_when_timeout_and_promote_requested(): void
    {
        $order = (new EngineeringModeExecutionOrderFactory)->make([
            'mode' => 'dev',
            'risk_class' => 'R2',
            'work_topology' => 'single',
            'duration_regime' => 'interactive',
            'run_id' => 'run-p2g-qos-timeout',
            'delivery_id' => 'delivery-p2g-qos-timeout',
            'run_hash' => str_repeat('a3', 32),
            'decision_event_id' => 'decision-p2g-timeout',
            'product_intent_verdict_hash' => str_repeat('b3', 32),
            'spec_hash' => str_repeat('c3', 32),
            'world_model_snapshot_hash' => str_repeat('d3', 32),
            'workspace' => '/tmp/atlas-p2g-qos-timeout',
            'base_commit' => str_repeat('1', 40),
            'allowed_scope' => ['README.md'],
            'forbidden_scope' => ['.env'],
            'mutate' => true,
            'authority_envelope' => [
                'request_class' => AgentQosExcellenceLaw::CLASS_IMPL,
                'timeout_exhausted' => true,
                'promote_requested' => true,
            ],
        ]);

        $candidate = app(EliteExecutorKernel::class)->prepareMutativeCandidate($order);

        $this->assertSame('blocked', $candidate->status);
        $this->assertContains('timeout_never_promotes', $candidate->blockers);
        $this->assertFalse($candidate->authorityEligible);
    }
}
