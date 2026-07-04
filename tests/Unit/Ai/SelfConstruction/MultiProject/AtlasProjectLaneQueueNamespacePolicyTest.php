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

    // ── AC4: validateManifestFacts() — non-throwing repair hints ────────────────

    public function test_validate_manifest_facts_valid_for_healthy_facts(): void
    {
        $result = (new AtlasProjectLaneQueueNamespacePolicy)->validateManifestFacts($this->manifestFacts());

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['repair_hints']);
    }

    public function test_validate_manifest_facts_hints_empty_project_id(): void
    {
        $result = (new AtlasProjectLaneQueueNamespacePolicy)->validateManifestFacts($this->manifestFacts(''));

        $this->assertFalse($result['valid']);
        $this->assertContains('set_a_non_empty_project_id', $result['repair_hints']);
    }

    public function test_validate_manifest_facts_hints_unsafe_project_id_characters(): void
    {
        $result = (new AtlasProjectLaneQueueNamespacePolicy)->validateManifestFacts($this->manifestFacts('bad project/../etc'));

        $this->assertFalse($result['valid']);
        $this->assertContains('project_id_must_only_contain_letters_digits_underscore_or_hyphen', $result['repair_hints']);
    }

    public function test_validate_manifest_facts_hints_relative_repo_root(): void
    {
        $result = (new AtlasProjectLaneQueueNamespacePolicy)->validateManifestFacts($this->manifestFacts('demo', 'relative/path/proj'));

        $this->assertFalse($result['valid']);
        $this->assertContains('repo_root_must_be_an_absolute_path', $result['repair_hints']);
    }

    public function test_validate_manifest_facts_hints_filesystem_root_repo_root(): void
    {
        $result = (new AtlasProjectLaneQueueNamespacePolicy)->validateManifestFacts($this->manifestFacts('demo', '/'));

        $this->assertFalse($result['valid']);
        $this->assertContains('repo_root_must_not_be_filesystem_root', $result['repair_hints']);
    }

    public function test_validate_manifest_facts_hints_traversal_in_repo_root(): void
    {
        $result = (new AtlasProjectLaneQueueNamespacePolicy)->validateManifestFacts($this->manifestFacts('demo', '/valid/../etc/passwd'));

        $this->assertFalse($result['valid']);
        $this->assertContains('repo_root_must_not_contain_traversal_segments', $result['repair_hints']);
    }

    public function test_validate_manifest_facts_hints_empty_mainline_branch(): void
    {
        $result = (new AtlasProjectLaneQueueNamespacePolicy)->validateManifestFacts($this->manifestFacts('demo', '/abs/path', ''));

        $this->assertFalse($result['valid']);
        $this->assertContains('set_a_non_empty_mainline_branch', $result['repair_hints']);
    }

    public function test_validate_manifest_facts_accumulates_multiple_hints(): void
    {
        $result = (new AtlasProjectLaneQueueNamespacePolicy)->validateManifestFacts([
            'project_id' => '',
            'repo_root' => 'relative/path',
            'mainline_branch' => '',
        ]);

        $this->assertFalse($result['valid']);
        $this->assertCount(3, $result['repair_hints']);
    }

    public function test_validate_manifest_facts_never_throws_unlike_derive(): void
    {
        $result = (new AtlasProjectLaneQueueNamespacePolicy)->validateManifestFacts([]);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['repair_hints']);
    }

    public function test_valid_manifest_facts_from_validation_succeed_in_derive(): void
    {
        $p = new AtlasProjectLaneQueueNamespacePolicy;
        $facts = $this->manifestFacts();

        $validation = $p->validateManifestFacts($facts);
        $this->assertTrue($validation['valid']);

        // No exception should be thrown given valid facts.
        $derived = $p->derive($facts);
        $this->assertNotEmpty($derived['namespace']);
    }

    // ── queueNamespaceIsolation: namespace_key, isolation_ok, collision_reasons, repair_hint ──

    public function test_isolated_namespace_is_ok(): void
    {
        $r = (new AtlasProjectLaneQueueNamespacePolicy)->queueNamespaceIsolation([
            'project_id' => 'atlas',
            'lane_namespace' => 'lane-1',
            'queued_targets' => [],
            'target_project' => 'atlas',
            'target_lane' => 'lane-1',
            'namespace_age_seconds' => 100,
        ]);
        $this->assertSame('atlas:lane-1', $r['namespace_key']);
        $this->assertTrue($r['isolation_ok']);
        $this->assertSame([], $r['collision_reasons']);
        $this->assertSame('no_action_required', $r['repair_hint']);
    }

    public function test_cross_project_collision_detected(): void
    {
        $r = (new AtlasProjectLaneQueueNamespacePolicy)->queueNamespaceIsolation([
            'project_id' => 'atlas',
            'lane_namespace' => 'lane-1',
            'queued_targets' => [],
            'target_project' => 'rivals2',
            'target_lane' => 'lane-1',
            'namespace_age_seconds' => 100,
        ]);
        $this->assertFalse($r['isolation_ok']);
        $this->assertCount(1, $r['collision_reasons']);
        $this->assertStringContainsString('target_project_mismatch', $r['collision_reasons'][0]);
        $this->assertSame('fix_namespace_collision_and_revalidate', $r['repair_hint']);
    }

    public function test_stale_namespace_state_detected(): void
    {
        $r = (new AtlasProjectLaneQueueNamespacePolicy)->queueNamespaceIsolation([
            'project_id' => 'atlas',
            'lane_namespace' => 'lane-1',
            'queued_targets' => [],
            'target_project' => 'atlas',
            'target_lane' => 'lane-1',
            'namespace_age_seconds' => 7200,
            'max_namespace_age_seconds' => 3600,
        ]);
        $this->assertFalse($r['isolation_ok']);
        $this->assertStringContainsString('stale_namespace_state', $r['collision_reasons'][0]);
    }

    public function test_queued_target_cross_project_detected(): void
    {
        $r = (new AtlasProjectLaneQueueNamespacePolicy)->queueNamespaceIsolation([
            'project_id' => 'atlas',
            'lane_namespace' => 'lane-1',
            'queued_targets' => ['rivals2:task-42'],
            'target_project' => 'atlas',
            'target_lane' => 'lane-1',
            'namespace_age_seconds' => 100,
        ]);
        $this->assertFalse($r['isolation_ok']);
        $this->assertStringContainsString('queued_target_cross_project', $r['collision_reasons'][0]);
    }
}
