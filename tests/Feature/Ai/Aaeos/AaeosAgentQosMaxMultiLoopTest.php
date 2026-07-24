<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Services\Ai\EngineeringKernel\AgentQosExcellenceLaw;
use App\Services\Ai\EngineeringKernel\EliteExecutorKernel;
use App\Services\Ai\EngineeringKernel\EngineeringModeExecutionOrderFactory;
use Tests\TestCase;

/**
 * P2g-QOS: C_ARCH×max requires multi-candidate sampling; vanity dial refused.
 */
final class AaeosAgentQosMaxMultiLoopTest extends TestCase
{
    public function test_arch_max_under_sampled_with_one_candidate(): void
    {
        $blockers = AgentQosExcellenceLaw::blockers([
            'request_class' => AgentQosExcellenceLaw::CLASS_ARCH,
            'architecture_candidates_count' => 1,
            'mutate' => false,
        ]);

        $this->assertContains('architecture_under_sampled', $blockers);
        $this->assertSame(
            AgentQosExcellenceLaw::DEPTH_MAX,
            AgentQosExcellenceLaw::resolveDepth(['request_class' => AgentQosExcellenceLaw::CLASS_ARCH]),
        );
    }

    public function test_arch_max_accepts_three_candidates_without_mutate(): void
    {
        $eval = AgentQosExcellenceLaw::evaluate([
            'request_class' => AgentQosExcellenceLaw::CLASS_ARCH,
            'architecture_candidates_count' => 3,
            'mutate' => false,
        ]);

        $this->assertTrue($eval['accepted']);
        $this->assertSame(AgentQosExcellenceLaw::DEPTH_MAX, $eval['excellence_depth']);
        $this->assertSame([], $eval['blockers']);
    }

    public function test_vanity_quality_ceiling_dial_forbidden(): void
    {
        $blockers = AgentQosExcellenceLaw::blockers([
            'request_class' => AgentQosExcellenceLaw::CLASS_IMPL,
            'quality_ceiling' => 'max',
            'quality_ceiling_changes_promote_alone' => true,
        ]);

        $this->assertContains('vanity_quality_ceiling_dial_forbidden', $blockers);
    }

    public function test_kernel_blocks_under_sampled_arch_before_provider(): void
    {
        $order = (new EngineeringModeExecutionOrderFactory)->make([
            'mode' => 'dev',
            'risk_class' => 'R2',
            'work_topology' => 'single',
            'duration_regime' => 'interactive',
            'run_id' => 'run-p2g-qos-max',
            'delivery_id' => 'delivery-p2g-qos-max',
            'run_hash' => str_repeat('a2', 32),
            'decision_event_id' => 'decision-p2g-max',
            'product_intent_verdict_hash' => str_repeat('b2', 32),
            'spec_hash' => str_repeat('c2', 32),
            'world_model_snapshot_hash' => str_repeat('d2', 32),
            'workspace' => '/tmp/atlas-p2g-qos-max',
            'base_commit' => str_repeat('f', 40),
            'allowed_scope' => ['README.md'],
            'forbidden_scope' => ['.env'],
            'mutate' => true,
            'authority_envelope' => [
                'request_class' => AgentQosExcellenceLaw::CLASS_ARCH,
                'architecture_candidates_count' => 1,
            ],
        ]);

        $candidate = app(EliteExecutorKernel::class)->prepareMutativeCandidate($order);

        $this->assertSame('blocked', $candidate->status);
        $this->assertContains('architecture_under_sampled', $candidate->blockers);
        $this->assertContains('architecture_mutate_refused', $candidate->blockers);
    }

    public function test_no_productive_quality_ceiling_cli_option(): void
    {
        $consoleRoot = base_path('app/Console');
        $hits = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($consoleRoot));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            if (str_contains($contents, 'quality_ceiling')) {
                $hits[] = $file->getPathname();
            }
        }

        $this->assertSame([], $hits, 'productive quality_ceiling CLI dial must not exist');
    }
}
