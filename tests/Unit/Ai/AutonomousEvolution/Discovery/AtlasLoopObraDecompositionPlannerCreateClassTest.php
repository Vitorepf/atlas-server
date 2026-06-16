<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopObraDecompositionPlanner;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopObraPlanValidator;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopPlanReadinessGate;
use PHPUnit\Framework\TestCase;

/**
 * ITEM8 — proves the CREATE-FIRST DAG shape is gate-valid: a generator that emits a create-class node at
 * seq 0 + a redirect-caller node at seq 1 (depends_on the create node) passes the REAL readiness gate
 * (ready=true, decision=implement). The seq-0 placement is what makes the to-be-created class land BEFORE
 * the caller redirect (the executor walks nodes by seq ascending), fixing the class-not-found root.
 *
 * This is a NEW file — the frozen AtlasLoopObraDecompositionPlannerTest is left untouched.
 */
final class AtlasLoopObraDecompositionPlannerCreateClassTest extends TestCase
{
    /** @return array<string,mixed> the create-first DAG a planning generator should emit. */
    private function createFirstPlan(): array
    {
        return [
            'plan_id' => 'extract-helper-and-redirect',
            'nodes' => [
                [
                    'id' => 'create-helper',
                    'seq' => 0,
                    'request' => 'Create app/NewHelper.php holding the cohesive validation cluster extracted from the hub.',
                    'target_area' => 'app/NewHelper.php',
                    'depends_on' => [],
                    'complexity_proof' => true,
                ],
                [
                    'id' => 'redirect-hub',
                    'seq' => 1,
                    'request' => 'Redirect app/Services/Hub.php to delegate to the new app/NewHelper.php, preserving behaviour exactly.',
                    'target_area' => 'app/Services/Hub.php',
                    'depends_on' => ['create-helper'],
                    'complexity_proof' => true,
                ],
            ],
        ];
    }

    /** @return list<string> */
    private function allowed(): array
    {
        return ['app/NewHelper.php', 'app/Services/Hub.php'];
    }

    public function test_create_class_at_seq_0_plus_redirect_passes_the_readiness_gate(): void
    {
        $planner = new AtlasLoopObraDecompositionPlanner(
            new AtlasLoopPlanReadinessGate(new AtlasLoopObraPlanValidator)
        );

        $out = $planner->plan(
            'Extract the validation cluster into a new helper and redirect the hub',
            [],
            $this->allowed(),
            fn (): array => $this->createFirstPlan(),
            3,
        );

        $this->assertTrue($out['ready'], 'the create-first DAG is gate-valid: '.json_encode($out['gaps'] ?? []));
        $this->assertSame(AtlasLoopPlanReadinessGate::IMPLEMENT, $out['assessment']['decision'] ?? null);
        $this->assertSame(1, $out['attempts'], 'an impeccable plan is accepted on the first attempt');

        // The seq-0 create node survives normalize() into the returned plan (the executor reads it).
        $nodes = $out['plan']['nodes'] ?? [];
        $create = $nodes[0] ?? [];
        $this->assertSame('create-helper', $create['id'] ?? null);
        $this->assertSame(0, (int) ($create['seq'] ?? -1), 'the create-class node carries seq=0 through the planner');
    }

    public function test_redirect_without_the_create_node_referencing_its_target_replans(): void
    {
        // A create node whose request does NOT mention its target file is flagged by the readiness gate
        // ('request_does_not_reference_its_target'), so the create-first shape only passes when anchored.
        $planner = new AtlasLoopObraDecompositionPlanner(
            new AtlasLoopPlanReadinessGate(new AtlasLoopObraPlanValidator)
        );

        $floating = $this->createFirstPlan();
        $floating['nodes'][0]['request'] = 'Build the extracted cluster as a brand new cohesive class somewhere.';

        $out = $planner->plan('Extract the cluster', [], $this->allowed(), fn (): array => $floating, 1);

        $this->assertFalse($out['ready'], 'an unanchored create node REPLANs (never spends execution budget)');
        $this->assertContains('node_create-helper:request_does_not_reference_its_target', $out['gaps']);
    }
}
