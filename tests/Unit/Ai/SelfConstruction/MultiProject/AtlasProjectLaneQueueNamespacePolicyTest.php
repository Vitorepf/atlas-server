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

    public function test_relative_repo_root_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/repo_root must be an absolute path/');
        (new AtlasProjectLaneQueueNamespacePolicy)->derive($this->manifestFacts('demo', 'relative/path/proj'));
    }

    public function test_filesystem_root_repo_root_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/repo_root must not be filesystem root/');
        (new AtlasProjectLaneQueueNamespacePolicy)->derive($this->manifestFacts('demo', '/'));
    }

    public function test_traversal_in_repo_root_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/repo_root must not contain traversal/');
        (new AtlasProjectLaneQueueNamespacePolicy)->derive($this->manifestFacts('demo', '/valid/../etc/passwd'));
    }

    public function test_branch_with_special_chars_sanitizes_to_valid_namespace(): void
    {
        $p = new AtlasProjectLaneQueueNamespacePolicy;
        $facts = $p->derive($this->manifestFacts('demo', '/abs/path', 'feature/my-branch@2'));
        $this->assertStringContainsString('feature_my-branch_2', $facts['namespace']);
        $this->assertStringStartsWith('lane.demo.', $facts['namespace']);
    }

    public function test_lane_prefix_smuggling_with_empty_task_segment_throws(): void
    {
        $p = new AtlasProjectLaneQueueNamespacePolicy;
        $facts = $p->derive($this->manifestFacts());
        $smuggled = $facts['namespace'].':';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/lane-prefix smuggling/');
        $p->namespacedTaskId($smuggled, $facts);
    }

    public function test_lane_prefixed_id_without_colon_throws_malformed(): void
    {
        $p = new AtlasProjectLaneQueueNamespacePolicy;
        $facts = $p->derive($this->manifestFacts());
        $noColon = $facts['namespace'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/malformed namespaced task_id/');
        $p->namespacedTaskId($noColon, $facts);
    }

    // ── new isolation facts: lane_id / prefixes / collision_risk_verdict ─────

    public function test_derive_includes_all_five_isolation_fact_keys(): void
    {
        $facts = (new AtlasProjectLaneQueueNamespacePolicy)->derive($this->manifestFacts());

        foreach (['lane_id', 'queue_prefix', 'task_id_prefix', 'storage_key_prefix', 'collision_risk_verdict'] as $key) {
            $this->assertArrayHasKey($key, $facts, "Missing isolation fact: {$key}");
            $this->assertNotEmpty($facts[$key], "Isolation fact '{$key}' must not be empty");
        }
    }

    public function test_lane_id_does_not_carry_the_lane_dot_prefix(): void
    {
        $facts = (new AtlasProjectLaneQueueNamespacePolicy)->derive($this->manifestFacts('myproject'));
        // lane_id = project_id.repoHash.branch (no "lane." prefix)
        $this->assertStringStartsWith('myproject.', $facts['lane_id']);
        $this->assertStringNotContainsString('lane.', $facts['lane_id']);
    }

    public function test_task_id_prefix_appended_to_bare_task_matches_namespaced_task_id(): void
    {
        $p     = new AtlasProjectLaneQueueNamespacePolicy;
        $facts = $p->derive($this->manifestFacts());

        $this->assertSame($facts['task_id_prefix'].'PACKET-7', $p->namespacedTaskId('PACKET-7', $facts));
    }

    public function test_storage_key_prefix_starts_with_store_and_namespace(): void
    {
        $facts = (new AtlasProjectLaneQueueNamespacePolicy)->derive($this->manifestFacts());
        $this->assertStringStartsWith('store.'.$facts['namespace'].'.', $facts['storage_key_prefix']);
    }

    public function test_collision_risk_verdict_is_safe_for_standard_project(): void
    {
        $facts = (new AtlasProjectLaneQueueNamespacePolicy)->derive($this->manifestFacts('demo', '/Users/me/proj', 'main'));
        $this->assertSame('safe', $facts['collision_risk_verdict']);
    }

    public function test_collision_risk_verdict_is_collision_risk_for_very_short_project_id(): void
    {
        $facts = (new AtlasProjectLaneQueueNamespacePolicy)->derive($this->manifestFacts('ab', '/abs/path', 'main'));
        $this->assertSame('collision_risk', $facts['collision_risk_verdict']);
    }

    // ── detectNamespaceCollision ──────────────────────────────────────────────

    public function test_detect_collision_safe_for_two_different_lanes(): void
    {
        $p      = new AtlasProjectLaneQueueNamespacePolicy;
        $factsA = $p->derive($this->manifestFacts('project-alpha', '/abs/alpha', 'main'));
        $factsB = $p->derive($this->manifestFacts('project-beta',  '/abs/beta',  'main'));

        $result = $p->detectNamespaceCollision($factsA, $factsB);
        $this->assertFalse($result['collision']);
        $this->assertSame('safe', $result['reason']);
    }

    public function test_detect_collision_detected_for_identical_namespace(): void
    {
        $p      = new AtlasProjectLaneQueueNamespacePolicy;
        $facts  = $p->derive($this->manifestFacts());

        $result = $p->detectNamespaceCollision($facts, $facts);
        $this->assertTrue($result['collision']);
        $this->assertSame('identical_namespace', $result['reason']);
    }

    public function test_detect_collision_missing_namespace_is_collision(): void
    {
        $p      = new AtlasProjectLaneQueueNamespacePolicy;
        $result = $p->detectNamespaceCollision([], ['namespace' => 'lane.x.abc.main']);
        $this->assertTrue($result['collision']);
        $this->assertSame('missing_namespace', $result['reason']);
    }
}
