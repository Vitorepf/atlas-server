<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopNodeInterfaceContract;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopObraDecompositionPlanner;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopPlanReadinessGate;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ACDE Leap 6 (plan-time) — the readiness gate consults the human-frozen interface contract's seam-to-seam
 * edge rules and REPLANS a DAG whose nodes carry a missing required edge or a forbidden (inverted) edge,
 * feeding the planner its first machine DESIGN-steering gap. Flag OFF / no contract => byte-identical.
 */
final class AtlasLoopNodeInterfacePlanGateTest extends TestCase
{
    private string $dir = '';

    private array $allowed = ['app/Support/HubHelper.php', 'app/Services/Hub.php'];

    private string $goal = 'Extract a HubHelper from app/Services/Hub.php with a clean inward dependency.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/atlas-ifaceedge-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0o755, true);
        config()->set('atlas.loop.interface_contract_dir', $this->dir);
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            (new Process(['rm', '-rf', $this->dir]))->run();
        }
        parent::tearDown();
    }

    private function node(string $id, string $file, array $deps = []): array
    {
        return [
            'id' => $id,
            'seq' => $deps === [] ? 0 : 1,
            'request' => 'Reduce the worst-method cyclomatic complexity of '.$file.' preserving behaviour.',
            'target_area' => $file,
            'depends_on' => $deps,
            'complexity_proof' => true,
        ];
    }

    /** The helper MUST NOT depend on the hub (anti-inversion); the hub MUST depend on the helper. */
    private function freezeEdges(): void
    {
        $r = new AtlasLoopNodeInterfaceContract($this->dir);
        file_put_contents($r->fixturePath($this->goal), json_encode(['files' => [
            'app/Support/HubHelper.php' => ['forbidden_depend_on' => ['app/Services/Hub.php']],
            'app/Services/Hub.php' => ['must_depend_on' => ['app/Support/HubHelper.php']],
        ]]));
    }

    public function test_an_inverted_edge_is_refused(): void
    {
        config()->set('atlas.loop.node_interface_plan_gate_enabled', true);
        $this->freezeEdges();

        // The helper node depends_on the hub node — the dependency-INVERSION the file-only oracle cannot see.
        // Expressed ACYCLICALLY (helper->hub, hub->nothing) so the structural validator passes and ONLY the
        // seam-edge rule refuses it.
        $plan = ['plan_id' => 'p', 'nodes' => [
            $this->node('helper', 'app/Support/HubHelper.php', ['hub']),
            $this->node('hub', 'app/Services/Hub.php'),
        ]];

        $r = (new AtlasLoopPlanReadinessGate)->assess($plan, $this->allowed, $this->goal);

        $this->assertFalse($r['ready']);
        $this->assertTrue($r['structural_valid'], 'structurally sound — only the seam-edge rule refuses it');
        $this->assertContains('node_interface_inverted_edge:app/Support/HubHelper.php->app/Services/Hub.php', $r['gaps']);
        $this->assertSame(AtlasLoopPlanReadinessGate::REPLAN, $r['decision']);
    }

    public function test_a_missing_required_edge_is_refused(): void
    {
        config()->set('atlas.loop.node_interface_plan_gate_enabled', true);
        $this->freezeEdges();

        // The hub node does NOT depend on the helper (the required inward edge is absent).
        $plan = ['plan_id' => 'p', 'nodes' => [
            $this->node('helper', 'app/Support/HubHelper.php'),
            $this->node('hub', 'app/Services/Hub.php'),
        ]];

        $r = (new AtlasLoopPlanReadinessGate)->assess($plan, $this->allowed, $this->goal);

        $this->assertFalse($r['ready']);
        $this->assertContains('node_interface_missing_edge:app/Services/Hub.php->app/Support/HubHelper.php', $r['gaps']);
    }

    public function test_a_correct_edge_structure_passes(): void
    {
        config()->set('atlas.loop.node_interface_plan_gate_enabled', true);
        $this->freezeEdges();

        // helper depends on nothing forbidden; hub depends on the helper — the right inward direction.
        $plan = ['plan_id' => 'p', 'nodes' => [
            $this->node('helper', 'app/Support/HubHelper.php'),
            $this->node('hub', 'app/Services/Hub.php', ['helper']),
        ]];

        $r = (new AtlasLoopPlanReadinessGate)->assess($plan, $this->allowed, $this->goal);

        $this->assertTrue($r['ready'], json_encode($r['gaps']));
    }

    public function test_flag_off_is_byte_identical(): void
    {
        $this->freezeEdges(); // fixture present but flag OFF => never consulted
        config()->set('atlas.loop.node_interface_plan_gate_enabled', false);

        // The inverted (but ACYCLIC) plan the gate WOULD refuse when the flag is ON.
        $plan = ['plan_id' => 'p', 'nodes' => [
            $this->node('helper', 'app/Support/HubHelper.php', ['hub']),
            $this->node('hub', 'app/Services/Hub.php'),
        ]];

        $withGoal = (new AtlasLoopPlanReadinessGate)->assess($plan, $this->allowed, $this->goal);
        $noGoal = (new AtlasLoopPlanReadinessGate)->assess($plan, $this->allowed);

        $this->assertSame($noGoal, $withGoal, 'flag OFF => byte-identical to the no-goal path');
        $this->assertTrue($withGoal['ready']);
    }

    public function test_the_inverted_edge_gap_round_trips_through_the_planner(): void
    {
        config()->set('atlas.loop.node_interface_plan_gate_enabled', true);
        $this->freezeEdges();

        $calls = 0;
        $generate = function (string $goal, array $context, array $priorGaps) use (&$calls): array {
            $calls++;
            if ($calls === 1) {
                return ['plan_id' => 'p1', 'nodes' => [
                    $this->node('helper', 'app/Support/HubHelper.php', ['hub']), // inverted (acyclic: helper->hub)
                    $this->node('hub', 'app/Services/Hub.php'),
                ]];
            }
            $this->assertContains('node_interface_inverted_edge:app/Support/HubHelper.php->app/Services/Hub.php', $priorGaps);

            return ['plan_id' => 'p2', 'nodes' => [
                $this->node('helper', 'app/Support/HubHelper.php'),
                $this->node('hub', 'app/Services/Hub.php', ['helper']), // corrected direction
            ]];
        };

        $out = (new AtlasLoopObraDecompositionPlanner)->plan($this->goal, [], $this->allowed, $generate, 3);

        $this->assertTrue($out['ready'], json_encode($out['gaps']));
        $this->assertSame(2, $out['attempts']);
    }
}
