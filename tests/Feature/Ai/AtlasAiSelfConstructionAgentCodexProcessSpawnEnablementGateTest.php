<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentCodexProcessSpawnEnablementGate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentCodexProcessSpawnEnablementGateTest extends TestCase
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

    public function test_gate_records_spawn_enablement_without_starting_codex(): void
    {
        $this->createSupervisedStartPreparedRun();

        $result = app(AgentCodexProcessSpawnEnablementGate::class)
            ->enableCodexProcessSpawn($this->validInput());

        $this->assertSame('codex_process_spawn_enablement_recorded', $result['status']);
        $this->assertFalse($result['idempotent']);
        $this->assertTrue($result['process_spawn_enabled']);
        $this->assertFalse($result['external_process_started']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['provider_started']);
        $this->assertFalse($result['dispatch_allowed']);

        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-spawn-enable-001',
        ]);
    }

    public function test_gate_is_idempotent_for_same_enablement_id(): void
    {
        $this->createSupervisedStartPreparedRun();
        $gate = app(AgentCodexProcessSpawnEnablementGate::class);

        $first = $gate->enableCodexProcessSpawn($this->validInput());
        $second = $gate->enableCodexProcessSpawn($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_gate_rejects_duplicate_enablement_for_different_id(): void
    {
        $this->createSupervisedStartPreparedRun();
        $gate = app(AgentCodexProcessSpawnEnablementGate::class);
        $gate->enableCodexProcessSpawn($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_process_spawn_enablement_already_recorded');

        $gate->enableCodexProcessSpawn(array_merge($this->validInput(), [
            'spawn_enablement_id' => 'codex-spawn-enable-002',
        ]));
    }

    public function test_gate_rejects_missing_supervised_start_metadata(): void
    {
        $this->createSupervisedStartPreparedRun(['metadata' => []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_supervised_start_missing_or_mismatch');

        app(AgentCodexProcessSpawnEnablementGate::class)
            ->enableCodexProcessSpawn($this->validInput());
    }

    public function test_gate_rejects_missing_operator_spawn_receipt_hash(): void
    {
        $this->createSupervisedStartPreparedRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing_operator_spawn_receipt_hash');

        app(AgentCodexProcessSpawnEnablementGate::class)
            ->enableCodexProcessSpawn(array_merge($this->validInput(), [
                'operator_spawn_receipt_hash' => '',
            ]));
    }

    public function test_gate_rejects_supervised_start_already_started_flag(): void
    {
        $this->createSupervisedStartPreparedRun([
            'metadata' => $this->metadataWithSupervisedStart(['external_process_started' => true]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('external_process_started_already_true');

        app(AgentCodexProcessSpawnEnablementGate::class)
            ->enableCodexProcessSpawn($this->validInput());
    }

    public function test_gate_rolls_back_metadata_when_ledger_write_fails(): void
    {
        $this->createSupervisedStartPreparedRun();
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 8)->primary();
        });

        try {
            app(AgentCodexProcessSpawnEnablementGate::class)
                ->enableCodexProcessSpawn($this->validInput());

            $this->fail('Expected ledger write failure.');
        } catch (\Throwable) {
            $run = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'provider-start:attempt-001')
                ->firstOrFail();

            $this->assertNull(data_get($run->metadata, 'codex_process_spawn_enablement'));
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createSupervisedStartPreparedRun(array $overrides = []): void
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
            'summary' => 'Codex supervised start executor prepared; process spawn remains disabled.',
            'metadata' => $this->metadataWithSupervisedStart(),
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $supervisedStartOverrides
     * @return array<string,mixed>
     */
    private function metadataWithSupervisedStart(array $supervisedStartOverrides = []): array
    {
        return [
            'codex_supervised_start' => array_merge([
                'supervised_start_id' => 'codex-supervised-start-001',
                'process_start_release_id' => 'codex-start-release-001',
                'codex_execution_id' => 'codex-execution-001',
                'operator_release_receipt_hash' => str_repeat('a', 64),
                'stdout_stderr_sanitizer_hash' => str_repeat('b', 64),
                'ready_probe_plan_hash' => str_repeat('c', 64),
                'rollback_plan_hash' => str_repeat('d', 64),
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'prepared_pending_process_spawn_enablement_contract',
                'supervised_start_prepared' => true,
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
            ], $supervisedStartOverrides),
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
            'operator_spawn_receipt_hash' => str_repeat('e', 64),
            'supervised_start_contract_hash' => str_repeat('f', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'enable_future_process_spawn_without_starting_codex',
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
