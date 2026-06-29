<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopObraPlanValidator;
use Tests\TestCase;

/**
 * Decomposition SAFETY GATE — frozen proof that any obra DAG is validated BEFORE execution: a
 * well-formed scoped plan passes; a single-node plan, a node escaping the allowed scope, a forbidden
 * self-target, an unverifiable node, a dependency cycle, a duplicate id, and a dangling dependency are
 * each REJECTED with a reason. This is what makes an ENORMOUS many-node obra safe to run autonomously.
 */
final class AtlasLoopObraPlanValidatorTest extends TestCase
{
    private function validator(): AtlasLoopObraPlanValidator
    {
        return new AtlasLoopObraPlanValidator();
    }

    private array $allowed = ['app/Services/Hub.php', 'app/Callers/CallerA.php'];

    private function node(string $id, string $file, array $extra = []): array
    {
        return array_merge([
            'id' => $id,
            'seq' => 0,
            'request' => 'reduce complexity in '.$file,
            'target_area' => $file,
            'acceptance' => ['commands' => ['./vendor/bin/phpunit tests/Unit/HubTest.php']],
        ], $extra);
    }

    public function test_a_well_formed_scoped_acyclic_plan_is_valid(): void
    {
        $plan = ['plan_id' => 'obra-ok', 'nodes' => [
            $this->node('n1', 'app/Services/Hub.php'),
            $this->node('n2', 'app/Callers/CallerA.php', ['depends_on' => ['n1']]),
        ]];
        $r = $this->validator()->validate($plan, $this->allowed);
        $this->assertTrue($r['valid'], json_encode($r['reasons']));
        $this->assertSame(2, $r['node_count']);
    }

    public function test_single_node_is_not_a_decomposition(): void
    {
        $plan = ['plan_id' => 'obra-1', 'nodes' => [$this->node('n1', 'app/Services/Hub.php')]];
        $r = $this->validator()->validate($plan, $this->allowed);
        $this->assertFalse($r['valid']);
        $this->assertContains('not_a_decomposition:node_count<2', $r['reasons']);
    }

    public function test_a_node_escaping_the_allowed_scope_is_rejected(): void
    {
        $plan = ['plan_id' => 'obra-esc', 'nodes' => [
            $this->node('n1', 'app/Services/Hub.php'),
            $this->node('n2', 'app/Secret/Other.php'), // not in allowed
        ]];
        $r = $this->validator()->validate($plan, $this->allowed);
        $this->assertFalse($r['valid']);
        $this->assertStringContainsString('file_outside_allowed_scope:app/Secret/Other.php', implode('|', $r['reasons']));
    }

    public function test_a_forbidden_self_target_node_is_rejected(): void
    {
        $plan = ['plan_id' => 'obra-self', 'nodes' => [
            $this->node('n1', 'app/Services/Hub.php'),
            $this->node('n2', 'app/Services/Ai/AutonomousEvolution/AtlasLoopAutoMergeService.php'),
        ]];
        $r = $this->validator()->validate($plan, []);
        $this->assertFalse($r['valid']);
        $this->assertStringContainsString('forbidden_self_target', implode('|', $r['reasons']));
    }

    public function test_an_unverifiable_node_is_rejected(): void
    {
        $plan = ['plan_id' => 'obra-unv', 'nodes' => [
            $this->node('n1', 'app/Services/Hub.php'),
            ['id' => 'n2', 'request' => 'do a thing', 'target_area' => 'app/Callers/CallerA.php'], // no acceptance
        ]];
        $r = $this->validator()->validate($plan, $this->allowed);
        $this->assertFalse($r['valid']);
        $this->assertContains('node_n2:unverifiable_no_acceptance', $r['reasons']);
    }

    public function test_a_dependency_cycle_is_detected(): void
    {
        $plan = ['plan_id' => 'obra-cyc', 'nodes' => [
            $this->node('n1', 'app/Services/Hub.php', ['depends_on' => ['n2']]),
            $this->node('n2', 'app/Callers/CallerA.php', ['depends_on' => ['n1']]),
        ]];
        $r = $this->validator()->validate($plan, $this->allowed);
        $this->assertFalse($r['valid']);
        $this->assertContains('dependency_cycle_detected', $r['reasons']);
    }

    public function test_allowed_files_only_node_forbidden_edge_is_flagged(): void
    {
        // create-class node: no target_area, new file declared via allowed_files
        $newFile = 'app/New/NewClass.php';
        $forbiddenDep = 'app/Forbidden/ForbiddenTarget.php';

        $plan = ['nodes' => [
            ['id' => 'n1', 'allowed_files' => [$newFile], 'depends_on' => ['n2']],
            ['id' => 'n2', 'target_area' => $forbiddenDep],
        ]];
        $contract = [
            $newFile => ['forbidden_depend_on' => [$forbiddenDep]],
        ];

        $gaps = $this->validator()->assertDecompositionEdges($plan, $contract);

        $this->assertContains(
            'node_interface_inverted_edge:'.$newFile.'->'.$forbiddenDep,
            $gaps,
            'a create-class node with only allowed_files must be checked for forbidden edges'
        );
    }

    public function test_duplicate_node_id_and_dangling_dependency_are_rejected(): void
    {
        $plan = ['plan_id' => 'obra-dup', 'nodes' => [
            $this->node('n1', 'app/Services/Hub.php'),
            $this->node('n1', 'app/Callers/CallerA.php', ['depends_on' => ['ghost']]), // dup id + dangling dep
        ]];
        $r = $this->validator()->validate($plan, $this->allowed);
        $this->assertFalse($r['valid']);
        $this->assertContains('node_n1:duplicate_id', $r['reasons']);
        $this->assertStringContainsString('depends_on_undeclared:ghost', implode('|', $r['reasons']));
    }
}
