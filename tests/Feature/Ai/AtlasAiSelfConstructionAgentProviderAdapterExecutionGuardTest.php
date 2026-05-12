<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentProviderAdapterExecutionGuard;
use App\Services\Ai\SelfConstruction\AgentProviderAdapterRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentProviderAdapterExecutionGuardTest extends TestCase
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

    public function test_guard_blocks_provider_execution_without_starting_provider(): void
    {
        $this->createAdapterPreparedRun();

        $result = app(AgentProviderAdapterExecutionGuard::class)
            ->blockUntilProviderSpecificContract($this->validInput());

        $this->assertSame('provider_adapter_execution_blocked', $result['status']);
        $this->assertFalse($result['idempotent']);
        $this->assertSame('provider_specific_execution_contract_missing', $result['blocked_by']);
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
            'receipt_id' => 'execution-guard-001',
        ]);
    }

    public function test_guard_is_idempotent_for_same_guard_id(): void
    {
        $this->createAdapterPreparedRun();
        $guard = app(AgentProviderAdapterExecutionGuard::class);

        $first = $guard->blockUntilProviderSpecificContract($this->validInput());
        $second = $guard->blockUntilProviderSpecificContract($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_guard_rejects_run_not_adapter_invocation_prepared(): void
    {
        $this->createAdapterPreparedRun(['status' => 'pre_start_guarded']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('agent_run_not_adapter_invocation_prepared');

        app(AgentProviderAdapterExecutionGuard::class)
            ->blockUntilProviderSpecificContract($this->validInput());
    }

    public function test_guard_rejects_adapter_invocation_id_mismatch(): void
    {
        $this->createAdapterPreparedRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('adapter_invocation_id_mismatch');

        app(AgentProviderAdapterExecutionGuard::class)
            ->blockUntilProviderSpecificContract(array_merge($this->validInput(), [
                'adapter_invocation_id' => 'different-invocation',
            ]));
    }

    public function test_guard_rejects_descriptor_hash_mismatch(): void
    {
        $this->createAdapterPreparedRun([
            'metadata' => array_merge($this->runMetadata(), [
                'adapter_invocation' => array_merge($this->adapterInvocationMetadata(), [
                    'adapter_descriptor_hash' => str_repeat('9', 64),
                ]),
            ]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('adapter_descriptor_hash_mismatch');

        app(AgentProviderAdapterExecutionGuard::class)
            ->blockUntilProviderSpecificContract($this->validInput());
    }

    public function test_guard_rolls_back_metadata_when_ledger_write_fails(): void
    {
        $this->createAdapterPreparedRun();
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 8)->primary();
        });

        try {
            app(AgentProviderAdapterExecutionGuard::class)
                ->blockUntilProviderSpecificContract($this->validInput());

            $this->fail('Expected ledger write failure.');
        } catch (\Throwable) {
            $run = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'provider-start:attempt-001')
                ->firstOrFail();

            $this->assertNull(data_get($run->metadata, 'provider_adapter_execution_guard'));
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createAdapterPreparedRun(array $overrides = []): void
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
            'summary' => 'Adapter invocation boundary prepared; external provider process remains disabled.',
            'metadata' => $this->runMetadata(),
        ], $overrides));
    }

    /**
     * @return array<string,mixed>
     */
    private function validInput(): array
    {
        return [
            'run_key' => 'provider-start:attempt-001',
            'execution_guard_id' => 'execution-guard-001',
            'adapter_invocation_id' => 'adapter-invocation-001',
            'provider' => 'codex',
            'adapter' => 'codex',
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'provider_specific_execution_contract_not_ready',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function runMetadata(): array
    {
        return [
            'provider_start_attempt_id' => 'attempt-001',
            'adapter' => 'codex',
            'command' => 'codex --continue',
            'cwd' => '/Users/vitorepf/develop/Atlas/worktrees/codex-a',
            'provider_started' => false,
            'adapter_invocation_allowed' => false,
            'adapter_invocation' => $this->adapterInvocationMetadata(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function adapterInvocationMetadata(): array
    {
        $registry = app(AgentProviderAdapterRegistry::class);
        $descriptor = $registry->resolve('codex', 'codex');

        return [
            'adapter_invocation_id' => 'adapter-invocation-001',
            'provider_start_attempt_id' => 'attempt-001',
            'provider' => 'codex',
            'adapter' => 'codex',
            'adapter_id' => $descriptor['adapter_id'],
            'adapter_descriptor_hash' => $registry->descriptorHash($descriptor),
            'command' => 'codex --continue',
            'cwd' => '/Users/vitorepf/develop/Atlas/worktrees/codex-a',
            'context_pack_hash' => str_repeat('1', 64),
            'continuation_summary_hash' => str_repeat('2', 64),
            'status' => 'prepared_pending_external_invocation',
            'external_process_started' => false,
            'token_spend_allowed' => false,
            'provider_started' => false,
            'dispatch_allowed' => false,
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
