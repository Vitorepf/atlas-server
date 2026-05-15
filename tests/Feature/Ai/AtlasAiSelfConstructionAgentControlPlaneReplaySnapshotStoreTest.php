<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneChainIntegrityAuditService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneDeterministicChainReplayService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneReplaySnapshotStore;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneReplaySnapshotStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_put_persists_snapshot_under_expected_path(): void
    {
        $replay = $this->freshReplay();
        $result = $this->newStore()->put($replay);

        $this->assertArrayHasKey('snapshot_id', $result);
        $this->assertArrayHasKey('path', $result);
        $this->assertArrayHasKey('created_at', $result);
        $this->assertArrayHasKey('snapshot', $result);
        $this->assertArrayHasKey('registry_size', $result);
        $this->assertNotEmpty($result['snapshot_id']);
        $this->assertStringStartsWith('snap_', (string) $result['snapshot_id']);
        $this->assertSame(
            AgentControlPlaneReplaySnapshotStore::STORAGE_PREFIX.'/'.$result['snapshot_id'].'.json',
            (string) $result['path'],
        );
        $this->assertSame(1, (int) $result['registry_size']);
        Storage::disk('local')->assertExists((string) $result['path']);
        Storage::disk('local')->assertExists(AgentControlPlaneReplaySnapshotStore::REGISTRY_PATH);
    }

    public function test_get_returns_persisted_snapshot(): void
    {
        $replay = $this->freshReplay();
        $store = $this->newStore();
        $put = $store->put($replay);

        $snapshot = $store->get((string) $put['snapshot_id']);

        $this->assertIsArray($snapshot);
        $this->assertSame(AgentControlPlaneReplaySnapshotStore::SCHEMA_VERSION, $snapshot['schema_version']);
        $this->assertSame($put['snapshot_id'], $snapshot['snapshot_id']);
        $this->assertArrayHasKey('created_at', $snapshot);
        $this->assertArrayHasKey('label', $snapshot);
        $this->assertArrayHasKey('replay_hash', $snapshot);
        $this->assertArrayHasKey('deterministic_replay_hash', $snapshot);
        $this->assertArrayHasKey('proof_bundle_hash', $snapshot);
        $this->assertArrayHasKey('current_pointer', $snapshot);
        $this->assertArrayHasKey('replay_summary', $snapshot);
        $this->assertArrayHasKey('replay_payload', $snapshot);
    }

    public function test_get_returns_null_for_unknown_id(): void
    {
        $this->assertNull($this->newStore()->get('snap_does_not_exist'));
    }

    public function test_latest_returns_newest_snapshot(): void
    {
        $store = $this->newStore();
        $replay = $this->freshReplay();
        $first = $store->put($replay);
        usleep(2000);
        $second = $store->put($replay, ['label' => 'second']);

        $latest = $store->latest();

        $this->assertIsArray($latest);
        $this->assertSame($second['snapshot_id'], $latest['snapshot_id']);
        $this->assertNotSame($first['snapshot_id'], $latest['snapshot_id']);
        $this->assertSame('second', $latest['label']);
        $this->assertSame(AgentControlPlaneReplaySnapshotStore::SCHEMA_VERSION, $latest['schema_version']);
    }

    public function test_latest_returns_null_when_empty(): void
    {
        $this->assertNull($this->newStore()->latest());
    }

    public function test_registry_caps_entries_when_keep_is_provided(): void
    {
        $store = $this->newStore();
        $replay = $this->freshReplay();
        for ($i = 0; $i < 5; $i++) {
            $store->put($replay, ['label' => 'snap-'.$i, 'keep' => 3]);
        }

        $registry = $store->registry();

        $this->assertSame(3, $registry['entry_count']);
        $this->assertCount(3, $registry['entries']);
        $this->assertSame(AgentControlPlaneReplaySnapshotStore::STORAGE_PREFIX, $registry['storage_prefix']);
        $this->assertSame(AgentControlPlaneReplaySnapshotStore::REGISTRY_PATH, $registry['registry_path']);
        $this->assertSame(AgentControlPlaneReplaySnapshotStore::DEFAULT_KEEP, $registry['keep_default']);
        $this->assertFalse((bool) $registry['corrupt']);
        $labels = array_map(static fn ($e) => (string) data_get($e, 'label'), $registry['entries']);
        $this->assertSame(['snap-2', 'snap-3', 'snap-4'], $labels);
    }

    public function test_prune_removes_old_entries(): void
    {
        $store = $this->newStore();
        $replay = $this->freshReplay();
        for ($i = 0; $i < 5; $i++) {
            $store->put($replay, ['label' => 'p-'.$i]);
        }

        $result = $store->prune(2);

        $this->assertSame(5, $result['before_count']);
        $this->assertSame(2, $result['after_count']);
        $this->assertSame(3, $result['removed_count']);
        $this->assertSame(2, $result['kept']);
        $this->assertSame(2, $store->registry()['entry_count']);
    }

    public function test_prune_zero_keep_clears_registry(): void
    {
        $store = $this->newStore();
        $replay = $this->freshReplay();
        $store->put($replay);

        $result = $store->prune(0);

        $this->assertSame(0, $result['after_count']);
        $this->assertSame(0, $store->registry()['entry_count']);
    }

    public function test_snapshot_schema_is_v1(): void
    {
        $replay = $this->freshReplay();
        $put = $this->newStore()->put($replay);
        $snapshot = (array) $put['snapshot'];

        $this->assertSame(
            'atlas.self_construction.agent_control_plane_replay_snapshot.v1',
            $snapshot['schema_version'],
        );
        $this->assertSame(AgentControlPlaneReplaySnapshotStore::SCHEMA_VERSION, $snapshot['schema_version']);
    }

    public function test_snapshot_summary_includes_proof_bundle_keys(): void
    {
        $replay = $this->freshReplay();
        $put = $this->newStore()->put($replay);
        $snapshot = (array) $put['snapshot'];

        $proofBundleKeys = (array) data_get($snapshot, 'replay_summary.proof_bundle_keys');
        $this->assertContains('chain_integrity_summary', $proofBundleKeys);
        $this->assertContains('control_plane_summary', $proofBundleKeys);
        $this->assertContains('capability_summary', $proofBundleKeys);
        $this->assertContains('readiness_summary', $proofBundleKeys);
        $this->assertContains('cli_summary', $proofBundleKeys);
    }

    public function test_snapshot_includes_replay_hashes(): void
    {
        $replay = $this->freshReplay();
        $put = $this->newStore()->put($replay);
        $snapshot = (array) $put['snapshot'];

        $this->assertSame(data_get($replay, 'replay_hash'), $snapshot['replay_hash']);
        $this->assertSame(data_get($replay, 'deterministic_replay_hash'), $snapshot['deterministic_replay_hash']);
        $this->assertSame(data_get($replay, 'proof_bundle_hash'), $snapshot['proof_bundle_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $snapshot['replay_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $snapshot['deterministic_replay_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $snapshot['proof_bundle_hash']);
    }

    public function test_snapshot_includes_current_pointer(): void
    {
        $replay = $this->freshReplay();
        $put = $this->newStore()->put($replay);
        $snapshot = (array) $put['snapshot'];

        $this->assertSame(data_get($replay, 'current_pointer'), $snapshot['current_pointer']);
        $this->assertNotEmpty($snapshot['current_pointer']);
        $this->assertSame(data_get($replay, 'expected_pointer'), $snapshot['expected_pointer']);
        $this->assertSame(data_get($replay, 'next_build_slices'), $snapshot['next_build_slices']);
        $this->assertSame(data_get($replay, 'not_yet_runtime_capable'), $snapshot['not_yet_runtime_capable']);
        $this->assertSame(data_get($replay, 'chain_integrity_hash'), $snapshot['chain_integrity_hash']);
        $this->assertSame((int) data_get($replay, 'replayed_slice_count'), (int) $snapshot['replayed_slice_count']);
        $this->assertSame((int) data_get($replay, 'replayed_edge_count'), (int) $snapshot['replayed_edge_count']);
    }

    public function test_snapshot_includes_runtime_safety_flag(): void
    {
        $replay = $this->freshReplay();
        $put = $this->newStore()->put($replay);
        $snapshot = (array) $put['snapshot'];

        $this->assertSame(
            (bool) data_get($replay, 'runtime_safety.runtime_safety_all_false'),
            (bool) $snapshot['runtime_safety_all_false'],
        );
        $this->assertSame(count((array) data_get($replay, 'violations', [])), (int) $snapshot['violation_count']);
        $this->assertSame(count((array) data_get($replay, 'warnings', [])), (int) $snapshot['warning_count']);
    }

    public function test_snapshot_is_read_only_flags_false(): void
    {
        $replay = $this->freshReplay();
        $put = $this->newStore()->put($replay);
        $snapshot = (array) $put['snapshot'];

        $this->assertTrue((bool) $snapshot['read_only']);
        $this->assertFalse((bool) $snapshot['external_provider_call']);
        $this->assertFalse((bool) $snapshot['token_spend']);
        $this->assertFalse((bool) $snapshot['process_started']);
        $this->assertFalse((bool) $snapshot['dispatch_allowed']);
        $this->assertFalse((bool) $snapshot['self_programming_allowed']);
        $this->assertFalse((bool) $snapshot['execution_allowed']);
        $this->assertFalse((bool) $snapshot['ledger_write_allowed']);
        $this->assertFalse((bool) $snapshot['runtime_write_allowed']);
    }

    public function test_put_does_not_mutate_input_replay_payload(): void
    {
        $replay = $this->freshReplay();
        $snapshotBefore = (string) data_get($replay, 'deterministic_replay_hash');
        $this->newStore()->put($replay);

        $this->assertSame($snapshotBefore, (string) data_get($replay, 'deterministic_replay_hash'));
    }

    public function test_deterministic_replay_hash_preserved_in_snapshot(): void
    {
        $replay = $this->freshReplay();
        $put = $this->newStore()->put($replay);

        $this->assertSame(
            (string) data_get($replay, 'deterministic_replay_hash'),
            (string) data_get($put, 'snapshot.deterministic_replay_hash'),
        );
    }

    public function test_corrupt_registry_handled_honestly(): void
    {
        Storage::disk('local')->put(AgentControlPlaneReplaySnapshotStore::REGISTRY_PATH, '{not valid json');

        $registry = $this->newStore()->registry();

        $this->assertSame(0, $registry['entry_count']);
        $this->assertTrue((bool) $registry['corrupt']);
    }

    public function test_status_projection_returns_latest_summary(): void
    {
        $replay = $this->freshReplay();
        $this->newStore()->put($replay, ['label' => 'projection-test']);

        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-replay-snapshot-store-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $status = data_get($payload, 'agent_control_plane_replay_snapshot_store_status');

        $this->assertSame('available', $status['status']);
        $this->assertSame(1, $status['entry_count']);
        $this->assertSame('projection-test', $status['latest_label']);
        $this->assertNotEmpty($status['latest_deterministic_replay_hash']);
        $this->assertNotEmpty($status['latest_snapshot_id']);
        $this->assertNotEmpty($status['latest_replay_hash']);
        $this->assertNotEmpty($status['latest_proof_bundle_hash']);
        $this->assertSame(
            'atlas.self_construction_agent_control_plane_replay_snapshot_store_status.v1',
            $payload['schema_version'],
        );
        $this->assertSame(
            'read_only_agent_control_plane_replay_snapshot_store_status',
            $payload['mode'],
        );
        $this->assertFalse((bool) $payload['execution_allowed']);
        $this->assertFalse((bool) $payload['dispatch_allowed']);
        $this->assertFalse((bool) $payload['ledger_write_allowed']);
        $this->assertFalse((bool) $payload['runtime_write_allowed']);
    }

    public function test_capture_command_writes_one_snapshot_without_runtime_execution(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-replay-snapshot-store-capture' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('atlas.self_construction_agent_control_plane_replay_snapshot_store_capture.v1', $payload['schema_version']);
        $this->assertSame('captured', $payload['status']);
        $this->assertTrue((bool) $payload['snapshot_write_allowed']);
        $this->assertTrue((bool) $payload['snapshot_write_performed']);
        $this->assertSame(0, (int) $payload['registry_entry_count_before']);
        $this->assertSame(1, (int) $payload['registry_entry_count_after']);
        $this->assertNotEmpty($payload['snapshot_id']);
        $this->assertNotEmpty($payload['latest_deterministic_replay_hash']);
        $this->assertFalse((bool) $payload['execution_allowed']);
        $this->assertFalse((bool) $payload['dispatch_allowed']);
        $this->assertFalse((bool) $payload['ledger_write_allowed']);
        $this->assertFalse((bool) $payload['runtime_write_allowed']);
        $this->assertFalse((bool) $payload['external_provider_call']);
        $this->assertFalse((bool) $payload['token_spend']);
        $this->assertFalse((bool) $payload['process_started']);
        $this->assertFalse((bool) $payload['provider_call_allowed']);
        $this->assertFalse((bool) $payload['adapter_execution_allowed']);
        $this->assertFalse((bool) $payload['self_programming_allowed']);
        $this->assertFalse((bool) $payload['completion_claim_allowed']);
        Storage::disk('local')->assertExists(AgentControlPlaneReplaySnapshotStore::REGISTRY_PATH);
    }

    public function test_capture_command_is_idempotent_when_latest_snapshot_is_current(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-replay-snapshot-store-capture' => true,
            '--json' => true,
        ]);
        $first = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-replay-snapshot-store-capture' => true,
            '--json' => true,
        ]);
        $second = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('captured', $first['status']);
        $this->assertSame('already_current', $second['status']);
        $this->assertFalse((bool) $second['snapshot_write_performed']);
        $this->assertSame(1, (int) $second['registry_entry_count_before']);
        $this->assertSame(1, (int) $second['registry_entry_count_after']);
        $this->assertSame($first['snapshot_id'], $second['snapshot_id']);
    }

    public function test_cli_contract_json_returns_v1(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-replay-snapshot-store-contract' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('atlas.self_construction_agent_control_plane_replay_snapshot_store_contract.v1', $payload['schema_version']);
        $this->assertSame('agent_control_plane_replay_snapshot_store_contract_ready', $payload['status']);
        $this->assertSame(AgentControlPlaneReplaySnapshotStore::SCHEMA_VERSION, data_get($payload, 'agent_control_plane_replay_snapshot_store_contract.snapshot_schema_version'));
        $this->assertSame(AgentControlPlaneReplaySnapshotStore::class, data_get($payload, 'agent_control_plane_replay_snapshot_store_contract.snapshot_store_class'));
    }

    public function test_cli_preflight_json_returns_v1(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-replay-snapshot-store-preflight' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('atlas.self_construction_agent_control_plane_replay_snapshot_store_preflight.v1', $payload['schema_version']);
        $this->assertSame('agent_control_plane_replay_snapshot_store_preflight_ready', $payload['status']);
        $this->assertSame(0, (int) data_get($payload, 'agent_control_plane_replay_snapshot_store_preflight.blocking_count'));
    }

    public function test_cli_implementation_packet_json_returns_v1(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-replay-snapshot-store-implementation-packet' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('atlas.self_construction_agent_control_plane_replay_snapshot_store_implementation_packet.v1', $payload['schema_version']);
        $this->assertSame('ready_for_scoped_agent_control_plane_replay_snapshot_store_implementation', $payload['status']);
        $this->assertNotEmpty(data_get($payload, 'agent_control_plane_replay_snapshot_store_implementation_packet.allowed_files'));
        $this->assertNotEmpty(data_get($payload, 'agent_control_plane_replay_snapshot_store_implementation_packet.acceptance_criteria'));
    }

    public function test_snapshot_store_never_writes_ledger_or_advances_pointer(): void
    {
        $beforePointer = $this->controlPlanePointer();
        $replay = $this->freshReplay();
        $this->newStore()->put($replay);
        $afterPointer = $this->controlPlanePointer();

        $this->assertSame($beforePointer, $afterPointer);
        $this->assertNotEmpty($beforePointer);
    }

    public function test_constants_are_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_control_plane_replay_snapshot.v1', AgentControlPlaneReplaySnapshotStore::SCHEMA_VERSION);
        $this->assertSame('read_only_agent_control_plane_replay_snapshot_store', AgentControlPlaneReplaySnapshotStore::MODE);
        $this->assertSame('atlas/self-construction/agent-control-plane/replay-snapshots', AgentControlPlaneReplaySnapshotStore::STORAGE_PREFIX);
        $this->assertSame('atlas/self-construction/agent-control-plane/replay-snapshots/registry.json', AgentControlPlaneReplaySnapshotStore::REGISTRY_PATH);
        $this->assertSame(20, AgentControlPlaneReplaySnapshotStore::DEFAULT_KEEP);
        $this->assertSame('local', AgentControlPlaneReplaySnapshotStore::DEFAULT_DISK);
    }

    public function test_registry_limit_option(): void
    {
        $store = $this->newStore();
        $replay = $this->freshReplay();
        for ($i = 0; $i < 5; $i++) {
            $store->put($replay, ['label' => 'snap-'.$i]);
        }

        $limited = $store->registry(['limit' => 2]);
        $this->assertSame(2, $limited['entry_count']);
        $this->assertCount(2, $limited['entries']);
    }

    private function newStore(): AgentControlPlaneReplaySnapshotStore
    {
        return new AgentControlPlaneReplaySnapshotStore('local');
    }

    /**
     * @return array<string, mixed>
     */
    private function freshReplay(): array
    {
        $readiness = app(AtlasSelfConstructionReadinessService::class);
        $audit = new AgentControlPlaneChainIntegrityAuditService($readiness);
        $replay = new AgentControlPlaneDeterministicChainReplayService($audit, $readiness);

        return $replay->replay();
    }

    private function controlPlanePointer(): string
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        return (string) data_get($payload, 'control_plane.persistent_runtime.next_required_slice');
    }
}
