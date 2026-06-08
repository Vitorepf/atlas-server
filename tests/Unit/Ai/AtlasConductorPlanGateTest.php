<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Services\Ai\AtlasDecide\AtlasConductorPlanGate;
use Tests\TestCase;

class AtlasConductorPlanGateTest extends TestCase
{
    private function gate(): AtlasConductorPlanGate
    {
        return app(AtlasConductorPlanGate::class);
    }

    /**
     * @param  list<string>  $deps
     * @return array<string,mixed>
     */
    private function node(string $id, string $task, string $role, array $deps = []): array
    {
        return ['node_id' => $id, 'task_category' => $task, 'role' => $role, 'depends_on' => $deps];
    }

    public function test_valid_plan_orders_nodes_topologically(): void
    {
        $plan = ['nodes' => [
            $this->node('reason', 'reasoning', 'engineer', ['gather']),
            $this->node('gather', 'retrieval', 'researcher'),
        ]];

        $r = $this->gate()->validate($plan, ['task_category' => 'build', 'input' => 'do the thing']);

        $this->assertTrue($r['ok'], 'blocked reason: '.(string) $r['reason']);
        $this->assertSame(['gather', 'reason'], array_map(static fn (array $n): string => $n['node_id'], $r['ordered']));
    }

    public function test_cyclic_plan_is_blocked(): void
    {
        $plan = ['nodes' => [
            $this->node('a', 'retrieval', 'r', ['b']),
            $this->node('b', 'reasoning', 'e', ['a']),
        ]];
        $r = $this->gate()->validate($plan, []);

        $this->assertFalse($r['ok']);
        $this->assertSame('plan_has_cycle', $r['reason']);
        $this->assertSame([], $r['ordered']);
    }

    public function test_unresolved_dependency_is_blocked(): void
    {
        $r = $this->gate()->validate(['nodes' => [$this->node('a', 'retrieval', 'r', ['ghost'])]], []);

        $this->assertFalse($r['ok']);
        $this->assertSame('plan_unresolved_dependency', $r['reason']);
    }

    public function test_duplicate_node_id_is_blocked(): void
    {
        $r = $this->gate()->validate(['nodes' => [$this->node('a', 'retrieval', 'r'), $this->node('a', 'reasoning', 'e')]], []);

        $this->assertFalse($r['ok']);
        $this->assertSame('plan_duplicate_node_id', $r['reason']);
    }

    public function test_over_cap_plan_is_blocked(): void
    {
        $nodes = [];
        for ($i = 0; $i <= AtlasConductorPlanGate::MAX_NODES; $i++) {
            $nodes[] = $this->node('n'.$i, 'retrieval', 'r');
        }
        $r = $this->gate()->validate(['nodes' => $nodes], []);

        $this->assertFalse($r['ok']);
        $this->assertSame('plan_exceeds_max_nodes', $r['reason']);
    }

    public function test_node_missing_task_or_role_is_blocked(): void
    {
        $r = $this->gate()->validate(['nodes' => [['node_id' => 'a', 'task_category' => '', 'role' => 'r']]], []);

        $this->assertFalse($r['ok']);
        $this->assertSame('plan_node_missing_id_task_or_role', $r['reason']);
    }

    public function test_empty_plan_is_blocked(): void
    {
        $r = $this->gate()->validate(['nodes' => []], []);

        $this->assertFalse($r['ok']);
        $this->assertSame('plan_has_no_nodes', $r['reason']);
    }
}
