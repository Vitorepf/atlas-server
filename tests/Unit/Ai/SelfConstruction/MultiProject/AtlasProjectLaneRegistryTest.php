<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\MultiProject;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneKnowledgeSyncPolicy;
use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneQueueNamespacePolicy;
use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneRegistry;
use RuntimeException;
use Tests\TestCase;

final class AtlasProjectLaneRegistryTest extends TestCase
{
    private function lane(string $id, string $root, array $extra = []): array
    {
        return ['project_id' => $id, 'repo_root' => $root, 'objective' => 'x'] + $extra;
    }

    public function test_first_registration_returns_the_stored_record(): void
    {
        $reg = new AtlasProjectLaneRegistry;
        $stored = $reg->register($this->lane('atlas-server', '/repos/atlas-server'));

        $this->assertSame('atlas-server', $stored['project_id']);
        $this->assertSame($stored, $reg->get('atlas-server'));
        $this->assertCount(1, $reg->listActive());
    }

    public function test_idempotent_same_lane_registration_is_a_noop(): void
    {
        $reg = new AtlasProjectLaneRegistry;
        $record = $this->lane('p', '/repos/p');
        $reg->register($record);
        $reg->register($record);
        $reg->register($record);

        $this->assertCount(1, $reg->listActive(), 'idempotent registration must NOT duplicate the lane');
    }

    public function test_duplicate_project_id_with_different_repo_root_is_refused_without_supersedes(): void
    {
        $reg = new AtlasProjectLaneRegistry;
        $reg->register($this->lane('p', '/repos/p'));

        $this->expectException(RuntimeException::class);
        $reg->register($this->lane('p', '/repos/p-new'));
    }

    public function test_supersedes_prior_lane_replaces_the_repo_root(): void
    {
        $reg = new AtlasProjectLaneRegistry;
        $reg->register($this->lane('p', '/repos/p'));
        $reg->register($this->lane('p', '/repos/p-new', ['supersedes_prior_lane' => true]));

        $this->assertSame('/repos/p-new', $reg->get('p')['repo_root']);
        $this->assertCount(1, $reg->listActive(), 'supersede MUST NOT duplicate the lane');
    }

    public function test_list_active_preserves_insertion_order_and_filters_non_active_status(): void
    {
        $reg = new AtlasProjectLaneRegistry;
        $reg->register($this->lane('a', '/repos/a'));
        $reg->register($this->lane('b', '/repos/b'));
        $reg->register($this->lane('c', '/repos/c', ['status' => 'paused']));
        $reg->register($this->lane('d', '/repos/d'));

        $this->assertSame(['a', 'b', 'd'], array_column($reg->listActive(), 'project_id'));
    }

    public function test_registry_strips_forbidden_runtime_keys_to_keep_metadata_provider_safe(): void
    {
        $reg = new AtlasProjectLaneRegistry;
        $stored = $reg->register($this->lane('safe', '/repos/safe', [
            'provider_key' => 'secret',
            'shell_cmd' => 'rm -rf /',
            'api_key' => 'oops',
        ]));

        foreach (['provider_key', 'shell_cmd', 'api_key'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $stored, "registry must never store runtime-execution key {$forbidden}");
        }
    }

    // ---------- derived facts (queue namespace + evidence namespace + knowledge_sync_policy) ----------

    public function test_two_lanes_get_distinct_queue_namespaces_and_evidence_namespaces(): void
    {
        $reg = new AtlasProjectLaneRegistry;
        $a = $reg->register($this->lane('project-alpha', '/repos/alpha'));
        $b = $reg->register($this->lane('project-beta',  '/repos/beta'));

        // Queue namespace.
        $nsA = $a['queue_namespace_facts']['namespace'] ?? null;
        $nsB = $b['queue_namespace_facts']['namespace'] ?? null;
        $this->assertNotNull($nsA, 'project-alpha must have queue_namespace_facts.namespace');
        $this->assertNotNull($nsB, 'project-beta must have queue_namespace_facts.namespace');
        $this->assertNotSame($nsA, $nsB, 'two lanes must have DISTINCT queue namespaces');
        $this->assertStringStartsWith(AtlasProjectLaneQueueNamespacePolicy::NAMESPACE_PREFIX, $nsA);
        $this->assertStringStartsWith(AtlasProjectLaneQueueNamespacePolicy::NAMESPACE_PREFIX, $nsB);

        // Evidence namespace.
        $evA = $a['evidence_namespace'] ?? null;
        $evB = $b['evidence_namespace'] ?? null;
        $this->assertNotNull($evA, 'project-alpha must have evidence_namespace');
        $this->assertNotNull($evB, 'project-beta must have evidence_namespace');
        $this->assertNotSame($evA, $evB, 'two lanes must have DISTINCT evidence namespaces');
        $this->assertStringStartsWith('evidence.', $evA);
        $this->assertStringStartsWith('evidence.', $evB);
    }

