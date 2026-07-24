<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Services\Ai\EngineeringKernel\AgentQosExcellenceLaw;
use App\Services\Ai\EngineeringKernel\EliteExecutorKernel;
use App\Services\Ai\EngineeringKernel\EngineeringModeExecutionOrderFactory;
use Tests\TestCase;

/**
 * P2g-QOS: C_ARCH cannot land product mutation without reclass.
 */
final class AaeosAgentQosArchitectureRefuseMutateTest extends TestCase
{
    public function test_law_blocks_architecture_mutate(): void
    {
        $blockers = AgentQosExcellenceLaw::blockers([
            'request_class' => AgentQosExcellenceLaw::CLASS_ARCH,
            'mutate' => true,
            'architecture_candidates_count' => 3,
        ]);

        $this->assertContains('architecture_mutate_refused', $blockers);
    }

    public function test_kernel_prepare_blocks_c_arch_mutate_before_provider(): void
    {
        $order = (new EngineeringModeExecutionOrderFactory)->make([
            'mode' => 'dev',
            'risk_class' => 'R2',
            'work_topology' => 'single',
            'duration_regime' => 'interactive',
            'run_id' => 'run-p2g-qos-arch',
            'delivery_id' => 'delivery-p2g-qos-arch',
            'run_hash' => str_repeat('a1', 32),
            'decision_event_id' => 'decision-p2g-arch',
            'product_intent_verdict_hash' => str_repeat('b1', 32),
            'spec_hash' => str_repeat('c1', 32),
            'world_model_snapshot_hash' => str_repeat('d1', 32),
            'workspace' => '/tmp/atlas-p2g-qos-arch',
            'base_commit' => str_repeat('e', 40),
            'allowed_scope' => ['README.md'],
            'forbidden_scope' => ['.env'],
            'mutate' => true,
            'authority_envelope' => [
                'request_class' => AgentQosExcellenceLaw::CLASS_ARCH,
                'architecture_candidates_count' => 3,
            ],
        ]);

        $candidate = app(EliteExecutorKernel::class)->prepareMutativeCandidate($order);

        $this->assertSame('blocked', $candidate->status);
        $this->assertFalse($candidate->authorityEligible);
        $this->assertContains('architecture_mutate_refused', $candidate->blockers);
        $this->assertSame('', $candidate->sandboxRoot);
    }
}
