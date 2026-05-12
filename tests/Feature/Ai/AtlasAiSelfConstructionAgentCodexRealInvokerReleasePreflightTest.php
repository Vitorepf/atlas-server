<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentCodexRealInvokerReleasePreflight;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentCodexRealInvokerReleasePreflightTest extends TestCase
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

    public function test_release_preflight_records_without_starting_codex(): void
    {
        $this->createDryRunReadyRun();

        $result = app(AgentCodexRealInvokerReleasePreflight::class)
            ->recordPreflight($this->validInput());

        $this->assertSame('codex_real_invoker_release_preflight_recorded', $result['status']);
        $this->assertFalse($result['idempotent']);
        $this->assertTrue($result['real_invoker_release_preflight_passed']);
        $this->assertFalse($result['external_process_started']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['provider_started']);
        $this->assertFalse($result['dispatch_allowed']);

        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-real-invoker-preflight-001',
        ]);
    }

    public function test_release_preflight_is_idempotent_for_same_preflight_id(): void
    {
        $this->createDryRunReadyRun();
        $preflight = app(AgentCodexRealInvokerReleasePreflight::class);

        $first = $preflight->recordPreflight($this->validInput());
        $second = $preflight->recordPreflight($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_release_preflight_rejects_duplicate_preflight_for_different_id(): void
    {
        $this->createDryRunReadyRun();
        $preflight = app(AgentCodexRealInvokerReleasePreflight::class);
        $preflight->recordPreflight($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_release_preflight_already_recorded');

        $preflight->recordPreflight(array_merge($this->validInput(), [
            'real_invoker_release_preflight_id' => 'codex-real-invoker-preflight-002',
        ]));
    }

    public function test_release_preflight_rejects_missing_dry_run_metadata(): void
    {
        $this->createDryRunReadyRun(['metadata' => []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_external_process_invoker_dry_run_missing_or_mismatch');

        app(AgentCodexRealInvokerReleasePreflight::class)
            ->recordPreflight($this->validInput());
    }

    public function test_release_preflight_rejects_missing_operator_release_preflight_receipt_hash(): void
    {
        $this->createDryRunReadyRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing_operator_release_preflight_receipt_hash');

        app(AgentCodexRealInvokerReleasePreflight::class)
            ->recordPreflight(array_merge($this->validInput(), [
                'operator_release_preflight_receipt_hash' => '',
            ]));
    }

    public function test_release_preflight_rejects_dry_run_already_started_flag(): void
    {
        $this->createDryRunReadyRun([
            'metadata' => $this->metadataWithDryRun(['external_process_started' => true]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('external_process_started_already_true');

        app(AgentCodexRealInvokerReleasePreflight::class)
            ->recordPreflight($this->validInput());
    }

    public function test_release_preflight_rolls_back_metadata_when_ledger_write_fails(): void
    {
        $this->createDryRunReadyRun();
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 8)->primary();
        });

        try {
            app(AgentCodexRealInvokerReleasePreflight::class)
                ->recordPreflight($this->validInput());

            $this->fail('Expected ledger write failure.');
        } catch (\Throwable) {
            $run = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'provider-start:attempt-001')
                ->firstOrFail();

            $this->assertNull(data_get($run->metadata, 'codex_real_invoker_release_preflight'));
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createDryRunReadyRun(array $overrides = []): void
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
            'summary' => 'Codex external process invoker dry-run prepared; real invoker remains disabled.',
            'metadata' => $this->metadataWithDryRun(),
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $dryRunOverrides
     * @return array<string,mixed>
     */
    private function metadataWithDryRun(array $dryRunOverrides = []): array
    {
        return [
            'codex_external_process_invoker_dry_run' => array_merge([
                'dry_run_id' => 'codex-invoker-dry-run-001',
                'invocation_authorization_id' => 'codex-invocation-auth-001',
                'runtime_driver_id' => 'codex-runtime-driver-001',
                'spawn_executor_id' => 'codex-spawn-executor-001',
                'spawn_enablement_id' => 'codex-spawn-enable-001',
                'supervised_start_id' => 'codex-supervised-start-001',
                'process_start_release_id' => 'codex-start-release-001',
                'codex_execution_id' => 'codex-execution-001',
                'operator_dry_run_receipt_hash' => str_repeat('b', 64),
                'invoker_contract_hash' => str_repeat('c', 64),
                'process_command_hash' => str_repeat('6', 64),
                'environment_contract_hash' => str_repeat('7', 64),
                'termination_policy_hash' => str_repeat('8', 64),
                'stdout_stderr_sink_hash' => str_repeat('e', 64),
                'liveness_probe_hash' => str_repeat('f', 64),
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'dry_run_ready_pending_real_invoker_release',
                'external_process_invoker_dry_run_prepared' => true,
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
            ], $dryRunOverrides),
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
            'dry_run_id' => 'codex-invoker-dry-run-001',
            'real_invoker_release_preflight_id' => 'codex-real-invoker-preflight-001',
            'operator_release_preflight_receipt_hash' => str_repeat('1', 64),
            'real_invoker_contract_hash' => str_repeat('2', 64),
            'process_command_hash' => str_repeat('6', 64),
            'environment_contract_hash' => str_repeat('7', 64),
            'termination_policy_hash' => str_repeat('8', 64),
            'stdout_stderr_sink_hash' => str_repeat('e', 64),
            'liveness_probe_hash' => str_repeat('f', 64),
            'rollback_plan_hash' => str_repeat('3', 64),
            'max_runtime_policy_hash' => str_repeat('4', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'record_real_invoker_release_preflight_without_starting_codex',
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
