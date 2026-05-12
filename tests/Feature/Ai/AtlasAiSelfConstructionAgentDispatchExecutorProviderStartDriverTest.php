<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentDispatchExecutorReleaseAuthorization;
use App\Models\AtlasSelfConstructionAgentDispatchReceipt;
use App\Models\AtlasSelfConstructionAgentSandboxBinding;
use App\Services\Ai\SelfConstruction\AgentDispatchExecutorProviderStartDriver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentDispatchExecutorProviderStartDriverTest extends TestCase
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

    public function test_driver_prepares_start_without_invoking_provider(): void
    {
        $this->createPrerequisites();

        $result = app(AgentDispatchExecutorProviderStartDriver::class)
            ->startProviderOnce($this->validInput());

        $this->assertSame('provider_start_prepared', $result['status']);
        $this->assertFalse($result['idempotent']);
        $this->assertTrue($result['pre_start_heartbeat_written']);
        $this->assertFalse($result['provider_started']);
        $this->assertFalse($result['adapter_invocation_allowed']);
        $this->assertFalse($result['dispatch_allowed']);

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

    public function test_driver_is_idempotent_for_same_provider_start_attempt(): void
    {
        $this->createPrerequisites();
        $driver = app(AgentDispatchExecutorProviderStartDriver::class);

        $first = $driver->startProviderOnce($this->validInput());
        $second = $driver->startProviderOnce($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_self_construction_agent_runs', 1);
        $this->assertDatabaseCount('atlas_self_construction_agent_heartbeats', 1);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_driver_rejects_receipt_that_was_not_marked_used(): void
    {
        $this->createPrerequisites([
            'receipt' => [
                'status' => 'signed_pending_dispatch',
                'used_at' => null,
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dispatch_receipt_not_used_pending_provider_start');

        app(AgentDispatchExecutorProviderStartDriver::class)
            ->startProviderOnce($this->validInput());
    }

    public function test_driver_rejects_missing_sandbox_binding(): void
    {
        $this->createPrerequisites(skipBinding: true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sandbox_binding_not_found');

        app(AgentDispatchExecutorProviderStartDriver::class)
            ->startProviderOnce($this->validInput());
    }

    public function test_driver_rejects_cwd_outside_active_binding(): void
    {
        $this->createPrerequisites();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sandbox_binding_cwd_mismatch');

        app(AgentDispatchExecutorProviderStartDriver::class)
            ->startProviderOnce(array_merge($this->validInput(), [
                'cwd' => '/Users/vitorepf/develop/Atlas/other-worktree',
            ]));
    }

    public function test_driver_rolls_back_run_when_ledger_write_fails(): void
    {
        $this->createPrerequisites();
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 8)->primary();
        });

        try {
            app(AgentDispatchExecutorProviderStartDriver::class)
                ->startProviderOnce($this->validInput());

            $this->fail('Expected ledger write failure.');
        } catch (\Throwable) {
            $this->assertDatabaseCount('atlas_self_construction_agent_runs', 0);
            $this->assertDatabaseCount('atlas_self_construction_agent_heartbeats', 0);
        }
    }

    /**
     * @param  array<string,array<string,mixed>>  $overrides
     */
    private function createPrerequisites(array $overrides = [], bool $skipBinding = false): void
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
            'payload' => ['source' => 'test'],
            'persisted_at' => CarbonImmutable::now(),
        ]);

        if ($skipBinding) {
            return;
        }

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
            'payload' => ['source' => 'test'],
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
            'reason' => 'future_provider_start_guard',
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
