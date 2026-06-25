<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\MultiProject;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneQueueNamespacePolicy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves AtlasProjectLaneQueueNamespacePolicy: derive() yields a stable namespace from the (project_id,
 * repo_root, mainline_branch) tuple; two different repo_roots ⇒ two different namespaces; empty
 * project_id and unsafe characters throw; an already-namespaced task_id is returned verbatim, but a
 * task_id namespaced for a DIFFERENT lane is refused (cross-lane); execution_topology stays
 * shared_local_main_with_scope_lock.
 */
final class AtlasProjectLaneQueueNamespacePolicyTest extends TestCase
{
    private function manifestFacts(string $projectId = 'demo', string $repoRoot = '/Users/me/proj', string $branch = 'main'): array
    {
        return ['project_id' => $projectId, 'repo_root' => $repoRoot, 'mainline_branch' => $branch];
    }

    public function test_derive_produces_stable_namespace_for_identical_facts(): void
    {
        $p = new AtlasProjectLaneQueueNamespacePolicy;
        $a = $p->derive($this->manifestFacts());
        $b = $p->derive($this->manifestFacts());
        $this->assertSame($a['namespace'], $b['namespace']);
        $this->assertStringStartsWith('lane.demo.', $a['namespace']);
        $this->assertSame('queue.'.$a['namespace'], $a['queue_key']);
        $this->assertSame(AtlasProjectLaneQueueNamespacePolicy::EXECUTION_TOPOLOGY, $a['execution_topology']);
    }

    public function test_different_repo_roots_produce_different_namespaces(): void
    {
        $p = new AtlasProjectLaneQueueNamespacePolicy;
        $a = $p->derive($this->manifestFacts('demo', '/Users/me/proj-A', 'main'));
        $b = $p->derive($this->manifestFacts('demo', '/Users/me/proj-B', 'main'));
        $this->assertNotSame($a['namespace'], $b['namespace']);
    }

    public function test_empty_project_id_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/empty project_id/');
        (new AtlasProjectLaneQueueNamespacePolicy)->derive($this->manifestFacts(''));
    }

    public function test_unsafe_characters_in_project_id_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/unsafe characters/');
        (new AtlasProjectLaneQueueNamespacePolicy)->derive($this->manifestFacts('bad project/../etc'));
    }

    public function test_already_namespaced_same_lane_task_id_is_returned_verbatim(): void
    {
        $p = new AtlasProjectLaneQueueNamespacePolicy;
        $facts = $p->derive($this->manifestFacts());
        $tid = $facts['namespace'].':PACKET-42';
        $this->assertSame($tid, $p->namespacedTaskId($tid, $facts));
    }

    public function test_cross_lane_namespaced_task_id_is_refused(): void
    {
        $p = new AtlasProjectLaneQueueNamespacePolicy;
        $factsA = $p->derive($this->manifestFacts('lane-a'));
        $factsB = $p->derive($this->manifestFacts('lane-b'));
        $crossId = $factsB['namespace'].':PACKET-99';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cross-lane refusal/');
        $p->namespacedTaskId($crossId, $factsA);
    }

    public function test_bare_task_id_gets_lane_namespace_prepended(): void
    {
        $p = new AtlasProjectLaneQueueNamespacePolicy;
        $facts = $p->derive($this->manifestFacts());
        $out = $p->namespacedTaskId('PACKET-1', $facts);
        $this->assertSame($facts['namespace'].':PACKET-1', $out);
    }
}
