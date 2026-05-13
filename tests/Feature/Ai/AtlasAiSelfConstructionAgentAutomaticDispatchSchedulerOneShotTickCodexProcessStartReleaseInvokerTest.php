<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentAutomaticDispatchSchedulerOneShotTickCodexProcessStartReleaseInvoker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexProcessStartReleaseInvokerTest extends TestCase
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

    public function test_invoker_authorizes_codex_process_start_release_without_starting_codex(): void
    {
        $this->createCodexExecutionPreparedRun();

        $result = app(AgentAutomaticDispatchSchedulerOneShotTickCodexProcessStartReleaseInvoker::class)
            ->authorizeCodexProcessStartRelease($this->validInput());

        $this->assertSame('one_shot_scheduler_codex_process_start_release_authorized', $result['status']);
        $this->assertTrue($result['codex_process_start_release_gate_invoked']);
        $this->assertSame(1, $result['codex_process_start_release_gate_invocation_count']);
        $this->assertTrue($result['process_start_release_authorized']);
        $this->assertFalse($result['external_process_started']);
        $this->assertFalse($result['provider_started']);
        $this->assertFalse($result['adapter_execution_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
        $this->assertSame('activate_signed_one_shot_scheduler_tick_codex_supervised_start_executor_release_contract', $result['next_required_slice']);

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

    public function test_invoker_is_idempotent_for_same_release_id(): void
    {
        $this->createCodexExecutionPreparedRun();
        $invoker = app(AgentAutomaticDispatchSchedulerOneShotTickCodexProcessStartReleaseInvoker::class);

        $first = $invoker->authorizeCodexProcessStartRelease($this->validInput());
        $second = $invoker->authorizeCodexProcessStartRelease($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertFalse($second['provider_started']);
        $this->assertDatabaseCount('atlas_self_construction_agent_runs', 1);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_invoker_rejects_invalid_operator_release_receipt_hash_without_updating_run(): void
    {
        $this->createCodexExecutionPreparedRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_operator_release_receipt_hash');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexProcessStartReleaseInvoker::class)
            ->authorizeCodexProcessStartRelease(array_merge($this->validInput(), [
                'operator_release_receipt_hash' => 'not-a-hash',
            ]));
    }

    public function test_invoker_rejects_missing_codex_provider_execution_metadata(): void
    {
        $this->createCodexExecutionPreparedRun([
            'metadata' => [],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_provider_execution_missing_or_mismatch');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexProcessStartReleaseInvoker::class)
            ->authorizeCodexProcessStartRelease($this->validInput());
    }

    public function test_invoker_rejects_duplicate_release_for_different_id(): void
    {
        $this->createCodexExecutionPreparedRun();
        $invoker = app(AgentAutomaticDispatchSchedulerOneShotTickCodexProcessStartReleaseInvoker::class);
        $invoker->authorizeCodexProcessStartRelease($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_process_start_release_already_authorized');

        $invoker->authorizeCodexProcessStartRelease(array_merge($this->validInput(), [
            'process_start_release_id' => 'codex-start-release-002',
        ]));
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
            'reason' => 'one_shot_scheduler_authorize_future_supervised_codex_start_without_starting_provider',
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
