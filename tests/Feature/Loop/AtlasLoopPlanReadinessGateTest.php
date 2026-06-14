<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopPlanReadinessGate;
use Tests\TestCase;

/**
 * PLAN-READINESS GATE — frozen proof of "plan impeccably, then implement": the EXPENSIVE
 * implementation only runs on a plan that is structurally sound, fully specified, and pre-verified.
 * A vague step, a step with no named target, or a step with no pre-defined acceptance => REPLAN
 * (cheap), never IMPLEMENT-and-discard (expensive). This is how the loop almost never wastes tokens.
 */
final class AtlasLoopPlanReadinessGateTest extends TestCase
{
    private function gate(): AtlasLoopPlanReadinessGate
    {
        return new AtlasLoopPlanReadinessGate();
    }

    private array $allowed = ['app/Services/Hub.php', 'app/Callers/CallerA.php'];

    private function goodNode(string $id, string $file): array
    {
        return [
            'id' => $id,
            'seq' => 0,
            'request' => 'Reduce the worst-method cyclomatic complexity of '.$file.' preserving behaviour.',
            'target_area' => $file,
            'complexity_proof' => true,
        ];
    }

    public function test_an_impeccable_plan_earns_implement(): void
    {
        $plan = ['plan_id' => 'obra-ready', 'nodes' => [
            $this->goodNode('n1', 'app/Services/Hub.php'),
            $this->goodNode('n2', 'app/Callers/CallerA.php'),
        ]];
        $r = $this->gate()->assess($plan, $this->allowed);
        $this->assertTrue($r['ready'], json_encode($r['gaps']));
        $this->assertSame(AtlasLoopPlanReadinessGate::IMPLEMENT, $r['decision']);
        $this->assertSame(1.0, $r['readiness_score']);
    }

    public function test_a_vague_step_forces_replan_not_implement(): void
    {
        $plan = ['plan_id' => 'obra-vague', 'nodes' => [
            $this->goodNode('n1', 'app/Services/Hub.php'),
            ['id' => 'n2', 'request' => 'fix it', 'target_area' => 'app/Callers/CallerA.php', 'complexity_proof' => true],
        ]];
        $r = $this->gate()->assess($plan, $this->allowed);
        $this->assertFalse($r['ready']);
        $this->assertSame(AtlasLoopPlanReadinessGate::REPLAN, $r['decision'], 'a vague step is cheap to replan, expensive to implement-and-discard');
        $this->assertContains('node_n2:request_too_vague', $r['gaps']);
    }

    public function test_a_step_with_no_predefined_acceptance_forces_replan(): void
    {
        $plan = ['plan_id' => 'obra-unv', 'nodes' => [
            $this->goodNode('n1', 'app/Services/Hub.php'),
            // long, references its target, but NO acceptance defined up front.
            ['id' => 'n2', 'request' => 'Refactor app/Callers/CallerA.php to extract a helper method.', 'target_area' => 'app/Callers/CallerA.php'],
        ]];
        $r = $this->gate()->assess($plan, $this->allowed);
        $this->assertFalse($r['ready']);
        $this->assertContains('node_n2:no_predefined_acceptance', $r['gaps'], 'we must know HOW we will verify it before spending to build it');
    }

    public function test_a_step_that_does_not_reference_its_target_forces_replan(): void
    {
        $plan = ['plan_id' => 'obra-float', 'nodes' => [
            $this->goodNode('n1', 'app/Services/Hub.php'),
            ['id' => 'n2', 'request' => 'Make the code generally cleaner and nicer somehow please.', 'target_area' => 'app/Callers/CallerA.php', 'complexity_proof' => true],
        ]];
        $r = $this->gate()->assess($plan, $this->allowed);
        $this->assertFalse($r['ready']);
        $this->assertContains('node_n2:request_does_not_reference_its_target', $r['gaps']);
    }

    public function test_a_structurally_invalid_plan_forces_replan(): void
    {
        $plan = ['plan_id' => 'obra-1', 'nodes' => [$this->goodNode('n1', 'app/Services/Hub.php')]]; // single node
        $r = $this->gate()->assess($plan, $this->allowed);
        $this->assertFalse($r['ready']);
        $this->assertFalse($r['structural_valid']);
        $this->assertSame(AtlasLoopPlanReadinessGate::REPLAN, $r['decision']);
    }
}
