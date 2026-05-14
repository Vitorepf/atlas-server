<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStartEnvelopeBuilderInvoker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStartEnvelopeBuilderInvokerTest extends TestCase
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

    public function test_invoker_builds_process_start_envelope_without_starting_codex(): void
    {
        $this->createRehearsalRun();

        $result = app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStartEnvelopeBuilderInvoker::class)
            ->buildCodexRealInvokerProcessStartEnvelope($this->validInput());

        $this->assertSame('one_shot_scheduler_codex_real_invoker_process_start_envelope_built', $result['status']);
        $this->assertTrue($result['codex_real_invoker_process_start_envelope_builder_invoked']);
        $this->assertSame(1, $result['codex_real_invoker_process_start_envelope_builder_invocation_count']);
        $this->assertTrue($result['real_invoker_process_start_envelope_built']);
        $this->assertTrue($result['process_start_rehearsed']);
        $this->assertTrue($result['start_envelope_ready']);
        $this->assertFalse($result['actual_process_start_allowed']);
        $this->assertFalse($result['external_process_started']);
        $this->assertFalse($result['provider_started']);
        $this->assertFalse($result['adapter_execution_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertSame('activate_signed_one_shot_scheduler_tick_codex_real_invoker_start_execution_gate_contract', $result['next_required_slice']);

        $observed = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'provider-start:attempt-001')
            ->firstOrFail();

        $this->assertSame(
            'real_invoker_process_start_envelope_built_pending_execution_gate',
            data_get($observed->metadata, 'codex_real_invoker_process_start_envelope.status')
        );
        $this->assertTrue((bool) data_get($observed->metadata, 'codex_real_invoker_process_start_envelope.start_envelope_ready'));
        $this->assertFalse((bool) data_get($observed->metadata, 'codex_real_invoker_process_start_envelope.actual_process_start_allowed'));
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-real-invoker-process-start-envelope-001',
        ]);
    }

    public function test_invoker_is_idempotent_for_same_envelope_id(): void
    {
        $this->createRehearsalRun();
        $invoker = app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStartEnvelopeBuilderInvoker::class);

        $first = $invoker->buildCodexRealInvokerProcessStartEnvelope($this->validInput());
        $second = $invoker->buildCodexRealInvokerProcessStartEnvelope($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_invoker_rejects_invalid_start_command_hash_without_updating_run(): void
    {
        $this->createRehearsalRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_start_command_hash');

        try {
            app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStartEnvelopeBuilderInvoker::class)
                ->buildCodexRealInvokerProcessStartEnvelope(array_merge($this->validInput(), [
                    'start_command_hash' => 'not-a-hash',
                ]));
        } finally {
            $observed = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'provider-start:attempt-001')
                ->firstOrFail();

            $this->assertNull(data_get($observed->metadata, 'codex_real_invoker_process_start_envelope'));
        }
    }

    public function test_invoker_rejects_missing_rehearsal_metadata(): void
    {
        $this->createRehearsalRun(['metadata' => []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_actual_process_start_rehearsal_missing_or_mismatch');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStartEnvelopeBuilderInvoker::class)
            ->buildCodexRealInvokerProcessStartEnvelope($this->validInput());
    }

    public function test_invoker_rejects_duplicate_envelope_id(): void
    {
        $this->createRehearsalRun();
        $invoker = app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStartEnvelopeBuilderInvoker::class);
        $invoker->buildCodexRealInvokerProcessStartEnvelope($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_process_start_envelope_already_built');

        $invoker->buildCodexRealInvokerProcessStartEnvelope(array_merge($this->validInput(), [
            'real_invoker_process_start_envelope_id' => 'codex-real-invoker-process-start-envelope-002',
        ]));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createRehearsalRun(array $overrides = []): void
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
            'summary' => 'Codex real invoker actual process start rehearsal recorded; actual process start remains disabled.',
            'metadata' => $this->metadataWithRehearsal(),
        ], $overrides));
    }

    /**
     * @return array<string,mixed>
     */
    private function metadataWithRehearsal(): array
    {
        return [
            'codex_real_invoker_actual_process_start_rehearsal' => [
                'codex_execution_id' => 'codex-execution-001',
                'real_invoker_executor_plan_id' => 'codex-real-invoker-executor-plan-001',
                'real_invoker_executor_fresh_release_id' => 'codex-real-invoker-fresh-release-001',
                'real_invoker_executor_enablement_id' => 'codex-real-invoker-executor-enable-001',
                'real_invoker_supervised_start_activation_id' => 'codex-real-invoker-supervised-start-activation-001',
                'real_invoker_guarded_process_start_id' => 'codex-real-invoker-guarded-process-start-001',
                'real_invoker_final_process_start_authorization_id' => 'codex-real-invoker-final-start-auth-001',
                'real_invoker_actual_process_start_rehearsal_id' => 'codex-real-invoker-actual-start-rehearsal-001',
                'process_start_rehearsal_hash' => str_repeat('1', 64),
                'command_resolution_hash' => str_repeat('2', 64),
                'environment_resolution_hash' => str_repeat('3', 64),
                'cwd_verification_hash' => str_repeat('4', 64),
                'supervisor_dry_run_hash' => str_repeat('5', 64),
                'liveness_probe_rehearsal_hash' => str_repeat('6', 64),
                'operator_final_start_receipt_hash' => str_repeat('a', 64),
                'final_start_signature_hash' => str_repeat('b', 64),
                'final_start_policy_hash' => str_repeat('c', 64),
                'final_start_window_hash' => str_repeat('d', 64),
                'final_start_replay_guard_hash' => str_repeat('e', 64),
                'final_start_kill_switch_hash' => str_repeat('f', 64),
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'real_invoker_actual_process_start_rehearsed_pending_start_executor',
                'real_invoker_actual_process_start_rehearsal_prepared' => true,
                'final_process_start_authorized' => true,
                'process_start_rehearsed' => true,
                'actual_process_start_allowed' => false,
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
            ],
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
            'real_invoker_executor_plan_id' => 'codex-real-invoker-executor-plan-001',
            'real_invoker_executor_fresh_release_id' => 'codex-real-invoker-fresh-release-001',
            'real_invoker_executor_enablement_id' => 'codex-real-invoker-executor-enable-001',
            'real_invoker_supervised_start_activation_id' => 'codex-real-invoker-supervised-start-activation-001',
            'real_invoker_guarded_process_start_id' => 'codex-real-invoker-guarded-process-start-001',
            'real_invoker_final_process_start_authorization_id' => 'codex-real-invoker-final-start-auth-001',
            'real_invoker_actual_process_start_rehearsal_id' => 'codex-real-invoker-actual-start-rehearsal-001',
            'real_invoker_process_start_envelope_id' => 'codex-real-invoker-process-start-envelope-001',
            'process_start_rehearsal_hash' => str_repeat('1', 64),
            'command_resolution_hash' => str_repeat('2', 64),
            'environment_resolution_hash' => str_repeat('3', 64),
            'cwd_verification_hash' => str_repeat('4', 64),
            'supervisor_dry_run_hash' => str_repeat('5', 64),
            'liveness_probe_rehearsal_hash' => str_repeat('6', 64),
            'operator_final_start_receipt_hash' => str_repeat('a', 64),
            'final_start_signature_hash' => str_repeat('b', 64),
            'final_start_policy_hash' => str_repeat('c', 64),
            'final_start_window_hash' => str_repeat('d', 64),
            'final_start_replay_guard_hash' => str_repeat('e', 64),
            'final_start_kill_switch_hash' => str_repeat('f', 64),
            'process_start_envelope_hash' => str_repeat('7', 64),
            'start_command_hash' => str_repeat('8', 64),
            'start_environment_hash' => str_repeat('9', 64),
            'start_cwd_hash' => str_repeat('a', 64),
            'start_supervisor_hash' => str_repeat('b', 64),
            'start_liveness_contract_hash' => str_repeat('c', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'build_process_start_envelope_without_starting_codex',
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
