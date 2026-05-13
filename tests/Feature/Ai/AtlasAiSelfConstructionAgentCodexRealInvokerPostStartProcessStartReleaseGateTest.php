<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentCodexRealInvokerPostStartProcessStartReleaseGate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentCodexRealInvokerPostStartProcessStartReleaseGateTest extends TestCase
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

    public function test_post_start_process_start_release_authorizes_release_without_starting_codex(): void
    {
        $this->createPrerequisites();

        $result = app(AgentCodexRealInvokerPostStartProcessStartReleaseGate::class)
            ->authorizePostStartProcessStartRelease($this->validInput());

        $this->assertSame('codex_real_invoker_post_start_process_start_release_authorized', $result['status']);
        $this->assertFalse($result['idempotent']);
        $this->assertTrue($result['process_start_release_authorized']);
        $this->assertTrue($result['supervised_start_executor_required']);
        $this->assertTrue($result['observed_external_process_started']);
        $this->assertTrue($result['observed_provider_started']);
        $this->assertFalse($result['actual_process_start_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['provider_process_call_allowed']);
        $this->assertFalse($result['adapter_execution_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertSame('codex_process_start_release_authorized', data_get($result, 'codex_process_start_release_result.status'));

        $observedRun = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'codex-post-start-observed-run-001')
            ->firstOrFail();

        $providerRun = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', 'provider-start:codex-post-start-attempt-001')
            ->firstOrFail();

        $this->assertSame(
            'post_start_process_start_release_authorized_pending_supervised_start_executor',
            data_get($observedRun->metadata, 'codex_real_invoker_post_start_process_start_release.status')
        );
        $this->assertSame(
            'authorized_pending_supervised_start_executor',
            data_get($providerRun->metadata, 'codex_process_start_release.status')
        );
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-start-release-001',
        ]);
    }

    public function test_post_start_process_start_release_is_idempotent_for_same_release(): void
    {
        $this->createPrerequisites();
        $gate = app(AgentCodexRealInvokerPostStartProcessStartReleaseGate::class);

        $first = $gate->authorizePostStartProcessStartRelease($this->validInput());
        $second = $gate->authorizePostStartProcessStartRelease($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_post_start_process_start_release_rejects_duplicate_release(): void
    {
        $this->createPrerequisites();
        $gate = app(AgentCodexRealInvokerPostStartProcessStartReleaseGate::class);
        $gate->authorizePostStartProcessStartRelease($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_process_start_release_already_recorded');

        $gate->authorizePostStartProcessStartRelease(array_merge($this->validInput(), [
            'process_start_release_id' => 'codex-start-release-002',
        ]));
    }

    public function test_post_start_process_start_release_rejects_missing_provider_execution_contract(): void
    {
        $this->createPrerequisites([
            'observed_run' => ['metadata' => []],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_post_start_provider_execution_contract_missing_or_mismatch');

        app(AgentCodexRealInvokerPostStartProcessStartReleaseGate::class)
            ->authorizePostStartProcessStartRelease($this->validInput());
    }

    public function test_post_start_process_start_release_rejects_provider_execution_contract_with_dispatch_allowed(): void
    {
        $this->createPrerequisites([
            'observed_run' => [
                'metadata' => $this->observedMetadata(['dispatch_allowed' => true]),
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dispatch_allowed_already_true');

        app(AgentCodexRealInvokerPostStartProcessStartReleaseGate::class)
            ->authorizePostStartProcessStartRelease($this->validInput());
    }

    public function test_post_start_process_start_release_rejects_provider_run_without_codex_execution(): void
    {
        $this->createPrerequisites([
            'provider_run' => ['metadata' => []],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_provider_execution_missing_or_mismatch');

        app(AgentCodexRealInvokerPostStartProcessStartReleaseGate::class)
            ->authorizePostStartProcessStartRelease($this->validInput());
    }

    public function test_post_start_process_start_release_rolls_back_when_ledger_write_fails(): void
    {
        $this->createPrerequisites();
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 8)->primary();
        });

        try {
            app(AgentCodexRealInvokerPostStartProcessStartReleaseGate::class)
                ->authorizePostStartProcessStartRelease($this->validInput());

            $this->fail('Expected ledger write failure.');
        } catch (\Throwable) {
            $observedRun = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'codex-post-start-observed-run-001')
                ->firstOrFail();

            $providerRun = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'provider-start:codex-post-start-attempt-001')
                ->firstOrFail();

            $this->assertNull(data_get($observedRun->metadata, 'codex_real_invoker_post_start_process_start_release'));
            $this->assertNull(data_get($providerRun->metadata, 'codex_process_start_release'));
        }
    }

    /**
     * @param  array<string,array<string,mixed>>  $overrides
     */
    private function createPrerequisites(array $overrides = []): void
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
            'summary' => 'Codex real invoker post-start provider execution contract prepared; process start remains disabled.',
            'metadata' => $this->observedMetadata(),
        ], $overrides['observed_run'] ?? []));

        AtlasSelfConstructionAgentRun::query()->create(array_merge([
            'run_key' => 'provider-start:codex-post-start-attempt-001',
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
        ], $overrides['provider_run'] ?? []));
    }

    /**
     * @param  array<string,mixed>  $contractOverrides
     * @return array<string,mixed>
     */
    private function observedMetadata(array $contractOverrides = []): array
    {
        return [
            'codex_real_invoker_post_start_provider_execution_contract' => array_merge([
                'provider_execution_contract_gate_id' => 'codex-real-invoker-post-start-provider-execution-contract-gate-001',
                'codex_execution_id' => 'codex-execution-001',
                'adapter_execution_guard_gate_id' => 'codex-real-invoker-post-start-adapter-execution-guard-gate-001',
                'execution_guard_id' => 'execution-guard-001',
                'adapter_invocation_boundary_gate_id' => 'codex-real-invoker-post-start-adapter-invocation-boundary-gate-001',
                'adapter_invocation_id' => 'adapter-invocation-001',
                'provider_start_driver_gate_id' => 'codex-real-invoker-post-start-provider-start-driver-gate-001',
                'provider_start_attempt_id' => 'codex-post-start-attempt-001',
                'provider_start_run_key' => 'provider-start:codex-post-start-attempt-001',
                'signed_dispatch_receipt_hash' => str_repeat('a', 64),
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'post_start_codex_provider_execution_contract_prepared_pending_process_start_release',
                'post_start_provider_execution_contract_prepared' => true,
                'post_start_adapter_execution_guard_recorded' => true,
                'provider_specific_execution_contract_ready' => true,
                'observed_external_process_started' => true,
                'observed_provider_started' => true,
                'actual_process_start_allowed' => false,
                'token_spend_allowed' => false,
                'provider_process_call_allowed' => false,
                'adapter_invocation_allowed' => false,
                'adapter_execution_allowed' => false,
                'dispatch_allowed' => false,
                'codex_provider_execution_result' => [
                    'status' => 'codex_provider_execution_prepared',
                    'external_process_started' => false,
                    'token_spend_allowed' => false,
                    'provider_started' => false,
                    'dispatch_allowed' => false,
                ],
            ], $contractOverrides),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validInput(): array
    {
        return [
            'run_key' => 'codex-post-start-observed-run-001',
            'post_start_process_start_release_gate_id' => 'codex-real-invoker-post-start-process-start-release-gate-001',
            'process_start_release_id' => 'codex-start-release-001',
            'provider_execution_contract_gate_id' => 'codex-real-invoker-post-start-provider-execution-contract-gate-001',
            'codex_execution_id' => 'codex-execution-001',
            'adapter_execution_guard_gate_id' => 'codex-real-invoker-post-start-adapter-execution-guard-gate-001',
            'execution_guard_id' => 'execution-guard-001',
            'adapter_invocation_boundary_gate_id' => 'codex-real-invoker-post-start-adapter-invocation-boundary-gate-001',
            'adapter_invocation_id' => 'adapter-invocation-001',
            'provider_start_driver_gate_id' => 'codex-real-invoker-post-start-provider-start-driver-gate-001',
            'provider_start_attempt_id' => 'codex-post-start-attempt-001',
            'signed_dispatch_receipt_hash' => str_repeat('a', 64),
            'operator_release_receipt_hash' => str_repeat('b', 64),
            'codex_execution_contract_hash' => str_repeat('c', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'authorize_post_start_process_start_release_without_starting_codex',
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
