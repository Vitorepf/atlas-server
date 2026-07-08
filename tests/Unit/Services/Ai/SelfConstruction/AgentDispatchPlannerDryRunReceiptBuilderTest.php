<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchPlannerDryRunReceiptBuilder;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AgentDispatchPlannerDryRunReceiptBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function builder(): AgentDispatchPlannerDryRunReceiptBuilder
    {
        return new AgentDispatchPlannerDryRunReceiptBuilder;
    }

    private function plannedDispatch(array $overrides = []): array
    {
        return array_merge([
            'task_packet_id' => 't1',
            'task_packet_hash' => 'hash1',
            'agent_id' => 'a1',
            'risk_level' => 'low',
            'workspace_policy' => 'none',
            'requires_lease' => false,
            'dry_run_only' => true,
            'scope_lock' => ['write_set' => ['app/Foo.php'], 'read_set' => []],
        ], $overrides);
    }

    // ── build persists a receipt with is_real_receipt=false ─────────────────────

    public function test_build_persists_a_receipt_that_is_never_marked_real(): void
    {
        $result = $this->builder()->build($this->plannedDispatch());

        self::assertSame('ok', $result['status']);
        self::assertSame('receipt_built', $result['event']);
        self::assertFalse($result['record']['is_real_receipt']);
        self::assertFalse($result['record']['is_real_claim']);
        self::assertFalse($result['record']['is_dispatched']);

        $persisted = $this->builder()->get($result['receipt_id']);
        self::assertNotNull($persisted);
        self::assertFalse($persisted['is_real_receipt']);
    }

    // ── stable receipt_hash for equivalent planned dispatches ───────────────────

    public function test_receipt_hash_is_stable_for_equivalent_planned_dispatches(): void
    {
        $builder = $this->builder();

        $a = $builder->build($this->plannedDispatch(['receipt_id' => 'r-a']), ['receipt_id' => 'r-a']);
        $b = $builder->build($this->plannedDispatch(['receipt_id' => 'r-b']), ['receipt_id' => 'r-b']);

        self::assertSame($a['receipt_hash'], $b['receipt_hash']);
    }

    public function test_receipt_hash_differs_when_write_set_differs(): void
    {
        $builder = $this->builder();

        $a = $builder->build($this->plannedDispatch(['scope_lock' => ['write_set' => ['app/A.php'], 'read_set' => []]]));
        $b = $builder->build($this->plannedDispatch(['scope_lock' => ['write_set' => ['app/B.php'], 'read_set' => []]]));

        self::assertNotSame($a['receipt_hash'], $b['receipt_hash']);
    }

    // ── sanitized receipt path (prevents path traversal) ─────────────────────────

    public function test_receipt_id_with_path_traversal_characters_is_sanitized_on_disk(): void
    {
        $result = $this->builder()->build($this->plannedDispatch(), ['receipt_id' => '../../etc/passwd']);

        self::assertSame('ok', $result['status']);
        Storage::disk('local')->assertMissing('etc/passwd');
        Storage::disk('local')->assertExists(
            AgentDispatchPlannerDryRunReceiptBuilder::STORAGE_PREFIX.'/receipt_______etc_passwd.json'
        );
    }

    public function test_receipt_id_with_arbitrary_storage_path_characters_is_confined_to_receipts_prefix(): void
    {
        $result = $this->builder()->build($this->plannedDispatch(), ['receipt_id' => 'a/b/c']);

        $path = AgentDispatchPlannerDryRunReceiptBuilder::STORAGE_PREFIX.'/receipt_a_b_c.json';
        Storage::disk('local')->assertExists($path);
        self::assertSame('ok', $result['status']);
    }

    // ── get/list filters ──────────────────────────────────────────────────────────

    public function test_get_returns_null_for_unknown_receipt_id(): void
    {
        self::assertNull($this->builder()->get('does-not-exist'));
    }

    public function test_list_filters_by_task_packet_id(): void
    {
        $builder = $this->builder();
        $builder->build($this->plannedDispatch(['task_packet_id' => 'ta']), ['receipt_id' => 'r1']);
        $builder->build($this->plannedDispatch(['task_packet_id' => 'tb']), ['receipt_id' => 'r2']);

        $results = $builder->list(['task_packet_id' => 'ta']);

        self::assertCount(1, $results);
        self::assertSame('ta', $results[0]['task_packet_id']);
    }

    public function test_list_filters_by_agent_id(): void
    {
        $builder = $this->builder();
        $builder->build($this->plannedDispatch(['agent_id' => 'agent-a']), ['receipt_id' => 'r1']);
        $builder->build($this->plannedDispatch(['agent_id' => 'agent-b']), ['receipt_id' => 'r2']);

        $results = $builder->list(['agent_id' => 'agent-b']);

        self::assertCount(1, $results);
        self::assertSame('agent-b', $results[0]['agent_id']);
    }

    public function test_list_respects_limit(): void
    {
        $builder = $this->builder();
        $builder->build($this->plannedDispatch(), ['receipt_id' => 'r1']);
        $builder->build($this->plannedDispatch(), ['receipt_id' => 'r2']);
        $builder->build($this->plannedDispatch(), ['receipt_id' => 'r3']);

        self::assertCount(2, $builder->list(['limit' => 2]));
    }

    // ── unavailable storage returns blocked (missing required identifiers) ───────

    public function test_missing_task_packet_id_returns_blocked_status(): void
    {
        $result = $this->builder()->build($this->plannedDispatch(['task_packet_id' => '']));

        self::assertSame('blocked', $result['status']);
        self::assertSame('task_packet_id_missing', $result['reason']);
    }

    public function test_missing_agent_id_returns_blocked_status(): void
    {
        $result = $this->builder()->build($this->plannedDispatch(['agent_id' => '']));

        self::assertSame('blocked', $result['status']);
        self::assertSame('agent_id_missing', $result['reason']);
    }

    public function test_is_available_reflects_working_storage(): void
    {
        self::assertTrue($this->builder()->isAvailable());
    }

    // ── dry-run receipts never claim real execution ──────────────────────────────

    public function test_build_result_never_sets_dispatch_provider_or_token_execution_flags(): void
    {
        $result = $this->builder()->build($this->plannedDispatch());

        foreach (['dispatch_allowed', 'provider_call_allowed', 'token_spend_allowed', 'runtime_execution_allowed', 'claim_real_allowed'] as $flag) {
            self::assertFalse($result[$flag], "{$flag} must be false");
            self::assertFalse($result['record'][$flag], "record.{$flag} must be false");
        }
    }

    // ── runtimeFlags describe persistent local audit only ────────────────────────

    public function test_runtime_flags_describe_local_audit_only_no_real_execution(): void
    {
        $flags = $this->builder()->runtimeFlags();

        self::assertSame([
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'claim_real_allowed' => false,
        ], $flags);
    }

    public function test_mode_constant_declares_persistent_local_audit(): void
    {
        self::assertSame('persistent_local_agent_dispatch_planner_dry_run_receipt', AgentDispatchPlannerDryRunReceiptBuilder::MODE);
    }
}
