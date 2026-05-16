<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneTaskPacketQueueRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_control_plane_task_packet_queue.v1', AgentControlPlaneTaskPacketQueueRepository::SCHEMA_VERSION);
        $this->assertSame('persistent_local_agent_control_plane_task_packet_queue', AgentControlPlaneTaskPacketQueueRepository::MODE);
        $this->assertSame('atlas/self-construction/agent-control-plane/task-queue', AgentControlPlaneTaskPacketQueueRepository::STORAGE_PREFIX);
        $this->assertContains('queued', AgentControlPlaneTaskPacketQueueRepository::STATUSES);
        $this->assertContains('claimable', AgentControlPlaneTaskPacketQueueRepository::STATUSES);
        $this->assertContains('claimed', AgentControlPlaneTaskPacketQueueRepository::STATUSES);
        $this->assertContains('completed_dry_run', AgentControlPlaneTaskPacketQueueRepository::STATUSES);
    }

    public function test_enqueue_valid_packet(): void
    {
        $repo = new AgentControlPlaneTaskPacketQueueRepository;
        $packet = $this->packet('queue-test-1');
        $result = $repo->enqueue($packet);
        $this->assertSame('ok', $result['status']);
        $this->assertSame('enqueued', $result['event']);
        $this->assertSame('claimable', $result['record']['status']);
        $this->assertFalse($result['record']['runtime_execution_allowed']);
        $this->assertFalse($result['record']['ledger_write_allowed']);
        $this->assertFalse($result['record']['dispatch_allowed']);
        $this->assertFalse($result['record']['provider_call_allowed']);
        $this->assertFalse($result['record']['token_spend_allowed']);
        $this->assertFalse($result['record']['self_programming_allowed']);
        $this->assertFalse($result['record']['completion_real_allowed']);
    }

    public function test_enqueue_idempotent_same_hash(): void
    {
        $repo = new AgentControlPlaneTaskPacketQueueRepository;
        $packet = $this->packet('idem-test-1');
        $a = $repo->enqueue($packet);
        $b = $repo->enqueue($packet);
        $this->assertTrue($b['idempotent'] ?? false);
        $this->assertSame('ok', $b['status']);
        $this->assertSame($a['record']['task_packet_hash'], $b['record']['task_packet_hash']);
    }

    public function test_enqueue_conflict_different_hash_blocks(): void
    {
        $repo = new AgentControlPlaneTaskPacketQueueRepository;
        $packet = $this->packet('conflict-test-1');
        $repo->enqueue($packet);

        $altered = $packet;
        $altered['task_packet_hash'] = 'different_hash_for_same_id';
        $result = $repo->enqueue($altered);
        $this->assertSame('blocked', $result['status']);
        $this->assertSame('task_packet_hash_conflict', $result['reason']);
        $this->assertSame($packet['task_packet_hash'], $result['existing_hash']);
    }

    public function test_get_returns_record_or_null(): void
    {
        $repo = new AgentControlPlaneTaskPacketQueueRepository;
        $this->assertNull($repo->get('does-not-exist'));
        $repo->enqueue($this->packet('get-test'));
        $found = $repo->get('get-test');
        $this->assertIsArray($found);
        $this->assertSame('get-test', $found['task_packet_id']);
    }

    public function test_list_filters_by_status(): void
    {
        $repo = new AgentControlPlaneTaskPacketQueueRepository;
        $repo->enqueue($this->packet('list-a'));
        $repo->enqueue($this->packet('list-b'));
        $repo->updateStatus('list-b', 'cancelled');
        $all = $repo->list();
        $this->assertGreaterThanOrEqual(2, count($all));
        $cancelled = $repo->list(['status' => 'cancelled']);
        foreach ($cancelled as $entry) {
            $this->assertSame('cancelled', $entry['status']);
        }
    }

    public function test_update_status_valid(): void
    {
        $repo = new AgentControlPlaneTaskPacketQueueRepository;
        $repo->enqueue($this->packet('update-1'));
        $result = $repo->updateStatus('update-1', 'claimed', ['lease_id' => 'l1', 'agent_id' => 'agent-1']);
        $this->assertSame('ok', $result['status']);
        $this->assertSame('claimed', $result['record']['status']);
        $events = array_column($result['record']['history'], 'event');
        $this->assertContains('status_changed', $events);
        $this->assertSame('l1', data_get($result, 'record.metadata.lease_id'));
        $this->assertSame('agent-1', data_get($result, 'record.metadata.agent_id'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($result, 'record.history.1.transition_policy_hash'));
    }

    public function test_claim_status_requires_lease_and_agent_metadata(): void
    {
        $repo = new AgentControlPlaneTaskPacketQueueRepository;
        $repo->enqueue($this->packet('claim-metadata-required'));

        $result = $repo->updateStatus('claim-metadata-required', 'claimed', ['lease_id' => 'lease-only']);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('transition_metadata_missing', $result['reason']);
        $this->assertSame(['agent_id'], $result['missing_metadata']);
        $this->assertTrue($result['claim_transition_requires_lease_id']);
        $this->assertTrue($result['claim_transition_requires_agent_id']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['transition_policy_hash']);
        $this->assertSame('claimable', $repo->get('claim-metadata-required')['status']);
    }

    public function test_terminal_status_cannot_be_reclaimed(): void
    {
        $repo = new AgentControlPlaneTaskPacketQueueRepository;
        $repo->enqueue($this->packet('terminal-no-reclaim'));
        $repo->updateStatus('terminal-no-reclaim', 'claimed', ['lease_id' => 'lease-terminal', 'agent_id' => 'agent-terminal']);
        $repo->updateStatus('terminal-no-reclaim', 'completed_dry_run', ['lease_id' => 'lease-terminal', 'agent_id' => 'agent-terminal']);

        $result = $repo->updateStatus('terminal-no-reclaim', 'claimed', ['lease_id' => 'lease-new', 'agent_id' => 'agent-new']);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('invalid_status_transition', $result['reason']);
        $this->assertSame('completed_dry_run', $result['from']);
        $this->assertSame('claimed', $result['to']);
        $this->assertSame([], $result['allowed_next_statuses']);
        $this->assertSame('completed_dry_run', $repo->get('terminal-no-reclaim')['status']);
    }

    public function test_update_invalid_status_blocks(): void
    {
        $repo = new AgentControlPlaneTaskPacketQueueRepository;
        $repo->enqueue($this->packet('update-2'));
        $result = $repo->updateStatus('update-2', 'invented_state');
        $this->assertSame('blocked', $result['status']);
        $this->assertSame('invalid_status', $result['reason']);
    }

    public function test_update_status_missing_packet_blocks(): void
    {
        $repo = new AgentControlPlaneTaskPacketQueueRepository;
        $result = $repo->updateStatus('does-not-exist-x', 'claimed');
        $this->assertSame('blocked', $result['status']);
        $this->assertSame('task_packet_not_found', $result['reason']);
    }

    public function test_append_receipt(): void
    {
        $repo = new AgentControlPlaneTaskPacketQueueRepository;
        $repo->enqueue($this->packet('receipt-1'));
        $result = $repo->appendReceipt('receipt-1', [
            'receipt_kind' => 'scope_lock_runtime_validated',
        ]);
        $this->assertSame('ok', $result['status']);
        $this->assertNotEmpty($result['record']['receipts']);
        $this->assertSame('scope_lock_runtime_validated', $result['record']['receipts'][0]['receipt_kind']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['record']['receipts'][0]['receipt_hash']);
        $this->assertFalse($result['record']['receipts'][0]['runtime_execution_allowed']);
        $this->assertFalse($result['record']['receipts'][0]['ledger_write_allowed']);
    }

    public function test_registry_capped(): void
    {
        $repo = new AgentControlPlaneTaskPacketQueueRepository;
        $registry = $repo->registry();
        $this->assertSame(AgentControlPlaneTaskPacketQueueRepository::STORAGE_PREFIX, $registry['storage_prefix']);
        $this->assertSame(AgentControlPlaneTaskPacketQueueRepository::SCHEMA_VERSION, $registry['schema_version']);
        $this->assertContains('queued', $registry['allowed_statuses']);
        $this->assertFalse($registry['runtime_execution_allowed']);
        $this->assertFalse($registry['dispatch_allowed']);
        $this->assertFalse($registry['ledger_write_allowed']);
        $this->assertFalse($registry['token_spend_allowed']);
    }

    public function test_runtime_flags_false(): void
    {
        $repo = new AgentControlPlaneTaskPacketQueueRepository;
        $flags = $repo->runtimeFlags();
        foreach ($flags as $flag => $value) {
            $this->assertFalse((bool) $value, "Flag {$flag} should be false");
        }
    }

    public function test_is_available_true(): void
    {
        $repo = new AgentControlPlaneTaskPacketQueueRepository;
        $this->assertTrue($repo->isAvailable());
    }

    public function test_corrupt_registry_handled(): void
    {
        Storage::disk('local')->put(AgentControlPlaneTaskPacketQueueRepository::REGISTRY_PATH, 'this is not json');
        $repo = new AgentControlPlaneTaskPacketQueueRepository;
        $registry = $repo->registry();
        $this->assertTrue($registry['corrupt']);
    }

    public function test_corrupt_task_file_returns_corrupt_marker(): void
    {
        $repo = new AgentControlPlaneTaskPacketQueueRepository;
        $repo->enqueue($this->packet('corrupt-test'));
        Storage::disk('local')->put(AgentControlPlaneTaskPacketQueueRepository::STORAGE_PREFIX.'/task_corrupt-test.json', 'broken json');
        $result = $repo->get('corrupt-test');
        $this->assertIsArray($result);
        $this->assertTrue($result['corrupt'] ?? false);
    }

    public function test_cli_status_returns_payload(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-task-packet-queue-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.self_construction_agent_control_plane_task_packet_queue_status.v1', $payload['schema_version']);
        $this->assertSame('available', data_get($payload, 'agent_control_plane_task_packet_queue_status.status'));
    }

    public function test_cli_quartet_works(): void
    {
        foreach (['contract', 'preflight', 'implementation-packet'] as $stage) {
            Artisan::call('atlas:ai:self-construction', [
                "--agent-control-plane-task-packet-queue-{$stage}" => true,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $stageKey = str_replace('-', '_', $stage);
            $this->assertSame("atlas.self_construction_agent_control_plane_task_packet_queue_{$stageKey}.v1", $payload['schema_version']);
            $this->assertFalse($payload['execution_allowed']);
            $this->assertFalse($payload['dispatch_allowed']);
            $this->assertFalse($payload['ledger_write_allowed']);
        }
    }

    public function test_full_status_payload_assertions(): void
    {
        $repo = new AgentControlPlaneTaskPacketQueueRepository;
        $packet = $this->packet('full-status-1');
        $result = $repo->enqueue($packet);
        foreach ([
            'schema_version', 'status', 'event', 'task_packet_id', 'record_status', 'record',
            'runtime_execution_allowed', 'dispatch_allowed', 'ledger_write_allowed',
        ] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing $key");
        }
        $record = $result['record'];
        foreach ([
            'schema_version', 'task_packet_id', 'task_packet_hash', 'enqueued_at', 'updated_at',
            'status', 'priority', 'tags', 'metadata', 'task_packet', 'history', 'receipts',
            'read_only_until_runtime', 'dispatch_allowed', 'provider_call_allowed',
            'token_spend_allowed', 'self_programming_allowed', 'ledger_write_allowed',
            'runtime_execution_allowed', 'completion_real_allowed',
        ] as $key) {
            $this->assertArrayHasKey($key, $record, "Missing record $key");
        }
        $this->assertTrue($record['read_only_until_runtime']);
        $this->assertSame(AgentControlPlaneTaskPacketQueueRepository::SCHEMA_VERSION, $record['schema_version']);
        $registry = $repo->registry();
        foreach ([
            'schema_version', 'mode', 'storage_prefix', 'registry_path', 'entry_count',
            'total_count', 'capped_to', 'corrupt', 'status_counts', 'allowed_statuses',
            'entries', 'runtime_execution_allowed', 'dispatch_allowed', 'ledger_write_allowed',
            'token_spend_allowed', 'provider_call_allowed', 'self_programming_allowed',
            'status_transition_policy', 'status_transition_policy_hash',
            'claim_transition_requires_lease_id', 'claim_transition_requires_agent_id',
        ] as $key) {
            $this->assertArrayHasKey($key, $registry, "Missing registry $key");
        }
        $this->assertSame(['claimed', 'blocked', 'cancelled'], $registry['status_transition_policy']['claimable']);
        $this->assertTrue($registry['claim_transition_requires_lease_id']);
        $this->assertTrue($registry['claim_transition_requires_agent_id']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $registry['status_transition_policy_hash']);
    }

    public function test_full_status_list_includes_canonical_fields(): void
    {
        $repo = new AgentControlPlaneTaskPacketQueueRepository;
        $repo->enqueue($this->packet('list-canon-1'));
        $repo->enqueue($this->packet('list-canon-2'));
        $repo->updateStatus('list-canon-2', 'claimed', ['lease_id' => 'lease-list-canon', 'agent_id' => 'agent-list-canon']);
        $repo->appendReceipt('list-canon-1', ['receipt_kind' => 'extra']);
        foreach ($repo->list() as $record) {
            $this->assertArrayHasKey('task_packet_id', $record);
            $this->assertArrayHasKey('task_packet_hash', $record);
            $this->assertArrayHasKey('status', $record);
            $this->assertArrayHasKey('history', $record);
            $this->assertArrayHasKey('receipts', $record);
        }
        $claimed = $repo->list(['status' => 'claimed']);
        $this->assertNotEmpty($claimed);
        foreach ($claimed as $record) {
            $this->assertSame('claimed', $record['status']);
        }
    }

    public function test_history_events_recorded(): void
    {
        $repo = new AgentControlPlaneTaskPacketQueueRepository;
        $repo->enqueue($this->packet('history-1'));
        $repo->updateStatus('history-1', 'claimed', ['lease_id' => 'lease-history', 'agent_id' => 'agent-history']);
        $repo->appendReceipt('history-1', ['receipt_kind' => 'h_test']);
        $repo->updateStatus('history-1', 'released');
        $record = $repo->get('history-1');
        $events = array_column((array) $record['history'], 'event');
        $this->assertContains('enqueued', $events);
        $this->assertContains('status_changed', $events);
        $this->assertContains('receipt_appended', $events);
        $this->assertSame(2, count(array_keys($events, 'status_changed')));
    }

    /**
     * @return array<string, mixed>
     */
    private function packet(string $id): array
    {
        return (new AgentControlPlaneTaskPacketBuilder)->build([
            'task_packet_id' => $id,
            'objective' => 'queue repo test packet',
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/Foo.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/Foo.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['task_packet_created'],
        ]);
    }
}
