<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentRuntimeRegistryHeartbeatRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AgentRuntimeRegistryHeartbeatRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_runtime_registry_heartbeat.v1', AgentRuntimeRegistryHeartbeatRepository::SCHEMA_VERSION);
        $this->assertSame('atlas/self-construction/agent-control-plane/agent-heartbeats', AgentRuntimeRegistryHeartbeatRepository::STORAGE_PREFIX);
        $this->assertContains('healthy', AgentRuntimeRegistryHeartbeatRepository::STATUSES);
        $this->assertContains('stale', AgentRuntimeRegistryHeartbeatRepository::STATUSES);
    }

    public function test_record_heartbeat(): void
    {
        $repo = new AgentRuntimeRegistryHeartbeatRepository;
        $result = $repo->record('agent-a', [
            'status' => 'healthy',
            'current_task_count' => 0,
            'max_parallel_tasks' => 2,
        ]);
        $this->assertSame('ok', $result['status']);
        $this->assertSame('heartbeat_recorded', $result['event']);
        $this->assertSame('agent-a', $result['record']['agent_id']);
        $this->assertSame(0, $result['record']['current_task_count']);
        $this->assertSame(2, $result['record']['max_parallel_tasks']);
        $this->assertFalse($result['record']['runtime_execution_allowed']);
        $this->assertFalse($result['record']['provider_call_allowed']);
        $this->assertFalse($result['record']['token_spend_allowed']);
    }

    public function test_record_invalid_agent_id_blocks(): void
    {
        $repo = new AgentRuntimeRegistryHeartbeatRepository;
        $result = $repo->record('', ['status' => 'healthy']);
        $this->assertSame('blocked', $result['status']);
        $this->assertSame('invalid_agent_id', $result['reason']);
    }

    public function test_record_invalid_status_blocks(): void
    {
        $repo = new AgentRuntimeRegistryHeartbeatRepository;
        $result = $repo->record('agent-a', ['status' => 'launching']);
        $this->assertSame('blocked', $result['status']);
        $this->assertSame('invalid_status', $result['reason']);
    }

    public function test_latest_returns_most_recent(): void
    {
        $repo = new AgentRuntimeRegistryHeartbeatRepository;
        $repo->record('agent-a', ['status' => 'healthy', 'observed_at' => '2026-05-14T10:00:00+00:00']);
        $repo->record('agent-a', ['status' => 'busy', 'observed_at' => '2026-05-14T10:05:00+00:00']);
        $latest = $repo->latest('agent-a');
        $this->assertNotNull($latest);
        $this->assertSame('busy', $latest['status']);
    }

    public function test_latest_unknown_null(): void
    {
        $repo = new AgentRuntimeRegistryHeartbeatRepository;
        $this->assertNull($repo->latest('agent-x'));
        $this->assertNull($repo->latest(''));
    }

    public function test_list_by_agent_and_since(): void
    {
        $repo = new AgentRuntimeRegistryHeartbeatRepository;
        $repo->record('agent-a', ['status' => 'healthy', 'observed_at' => '2026-05-14T09:00:00+00:00']);
        $repo->record('agent-a', ['status' => 'busy', 'observed_at' => '2026-05-14T10:00:00+00:00']);
        $repo->record('agent-b', ['status' => 'healthy', 'observed_at' => '2026-05-14T10:00:00+00:00']);
        $this->assertCount(2, $repo->list(['agent_id' => 'agent-a']));
        $this->assertCount(3, $repo->list());
        $this->assertCount(2, $repo->list(['since' => '2026-05-14T09:30:00+00:00']));
        $this->assertCount(1, $repo->list(['agent_id' => 'agent-a', 'limit' => 1]));
    }

    public function test_stale_detection(): void
    {
        $repo = new AgentRuntimeRegistryHeartbeatRepository;
        $repo->record('agent-stale', [
            'status' => 'healthy',
            'observed_at' => CarbonImmutable::now()->subSeconds(3600)->toIso8601String(),
        ]);
        $repo->record('agent-fresh', [
            'status' => 'healthy',
            'observed_at' => CarbonImmutable::now()->toIso8601String(),
        ]);
        $summary = $repo->staleAgents(['ttl_seconds' => 60]);
        $this->assertSame(1, $summary['stale_count']);
        $this->assertSame(1, $summary['fresh_count']);
        $this->assertSame(1, $summary['stale_detail_count']);
        $this->assertSame(1, $summary['fresh_detail_count']);
        $this->assertFalse($summary['stale_detail_truncated']);
        $this->assertFalse($summary['fresh_detail_truncated']);
        $this->assertSame(60, $summary['ttl_seconds']);
        $this->assertFalse($summary['dispatch_allowed']);
    }

    public function test_stale_detection_caps_detail_without_losing_counts(): void
    {
        $repo = new AgentRuntimeRegistryHeartbeatRepository;
        for ($i = 0; $i < 5; $i++) {
            $repo->record('agent-stale-'.$i, [
                'status' => 'healthy',
                'observed_at' => CarbonImmutable::now()->subSeconds(3600 + $i)->toIso8601String(),
            ]);
            $repo->record('agent-fresh-'.$i, [
                'status' => 'healthy',
                'observed_at' => CarbonImmutable::now()->toIso8601String(),
            ]);
        }

        $summary = $repo->staleAgents([
            'ttl_seconds' => 60,
            'detail_limit' => 2,
        ]);

        $this->assertSame(5, $summary['stale_count']);
        $this->assertSame(5, $summary['fresh_count']);
        $this->assertSame(2, $summary['stale_detail_count']);
        $this->assertSame(2, $summary['fresh_detail_count']);
        $this->assertTrue($summary['stale_detail_truncated']);
        $this->assertTrue($summary['fresh_detail_truncated']);
        $this->assertCount(2, $summary['stale_agents']);
        $this->assertCount(2, $summary['fresh_agents']);
        $this->assertFalse($summary['runtime_execution_allowed']);
        $this->assertFalse($summary['self_programming_allowed']);
    }

    public function test_index_is_compacted_to_recent_unique_agents(): void
    {
        $repo = new AgentRuntimeRegistryHeartbeatRepository;
        $oldObserved = CarbonImmutable::now()->subDays(2);
        $newObserved = CarbonImmutable::now();
        $index = [];
        for ($i = 0; $i < AgentRuntimeRegistryHeartbeatRepository::DEFAULT_INDEX_AGENT_CAP + 5; $i++) {
            $observed = $oldObserved->subSeconds($i)->toIso8601String();
            $index[] = [
                'agent_id' => 'bulk-agent-'.$i,
                'observed_at' => $observed,
                'recorded_at' => $observed,
                'status' => 'healthy',
                'current_task_count' => 0,
                'max_parallel_tasks' => 1,
            ];
        }
        $index[] = [
            'agent_id' => 'bulk-agent-0',
            'observed_at' => $newObserved->toIso8601String(),
            'recorded_at' => $newObserved->toIso8601String(),
            'status' => 'busy',
            'current_task_count' => 0,
            'max_parallel_tasks' => 1,
        ];

        Storage::disk('local')->put(
            AgentRuntimeRegistryHeartbeatRepository::INDEX_PATH,
            json_encode($index, JSON_THROW_ON_ERROR),
        );

        $repo->record('newest-agent', [
            'status' => 'healthy',
            'observed_at' => $newObserved->addSecond()->toIso8601String(),
        ]);

        $summary = $repo->staleAgents(['detail_limit' => 0]);
        $allAgents = collect(array_merge($summary['stale_agents'], $summary['fresh_agents']))
            ->pluck('agent_id')
            ->all();

        $this->assertLessThanOrEqual(AgentRuntimeRegistryHeartbeatRepository::DEFAULT_INDEX_AGENT_CAP, $summary['stale_count'] + $summary['fresh_count']);
        $this->assertContains('newest-agent', $allAgents);
        $this->assertContains('bulk-agent-0', $allAgents);
        $this->assertSame(count($allAgents), count(array_unique($allAgents)));
    }

    public function test_heartbeat_status_fresh_and_stale(): void
    {
        $repo = new AgentRuntimeRegistryHeartbeatRepository;
        $repo->record('agent-a', [
            'status' => 'healthy',
            'observed_at' => CarbonImmutable::now()->subSeconds(10)->toIso8601String(),
        ]);
        $repo->record('agent-b', [
            'status' => 'healthy',
            'observed_at' => CarbonImmutable::now()->subSeconds(3600)->toIso8601String(),
        ]);
        $fresh = $repo->heartbeatStatus('agent-a', ['ttl_seconds' => 60]);
        $stale = $repo->heartbeatStatus('agent-b', ['ttl_seconds' => 60]);
        $this->assertTrue($fresh['is_fresh']);
        $this->assertFalse($fresh['is_stale']);
        $this->assertTrue($stale['is_stale']);
        $this->assertFalse($stale['is_fresh']);
        $this->assertSame('older_than_ttl', $stale['reason']);
    }

    public function test_heartbeat_status_missing(): void
    {
        $repo = new AgentRuntimeRegistryHeartbeatRepository;
        $status = $repo->heartbeatStatus('agent-x');
        $this->assertFalse($status['has_heartbeat']);
        $this->assertTrue($status['is_stale']);
        $this->assertSame('no_heartbeat', $status['reason']);
    }

    public function test_runtime_flags_helper(): void
    {
        $repo = new AgentRuntimeRegistryHeartbeatRepository;
        foreach ($repo->runtimeFlags() as $key => $value) {
            $this->assertFalse($value, "flag {$key} must remain false");
        }
    }

    public function test_is_available(): void
    {
        $repo = new AgentRuntimeRegistryHeartbeatRepository;
        $this->assertTrue($repo->isAvailable());
    }

    public function test_normalizes_workspace_and_lease_ids(): void
    {
        $repo = new AgentRuntimeRegistryHeartbeatRepository;
        $result = $repo->record('agent-a', [
            'status' => 'busy',
            'active_lease_ids' => [' lease-1 ', 'lease-2', 'lease-1', ''],
            'current_workspace_ids' => ['ws-2', 'ws-1', 'ws-1'],
        ]);
        $this->assertSame(['lease-1', 'lease-2'], $result['record']['active_lease_ids']);
        $this->assertSame(['ws-1', 'ws-2'], $result['record']['current_workspace_ids']);
    }

    public function test_record_clamps_current_task_count(): void
    {
        $repo = new AgentRuntimeRegistryHeartbeatRepository;
        $result = $repo->record('agent-a', [
            'status' => 'busy',
            'current_task_count' => 99,
            'max_parallel_tasks' => 2,
        ]);
        $this->assertSame(2, $result['record']['current_task_count']);
    }

    public function test_stale_agents_with_invalid_timestamp_handled(): void
    {
        Storage::disk('local')->put(
            AgentRuntimeRegistryHeartbeatRepository::INDEX_PATH,
            json_encode([
                ['agent_id' => 'agent-broken', 'observed_at' => 'not-a-date', 'status' => 'healthy'],
            ])
        );
        $repo = new AgentRuntimeRegistryHeartbeatRepository;
        $summary = $repo->staleAgents();
        $this->assertSame(1, $summary['stale_count']);
        $this->assertSame('invalid_timestamp', $summary['stale_agents'][0]['reason']);
    }

    public function test_heartbeat_status_invalid_timestamp_handled(): void
    {
        $repo = new AgentRuntimeRegistryHeartbeatRepository;
        $repo->record('agent-a', ['status' => 'healthy', 'observed_at' => 'broken-string']);
        $status = $repo->heartbeatStatus('agent-a');
        $this->assertTrue($status['is_stale']);
        $this->assertSame('invalid_timestamp', $status['reason']);
    }

    public function test_list_with_no_data_returns_empty(): void
    {
        $repo = new AgentRuntimeRegistryHeartbeatRepository;
        $this->assertSame([], $repo->list());
        $this->assertSame([], $repo->list(['agent_id' => 'absent']));
    }

    public function test_runtime_flags_in_record_envelope(): void
    {
        $repo = new AgentRuntimeRegistryHeartbeatRepository;
        $result = $repo->record('agent-a', ['status' => 'healthy']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['runtime_execution_allowed']);
        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['ledger_write_allowed']);
    }
}
