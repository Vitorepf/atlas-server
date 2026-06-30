<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentCodexExternalProcessRuntimeDriver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentCodexExternalProcessRuntimeDriverTest extends TestCase
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

    public function test_driver_prepares_external_runtime_without_starting_codex(): void
    {
        $this->createSpawnExecutorPreparedRun();

        $result = app(AgentCodexExternalProcessRuntimeDriver::class)
            ->prepareExternalRuntime($this->validInput());

        $this->assertSame('codex_external_process_runtime_driver_prepared', $result['status']);
        $this->assertFalse($result['idempotent']);
        $this->assertTrue($result['external_runtime_driver_prepared']);
        $this->assertFalse($result['external_process_started']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['provider_started']);
        $this->assertFalse($result['dispatch_allowed']);

        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-runtime-driver-001',
        ]);
    }

    public function test_driver_is_idempotent_for_same_runtime_driver_id(): void
    {
        $this->createSpawnExecutorPreparedRun();
        $driver = app(AgentCodexExternalProcessRuntimeDriver::class);

        $first = $driver->prepareExternalRuntime($this->validInput());
        $second = $driver->prepareExternalRuntime($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_driver_rejects_duplicate_runtime_for_different_id(): void
    {
        $this->createSpawnExecutorPreparedRun();
        $driver = app(AgentCodexExternalProcessRuntimeDriver::class);
        $driver->prepareExternalRuntime($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_external_process_runtime_driver_already_prepared');

        $driver->prepareExternalRuntime(array_merge($this->validInput(), [
            'runtime_driver_id' => 'codex-runtime-driver-002',
        ]));
    }

    public function test_driver_rejects_missing_spawn_executor_metadata(): void
    {
        $this->createSpawnExecutorPreparedRun(['metadata' => []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_process_spawn_executor_missing_or_mismatch');

        app(AgentCodexExternalProcessRuntimeDriver::class)
            ->prepareExternalRuntime($this->validInput());
    }

    public function test_driver_rejects_missing_runtime_receipt_hash(): void
    {
        $this->createSpawnExecutorPreparedRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing_operator_runtime_receipt_hash');

        app(AgentCodexExternalProcessRuntimeDriver::class)
            ->prepareExternalRuntime(array_merge($this->validInput(), [
                'operator_runtime_receipt_hash' => '',
            ]));
    }

    public function test_driver_rejects_spawn_executor_already_started_flag(): void
    {
        $this->createSpawnExecutorPreparedRun([
            'metadata' => $this->metadataWithSpawnExecutor(['external_process_started' => true]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('external_process_started_already_true');

        app(AgentCodexExternalProcessRuntimeDriver::class)
            ->prepareExternalRuntime($this->validInput());
    }

    public function test_driver_rolls_back_metadata_when_ledger_write_fails(): void
    {
        $this->createSpawnExecutorPreparedRun();
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 8)->primary();
        });

        try {
            app(AgentCodexExternalProcessRuntimeDriver::class)
                ->prepareExternalRuntime($this->validInput());

            $this->fail('Expected ledger write failure.');
        } catch (\Throwable) {
            $run = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'provider-start:attempt-001')
                ->firstOrFail();

            $this->assertNull(data_get($run->metadata, 'codex_external_process_runtime_driver'));
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createSpawnExecutorPreparedRun(array $overrides = []): void
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
            'summary' => 'Codex process spawn executor prepared; external process runtime remains disabled.',
            'metadata' => $this->metadataWithSpawnExecutor(),
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $executorOverrides
     * @return array<string,mixed>
     */
    private function metadataWithSpawnExecutor(array $executorOverrides = []): array
    {
        return [
            'codex_process_spawn_executor' => array_merge([
                'spawn_executor_id' => 'codex-spawn-executor-001',
                'spawn_enablement_id' => 'codex-spawn-enable-001',
                'supervised_start_id' => 'codex-supervised-start-001',
                'process_start_release_id' => 'codex-start-release-001',
                'codex_execution_id' => 'codex-execution-001',
                'operator_final_spawn_receipt_hash' => str_repeat('1', 64),
                'runtime_supervision_plan_hash' => str_repeat('2', 64),
                'stdout_stderr_sink_hash' => str_repeat('3', 64),
                'liveness_probe_hash' => str_repeat('4', 64),
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'prepared_pending_external_process_runtime',
                'process_spawn_executor_prepared' => true,
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
            ], $executorOverrides),
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
            'operator_runtime_receipt_hash' => str_repeat('5', 64),
            'process_command_hash' => str_repeat('6', 64),
            'environment_contract_hash' => str_repeat('7', 64),
            'termination_policy_hash' => str_repeat('8', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'prepare_external_runtime_without_invoking_codex',
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

    // ── AC1/AC2/AC3: classifyExecutionOutcome — pure outcome classifier ────────

    public function test_default_input_with_no_heartbeat_or_output_is_stale_heartbeat(): void
    {
        $result = app(AgentCodexExternalProcessRuntimeDriver::class)->classifyExecutionOutcome([]);

        $this->assertSame(
            AgentCodexExternalProcessRuntimeDriver::OUTCOME_STALE_HEARTBEAT,
            $result['outcome_payload']['outcome_class'],
        );
    }

    public function test_launch_failure_takes_priority_over_everything(): void
    {
        $result = app(AgentCodexExternalProcessRuntimeDriver::class)->classifyExecutionOutcome([
            'launch_succeeded' => false,
            'heartbeat_age_seconds' => 1.0,
            'output_received' => true,
            'proof_present' => true,
        ]);

        $this->assertSame(
            AgentCodexExternalProcessRuntimeDriver::OUTCOME_LAUNCH_FAILURE,
            $result['outcome_payload']['outcome_class'],
        );
        $this->assertSame(AgentCodexExternalProcessRuntimeDriver::OUTCOME_LAUNCH_FAILURE, $result['outcome_payload']['failure_class']);
    }

    public function test_stale_heartbeat_age_exceeding_threshold_blocks(): void
    {
        $result = app(AgentCodexExternalProcessRuntimeDriver::class)->classifyExecutionOutcome([
            'heartbeat_age_seconds' => 999.0,
            'heartbeat_stale_threshold_seconds' => 120,
        ]);

        $this->assertSame(
            AgentCodexExternalProcessRuntimeDriver::OUTCOME_STALE_HEARTBEAT,
            $result['outcome_payload']['outcome_class'],
        );
    }

    public function test_fresh_heartbeat_but_no_output_is_no_output(): void
    {
        $result = app(AgentCodexExternalProcessRuntimeDriver::class)->classifyExecutionOutcome([
            'heartbeat_age_seconds' => 5.0,
            'output_received' => false,
        ]);

        $this->assertSame(
            AgentCodexExternalProcessRuntimeDriver::OUTCOME_NO_OUTPUT,
            $result['outcome_payload']['outcome_class'],
        );
    }

    public function test_output_without_proof_is_completed_without_proof(): void
    {
        $result = app(AgentCodexExternalProcessRuntimeDriver::class)->classifyExecutionOutcome([
            'heartbeat_age_seconds' => 5.0,
            'output_received' => true,
            'proof_present' => false,
        ]);

        $this->assertSame(
            AgentCodexExternalProcessRuntimeDriver::OUTCOME_COMPLETED_WITHOUT_PROOF,
            $result['outcome_payload']['outcome_class'],
        );
        $this->assertSame(AgentCodexExternalProcessRuntimeDriver::OUTCOME_COMPLETED_WITHOUT_PROOF, $result['outcome_payload']['failure_class']);
    }

    public function test_output_with_proof_is_completed_with_proof_and_no_failure_class(): void
    {
        $result = app(AgentCodexExternalProcessRuntimeDriver::class)->classifyExecutionOutcome([
            'heartbeat_age_seconds' => 5.0,
            'output_received' => true,
            'proof_present' => true,
        ]);

        $this->assertSame(
            AgentCodexExternalProcessRuntimeDriver::OUTCOME_COMPLETED_WITH_PROOF,
            $result['outcome_payload']['outcome_class'],
        );
        $this->assertNull($result['outcome_payload']['failure_class']);
    }

    public function test_outcome_payload_includes_start_receipt_heartbeat_marker_and_output_receipt(): void
    {
        $result = app(AgentCodexExternalProcessRuntimeDriver::class)->classifyExecutionOutcome([
            'heartbeat_age_seconds' => 5.0,
            'output_received' => true,
            'proof_present' => true,
        ]);

        $payload = $result['outcome_payload'];
        $this->assertArrayHasKey('start_receipt', $payload);
        $this->assertArrayHasKey('heartbeat_marker', $payload);
        $this->assertArrayHasKey('output_receipt', $payload);
        $this->assertTrue($payload['suitable_for_learning']);
    }

    public function test_classify_execution_outcome_is_deterministic(): void
    {
        $driver = app(AgentCodexExternalProcessRuntimeDriver::class);
        $input = ['heartbeat_age_seconds' => 5.0, 'output_received' => true, 'proof_present' => true];

        $this->assertSame($driver->classifyExecutionOutcome($input), $driver->classifyExecutionOutcome($input));
    }
}
