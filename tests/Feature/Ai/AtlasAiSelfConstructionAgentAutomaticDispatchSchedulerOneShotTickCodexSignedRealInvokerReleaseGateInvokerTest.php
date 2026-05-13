<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentAutomaticDispatchSchedulerOneShotTickCodexSignedRealInvokerReleaseGateInvoker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexSignedRealInvokerReleaseGateInvokerTest extends TestCase
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

    public function test_invoker_authorizes_signed_real_invoker_release_without_starting_codex(): void
    {
        $this->createPreflightPassedRun();

        $result = app(AgentAutomaticDispatchSchedulerOneShotTickCodexSignedRealInvokerReleaseGateInvoker::class)
            ->authorizeCodexSignedRealInvokerRelease($this->validInput());

        $this->assertSame('one_shot_scheduler_codex_signed_real_invoker_release_authorized', $result['status']);
        $this->assertTrue($result['codex_signed_real_invoker_release_gate_invoked']);
        $this->assertSame(1, $result['codex_signed_real_invoker_release_gate_invocation_count']);
        $this->assertSame('codex-signed-real-invoker-release-001', $result['signed_real_invoker_release_id']);
        $this->assertTrue($result['signed_real_invoker_release_authorized']);
        $this->assertFalse($result['external_process_started']);
        $this->assertFalse($result['provider_started']);
        $this->assertFalse($result['adapter_execution_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertSame('activate_signed_one_shot_scheduler_tick_codex_real_invoker_implementation_boundary_contract', $result['next_required_slice']);

        $observed = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'provider-start:attempt-001')
            ->firstOrFail();

        $this->assertSame(
            'signed_real_invoker_release_authorized_pending_invoker_implementation',
            data_get($observed->metadata, 'codex_signed_real_invoker_release.status')
        );
        $this->assertFalse(data_get($observed->metadata, 'codex_signed_real_invoker_release.external_process_started'));
        $this->assertFalse(data_get($observed->metadata, 'codex_signed_real_invoker_release.token_spend_allowed'));
    }

    public function test_invoker_is_idempotent_for_same_signed_release_id(): void
    {
        $this->createPreflightPassedRun();
        $invoker = app(AgentAutomaticDispatchSchedulerOneShotTickCodexSignedRealInvokerReleaseGateInvoker::class);

        $first = $invoker->authorizeCodexSignedRealInvokerRelease($this->validInput());
        $second = $invoker->authorizeCodexSignedRealInvokerRelease($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_invoker_rejects_invalid_signature_verification_report_hash_without_updating_run(): void
    {
        $this->createPreflightPassedRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_signature_verification_report_hash');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexSignedRealInvokerReleaseGateInvoker::class)
            ->authorizeCodexSignedRealInvokerRelease(array_merge($this->validInput(), [
                'signature_verification_report_hash' => 'not-a-hash',
            ]));
    }

    public function test_invoker_rejects_missing_release_preflight_metadata(): void
    {
        $this->createPreflightPassedRun(['metadata' => []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_release_preflight_missing_or_mismatch');

        app(AgentAutomaticDispatchSchedulerOneShotTickCodexSignedRealInvokerReleaseGateInvoker::class)
            ->authorizeCodexSignedRealInvokerRelease($this->validInput());
    }

    public function test_invoker_rejects_duplicate_signed_release_for_different_id(): void
    {
        $this->createPreflightPassedRun();
        $invoker = app(AgentAutomaticDispatchSchedulerOneShotTickCodexSignedRealInvokerReleaseGateInvoker::class);
        $invoker->authorizeCodexSignedRealInvokerRelease($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_signed_real_invoker_release_already_authorized');

        $invoker->authorizeCodexSignedRealInvokerRelease(array_merge($this->validInput(), [
            'signed_real_invoker_release_id' => 'codex-signed-real-invoker-release-002',
        ]));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createPreflightPassedRun(array $overrides = []): void
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
            'summary' => 'Codex real invoker release preflight passed; real invoker remains disabled.',
            'metadata' => $this->metadataWithPreflight(),
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $preflightOverrides
     * @return array<string,mixed>
     */
    private function metadataWithPreflight(array $preflightOverrides = []): array
    {
        return [
            'codex_real_invoker_release_preflight' => array_merge([
                'real_invoker_release_preflight_id' => 'codex-real-invoker-preflight-001',
                'dry_run_id' => 'codex-invoker-dry-run-001',
                'invocation_authorization_id' => 'codex-invocation-auth-001',
                'runtime_driver_id' => 'codex-runtime-driver-001',
                'spawn_executor_id' => 'codex-spawn-executor-001',
                'spawn_enablement_id' => 'codex-spawn-enable-001',
                'supervised_start_id' => 'codex-supervised-start-001',
                'process_start_release_id' => 'codex-start-release-001',
                'codex_execution_id' => 'codex-execution-001',
                'operator_release_preflight_receipt_hash' => str_repeat('1', 64),
                'real_invoker_contract_hash' => str_repeat('2', 64),
                'process_command_hash' => str_repeat('6', 64),
                'environment_contract_hash' => str_repeat('7', 64),
                'termination_policy_hash' => str_repeat('8', 64),
                'stdout_stderr_sink_hash' => str_repeat('e', 64),
                'liveness_probe_hash' => str_repeat('f', 64),
                'rollback_plan_hash' => str_repeat('3', 64),
                'max_runtime_policy_hash' => str_repeat('4', 64),
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'real_invoker_release_preflight_passed_pending_signed_release',
                'real_invoker_release_preflight_passed' => true,
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
            ], $preflightOverrides),
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
            'signed_real_invoker_release_id' => 'codex-signed-real-invoker-release-001',
            'operator_signed_release_receipt_hash' => str_repeat('9', 64),
            'signature_verification_report_hash' => str_repeat('a', 64),
            'real_invoker_contract_hash' => str_repeat('2', 64),
            'release_policy_hash' => str_repeat('5', 64),
            'process_command_hash' => str_repeat('6', 64),
            'environment_contract_hash' => str_repeat('7', 64),
            'termination_policy_hash' => str_repeat('8', 64),
            'stdout_stderr_sink_hash' => str_repeat('e', 64),
            'liveness_probe_hash' => str_repeat('f', 64),
            'rollback_plan_hash' => str_repeat('3', 64),
            'max_runtime_policy_hash' => str_repeat('4', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'authorize_one_shot_scheduler_signed_real_invoker_release_without_starting_codex',
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
