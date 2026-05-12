<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentCodexExternalProcessInvocationAuthorizationGate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentCodexExternalProcessInvocationAuthorizationGateTest extends TestCase
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

    public function test_gate_authorizes_external_process_invocation_without_starting_codex(): void
    {
        $this->createRuntimeDriverPreparedRun();

        $result = app(AgentCodexExternalProcessInvocationAuthorizationGate::class)
            ->authorizeExternalProcessInvocation($this->validInput());

        $this->assertSame('codex_external_process_invocation_authorization_recorded', $result['status']);
        $this->assertFalse($result['idempotent']);
        $this->assertTrue($result['external_process_invocation_authorized']);
        $this->assertFalse($result['external_process_started']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['provider_started']);
        $this->assertFalse($result['dispatch_allowed']);

        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-invocation-auth-001',
        ]);
    }

    public function test_gate_is_idempotent_for_same_invocation_authorization_id(): void
    {
        $this->createRuntimeDriverPreparedRun();
        $gate = app(AgentCodexExternalProcessInvocationAuthorizationGate::class);

        $first = $gate->authorizeExternalProcessInvocation($this->validInput());
        $second = $gate->authorizeExternalProcessInvocation($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_gate_rejects_duplicate_authorization_for_different_id(): void
    {
        $this->createRuntimeDriverPreparedRun();
        $gate = app(AgentCodexExternalProcessInvocationAuthorizationGate::class);
        $gate->authorizeExternalProcessInvocation($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_external_process_invocation_authorization_already_recorded');

        $gate->authorizeExternalProcessInvocation(array_merge($this->validInput(), [
            'invocation_authorization_id' => 'codex-invocation-auth-002',
        ]));
    }

    public function test_gate_rejects_missing_runtime_driver_metadata(): void
    {
        $this->createRuntimeDriverPreparedRun(['metadata' => []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_external_process_runtime_driver_missing_or_mismatch');

        app(AgentCodexExternalProcessInvocationAuthorizationGate::class)
            ->authorizeExternalProcessInvocation($this->validInput());
    }

    public function test_gate_rejects_missing_operator_invocation_receipt_hash(): void
    {
        $this->createRuntimeDriverPreparedRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing_operator_invocation_receipt_hash');

        app(AgentCodexExternalProcessInvocationAuthorizationGate::class)
            ->authorizeExternalProcessInvocation(array_merge($this->validInput(), [
                'operator_invocation_receipt_hash' => '',
            ]));
    }

    public function test_gate_rejects_runtime_driver_already_started_flag(): void
    {
        $this->createRuntimeDriverPreparedRun([
            'metadata' => $this->metadataWithRuntimeDriver(['external_process_started' => true]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('external_process_started_already_true');

        app(AgentCodexExternalProcessInvocationAuthorizationGate::class)
            ->authorizeExternalProcessInvocation($this->validInput());
    }

    public function test_gate_rolls_back_metadata_when_ledger_write_fails(): void
    {
        $this->createRuntimeDriverPreparedRun();
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 8)->primary();
        });

        try {
            app(AgentCodexExternalProcessInvocationAuthorizationGate::class)
                ->authorizeExternalProcessInvocation($this->validInput());

            $this->fail('Expected ledger write failure.');
        } catch (\Throwable) {
            $run = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'provider-start:attempt-001')
                ->firstOrFail();

            $this->assertNull(data_get($run->metadata, 'codex_external_process_invocation_authorization'));
        }
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
            'metadata' => $this->metadataWithRuntimeDriver(),
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $driverOverrides
     * @return array<string,mixed>
     */
    private function metadataWithRuntimeDriver(array $driverOverrides = []): array
    {
        return [
            'codex_external_process_runtime_driver' => array_merge([
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
            ], $driverOverrides),
        ];
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
            'reason' => 'authorize_external_invocation_without_starting_codex',
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
