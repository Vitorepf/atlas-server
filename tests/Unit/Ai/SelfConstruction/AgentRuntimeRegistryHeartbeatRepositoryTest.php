<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

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

    // ── AC1: capability snapshot recorded and returned ─────────────────────────

    public function test_record_stores_capability_snapshot(): void
    {
        $repo = new AgentRuntimeRegistryHeartbeatRepository;
        $result = $repo->record('agent-a', [
            'status' => 'healthy',
            'capabilities' => ['php', 'testing', 'php'],
        ]);

        $this->assertSame(['php', 'testing'], $result['record']['capabilities']);
    }

    public function test_capabilities_default_to_empty_list(): void
    {
        $repo = new AgentRuntimeRegistryHeartbeatRepository;
        $result = $repo->record('agent-a', ['status' => 'healthy']);

        $this->assertSame([], $result['record']['capabilities']);
    }

    public function test_heartbeat_status_exposes_capability_snapshot(): void
    {
        $repo = new AgentRuntimeRegistryHeartbeatRepository;
        $repo->record('agent-a', ['status' => 'healthy', 'capabilities' => ['php', 'refactor']]);

        $status = $repo->heartbeatStatus('agent-a');

        $this->assertSame(['php', 'refactor'], $status['capabilities']);
    }

    // ── AC2 (this task's AC3): quarantined status is deterministic and safety-first ──

    public function test_quarantined_status_is_accepted_by_record(): void
    {
        $repo = new AgentRuntimeRegistryHeartbeatRepository;
        $result = $repo->record('agent-a', ['status' => 'quarantined']);

        $this->assertSame('ok', $result['status']);
        $this->assertSame('quarantined', $result['record']['status']);
    }

    public function test_quarantined_agent_status_category_is_quarantined_even_when_fresh(): void
    {
        $repo = new AgentRuntimeRegistryHeartbeatRepository;
        $repo->record('agent-a', [
            'status' => 'quarantined',
            'observed_at' => CarbonImmutable::now()->toIso8601String(),
        ]);

        $status = $repo->heartbeatStatus('agent-a', ['ttl_seconds' => 60]);

        $this->assertTrue($status['is_quarantined']);
        $this->assertSame(AgentRuntimeRegistryHeartbeatRepository::STATUS_CATEGORY_QUARANTINED, $status['status_category']);
        $this->assertSame('quarantined', $status['reason']);
    }

    public function test_fresh_healthy_agent_status_category_is_fresh(): void
    {
        $repo = new AgentRuntimeRegistryHeartbeatRepository;
        $repo->record('agent-a', [
            'status' => 'healthy',
            'observed_at' => CarbonImmutable::now()->toIso8601String(),
        ]);

        $status = $repo->heartbeatStatus('agent-a', ['ttl_seconds' => 60]);

        $this->assertFalse($status['is_quarantined']);
        $this->assertSame(AgentRuntimeRegistryHeartbeatRepository::STATUS_CATEGORY_FRESH, $status['status_category']);
    }

    public function test_stale_agent_status_category_is_stale(): void
    {
        $repo = new AgentRuntimeRegistryHeartbeatRepository;
        $repo->record('agent-a', [
            'status' => 'healthy',
            'observed_at' => CarbonImmutable::now()->subSeconds(3600)->toIso8601String(),
        ]);

        $status = $repo->heartbeatStatus('agent-a', ['ttl_seconds' => 60]);

        $this->assertSame(AgentRuntimeRegistryHeartbeatRepository::STATUS_CATEGORY_STALE, $status['status_category']);
    }

    public function test_missing_agent_status_category_is_missing(): void
    {
        $repo = new AgentRuntimeRegistryHeartbeatRepository;

        $status = $repo->heartbeatStatus('agent-nonexistent');

        $this->assertSame(AgentRuntimeRegistryHeartbeatRepository::STATUS_CATEGORY_MISSING, $status['status_category']);
    }

    // ── AC4 (this task's): stale query returns suggested recovery action ──────

    public function test_stale_agent_entry_includes_suggested_recovery_action(): void
    {
        $repo = new AgentRuntimeRegistryHeartbeatRepository;
        $repo->record('agent-stale', [
            'status' => 'healthy',
            'observed_at' => CarbonImmutable::now()->subSeconds(3600)->toIso8601String(),
        ]);

        $summary = $repo->staleAgents(['ttl_seconds' => 60]);

        $this->assertArrayHasKey('suggested_recovery_action', $summary['stale_agents'][0]);
        $this->assertNotEmpty($summary['stale_agents'][0]['suggested_recovery_action']);
    }

    public function test_invalid_timestamp_stale_entry_includes_suggested_recovery_action(): void
    {
        Storage::disk('local')->put(
            AgentRuntimeRegistryHeartbeatRepository::INDEX_PATH,
            json_encode([
                ['agent_id' => 'agent-broken', 'observed_at' => 'not-a-date', 'status' => 'healthy'],
            ]),
        );
        $repo = new AgentRuntimeRegistryHeartbeatRepository;

        $summary = $repo->staleAgents();

        $this->assertArrayHasKey('suggested_recovery_action', $summary['stale_agents'][0]);
    }

    public function test_stale_query_does_not_mutate_runtime_state(): void
    {
        $repo = new AgentRuntimeRegistryHeartbeatRepository;
        $repo->record('agent-a', [
            'status' => 'healthy',
            'observed_at' => CarbonImmutable::now()->subSeconds(3600)->toIso8601String(),
        ]);

        $before = $repo->list(['agent_id' => 'agent-a']);
        $repo->staleAgents(['ttl_seconds' => 60]);
        $after = $repo->list(['agent_id' => 'agent-a']);

        $this->assertSame($before, $after, 'staleAgents() must be read-only');
    }

    public function test_fresh_agent_entry_has_no_recovery_action(): void
    {
        $repo = new AgentRuntimeRegistryHeartbeatRepository;
        $repo->record('agent-fresh', [
            'status' => 'healthy',
            'observed_at' => CarbonImmutable::now()->toIso8601String(),
        ]);

        $summary = $repo->staleAgents(['ttl_seconds' => 60]);

        $this->assertArrayNotHasKey('suggested_recovery_action', $summary['fresh_agents'][0]);
    }

    // ── AC: repeated heartbeats with unchanged task id, evidence hash and no progress become fake_alive ──

    public function test_repeated_heartbeats_with_no_progress_become_fake_alive(): void
    {
        $repo = new AgentRuntimeRegistryHeartbeatRepository;
        $now = CarbonImmutable::now()->toIso8601String();

        // First heartbeat: has a task, productive (first heartbeat gets benefit of the doubt)
        $repo->record('agent-fake', [
            'status' => 'healthy',
            'observed_at' => $now,
            'current_task_count' => 1,
            'progress_marker' => 'marker-1',
            'last_outcome_marker' => 'outcome-1',
        ]);

        // Second heartbeat: same task, same markers → fake_alive
        $repo->record('agent-fake', [
            'status' => 'healthy',
            'observed_at' => $now,
            'current_task_count' => 1,
            'progress_marker' => 'marker-1',
            'last_outcome_marker' => 'outcome-1',
        ]);

        $status = $repo->heartbeatStatus('agent-fake', ['ttl_seconds' => 60]);

        $this->assertSame(AgentRuntimeRegistryHeartbeatRepository::HEARTBEAT_STATE_FAKE_ALIVE, $status['heartbeat_state']);
    }

    // ── AC: productive heartbeats with new evidence or completed task movement remain productive ──

    public function test_productive_heartbeat_with_new_progress_marker_remains_productive(): void
    {
        $repo = new AgentRuntimeRegistryHeartbeatRepository;
        $now = CarbonImmutable::now()->toIso8601String();

        $repo->record('agent-prod', [
            'status' => 'healthy',
            'observed_at' => $now,
            'current_task_count' => 1,
            'progress_marker' => 'marker-1',
            'last_outcome_marker' => 'outcome-1',
        ]);

        $repo->record('agent-prod', [
            'status' => 'healthy',
            'observed_at' => $now,
            'current_task_count' => 1,
            'progress_marker' => 'marker-2',
            'last_outcome_marker' => 'outcome-1',
        ]);

        $status = $repo->heartbeatStatus('agent-prod', ['ttl_seconds' => 60]);

        $this->assertSame(AgentRuntimeRegistryHeartbeatRepository::HEARTBEAT_STATE_PRODUCTIVE, $status['heartbeat_state']);
    }

    public function test_productive_heartbeat_with_new_outcome_marker_remains_productive(): void
    {
        $repo = new AgentRuntimeRegistryHeartbeatRepository;
        $now = CarbonImmutable::now()->toIso8601String();

        $repo->record('agent-prod', [
            'status' => 'healthy',
            'observed_at' => $now,
            'current_task_count' => 1,
            'progress_marker' => 'marker-1',
            'last_outcome_marker' => 'outcome-1',
        ]);

        $repo->record('agent-prod', [
            'status' => 'healthy',
            'observed_at' => $now,
            'current_task_count' => 1,
            'progress_marker' => 'marker-1',
            'last_outcome_marker' => 'outcome-2',
        ]);

        $status = $repo->heartbeatStatus('agent-prod', ['ttl_seconds' => 60]);

        $this->assertSame(AgentRuntimeRegistryHeartbeatRepository::HEARTBEAT_STATE_PRODUCTIVE, $status['heartbeat_state']);
    }

    // ── AC: staleAgents reports fake_alive separately from clock-based stale workers ──

    public function test_stale_agents_reports_fake_alive_separately(): void
    {
        $repo = new AgentRuntimeRegistryHeartbeatRepository;
        $now = CarbonImmutable::now()->toIso8601String();

        // Fake-alive agent: two heartbeats with same markers
        $repo->record('agent-fake', [
            'status' => 'healthy',
            'observed_at' => $now,
            'current_task_count' => 1,
            'progress_marker' => 'm1',
            'last_outcome_marker' => 'o1',
        ]);
        $repo->record('agent-fake', [
            'status' => 'healthy',
            'observed_at' => $now,
            'current_task_count' => 1,
            'progress_marker' => 'm1',
            'last_outcome_marker' => 'o1',
        ]);

        // Stale agent: old heartbeat
        $repo->record('agent-stale', [
            'status' => 'healthy',
            'observed_at' => CarbonImmutable::now()->subSeconds(3600)->toIso8601String(),
        ]);

        $summary = $repo->staleAgents(['ttl_seconds' => 60]);

        $this->assertArrayHasKey('fake_alive_agents', $summary);
        $this->assertArrayHasKey('fake_alive_count', $summary);
        $this->assertGreaterThan(0, $summary['fake_alive_count']);

        $fakeAliveIds = array_column($summary['fake_alive_agents'], 'agent_id');
        $this->assertContains('agent-fake', $fakeAliveIds);
        $this->assertNotContains('agent-stale', $fakeAliveIds);
    }
}
