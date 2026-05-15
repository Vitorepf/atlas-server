<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentDispatchPlannerDryRunReceiptBuilder;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AgentDispatchPlannerDryRunReceiptBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_dispatch_planner_dry_run_receipt.v1', AgentDispatchPlannerDryRunReceiptBuilder::SCHEMA_VERSION);
        $this->assertSame('atlas/self-construction/agent-control-plane/dispatch-planner/receipts', AgentDispatchPlannerDryRunReceiptBuilder::STORAGE_PREFIX);
        $this->assertSame('dispatch_plan_dry_run', AgentDispatchPlannerDryRunReceiptBuilder::RECEIPT_KIND);
    }

    public function test_build_valid_receipt(): void
    {
        $repo = new AgentDispatchPlannerDryRunReceiptBuilder;
        $result = $repo->build($this->payload());
        $this->assertSame('ok', $result['status']);
        $this->assertSame('receipt_built', $result['event']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['receipt_hash']);
        $this->assertFalse($result['record']['runtime_execution_allowed']);
        $this->assertFalse($result['record']['dispatch_allowed']);
        $this->assertFalse($result['record']['is_dispatched']);
        $this->assertFalse($result['record']['is_real_claim']);
        $this->assertFalse($result['record']['is_real_receipt']);
        $this->assertFalse($result['record']['claim_real_allowed']);
    }

    public function test_missing_task_packet_id_blocks(): void
    {
        $repo = new AgentDispatchPlannerDryRunReceiptBuilder;
        $payload = $this->payload();
        $payload['task_packet_id'] = '';
        $result = $repo->build($payload);
        $this->assertSame('blocked', $result['status']);
        $this->assertSame('task_packet_id_missing', $result['reason']);
    }

    public function test_missing_agent_id_blocks(): void
    {
        $repo = new AgentDispatchPlannerDryRunReceiptBuilder;
        $payload = $this->payload();
        $payload['agent_id'] = '';
        $result = $repo->build($payload);
        $this->assertSame('blocked', $result['status']);
        $this->assertSame('agent_id_missing', $result['reason']);
    }

    public function test_get_returns_persisted_receipt(): void
    {
        $repo = new AgentDispatchPlannerDryRunReceiptBuilder;
        $result = $repo->build($this->payload());
        $loaded = $repo->get($result['receipt_id']);
        $this->assertNotNull($loaded);
        $this->assertSame($result['receipt_hash'], $loaded['receipt_hash']);
    }

    public function test_list_by_task(): void
    {
        $repo = new AgentDispatchPlannerDryRunReceiptBuilder;
        $repo->build($this->payload(['task_packet_id' => 'tp-1']));
        $repo->build($this->payload(['task_packet_id' => 'tp-2']));
        $list = $repo->list(['task_packet_id' => 'tp-2']);
        $this->assertCount(1, $list);
        $this->assertSame('tp-2', $list[0]['task_packet_id']);
    }

    public function test_list_by_agent(): void
    {
        $repo = new AgentDispatchPlannerDryRunReceiptBuilder;
        $repo->build($this->payload(['agent_id' => 'agent-a']));
        $repo->build($this->payload(['agent_id' => 'agent-b']));
        $list = $repo->list(['agent_id' => 'agent-b']);
        $this->assertCount(1, $list);
        $this->assertSame('agent-b', $list[0]['agent_id']);
    }

    public function test_receipt_hash_stable_for_same_payload(): void
    {
        $repo = new AgentDispatchPlannerDryRunReceiptBuilder;
        $payload = $this->payload();
        $a = $repo->build($payload, ['receipt_id' => 'rcpt-a']);
        $b = $repo->build($payload, ['receipt_id' => 'rcpt-b']);
        $this->assertSame($a['receipt_hash'], $b['receipt_hash']);
    }

    public function test_normalizes_write_set(): void
    {
        $repo = new AgentDispatchPlannerDryRunReceiptBuilder;
        $payload = $this->payload(['scope_lock' => ['write_set' => ['  fileA.php  ', 'fileA.php', ''], 'read_set' => []]]);
        $result = $repo->build($payload);
        $this->assertSame(['fileA.php'], $result['record']['write_set']);
    }

    public function test_list_limit_caps(): void
    {
        $repo = new AgentDispatchPlannerDryRunReceiptBuilder;
        for ($i = 0; $i < 5; $i++) {
            $repo->build($this->payload(['task_packet_id' => 'tp-'.$i]));
        }
        $this->assertCount(2, $repo->list(['limit' => 2]));
    }

    public function test_is_available(): void
    {
        $repo = new AgentDispatchPlannerDryRunReceiptBuilder;
        $this->assertTrue($repo->isAvailable());
    }

    public function test_runtime_flags_helper(): void
    {
        $repo = new AgentDispatchPlannerDryRunReceiptBuilder;
        foreach ($repo->runtimeFlags() as $key => $value) {
            $this->assertFalse($value, "flag {$key} must be false");
        }
    }

    public function test_get_unknown_returns_null(): void
    {
        $repo = new AgentDispatchPlannerDryRunReceiptBuilder;
        $this->assertNull($repo->get('nope'));
    }

    public function test_record_contains_recorded_at_iso(): void
    {
        $repo = new AgentDispatchPlannerDryRunReceiptBuilder;
        $result = $repo->build($this->payload());
        $this->assertNotEmpty($result['record']['recorded_at']);
    }

    public function test_receipt_kind_canonical(): void
    {
        $repo = new AgentDispatchPlannerDryRunReceiptBuilder;
        $result = $repo->build($this->payload());
        $this->assertSame('dispatch_plan_dry_run', $result['record']['receipt_kind']);
    }

    public function test_index_capped(): void
    {
        $repo = new AgentDispatchPlannerDryRunReceiptBuilder;
        for ($i = 0; $i < 3; $i++) {
            $repo->build($this->payload(['task_packet_id' => 'tp-'.$i]));
        }
        $list = $repo->list();
        $this->assertCount(3, $list);
    }

    public function test_list_no_filter_returns_all(): void
    {
        $repo = new AgentDispatchPlannerDryRunReceiptBuilder;
        $repo->build($this->payload(['task_packet_id' => 'tp-1']));
        $repo->build($this->payload(['agent_id' => 'agent-x']));
        $this->assertCount(2, $repo->list());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'task_packet_id' => 'tp-1',
            'task_packet_hash' => str_repeat('a', 64),
            'agent_id' => 'agent-a',
            'risk_level' => 'low',
            'workspace_policy' => 'none',
            'requires_lease' => false,
            'dry_run_only' => true,
            'scope_lock' => ['write_set' => ['fileA.php'], 'read_set' => []],
            'evidence_refs' => ['ev1.md'],
        ], $overrides);
    }
}
