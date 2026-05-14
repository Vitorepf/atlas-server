<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentDispatchExecutorReleaseAuthorization;
use App\Models\AtlasSelfConstructionAgentDispatchReceipt;
use App\Models\AtlasSelfConstructionAgentRun;
use App\Models\AtlasSelfConstructionAgentSandboxBinding;
use App\Services\Ai\SelfConstruction\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateInvoker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateInvokerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTables();

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_12_010000_create_atlas_self_construction_agent_control_plane_tables.php'))->up();
        (require database_path('migrations/2026_05_12_020000_create_atlas_self_construction_agent_dispatch_executor_release_authorizations_table.php'))->up();
        (require database_path('migrations/2026_05_12_030000_create_atlas_self_construction_agent_sandbox_bindings_table.php'))->up();
    }

    protected function tearDown(): void
    {
        $this->dropTables();

        parent::tearDown();
    }

    public function test_scheduler_post_start_provider_start_driver_gate_prepares_bridge_without_starting_codex(): void
    {
        $this->createPrerequisites();

        $result = app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateInvoker::class)
            ->prepareCodexRealInvokerPostStartProviderStartDriverGate($this->validInput());

        $this->assertSame('one_shot_scheduler_codex_real_invoker_post_start_provider_start_driver_gate_prepared', $result['status']);
        $this->assertTrue($result['codex_real_invoker_post_start_provider_start_driver_gate_invoked']);
        $this->assertSame(1, $result['codex_real_invoker_post_start_provider_start_driver_gate_invocation_count']);
        $this->assertSame('codex_real_invoker_post_start_provider_start_driver_prepared', data_get($result, 'codex_real_invoker_post_start_provider_start_driver_gate_result.status'));
        $this->assertSame('provider_start_prepared', data_get($result, 'provider_start_result.status'));
        $this->assertTrue($result['dispatch_receipt_used']);
        $this->assertTrue($result['observed_external_process_started']);
        $this->assertTrue($result['observed_provider_started']);
        $this->assertFalse($result['provider_start_driver_external_process_started']);
        $this->assertFalse($result['driver_provider_started']);
        $this->assertFalse($result['actual_process_start_allowed']);
        $this->assertFalse($result['provider_process_call_allowed']);
        $this->assertFalse($result['adapter_invocation_allowed']);
        $this->assertFalse($result['adapter_execution_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertSame(
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract',
            $result['next_required_slice']
        );

        $observedRun = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'codex-post-start-observed-run-001')
            ->firstOrFail();

        $this->assertSame(
            'post_start_provider_start_driver_prepared_pending_adapter_invocation_boundary',
            data_get($observedRun->metadata, 'codex_real_invoker_post_start_provider_start_driver.status')
        );

        $this->assertDatabaseHas('atlas_self_construction_agent_runs', [
            'run_key' => 'provider-start:codex-post-start-attempt-001',
            'status' => 'pre_start_guarded',
            'provider' => 'codex',
        ]);
        $this->assertDatabaseHas('atlas_self_construction_agent_heartbeats', [
            'heartbeat_key' => 'provider-start:codex-post-start-attempt-001:heartbeat:pre-start',
            'signal' => 'pre_start_guard',
        ]);
    }

    public function test_scheduler_post_start_provider_start_driver_gate_is_idempotent_for_same_attempt(): void
    {
        $this->createPrerequisites();
        $invoker = app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateInvoker::class);

        $first = $invoker->prepareCodexRealInvokerPostStartProviderStartDriverGate($this->validInput());
        $second = $invoker->prepareCodexRealInvokerPostStartProviderStartDriverGate($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_self_construction_agent_heartbeats', 1);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_scheduler_post_start_provider_start_driver_gate_rejects_missing_receipt_use_metadata(): void
    {
        $this->createPrerequisites([
            'run' => ['metadata' => []],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_dispatch_receipt_use_missing_or_mismatch');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateInvoker::class)
            ->prepareCodexRealInvokerPostStartProviderStartDriverGate($this->validInput());
    }

    public function test_scheduler_post_start_provider_start_driver_gate_rejects_dispatch_allowed_receipt_use(): void
    {
        $this->createPrerequisites([
            'run' => [
                'metadata' => $this->metadataWithReceiptUse(['dispatch_allowed' => true]),
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dispatch_allowed_already_true');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateInvoker::class)
            ->prepareCodexRealInvokerPostStartProviderStartDriverGate($this->validInput());
    }

    public function test_scheduler_post_start_provider_start_driver_gate_rejects_missing_sandbox_binding(): void
    {
        $this->createPrerequisites(skipBinding: true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sandbox_binding_not_found');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateInvoker::class)
            ->prepareCodexRealInvokerPostStartProviderStartDriverGate($this->validInput());
    }

    public function test_scheduler_post_start_provider_start_driver_gate_rejects_invalid_executor_contract_hash(): void
    {
        $this->createPrerequisites();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_executor_contract_hash');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateInvoker::class)
            ->prepareCodexRealInvokerPostStartProviderStartDriverGate(array_merge($this->validInput(), [
                'executor_contract_hash' => 'not-a-hash',
            ]));
    }

    /**
     * @param  array<string,array<string,mixed>>  $overrides
     */
    private function createPrerequisites(array $overrides = [], bool $skipBinding = false): void
    {
        AtlasSelfConstructionAgentRun::query()->create(array_merge([
            'run_key' => 'codex-post-start-observed-run-001',
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
            'summary' => 'Codex real invoker post-start dispatch receipt marked used; provider start remains disabled pending provider start driver.',
            'metadata' => $this->metadataWithReceiptUse(),
        ], $overrides['run'] ?? []));

        AtlasSelfConstructionAgentDispatchReceipt::query()->create(array_merge([
            'receipt_key' => 'DISPATCH-RECEIPT-001',
            'packet_id' => 'AP-001',
            'provider' => 'codex',
            'provider_role' => 'implementation',
            'decision' => 'approve_dispatch_once',
            'status' => 'used_pending_provider_start',
            'signed_by' => 'vitor',
            'signed_at' => CarbonImmutable::now(),
            'expires_at' => CarbonImmutable::now()->addHour(),
            'used_at' => CarbonImmutable::now(),
            'dispatch_envelope_hash' => str_repeat('1', 64),
            'adapter_contract_hash' => str_repeat('2', 64),
            'receipt_hash' => str_repeat('a', 64),
            'payload' => [
                'receipt_use' => [
                    'provider_start_attempt_id' => 'codex-post-start-attempt-001',
                    'executor_contract_hash' => str_repeat('b', 64),
                    'executor_release_authorization_hash' => str_repeat('c', 64),
                ],
            ],
        ], $overrides['receipt'] ?? []));

        AtlasSelfConstructionAgentDispatchExecutorReleaseAuthorization::query()->create([
            'authorization_key' => 'AUTH-001',
            'receipt_key' => 'RELEASE-AUTH-RECEIPT-001',
            'authorization_id' => 'DISPATCH-EXECUTOR-RELEASE-AUTH-001',
            'packet_id' => 'AP-001',
            'provider' => 'codex',
            'provider_role' => 'implementation',
            'decision' => 'approve_release_once',
            'status' => 'persisted_pending_executor_release',
            'signed_by' => 'vitor',
            'signed_at' => CarbonImmutable::now(),
            'expires_at' => CarbonImmutable::now()->addHour(),
            'signed_receipt_template_hash' => str_repeat('1', 64),
            'signed_receipt_preflight_hash' => str_repeat('2', 64),
            'persistence_template_hash' => str_repeat('3', 64),
            'persistence_preflight_hash' => str_repeat('4', 64),
            'external_signature_validation_report_hash' => str_repeat('5', 64),
            'signed_receipt_hash' => str_repeat('c', 64),
            'payload' => ['source' => 'codex_real_invoker_post_start_provider_start_driver_gate_invoker_test'],
            'persisted_at' => CarbonImmutable::now(),
        ]);

        if ($skipBinding) {
            return;
        }

        AtlasSelfConstructionAgentSandboxBinding::query()->create([
            'binding_key' => 'BINDING-001',
            'receipt_hash' => str_repeat('a', 64),
            'receipt_key' => 'DISPATCH-RECEIPT-001',
            'packet_id' => 'AP-001',
            'provider' => 'codex',
            'provider_role' => 'implementation',
            'status' => 'active_pending_provider_start',
            'workspace_root' => '/Users/vitorepf/develop/Atlas',
            'worktree_path' => '/Users/vitorepf/develop/Atlas/worktrees/codex-a',
            'branch' => 'self-construction/codex-a',
            'executor_contract_hash' => str_repeat('b', 64),
            'executor_release_authorization_hash' => str_repeat('c', 64),
            'allowed_files_hash' => str_repeat('d', 64),
            'forbidden_scope_hash' => str_repeat('e', 64),
            'scope_validator_hash' => str_repeat('f', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'payload' => ['source' => 'codex_real_invoker_post_start_provider_start_driver_gate_invoker_test'],
            'activated_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $receiptUseOverrides
     * @return array<string,mixed>
     */
    private function metadataWithReceiptUse(array $receiptUseOverrides = []): array
    {
        return [
            'codex_real_invoker_post_start_dispatch_receipt_use' => array_merge([
                'provider_start_attempt_id' => 'codex-post-start-attempt-001',
                'dispatch_executor_handoff_id' => 'codex-real-invoker-post-start-dispatch-executor-handoff-001',
                'signed_dispatch_authorization_id' => 'codex-real-invoker-post-start-signed-dispatch-auth-001',
                'post_start_evidence_acceptance_bridge_id' => 'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
                'signed_dispatch_receipt_hash' => str_repeat('a', 64),
                'executor_contract_hash' => str_repeat('b', 64),
                'executor_release_authorization_hash' => str_repeat('c', 64),
                'executor_handoff_packet_hash' => str_repeat('6', 64),
                'executor_workspace_hash' => str_repeat('7', 64),
                'executor_scope_lock_hash' => str_repeat('8', 64),
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'post_start_dispatch_receipt_used_pending_provider_start_driver',
                'dispatch_receipt_used' => true,
                'dispatch_executor_handoff_prepared' => true,
                'future_dispatch_authorized' => true,
                'actual_process_start_allowed' => false,
                'external_process_started' => true,
                'token_spend_allowed' => false,
                'provider_started' => true,
                'provider_process_call_allowed' => false,
                'provider_start_allowed_after_mark' => false,
                'dispatch_allowed' => false,
            ], $receiptUseOverrides),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validInput(): array
    {
        return [
            'run_key' => 'codex-post-start-observed-run-001',
            'provider_start_driver_gate_id' => 'codex-real-invoker-post-start-provider-start-driver-gate-001',
            'provider_start_attempt_id' => 'codex-post-start-attempt-001',
            'dispatch_executor_handoff_id' => 'codex-real-invoker-post-start-dispatch-executor-handoff-001',
            'signed_dispatch_authorization_id' => 'codex-real-invoker-post-start-signed-dispatch-auth-001',
            'post_start_evidence_acceptance_bridge_id' => 'codex-real-invoker-post-start-evidence-acceptance-bridge-001',
            'signed_dispatch_receipt_hash' => str_repeat('a', 64),
            'executor_contract_hash' => str_repeat('b', 64),
            'executor_release_authorization_hash' => str_repeat('c', 64),
            'executor_handoff_packet_hash' => str_repeat('6', 64),
            'executor_workspace_hash' => str_repeat('7', 64),
            'executor_scope_lock_hash' => str_repeat('8', 64),
            'sandbox_binding_key' => 'BINDING-001',
            'command' => 'codex --continue',
            'cwd' => '/Users/vitorepf/develop/Atlas/worktrees/codex-a',
            'actor' => 'codex-a',
            'session' => 'session-a',
            'max_runtime_minutes' => 30,
            'max_cost_usd' => 5,
            'reason' => 'prepare_post_start_provider_start_driver_without_calling_codex',
        ];
    }

    private function dropTables(): void
    {
        Schema::dropIfExists('atlas_self_construction_agent_sandbox_bindings');
        Schema::dropIfExists('atlas_self_construction_agent_dispatch_executor_release_authorizations');
        Schema::dropIfExists('atlas_self_construction_agent_dispatch_receipts');
        Schema::dropIfExists('atlas_self_construction_agent_wakeup_items');
        Schema::dropIfExists('atlas_self_construction_agent_work_products');
        Schema::dropIfExists('atlas_self_construction_agent_cost_events');
        Schema::dropIfExists('atlas_self_construction_agent_heartbeats');
        Schema::dropIfExists('atlas_self_construction_agent_runs');
        Schema::dropIfExists('atlas_ledger_events');
    }
}
