<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentRuntimeRegistryQuarantineRepository;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AgentRuntimeRegistryQuarantineRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_runtime_registry_quarantine.v1', AgentRuntimeRegistryQuarantineRepository::SCHEMA_VERSION);
        $this->assertSame('atlas/self-construction/agent-control-plane/agent-quarantine', AgentRuntimeRegistryQuarantineRepository::STORAGE_PREFIX);
        $this->assertContains('stale_heartbeat', AgentRuntimeRegistryQuarantineRepository::REASONS);
        $this->assertContains('operator_disabled', AgentRuntimeRegistryQuarantineRepository::REASONS);
    }

    public function test_quarantine_agent(): void
    {
        $repo = new AgentRuntimeRegistryQuarantineRepository;
        $result = $repo->quarantine('agent-a', [
            'code' => 'stale_heartbeat',
            'detail' => 'no heartbeat in 1h',
            'declared_by' => 'operator-1',
        ]);
        $this->assertSame('ok', $result['status']);
        $this->assertSame('quarantined', $result['event']);
        $this->assertTrue($result['record']['is_quarantined']);
        $this->assertTrue($repo->isQuarantined('agent-a'));
        $this->assertFalse($result['record']['runtime_execution_allowed']);
        $this->assertFalse($result['record']['dispatch_allowed']);
    }

    public function test_quarantine_invalid_reason_blocks(): void
    {
        $repo = new AgentRuntimeRegistryQuarantineRepository;
        $result = $repo->quarantine('agent-a', [
            'code' => 'rogue_reason',
            'declared_by' => 'operator-1',
        ]);
        $this->assertSame('blocked', $result['status']);
        $this->assertSame('invalid_reason', $result['reason']);
    }

    public function test_quarantine_invalid_agent_id_blocks(): void
    {
        $repo = new AgentRuntimeRegistryQuarantineRepository;
        $result = $repo->quarantine('', [
            'code' => 'stale_heartbeat',
            'declared_by' => 'operator-1',
        ]);
        $this->assertSame('blocked', $result['status']);
        $this->assertSame('invalid_agent_id', $result['reason']);
    }

    public function test_quarantine_requires_declared_by(): void
    {
        $repo = new AgentRuntimeRegistryQuarantineRepository;
        $result = $repo->quarantine('agent-a', [
            'code' => 'stale_heartbeat',
        ]);
        $this->assertSame('blocked', $result['status']);
        $this->assertSame('declared_by_missing', $result['reason']);
    }

    public function test_release_requires_reviewer_and_reason(): void
    {
        $repo = new AgentRuntimeRegistryQuarantineRepository;
        $repo->quarantine('agent-a', [
            'code' => 'stale_heartbeat',
            'declared_by' => 'operator-1',
        ]);
        $noReviewer = $repo->release('agent-a', ['reason' => 'fixed']);
        $this->assertSame('blocked', $noReviewer['status']);
        $this->assertSame('reviewer_missing', $noReviewer['reason']);
        $noReason = $repo->release('agent-a', ['reviewer' => 'op']);
        $this->assertSame('blocked', $noReason['status']);
        $this->assertSame('release_reason_missing', $noReason['reason']);
    }

    public function test_release_succeeds_with_reviewer_and_reason(): void
    {
        $repo = new AgentRuntimeRegistryQuarantineRepository;
        $repo->quarantine('agent-a', [
            'code' => 'stale_heartbeat',
            'declared_by' => 'operator-1',
        ]);
        $result = $repo->release('agent-a', [
            'reviewer' => 'reviewer-1',
            'reason' => 'heartbeat_restored',
        ]);
        $this->assertSame('ok', $result['status']);
        $this->assertSame('released', $result['event']);
        $this->assertFalse($result['record']['is_quarantined']);
        $this->assertTrue($result['record']['released']);
        $this->assertSame('reviewer-1', $result['record']['released_by']);
        $this->assertFalse($repo->isQuarantined('agent-a'));
    }

    public function test_release_when_not_quarantined_blocks(): void
    {
        $repo = new AgentRuntimeRegistryQuarantineRepository;
        $result = $repo->release('agent-x', ['reviewer' => 'op', 'reason' => 'reason']);
        $this->assertSame('blocked', $result['status']);
        $this->assertSame('not_quarantined', $result['reason']);
    }

    public function test_release_when_already_released_blocks(): void
    {
        $repo = new AgentRuntimeRegistryQuarantineRepository;
        $repo->quarantine('agent-a', ['code' => 'stale_heartbeat', 'declared_by' => 'op']);
        $repo->release('agent-a', ['reviewer' => 'rev', 'reason' => 'r']);
        $second = $repo->release('agent-a', ['reviewer' => 'rev', 'reason' => 'r']);
        $this->assertSame('blocked', $second['status']);
        $this->assertSame('agent_not_currently_quarantined', $second['reason']);
    }

    public function test_list_filters(): void
    {
        $repo = new AgentRuntimeRegistryQuarantineRepository;
        $repo->quarantine('agent-a', ['code' => 'stale_heartbeat', 'declared_by' => 'op']);
        $repo->quarantine('agent-b', ['code' => 'lease_violation', 'declared_by' => 'op']);
        $repo->release('agent-b', ['reviewer' => 'r', 'reason' => 'ok']);
        $all = $repo->list();
        $this->assertCount(2, $all);
        $onlyActive = $repo->list(['only_active' => true]);
        $this->assertCount(1, $onlyActive);
        $byReason = $repo->list(['reason_code' => 'stale_heartbeat']);
        $this->assertCount(1, $byReason);
    }

    public function test_active_agent_ids(): void
    {
        $repo = new AgentRuntimeRegistryQuarantineRepository;
        $repo->quarantine('agent-a', ['code' => 'stale_heartbeat', 'declared_by' => 'op']);
        $repo->quarantine('agent-b', ['code' => 'lease_violation', 'declared_by' => 'op']);
        $repo->release('agent-b', ['reviewer' => 'r', 'reason' => 'ok']);
        $active = $repo->activeAgentIds();
        $this->assertSame(['agent-a'], $active);
    }

    public function test_receipts_recorded_locally(): void
    {
        $repo = new AgentRuntimeRegistryQuarantineRepository;
        $repo->quarantine('agent-a', ['code' => 'stale_heartbeat', 'declared_by' => 'op']);
        $repo->release('agent-a', ['reviewer' => 'r', 'reason' => 'ok']);
        $list = $repo->list(['only_active' => false]);
        $this->assertCount(1, $list);
        $this->assertGreaterThanOrEqual(2, count($list[0]['receipts']));
        foreach ($list[0]['receipts'] as $receipt) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $receipt['receipt_hash']);
        }
    }

    public function test_runtime_flags_helper(): void
    {
        $repo = new AgentRuntimeRegistryQuarantineRepository;
        foreach ($repo->runtimeFlags() as $key => $value) {
            $this->assertFalse($value, "flag {$key} must remain false");
        }
    }

    public function test_is_available(): void
    {
        $repo = new AgentRuntimeRegistryQuarantineRepository;
        $this->assertTrue($repo->isAvailable());
    }

    public function test_quarantine_envelope_runtime_flags(): void
    {
        $repo = new AgentRuntimeRegistryQuarantineRepository;
        $result = $repo->quarantine('agent-a', ['code' => 'stale_heartbeat', 'declared_by' => 'op']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['runtime_execution_allowed']);
        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['ledger_write_allowed']);
    }

    public function test_release_invalid_agent_blocks(): void
    {
        $repo = new AgentRuntimeRegistryQuarantineRepository;
        $result = $repo->release('', ['reviewer' => 'op', 'reason' => 'r']);
        $this->assertSame('blocked', $result['status']);
        $this->assertSame('invalid_agent_id', $result['reason']);
    }

    public function test_is_quarantined_invalid_id_false(): void
    {
        $repo = new AgentRuntimeRegistryQuarantineRepository;
        $this->assertFalse($repo->isQuarantined(''));
        $this->assertFalse($repo->isQuarantined('agent-not-yet'));
    }

    public function test_active_agent_ids_empty_when_none(): void
    {
        $repo = new AgentRuntimeRegistryQuarantineRepository;
        $this->assertSame([], $repo->activeAgentIds());
    }
}