    public function test_two_lanes_get_distinct_knowledge_sync_policy_facts(): void
    {
        $reg = new AtlasProjectLaneRegistry;
        $a = $reg->register($this->lane('lane-x', '/repos/x'));
        $b = $reg->register($this->lane('lane-y', '/repos/y'));

        $kspA = $a['knowledge_sync_policy'] ?? null;
        $kspB = $b['knowledge_sync_policy'] ?? null;

        $this->assertIsArray($kspA, 'lane-x must have knowledge_sync_policy');
        $this->assertIsArray($kspB, 'lane-y must have knowledge_sync_policy');
        $this->assertSame(AtlasProjectLaneKnowledgeSyncPolicy::SCHEMA, $kspA['schema']);
        $this->assertSame(AtlasProjectLaneKnowledgeSyncPolicy::SCHEMA, $kspB['schema']);

        $this->assertSame('lane-x', $kspA['project_id']);
        $this->assertSame('lane-y', $kspB['project_id']);
        $this->assertNotSame($kspA['project_id'], $kspB['project_id'], 'knowledge_sync_policy.project_id must differ');
        $this->assertTrue($kspA['docs_sync_required']);
        $this->assertTrue($kspA['code_index_required']);
    }

    public function test_lane_ids_returns_insertion_order(): void
    {
        $reg = new AtlasProjectLaneRegistry;
        $reg->register($this->lane('first',  '/repos/first'));
        $reg->register($this->lane('second', '/repos/second'));
        $reg->register($this->lane('third',  '/repos/third'));

        $this->assertSame(['first', 'second', 'third'], $reg->laneIds());
    }

    public function test_register_fails_closed_for_unsafe_project_id(): void
    {
        $reg = new AtlasProjectLaneRegistry;
        $this->expectException(RuntimeException::class);
        $reg->register(['project_id' => 'bad/id', 'repo_root' => '/repos/r', 'objective' => 'x']);
    }

    public function test_relative_repo_root_is_rejected(): void
    {
        $reg = new AtlasProjectLaneRegistry;
        $this->expectException(RuntimeException::class);
        $reg->register($this->lane('p', 'repos/relative'));
    }

    public function test_traversal_repo_root_is_rejected(): void
    {
        $reg = new AtlasProjectLaneRegistry;
        $this->expectException(RuntimeException::class);
        $reg->register($this->lane('p', '/repos/../secret'));
    }

    public function test_stored_record_includes_stable_lane_hash(): void
    {
        $reg = new AtlasProjectLaneRegistry;
        $a = $reg->register($this->lane('p', '/repos/p'));
        $reg2 = new AtlasProjectLaneRegistry;
        $b = $reg2->register($this->lane('p', '/repos/p'));

        $this->assertArrayHasKey('lane_hash', $a);
        $this->assertSame(64, strlen($a['lane_hash']));
        $this->assertSame($a['lane_hash'], $b['lane_hash']);
    }

    public function test_lane_hash_changes_when_repo_root_changes(): void
    {
        $reg = new AtlasProjectLaneRegistry;
        $a = $reg->register($this->lane('p', '/repos/alpha'));
        $reg2 = new AtlasProjectLaneRegistry;
        $b = $reg2->register($this->lane('p', '/repos/beta'));

        $this->assertNotSame($a['lane_hash'], $b['lane_hash']);
    }

    public function test_expanded_credential_keys_are_stripped(): void
    {
        $reg = new AtlasProjectLaneRegistry;
        $stored = $reg->register($this->lane('sec', '/repos/sec', [
            'password' => 'hunter2',
            'token' => 'abc123',
            'secret' => 'shh',
            'bearer_token' => 'xyz',
            'credential' => 'cred',
        ]));

        foreach (['password', 'token', 'secret', 'bearer_token', 'credential'] as $key) {
            $this->assertArrayNotHasKey($key, $stored, "registry must strip credential key: {$key}");
        }
    }
}
