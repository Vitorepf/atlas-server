<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\TaskQueue\AgentControlPlaneTaskDependencyClassifier;
use Tests\TestCase;

final class AgentControlPlaneTaskDependencyClassifierTest extends TestCase
{
    private function loader(array $nodes): \Closure
    {
        return static fn (string $id): ?array => $nodes[$id] ?? null;
    }

    public function test_explain_dependencies_reports_inflight_blocked_absent_and_cycle_broken(): void
    {
        $nodes = [
            'dep-inflight' => ['status' => 'claimed', 'metadata' => ['depends_on' => []]],
            'dep-blocked' => ['status' => 'blocked', 'metadata' => ['depends_on' => []]],
            'dep-cycle' => ['status' => 'claimed', 'metadata' => ['depends_on' => ['root']]],
        ];
        $candidate = [
            'task_packet_id' => 'root',
            'metadata' => ['depends_on' => ['dep-inflight', 'dep-blocked', 'dep-absent', 'dep-cycle']],
        ];

        $cache = [];
        $result = AgentControlPlaneTaskDependencyClassifier::explainDependencies($this->loader($nodes), $candidate, $cache);

        $this->assertSame('inflight', $result['verdict']);
        $this->assertSame(['dep-inflight'], $result['inflight_dependency_ids']);
        $this->assertSame(['dep-blocked'], $result['blocked_dependency_ids']);
        $this->assertSame(['dep-absent'], $result['absent_dependency_ids']);
        $this->assertSame(['dep-cycle'], $result['cycle_broken_dependency_ids']);
    }

    public function test_cyclic_dependency_is_fail_open_but_visible(): void
    {
        $nodes = [
            'dep-cycle' => ['status' => 'claimed', 'metadata' => ['depends_on' => ['root']]],
        ];
        $candidate = ['task_packet_id' => 'root', 'metadata' => ['depends_on' => ['dep-cycle']]];

        $cache = [];
        $classifyCache = [];
        $verdict = AgentControlPlaneTaskDependencyClassifier::classifyDependencies($this->loader($nodes), $candidate, $classifyCache);
        $explanation = AgentControlPlaneTaskDependencyClassifier::explainDependencies($this->loader($nodes), $candidate, $cache);

        $this->assertSame('met', $verdict);
        $this->assertSame('met', $explanation['verdict']);
        $this->assertSame(['dep-cycle'], $explanation['cycle_broken_dependency_ids']);
    }

    public function test_classify_dependencies_return_values_remain_backward_compatible(): void
    {
        $nodes = [
            'dep-done' => ['status' => 'completed_dry_run', 'metadata' => ['depends_on' => []]],
            'dep-inflight' => ['status' => 'claimed', 'metadata' => ['depends_on' => []]],
            'dep-blocked' => ['status' => 'blocked', 'metadata' => ['depends_on' => []]],
        ];

        $metCache = [];
        $this->assertSame('met', AgentControlPlaneTaskDependencyClassifier::classifyDependencies(
            $this->loader($nodes),
            ['task_packet_id' => 'root', 'metadata' => ['depends_on' => ['dep-done']]],
            $metCache,
        ));

        $inflightCache = [];
        $this->assertSame('inflight', AgentControlPlaneTaskDependencyClassifier::classifyDependencies(
            $this->loader($nodes),
            ['task_packet_id' => 'root', 'metadata' => ['depends_on' => ['dep-inflight', 'dep-blocked']]],
            $inflightCache,
        ));

        $blockedCache = [];
        $this->assertSame('blocked', AgentControlPlaneTaskDependencyClassifier::classifyDependencies(
            $this->loader($nodes),
            ['task_packet_id' => 'root', 'metadata' => ['depends_on' => ['dep-blocked']]],
            $blockedCache,
        ));
    }
}
