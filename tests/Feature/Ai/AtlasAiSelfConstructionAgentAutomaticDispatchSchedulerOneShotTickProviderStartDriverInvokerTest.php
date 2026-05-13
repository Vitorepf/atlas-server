<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentDispatchExecutorReleaseAuthorization;
use App\Models\AtlasSelfConstructionAgentDispatchReceipt;
use App\Models\AtlasSelfConstructionAgentSandboxBinding;
use App\Services\Ai\SelfConstruction\AgentAutomaticDispatchSchedulerOneShotTickProviderStartDriverInvoker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickProviderStartDriverInvokerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTables();

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_12_010000_create_atlas_self_construction_agent_control_plane_tables.php'))->up();
        (require database_path('migrations/2026_05_12_020000_create_atlas_self_construction_agent_dispatch_executor_release_authorizations_table.php'))->up();
        (require database_path('migrations/2026_05_12_030000_create_atlas_self_construction_agent_sandbox_bindings_table.php'))->up();
    }

    protected function tearDown(): void
    {
        $this->dropTables();

        parent::tearDown();
    }

    public function test_invoker_prepares_pre_start_guarded_run_without_starting_provider(): void
    {
        $this->createPrerequisites();

        $result = app(AgentAutomaticDispatchSchedulerOneShotTickProviderStartDriverInvoker::class)
            ->prepareProviderStartDriver($this->validInput());

        $this->assertSame('one_shot_scheduler_provider_start_driver_prepared', $result['status']);
        $this->assertTrue($result['provider_start_driver_invoked']);
        $this->assertSame(1, $result['provider_start_driver_invocation_count']);
        $this->assertFalse($result['provider_external_process_started']);
        $this->assertFalse($result['provider_started']);
        $this->assertFalse($result['adapter_invocation_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
        $this->assertSame('activate_signed_one_shot_scheduler_tick_adapter_invocation_boundary_release_contract', $result['next_required_slice']);

        $this->assertDatabaseHas('atlas_self_construction_agent_runs', [
            'run_key' => 'provider-start:attempt-001',
            'status' => 'pre_start_guarded',
            'provider' => 'codex',
        ]);
        $this->assertDatabaseHas('atlas_self_construction_agent_heartbeats', [
            'heartbeat_key' => 'provider-start:attempt-001:heartbeat:pre-start',
            'signal' => 'pre_start_guard',
        ]);
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'provider-start:attempt-001',
        ]);
    }

    public function test_invoker_is_idempotent_for_same_provider_start_attempt(): void
    {
        $this->createPrerequisites();
        $invoker = app(AgentAutomaticDispatchSchedulerOneShotTickProviderStartDriverInvoker::class);

        $first = $invoker->prepareProviderStartDriver($this->validInput());
        $second = $invoker->prepareProviderStartDriver($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertFalse($second['provider_started']);
        $this->assertDatabaseCount('atlas_self_construction_agent_runs', 1);
        $this->assertDatabaseCount('atlas_self_construction_agent_heartbeats', 1);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_invoker_rejects_invalid_receipt_hash_without_creating_run(): void
    {
        $this->createPrerequisites();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_receipt_hash');

        app(AgentAutomaticDispatchSchedulerOneShotTickProviderStartDriverInvoker::class)
            ->prepareProviderStartDriver(array_merge($this->validInput(), [
                'receipt_hash' => 'not-a-hash',
            ]));

        $this->assertDatabaseCount('atlas_self_construction_agent_runs', 0);
    }

    public function test_invoker_rejects_receipt_that_was_not_marked_used(): void
    {
        $this->createPrerequisites([
            'receipt' => [
                'status' => 'signed_pending_dispatch',
                'used_at' => null,
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dispatch_receipt_not_used_pending_provider_start');

        app(AgentAutomaticDispatchSchedulerOneShotTickProviderStartDriverInvoker::class)
            ->prepareProviderStartDriver($this->validInput());
    }

    public function test_invoker_rejects_sandbox_cwd_mismatch(): void
    {
        $this->createPrerequisites();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sandbox_binding_cwd_mismatch');

        app(AgentAutomaticDispatchSchedulerOneShotTickProviderStartDriverInvoker::class)
            ->prepareProviderStartDriver(array_merge($this->validInput(), [
                'cwd' => '/Users/vitorepf/develop/Atlas/other-worktree',
            ]));
    }

    /**
     * @param  array<string,array<string,mixed>>  $overrides
     */
    private function createPrerequisites(array $overrides = []): void
    {
        AtlasSelfConstructionAgentDispatchReceipt::query()->create(array_merge([
            'receipt_key' => 'DISPATCH-RECEIPT-001',
            'packet_id' => 'AP-001',
            'provider' => 'codex',
            'provider_role' => 'implementation',
            'decision' => 'approve_dispatch_once',
            'status' => 'used_pending_provider_start',
            'signed_by' => 'vitor',
            'signed_at' => CarbonImmutable::now(),
            'expires_at' => CarbonImmutable::now()->addHour(),
            'used_at' => CarbonImmutable::now(),
            'dispatch_envelope_hash' => str_repeat('1', 64),
            'adapter_contract_hash' => str_repeat('2', 64),
            'receipt_hash' => str_repeat('a', 64),
            'payload' => [
                'receipt_use' => [
                    'provider_start_attempt_id' => 'attempt-001',
                    'executor_contract_hash' => str_repeat('b', 64),
                    'executor_release_authorization_hash' => str_repeat('c', 64),
                ],
            ],
        ], $overrides['receipt'] ?? []));

        AtlasSelfConstructionAgentDispatchExecutorReleaseAuthorization::query()->create([
            'authorization_key' => 'AUTH-001',
            'receipt_key' => 'RELEASE-AUTH-RECEIPT-001',
            'authorization_id' => 'DISPATCH-EXECUTOR-RELEASE-AUTH-001',
            'packet_id' => 'AP-001',
            'provider' => 'codex',
            'provider_role' => 'implementation',
            'decision' => 'approve_release_once',
            'status' => 'persisted_pending_executor_release',
            'signed_by' => 'vitor',
            'signed_at' => CarbonImmutable::now(),
            'expires_at' => CarbonImmutable::now()->addHour(),
            'signed_receipt_template_hash' => str_repeat('1', 64),
            'signed_receipt_preflight_hash' => str_repeat('2', 64),
            'persistence_template_hash' => str_repeat('3', 64),
            'persistence_preflight_hash' => str_repeat('4', 64),
            'external_signature_validation_report_hash' => str_repeat('5', 64),
            'signed_receipt_hash' => str_repeat('c', 64),
            'payload' => ['source' => 'one_shot_scheduler_provider_start_driver_invoker_test'],
            'persisted_at' => CarbonImmutable::now(),
        ]);

        AtlasSelfConstructionAgentSandboxBinding::query()->create([
            'binding_key' => 'BINDING-001',
            'receipt_hash' => str_repeat('a', 64),
            'receipt_key' => 'DISPATCH-RECEIPT-001',
            'packet_id' => 'AP-001',
            'provider' => 'codex',
            'provider_role' => 'implementation',
            'status' => 'active_pending_provider_start',
            'workspace_root' => '/Users/vitorepf/develop/Atlas',
            'worktree_path' => '/Users/vitorepf/develop/Atlas/worktrees/codex-a',
            'branch' => 'self-construction/codex-a',
            'executor_contract_hash' => str_repeat('b', 64),
            'executor_release_authorization_hash' => str_repeat('c', 64),
            'allowed_files_hash' => str_repeat('d', 64),
            'forbidden_scope_hash' => str_repeat('e', 64),
            'scope_validator_hash' => str_repeat('f', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'payload' => ['source' => 'one_shot_scheduler_provider_start_driver_invoker_test'],
            'activated_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function validInput(): array
    {
        return [
            'receipt_hash' => str_repeat('a', 64),
            'executor_contract_hash' => str_repeat('b', 64),
            'executor_release_authorization_hash' => str_repeat('c', 64),
            'sandbox_binding_key' => 'BINDING-001',
            'provider_start_attempt_id' => 'attempt-001',
            'packet_id' => 'AP-001',
            'provider' => 'codex',
            'adapter' => 'codex',
            'command' => 'codex --continue',
            'cwd' => '/Users/vitorepf/develop/Atlas/worktrees/codex-a',
            'actor' => 'codex-a',
            'session' => 'session-a',
            'max_runtime_minutes' => 30,
            'max_cost_usd' => 5,
            'reason' => 'one_shot_scheduler_provider_start_driver_guard',
        ];
    }

    private function dropTables(): void
    {
        Schema::dropIfExists('atlas_self_construction_agent_sandbox_bindings');
        Schema::dropIfExists('atlas_self_construction_agent_dispatch_executor_release_authorizations');
        Schema::dropIfExists('atlas_self_construction_agent_dispatch_receipts');
        Schema::dropIfExists('atlas_self_construction_agent_wakeup_items');
        Schema::dropIfExists('atlas_self_construction_agent_work_products');
        Schema::dropIfExists('atlas_self_construction_agent_cost_events');
        Schema::dropIfExists('atlas_self_construction_agent_heartbeats');
        Schema::dropIfExists('atlas_self_construction_agent_runs');
        Schema::dropIfExists('atlas_ledger_events');
    }
}
