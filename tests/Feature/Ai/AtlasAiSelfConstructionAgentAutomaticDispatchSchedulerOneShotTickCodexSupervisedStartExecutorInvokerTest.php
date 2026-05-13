<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentAutomaticDispatchSchedulerOneShotTickCodexSupervisedStartExecutorInvoker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexSupervisedStartExecutorInvokerTest extends TestCase
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

    public function test_invoker_prepares_codex_supervised_start_without_starting_codex(): void
    {
        $this->createProcessStartReleaseAuthorizedRun();

        $result = app(AgentAutomaticDispatchSchedulerOneShotTickCodexSupervisedStartExecutorInvoker::class)
            ->prepareCodexSupervisedStart($this->validInput());

        $this->assertSame('one_shot_scheduler_codex_supervised_start_prepared', $result['status']);
        $this->assertTrue($result['codex_supervised_start_executor_invoked']);
        $this->assertSame(1, $result['codex_supervised_start_executor_invocation_count']);
        $this->assertTrue($result['supervised_start_prepared']);
        $this->assertFalse($result['external_process_started']);
        $this->assertFalse($result['provider_started']);
        $this->assertFalse($result['adapter_execution_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
        $this->assertSame('activate_signed_one_shot_scheduler_tick_codex_process_spawn_enablement_contract', $result['next_required_slice']);

        $this->assertDatabaseHas('atlas_self_construction_agent_runs', [
            'run_key' => 'provider-start:attempt-001',
            'status' => 'adapter_invocation_prepared',
            'provider' => 'codex',
        ]);
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-supervised-start-001',
        ]);
    }

    public function test_invoker_is_idempotent_for_same_supervised_start_id(): void
    {
        $this->createProcessStartReleaseAuthorizedRun();
        $invoker = app(AgentAutomaticDispatchSchedulerOneShotTickCodexSupervisedStartExecutorInvoker::class);

        $first = $invoker->prepareCodexSupervisedStart($this->validInput());
        $second = $invoker->prepareCodexSupervisedStart($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertFalse($second['provider_started']);
        $this->assertDatabaseCount('atlas_self_construction_agent_runs', 1);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_invoker_rejects_invalid_ready_probe_hash_without_updating_run(): void
    {
        $this->createProcessStartReleaseAuthorizedRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_ready_probe_plan_hash');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexSupervisedStartExecutorInvoker::class)
            ->prepareCodexSupervisedStart(array_merge($this->validInput(), [
                'ready_probe_plan_hash' => 'not-a-hash',
            ]));
    }

    public function test_invoker_rejects_missing_process_start_release_metadata(): void
    {
        $this->createProcessStartReleaseAuthorizedRun([
            'metadata' => [],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_process_start_release_missing_or_mismatch');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexSupervisedStartExecutorInvoker::class)
            ->prepareCodexSupervisedStart($this->validInput());
    }

    public function test_invoker_rejects_duplicate_supervised_start_for_different_id(): void
    {
        $this->createProcessStartReleaseAuthorizedRun();
        $invoker = app(AgentAutomaticDispatchSchedulerOneShotTickCodexSupervisedStartExecutorInvoker::class);
        $invoker->prepareCodexSupervisedStart($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_supervised_start_already_prepared');

        $invoker->prepareCodexSupervisedStart(array_merge($this->validInput(), [
            'supervised_start_id' => 'codex-supervised-start-002',
        ]));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createProcessStartReleaseAuthorizedRun(array $overrides = []): void
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
            'summary' => 'Codex process start release authorized; supervised start executor remains disabled.',
            'metadata' => [
                'codex_process_start_release' => [
                    'process_start_release_id' => 'codex-start-release-001',
                    'codex_execution_id' => 'codex-execution-001',
                    'operator_release_receipt_hash' => str_repeat('a', 64),
                    'provider' => 'codex',
                    'adapter' => 'codex',
                    'status' => 'authorized_pending_supervised_start_executor',
                    'process_start_release_authorized' => true,
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
            'operator_release_receipt_hash' => str_repeat('a', 64),
            'stdout_stderr_sanitizer_hash' => str_repeat('b', 64),
            'ready_probe_plan_hash' => str_repeat('c', 64),
            'rollback_plan_hash' => str_repeat('d', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'one_shot_scheduler_prepare_supervised_codex_start_without_spawning_provider',
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
