<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentAutomaticDispatchSchedulerOneShotTickCodexProcessInvocationAuthorizationInvoker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexProcessInvocationAuthorizationInvokerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTables();

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_12_010000_create_atlas_self_construction_agent_control_plane_tables.php'))->up();
    }

    protected function tearDown(): void
    {
        $this->dropTables();

        parent::tearDown();
    }

    public function test_invoker_authorizes_process_invocation_without_starting_codex(): void
    {
        $this->createRuntimeDriverPreparedRun();

        $result = app(AgentAutomaticDispatchSchedulerOneShotTickCodexProcessInvocationAuthorizationInvoker::class)
            ->authorizeCodexProcessInvocation($this->validInput());

        $this->assertSame('one_shot_scheduler_codex_process_invocation_authorization_recorded', $result['status']);
        $this->assertTrue($result['codex_process_invocation_authorization_invoked']);
        $this->assertSame(1, $result['codex_process_invocation_authorization_invocation_count']);
        $this->assertTrue($result['external_process_invocation_authorized']);
        $this->assertFalse($result['external_process_started']);
        $this->assertFalse($result['provider_started']);
        $this->assertFalse($result['adapter_execution_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertSame(
            'activate_signed_one_shot_scheduler_tick_codex_external_process_invoker_dry_run_contract',
            $result['next_required_slice']
        );

        $observed = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'provider-start:attempt-001')
            ->firstOrFail();

        $this->assertSame(
            'authorized_pending_external_process_invoker',
            data_get($observed->metadata, 'codex_external_process_invocation_authorization.status')
        );
        $this->assertFalse(data_get($observed->metadata, 'codex_external_process_invocation_authorization.external_process_started'));
        $this->assertFalse(data_get($observed->metadata, 'codex_external_process_invocation_authorization.token_spend_allowed'));
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-invocation-auth-001',
        ]);
    }

    public function test_invoker_is_idempotent_for_same_invocation_authorization_id(): void
    {
        $this->createRuntimeDriverPreparedRun();
        $invoker = app(AgentAutomaticDispatchSchedulerOneShotTickCodexProcessInvocationAuthorizationInvoker::class);

        $first = $invoker->authorizeCodexProcessInvocation($this->validInput());
        $second = $invoker->authorizeCodexProcessInvocation($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertFalse($second['provider_started']);
        $this->assertDatabaseCount('atlas_self_construction_agent_runs', 1);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_invoker_rejects_invalid_runtime_driver_contract_hash_without_updating_run(): void
    {
        $this->createRuntimeDriverPreparedRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_runtime_driver_contract_hash');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexProcessInvocationAuthorizationInvoker::class)
            ->authorizeCodexProcessInvocation(array_merge($this->validInput(), [
                'runtime_driver_contract_hash' => 'not-a-hash',
            ]));
    }

    public function test_invoker_rejects_missing_runtime_driver_metadata(): void
    {
        $this->createRuntimeDriverPreparedRun([
            'metadata' => [],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_external_process_runtime_driver_missing_or_mismatch');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexProcessInvocationAuthorizationInvoker::class)
            ->authorizeCodexProcessInvocation($this->validInput());
    }

    public function test_invoker_rejects_duplicate_authorization_for_different_id(): void
    {
        $this->createRuntimeDriverPreparedRun();
        $invoker = app(AgentAutomaticDispatchSchedulerOneShotTickCodexProcessInvocationAuthorizationInvoker::class);
        $invoker->authorizeCodexProcessInvocation($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_external_process_invocation_authorization_already_recorded');

        $invoker->authorizeCodexProcessInvocation(array_merge($this->validInput(), [
            'invocation_authorization_id' => 'codex-invocation-auth-002',
        ]));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createRuntimeDriverPreparedRun(array $overrides = []): void
    {
        AtlasSelfConstructionAgentRun::query()->create(array_merge([
            'run_key' => 'provider-start:attempt-001',
            'packet_id' => 'AP-001',
            'reservation_id' => null,
            'actor' => 'codex-a',
            'provider' => 'codex',
            'provider_role' => 'implementation',
            'session_id' => 'session-a',
            'workspace_id' => 'atlas-self-construction-forge-workspace',
            'obra_id' => 'atlas-self-construction-os',
            'status' => 'adapter_invocation_prepared',
            'liveness' => 'alive',
            'packet_hash' => null,
            'allowed_files_hash' => str_repeat('d', 64),
            'lease_expires_at' => CarbonImmutable::now()->addMinutes(30),
            'last_heartbeat_at' => CarbonImmutable::now(),
            'started_at' => null,
            'finished_at' => null,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost_usd' => 0,
            'completion_evidence_hash' => null,
            'summary' => 'Codex external process runtime driver prepared; process invocation remains disabled.',
            'metadata' => [
                'codex_external_process_runtime_driver' => [
                    'runtime_driver_id' => 'codex-runtime-driver-001',
                    'spawn_executor_id' => 'codex-spawn-executor-001',
                    'spawn_enablement_id' => 'codex-spawn-enable-001',
                    'supervised_start_id' => 'codex-supervised-start-001',
                    'process_start_release_id' => 'codex-start-release-001',
                    'codex_execution_id' => 'codex-execution-001',
                    'operator_runtime_receipt_hash' => str_repeat('5', 64),
                    'process_command_hash' => str_repeat('6', 64),
                    'environment_contract_hash' => str_repeat('7', 64),
                    'termination_policy_hash' => str_repeat('8', 64),
                    'provider' => 'codex',
                    'adapter' => 'codex',
                    'status' => 'prepared_pending_process_invocation',
                    'external_runtime_driver_prepared' => true,
                    'external_process_started' => false,
                    'token_spend_allowed' => false,
                    'provider_started' => false,
                    'dispatch_allowed' => false,
                ],
            ],
        ], $overrides));
    }

    /**
     * @return array<string,mixed>
     */
    private function validInput(): array
    {
        return [
            'run_key' => 'provider-start:attempt-001',
            'codex_execution_id' => 'codex-execution-001',
            'process_start_release_id' => 'codex-start-release-001',
            'supervised_start_id' => 'codex-supervised-start-001',
            'spawn_enablement_id' => 'codex-spawn-enable-001',
            'spawn_executor_id' => 'codex-spawn-executor-001',
            'runtime_driver_id' => 'codex-runtime-driver-001',
            'invocation_authorization_id' => 'codex-invocation-auth-001',
            'operator_invocation_receipt_hash' => str_repeat('9', 64),
            'runtime_driver_contract_hash' => str_repeat('a', 64),
            'process_command_hash' => str_repeat('6', 64),
            'environment_contract_hash' => str_repeat('7', 64),
            'termination_policy_hash' => str_repeat('8', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'one_shot_scheduler_authorize_process_invocation_without_invoking_codex',
        ];
    }

    private function dropTables(): void
    {
        Schema::dropIfExists('atlas_self_construction_agent_wakeup_items');
        Schema::dropIfExists('atlas_self_construction_agent_work_products');
        Schema::dropIfExists('atlas_self_construction_agent_cost_events');
        Schema::dropIfExists('atlas_self_construction_agent_heartbeats');
        Schema::dropIfExists('atlas_self_construction_agent_runs');
        Schema::dropIfExists('atlas_ledger_events');
    }
}
