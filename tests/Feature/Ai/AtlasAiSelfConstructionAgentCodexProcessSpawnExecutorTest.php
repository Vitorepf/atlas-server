<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentCodexProcessSpawnExecutor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentCodexProcessSpawnExecutorTest extends TestCase
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

    public function test_executor_prepares_process_spawn_without_starting_codex(): void
    {
        $this->createSpawnEnabledRun();

        $result = app(AgentCodexProcessSpawnExecutor::class)
            ->prepareProcessSpawn($this->validInput());

        $this->assertSame('codex_process_spawn_executor_prepared', $result['status']);
        $this->assertFalse($result['idempotent']);
        $this->assertTrue($result['process_spawn_executor_prepared']);
        $this->assertFalse($result['external_process_started']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['provider_started']);
        $this->assertFalse($result['dispatch_allowed']);

        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-spawn-executor-001',
        ]);
    }

    public function test_executor_is_idempotent_for_same_executor_id(): void
    {
        $this->createSpawnEnabledRun();
        $executor = app(AgentCodexProcessSpawnExecutor::class);

        $first = $executor->prepareProcessSpawn($this->validInput());
        $second = $executor->prepareProcessSpawn($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_executor_rejects_duplicate_executor_for_different_id(): void
    {
        $this->createSpawnEnabledRun();
        $executor = app(AgentCodexProcessSpawnExecutor::class);
        $executor->prepareProcessSpawn($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_process_spawn_executor_already_prepared');

        $executor->prepareProcessSpawn(array_merge($this->validInput(), [
            'spawn_executor_id' => 'codex-spawn-executor-002',
        ]));
    }

    public function test_executor_rejects_missing_spawn_enablement_metadata(): void
    {
        $this->createSpawnEnabledRun(['metadata' => []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_process_spawn_enablement_missing_or_mismatch');

        app(AgentCodexProcessSpawnExecutor::class)
            ->prepareProcessSpawn($this->validInput());
    }

    public function test_executor_rejects_missing_final_spawn_receipt_hash(): void
    {
        $this->createSpawnEnabledRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing_operator_final_spawn_receipt_hash');

        app(AgentCodexProcessSpawnExecutor::class)
            ->prepareProcessSpawn(array_merge($this->validInput(), [
                'operator_final_spawn_receipt_hash' => '',
            ]));
    }

    public function test_executor_rejects_enablement_already_started_flag(): void
    {
        $this->createSpawnEnabledRun([
            'metadata' => $this->metadataWithSpawnEnablement(['provider_started' => true]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('provider_started_already_true');

        app(AgentCodexProcessSpawnExecutor::class)
            ->prepareProcessSpawn($this->validInput());
    }

    public function test_executor_rolls_back_metadata_when_ledger_write_fails(): void
    {
        $this->createSpawnEnabledRun();
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 8)->primary();
        });

        try {
            app(AgentCodexProcessSpawnExecutor::class)
                ->prepareProcessSpawn($this->validInput());

            $this->fail('Expected ledger write failure.');
        } catch (\Throwable) {
            $run = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'provider-start:attempt-001')
                ->firstOrFail();

            $this->assertNull(data_get($run->metadata, 'codex_process_spawn_executor'));
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createSpawnEnabledRun(array $overrides = []): void
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
            'summary' => 'Codex process spawn enablement recorded; final process spawn executor remains disabled.',
            'metadata' => $this->metadataWithSpawnEnablement(),
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $enablementOverrides
     * @return array<string,mixed>
     */
    private function metadataWithSpawnEnablement(array $enablementOverrides = []): array
    {
        return [
            'codex_process_spawn_enablement' => array_merge([
                'spawn_enablement_id' => 'codex-spawn-enable-001',
                'supervised_start_id' => 'codex-supervised-start-001',
                'process_start_release_id' => 'codex-start-release-001',
                'codex_execution_id' => 'codex-execution-001',
                'operator_spawn_receipt_hash' => str_repeat('e', 64),
                'supervised_start_contract_hash' => str_repeat('f', 64),
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'enabled_pending_final_process_spawn_executor',
                'process_spawn_enabled' => true,
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
            ], $enablementOverrides),
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
            'operator_final_spawn_receipt_hash' => str_repeat('1', 64),
            'runtime_supervision_plan_hash' => str_repeat('2', 64),
            'stdout_stderr_sink_hash' => str_repeat('3', 64),
            'liveness_probe_hash' => str_repeat('4', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'prepare_process_spawn_without_invoking_codex',
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
