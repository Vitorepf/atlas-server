<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentRuntimeRegistryRepository;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AgentRuntimeRegistryRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_runtime_registry.v1', AgentRuntimeRegistryRepository::SCHEMA_VERSION);
        $this->assertSame('persistent_local_agent_runtime_registry', AgentRuntimeRegistryRepository::MODE);
        $this->assertSame('atlas/self-construction/agent-control-plane/agent-registry', AgentRuntimeRegistryRepository::STORAGE_PREFIX);
        $this->assertContains('registered', AgentRuntimeRegistryRepository::STATUSES);
        $this->assertContains('quarantined', AgentRuntimeRegistryRepository::STATUSES);
        $this->assertContains('codex', AgentRuntimeRegistryRepository::KINDS);
    }

    public function test_register_valid_agent(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $result = $repo->register($this->validAgent());
        $this->assertSame('ok', $result['status']);
        $this->assertSame('registered', $result['event']);
        $this->assertSame('agent-a', $result['agent_id']);
        $this->assertSame('registered', $result['record_status']);
        $this->assertSame('Agent A', $result['record']['label']);
        $this->assertFalse($result['runtime_execution_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
        $this->assertFalse($result['ledger_write_allowed']);
    }

    public function test_register_normalizes_capabilities(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $agent = $this->validAgent();
        $agent['capabilities'] = ['  Code_Edit ', 'code_edit', 'docs_writer', ''];
        $result = $repo->register($agent);
        $this->assertSame(['code_edit', 'docs_writer'], $result['record']['capabilities']);
    }

    public function test_register_idempotent(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $repo->register($this->validAgent());
        $second = $repo->register($this->validAgent());
        $this->assertTrue($second['idempotent']);
        $this->assertSame('idempotent_register', $second['event']);
    }

    public function test_register_invalid_agent_id_blocks(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $agent = $this->validAgent();
        $agent['agent_id'] = '';
        $result = $repo->register($agent);
        $this->assertSame('blocked', $result['status']);
        $this->assertSame('invalid_agent_id', $result['reason']);
    }

    public function test_register_invalid_kind_blocks(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $agent = $this->validAgent();
        $agent['kind'] = 'rogue';
        $result = $repo->register($agent);
        $this->assertSame('blocked', $result['status']);
        $this->assertSame('invalid_kind', $result['reason']);
        $this->assertContains('codex', $result['allowed_kinds']);
    }

    public function test_register_invalid_status_blocks(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $agent = $this->validAgent();
        $agent['status'] = 'launching';
        $result = $repo->register($agent);
        $this->assertSame('blocked', $result['status']);
        $this->assertSame('invalid_status', $result['reason']);
    }

    public function test_get_returns_record(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $repo->register($this->validAgent());
        $record = $repo->get('agent-a');
        $this->assertNotNull($record);
        $this->assertSame('agent-a', $record['agent_id']);
        $this->assertSame('registered', $record['status']);
    }

    public function test_get_unknown_returns_null(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $this->assertNull($repo->get('nope'));
        $this->assertNull($repo->get(''));
    }

    public function test_list_filters_by_status_kind_capability_tag(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $repo->register($this->validAgent());
        $repo->register([
            'agent_id' => 'agent-b',
            'kind' => 'codex',
            'label' => 'Agent B',
            'status' => 'available',
            'capabilities' => ['docs_writer'],
            'max_parallel_tasks' => 2,
            'current_task_count' => 0,
            'tags' => ['exp'],
        ]);
        $this->assertCount(2, $repo->list());
        $this->assertCount(1, $repo->list(['status' => 'available']));
        $this->assertCount(1, $repo->list(['kind' => 'codex']));
        $this->assertCount(1, $repo->list(['capability' => 'docs_writer']));
        $this->assertCount(1, $repo->list(['tag' => 'exp']));
        $this->assertCount(1, $repo->list(['limit' => 1]));
    }

    public function test_update_status(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $repo->register($this->validAgent());
        $result = $repo->updateStatus('agent-a', 'available', ['note' => 'ready']);
        $this->assertSame('ok', $result['status']);
        $this->assertSame('available', $result['record']['status']);
        $this->assertSame('ready', $result['record']['metadata']['note']);
        $last = end($result['record']['history']);
        $this->assertSame('status_changed', $last['event']);
    }

    public function test_update_status_invalid_blocks(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $repo->register($this->validAgent());
        $result = $repo->updateStatus('agent-a', 'launching');
        $this->assertSame('blocked', $result['status']);
        $this->assertSame('invalid_status', $result['reason']);
    }

    public function test_update_status_unknown_agent_blocks(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $result = $repo->updateStatus('agent-unknown', 'available');
        $this->assertSame('blocked', $result['status']);
        $this->assertSame('agent_not_found', $result['reason']);
    }

    public function test_unregister(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $repo->register($this->validAgent());
        $result = $repo->unregister('agent-a', ['reason' => 'maintenance']);
        $this->assertSame('ok', $result['status']);
        $this->assertSame('unregistered', $result['record']['status']);
        $this->assertNotEmpty($result['record']['unregistered_at']);
    }

    public function test_unregister_unknown_blocks(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $result = $repo->unregister('agent-x');
        $this->assertSame('blocked', $result['status']);
        $this->assertSame('agent_not_found', $result['reason']);
    }

    public function test_append_receipt_local(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $repo->register($this->validAgent());
        $result = $repo->appendReceipt('agent-a', [
            'receipt_kind' => 'agent_registered',
            'extra' => 'value',
        ]);
        $this->assertSame('ok', $result['status']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['receipt']['receipt_hash']);
        $this->assertFalse($result['receipt']['runtime_execution_allowed']);
        $this->assertFalse($result['receipt']['ledger_write_allowed']);
        $this->assertFalse($result['receipt']['dispatch_allowed']);
        $record = $repo->get('agent-a');
        $this->assertCount(1, $record['receipts']);
    }

    public function test_registry_summary(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $repo->register($this->validAgent());
        $repo->register([
            'agent_id' => 'agent-b',
            'kind' => 'codex',
            'label' => 'Agent B',
            'status' => 'available',
            'capabilities' => ['docs_writer'],
            'max_parallel_tasks' => 2,
            'current_task_count' => 0,
        ]);
        $registry = $repo->registry();
        $this->assertSame(AgentRuntimeRegistryRepository::SCHEMA_VERSION, $registry['schema_version']);
        $this->assertSame(2, $registry['total_count']);
        $this->assertSame(2, $registry['entry_count']);
        $this->assertSame(1, $registry['status_counts']['registered']);
        $this->assertSame(1, $registry['status_counts']['available']);
        $this->assertContains('codex', $registry['kind_counts'] ? array_keys($registry['kind_counts']) : []);
        $this->assertFalse($registry['runtime_execution_allowed']);
        $this->assertFalse($registry['dispatch_allowed']);
        $this->assertFalse($registry['ledger_write_allowed']);
        $this->assertFalse($registry['provider_call_allowed']);
    }

    public function test_registry_filter_by_status_and_kind(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $repo->register($this->validAgent());
        $repo->register([
            'agent_id' => 'agent-b',
            'kind' => 'codex',
            'label' => 'Agent B',
            'status' => 'available',
            'capabilities' => ['docs_writer'],
            'max_parallel_tasks' => 2,
            'current_task_count' => 0,
        ]);
        $byStatus = $repo->registry(['status' => 'available']);
        $this->assertSame(1, $byStatus['entry_count']);
        $byKind = $repo->registry(['kind' => 'dry_run_agent']);
        $this->assertSame(1, $byKind['entry_count']);
    }

    public function test_runtime_flags_helper(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        foreach ($repo->runtimeFlags() as $key => $value) {
            $this->assertFalse($value, "flag {$key} must remain false");
        }
    }

    public function test_is_available(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $this->assertTrue($repo->isAvailable());
    }

    public function test_corrupt_registry_handled(): void
    {
        Storage::disk('local')->put(AgentRuntimeRegistryRepository::REGISTRY_PATH, '{not-json');
        $repo = new AgentRuntimeRegistryRepository;
        $registry = $repo->registry();
        $this->assertTrue($registry['corrupt']);
        $this->assertSame(0, $registry['total_count']);
    }

    public function test_corrupt_agent_file_returns_corrupt_flag(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $repo->register($this->validAgent());
        Storage::disk('local')->put(
            AgentRuntimeRegistryRepository::STORAGE_PREFIX.'/agent_agent-a.json',
            '{broken'
        );
        $record = $repo->get('agent-a');
        $this->assertTrue($record['corrupt']);
        $this->assertSame('invalid_json', $record['corrupt_reason']);
        $update = $repo->updateStatus('agent-a', 'available');
        $this->assertSame('blocked', $update['status']);
        $this->assertSame('agent_record_corrupt', $update['reason']);
    }

    public function test_register_signature_changes_on_status_update(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $repo->register($this->validAgent());
        $agent = $this->validAgent();
        $agent['status'] = 'available';
        $result = $repo->register($agent);
        $this->assertSame('ok', $result['status']);
        $this->assertFalse($result['idempotent'] ?? false);
        $this->assertSame('registered', $result['event']);
        $this->assertSame('available', $result['record']['status']);
    }

    public function test_max_parallel_tasks_clamped(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $agent = $this->validAgent();
        $agent['max_parallel_tasks'] = 3;
        $agent['current_task_count'] = 10;
        $result = $repo->register($agent);
        $this->assertSame(3, $result['record']['max_parallel_tasks']);
        $this->assertSame(3, $result['record']['current_task_count']);
    }

    public function test_append_receipt_to_unknown_blocks(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $result = $repo->appendReceipt('agent-x', ['receipt_kind' => 'k']);
        $this->assertSame('blocked', $result['status']);
        $this->assertSame('agent_not_found', $result['reason']);
    }

    public function test_append_receipt_to_invalid_agent_id_blocks(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $result = $repo->appendReceipt('!', ['receipt_kind' => 'k']);
        $this->assertSame('blocked', $result['status']);
        $this->assertSame('invalid_agent_id', $result['reason']);
    }

    public function test_unregister_invalid_agent_id_blocks(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $result = $repo->unregister('');
        $this->assertSame('blocked', $result['status']);
        $this->assertSame('invalid_agent_id', $result['reason']);
    }

    public function test_update_status_invalid_agent_id_blocks(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $result = $repo->updateStatus('', 'available');
        $this->assertSame('blocked', $result['status']);
        $this->assertSame('invalid_agent_id', $result['reason']);
    }

    public function test_register_normalizes_surfaces_and_tags(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $agent = $this->validAgent();
        $agent['surfaces'] = ['surface-b', 'surface-a', 'surface-a', ''];
        $agent['tags'] = [' beta ', 'alpha', ''];
        $result = $repo->register($agent);
        $this->assertSame(['surface-a', 'surface-b'], $result['record']['surfaces']);
        $this->assertSame(['alpha', 'beta'], $result['record']['tags']);
    }

    public function test_register_preserves_registered_at_on_signature_change(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $first = $repo->register($this->validAgent());
        $agent = $this->validAgent();
        $agent['status'] = 'available';
        $second = $repo->register($agent);
        $this->assertSame($first['record']['registered_at'], $second['record']['registered_at']);
    }

    public function test_registry_caps_to_default_cap(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $registry = $repo->registry(['cap' => 10]);
        $this->assertSame(10, $registry['capped_to']);
    }

    public function test_get_invalid_id_returns_null(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $this->assertNull($repo->get('!'));
    }

    public function test_unregister_status_persists(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $repo->register($this->validAgent());
        $repo->unregister('agent-a');
        $record = $repo->get('agent-a');
        $this->assertSame('unregistered', $record['status']);
        $this->assertSame('operator_request', end($record['history'])['reason']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validAgent(): array
    {
        return [
            'agent_id' => 'agent-a',
            'kind' => 'dry_run_agent',
            'label' => 'Agent A',
            'status' => 'registered',
            'capabilities' => ['code_edit', 'evidence_collection'],
            'surfaces' => ['atlas-self-construction'],
            'max_parallel_tasks' => 1,
            'current_task_count' => 0,
            'heartbeat_required' => true,
            'lease_supported' => true,
            'workspace_isolation_supported' => true,
            'cost_meter_supported' => false,
            'continuation_summary_supported' => true,
            'evidence_required' => true,
        ];
    }
}
