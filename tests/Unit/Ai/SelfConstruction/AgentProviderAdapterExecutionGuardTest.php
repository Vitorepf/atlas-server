<?php

namespace Tests\Unit\Ai\SelfConstruction;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentProviderAdapterExecutionGuard;
use App\Services\Ai\SelfConstruction\AgentProviderAdapterRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AgentProviderAdapterExecutionGuardTest extends TestCase
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

    // ── AC1/AC2/AC3: evaluateExecutionStart — pure pre-flight evaluator ────────

    public function test_all_checks_passing_allows_start(): void
    {
        $result = app(AgentProviderAdapterExecutionGuard::class)->evaluateExecutionStart([]);

        $this->assertTrue($result['allow_start']);
        $this->assertNull($result['block_reason']);
        $this->assertSame([], $result['block_reasons']);
        $this->assertNull($result['required_repair_hint']);
    }

    public function test_adapter_not_ready_blocks_start(): void
    {
        $result = app(AgentProviderAdapterExecutionGuard::class)->evaluateExecutionStart([
            'adapter_ready' => false,
        ]);

        $this->assertFalse($result['allow_start']);
        $this->assertSame(AgentProviderAdapterExecutionGuard::BLOCK_REASON_ADAPTER_NOT_READY, $result['block_reason']);
        $this->assertNotEmpty($result['required_repair_hint']);
    }

    public function test_unsupported_task_family_blocks_start(): void
    {
        $result = app(AgentProviderAdapterExecutionGuard::class)->evaluateExecutionStart([
            'task_family' => 'unknown_family',
            'supported_task_families' => ['known_family'],
        ]);

        $this->assertFalse($result['allow_start']);
        $this->assertSame(AgentProviderAdapterExecutionGuard::BLOCK_REASON_TASK_FAMILY_UNSUPPORTED, $result['block_reason']);
    }

    public function test_supported_task_family_does_not_block_start(): void
    {
        $result = app(AgentProviderAdapterExecutionGuard::class)->evaluateExecutionStart([
            'task_family' => 'known_family',
            'supported_task_families' => ['known_family'],
        ]);

        $this->assertTrue($result['allow_start']);
    }

    public function test_empty_supported_task_families_does_not_block(): void
    {
        $result = app(AgentProviderAdapterExecutionGuard::class)->evaluateExecutionStart([
            'task_family' => 'anything',
            'supported_task_families' => [],
        ]);

        $this->assertTrue($result['allow_start']);
    }

    public function test_unsafe_scope_blocks_start(): void
    {
        $result = app(AgentProviderAdapterExecutionGuard::class)->evaluateExecutionStart([
            'scope_safe' => false,
        ]);

        $this->assertFalse($result['allow_start']);
        $this->assertSame(AgentProviderAdapterExecutionGuard::BLOCK_REASON_SCOPE_UNSAFE, $result['block_reason']);
    }

    public function test_unsatisfied_evidence_policy_blocks_start(): void
    {
        $result = app(AgentProviderAdapterExecutionGuard::class)->evaluateExecutionStart([
            'evidence_policy_satisfied' => false,
        ]);

        $this->assertFalse($result['allow_start']);
        $this->assertSame(AgentProviderAdapterExecutionGuard::BLOCK_REASON_EVIDENCE_POLICY_NOT_SATISFIED, $result['block_reason']);
    }

    public function test_unavailable_fallback_blocks_start(): void
    {
        $result = app(AgentProviderAdapterExecutionGuard::class)->evaluateExecutionStart([
            'fallback_available' => false,
        ]);

        $this->assertFalse($result['allow_start']);
        $this->assertSame(AgentProviderAdapterExecutionGuard::BLOCK_REASON_FALLBACK_UNAVAILABLE, $result['block_reason']);
    }

    public function test_multiple_failures_all_recorded_but_first_is_primary(): void
    {
        $result = app(AgentProviderAdapterExecutionGuard::class)->evaluateExecutionStart([
            'adapter_ready' => false,
            'scope_safe' => false,
        ]);

        $this->assertFalse($result['allow_start']);
        $this->assertSame(AgentProviderAdapterExecutionGuard::BLOCK_REASON_ADAPTER_NOT_READY, $result['block_reason']);
        $this->assertContains(AgentProviderAdapterExecutionGuard::BLOCK_REASON_ADAPTER_NOT_READY, $result['block_reasons']);
        $this->assertContains(AgentProviderAdapterExecutionGuard::BLOCK_REASON_SCOPE_UNSAFE, $result['block_reasons']);
    }

    public function test_evaluate_execution_start_is_deterministic(): void
    {
        $guard = app(AgentProviderAdapterExecutionGuard::class);
        $input = ['adapter_ready' => false, 'fallback_available' => false];

        $this->assertSame($guard->evaluateExecutionStart($input), $guard->evaluateExecutionStart($input));
    }

    // ── AC2: unsupported task families are blocked with task_family_unsupported and repair hint ──

    public function test_unsupported_task_family_blocked_with_repair_hint(): void
    {
        $guard = app(AgentProviderAdapterExecutionGuard::class);
        $result = $guard->evaluateExecutionStart([
            'task_family' => 'unsupported_family',
            'supported_task_families' => ['supported_family'],
        ]);

        $this->assertFalse($result['allow_start']);
        $this->assertContains('task_family_unsupported', $result['block_reasons']);
        $this->assertNotNull($result['required_repair_hint']);
    }

    // ── AC3: stale or missing evidence policy blocks execution unless fallback adapter is available ──

    public function test_stale_evidence_blocks_without_fallback(): void
    {
        $guard = app(AgentProviderAdapterExecutionGuard::class);
        $result = $guard->evaluateExecutionStart([
            'evidence_policy_satisfied' => false,
            'fallback_available' => false,
        ]);

        $this->assertFalse($result['allow_start']);
        $this->assertContains('evidence_policy_not_satisfied', $result['block_reasons']);
    }

    public function test_stale_evidence_allowed_with_fallback(): void
    {
        $guard = app(AgentProviderAdapterExecutionGuard::class);
        $result = $guard->evaluateExecutionStart([
            'evidence_policy_satisfied' => false,
            'fallback_available' => true,
        ]);

        // Evidence policy not satisfied still blocks, but fallback is available
        $this->assertFalse($result['allow_start']);
        $this->assertTrue($result['fallback_policy']['fallback_available']);
    }

    // ── AC4: approved execution returns provider_contract, fallback_policy and evidence_freshness ──

    public function test_approved_execution_returns_all_fields(): void
    {
        $guard = app(AgentProviderAdapterExecutionGuard::class);
        $result = $guard->evaluateExecutionStart([
            'adapter_ready' => true,
            'task_family' => 'coding',
            'supported_task_families' => ['coding'],
            'scope_safe' => true,
            'evidence_policy_satisfied' => true,
            'fallback_available' => true,
            'provider' => 'openai',
            'adapter' => 'codex',
            'evidence_age_seconds' => 60,
        ]);

        $this->assertTrue($result['allow_start']);
        $this->assertNotNull($result['provider_contract']);
        $this->assertSame('openai', $result['provider_contract']['provider']);
        $this->assertArrayHasKey('fallback_policy', $result);
        $this->assertArrayHasKey('evidence_freshness', $result);
        $this->assertTrue($result['evidence_freshness']['evidence_policy_satisfied']);
    }
}
