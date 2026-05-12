<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentCodexProcessStartReleaseGate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentCodexProcessStartReleaseGateTest extends TestCase
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

    public function test_gate_authorizes_process_start_release_without_starting_codex(): void
    {
        $this->createCodexExecutionPreparedRun();

        $result = app(AgentCodexProcessStartReleaseGate::class)
            ->authorizeCodexProcessStart($this->validInput());

        $this->assertSame('codex_process_start_release_authorized', $result['status']);
        $this->assertFalse($result['idempotent']);
        $this->assertTrue($result['process_start_release_authorized']);
        $this->assertFalse($result['external_process_started']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['provider_started']);
        $this->assertFalse($result['dispatch_allowed']);

        $this->assertDatabaseHas('atlas_self_construction_agent_runs', [
            'run_key' => 'provider-start:attempt-001',
            'status' => 'adapter_invocation_prepared',
            'provider' => 'codex',
        ]);

        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-start-release-001',
        ]);
    }

    public function test_gate_is_idempotent_for_same_release_id(): void
    {
        $this->createCodexExecutionPreparedRun();
        $gate = app(AgentCodexProcessStartReleaseGate::class);

        $first = $gate->authorizeCodexProcessStart($this->validInput());
        $second = $gate->authorizeCodexProcessStart($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_gate_rejects_duplicate_release_for_different_id(): void
    {
        $this->createCodexExecutionPreparedRun();
        $gate = app(AgentCodexProcessStartReleaseGate::class);
        $gate->authorizeCodexProcessStart($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_process_start_release_already_authorized');

        $gate->authorizeCodexProcessStart(array_merge($this->validInput(), [
            'process_start_release_id' => 'codex-start-release-002',
        ]));
    }

    public function test_gate_rejects_missing_operator_release_receipt_hash(): void
    {
        $this->createCodexExecutionPreparedRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing_operator_release_receipt_hash');

        app(AgentCodexProcessStartReleaseGate::class)
            ->authorizeCodexProcessStart(array_merge($this->validInput(), [
                'operator_release_receipt_hash' => '',
            ]));
    }

    public function test_gate_rejects_missing_codex_execution_metadata(): void
    {
        $this->createCodexExecutionPreparedRun([
            'metadata' => [],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_provider_execution_missing_or_mismatch');

        app(AgentCodexProcessStartReleaseGate::class)
            ->authorizeCodexProcessStart($this->validInput());
    }

    public function test_gate_rolls_back_metadata_when_ledger_write_fails(): void
    {
        $this->createCodexExecutionPreparedRun();
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 8)->primary();
        });

        try {
            app(AgentCodexProcessStartReleaseGate::class)
                ->authorizeCodexProcessStart($this->validInput());

            $this->fail('Expected ledger write failure.');
        } catch (\Throwable) {
            $run = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'provider-start:attempt-001')
                ->firstOrFail();

            $this->assertNull(data_get($run->metadata, 'codex_process_start_release'));
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createCodexExecutionPreparedRun(array $overrides = []): void
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
            'summary' => 'Codex provider execution envelope prepared; Codex process remains disabled.',
            'metadata' => [
                'codex_provider_execution' => [
                    'codex_execution_id' => 'codex-execution-001',
                    'execution_guard_id' => 'execution-guard-001',
                    'adapter_invocation_id' => 'adapter-invocation-001',
                    'provider' => 'codex',
                    'adapter' => 'codex',
                    'status' => 'prepared_pending_explicit_codex_process_release',
                    'provider_specific_contract_ready' => true,
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
            'operator_release_receipt_hash' => str_repeat('a', 64),
            'codex_execution_contract_hash' => str_repeat('b', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'operator_authorized_future_supervised_codex_start',
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
