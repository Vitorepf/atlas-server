<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentCodexExternalProcessInvokerDryRun;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\Concerns\CreatesSelfConstructionControlPlaneTables;
use Tests\Concerns\MakesSelfConstructionAgentRuns;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentCodexExternalProcessInvokerDryRunTest extends TestCase
{
    use CreatesSelfConstructionControlPlaneTables;
    use MakesSelfConstructionAgentRuns;

    public function test_dry_run_prepares_invoker_without_starting_codex(): void
    {
        $this->createInvocationAuthorizedRun();

        $result = app(AgentCodexExternalProcessInvokerDryRun::class)
            ->prepareDryRun($this->validInput());

        $this->assertSame('codex_external_process_invoker_dry_run_prepared', $result['status']);
        $this->assertFalse($result['idempotent']);
        $this->assertTrue($result['external_process_invoker_dry_run_prepared']);
        $this->assertFalse($result['external_process_started']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['provider_started']);
        $this->assertFalse($result['dispatch_allowed']);

        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-invoker-dry-run-001',
        ]);
    }

    public function test_dry_run_is_idempotent_for_same_dry_run_id(): void
    {
        $this->createInvocationAuthorizedRun();
        $dryRun = app(AgentCodexExternalProcessInvokerDryRun::class);

        $first = $dryRun->prepareDryRun($this->validInput());
        $second = $dryRun->prepareDryRun($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_dry_run_rejects_duplicate_dry_run_for_different_id(): void
    {
        $this->createInvocationAuthorizedRun();
        $dryRun = app(AgentCodexExternalProcessInvokerDryRun::class);
        $dryRun->prepareDryRun($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_external_process_invoker_dry_run_already_prepared');

        $dryRun->prepareDryRun(array_merge($this->validInput(), [
            'dry_run_id' => 'codex-invoker-dry-run-002',
        ]));
    }

    public function test_dry_run_rejects_missing_invocation_authorization_metadata(): void
    {
        $this->createInvocationAuthorizedRun(['metadata' => []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_external_process_invocation_authorization_missing_or_mismatch');

        app(AgentCodexExternalProcessInvokerDryRun::class)
            ->prepareDryRun($this->validInput());
    }

    public function test_dry_run_rejects_missing_operator_dry_run_receipt_hash(): void
    {
        $this->createInvocationAuthorizedRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing_operator_dry_run_receipt_hash');

        app(AgentCodexExternalProcessInvokerDryRun::class)
            ->prepareDryRun(array_merge($this->validInput(), [
                'operator_dry_run_receipt_hash' => '',
            ]));
    }

    public function test_dry_run_rejects_authorization_already_started_flag(): void
    {
        $this->createInvocationAuthorizedRun([
            'metadata' => $this->metadataWithInvocationAuthorization(['external_process_started' => true]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('external_process_started_already_true');

        app(AgentCodexExternalProcessInvokerDryRun::class)
            ->prepareDryRun($this->validInput());
    }

    public function test_dry_run_rolls_back_metadata_when_ledger_write_fails(): void
    {
        $this->createInvocationAuthorizedRun();
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 8)->primary();
        });

        try {
            app(AgentCodexExternalProcessInvokerDryRun::class)
                ->prepareDryRun($this->validInput());

            $this->fail('Expected ledger write failure.');
        } catch (\Throwable) {
            $run = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'provider-start:attempt-001')
                ->firstOrFail();

            $this->assertNull(data_get($run->metadata, 'codex_external_process_invoker_dry_run'));
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createInvocationAuthorizedRun(array $overrides = []): void
    {
        $this->makeAgentRun(array_merge([
            'run_key' => 'provider-start:attempt-001',
            'status' => 'adapter_invocation_prepared',
            'summary' => 'Codex external process invocation authorized; external process invoker remains disabled.',
            'metadata' => $this->metadataWithInvocationAuthorization(),
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $authorizationOverrides
     * @return array<string,mixed>
     */
    private function metadataWithInvocationAuthorization(array $authorizationOverrides = []): array
    {
        return [
            'codex_external_process_invocation_authorization' => array_merge([
                'invocation_authorization_id' => 'codex-invocation-auth-001',
                'runtime_driver_id' => 'codex-runtime-driver-001',
                'spawn_executor_id' => 'codex-spawn-executor-001',
                'spawn_enablement_id' => 'codex-spawn-enable-001',
                'supervised_start_id' => 'codex-supervised-start-001',
                'process_start_release_id' => 'codex-start-release-001',
                'codex_execution_id' => 'codex-execution-001',
                'operator_invocation_receipt_hash' => str_repeat('9', 64),
                'runtime_driver_contract_hash' => str_repeat('a', 64),
                'process_command_hash' => str_repeat('6', 64),
                'environment_contract_hash' => str_repeat('7', 64),
                'termination_policy_hash' => str_repeat('8', 64),
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'authorized_pending_external_process_invoker',
                'external_process_invocation_authorized' => true,
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
            ], $authorizationOverrides),
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
            'operator_dry_run_receipt_hash' => str_repeat('b', 64),
            'invoker_contract_hash' => str_repeat('c', 64),
            'process_command_hash' => str_repeat('6', 64),
            'environment_contract_hash' => str_repeat('7', 64),
            'termination_policy_hash' => str_repeat('8', 64),
            'stdout_stderr_sink_hash' => str_repeat('e', 64),
            'liveness_probe_hash' => str_repeat('f', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'prepare_invoker_dry_run_without_starting_codex',
        ];
    }


    // ── preview() ────────────────────────────────────────────────────────────

    public function test_preview_returns_command_context_and_environment_without_side_effects(): void
    {
        $this->createInvocationAuthorizedRun();

        $result = app(AgentCodexExternalProcessInvokerDryRun::class)->preview($this->validInput());

        $this->assertSame('codex_external_process_invoker_dry_run_preview_ok', $result['status']);
        $this->assertSame([], $result['blocked_reasons']);
        $this->assertNotEmpty($result['command_preview']);
        $this->assertStringContainsString('codex-execution-001', $result['command_preview']);
        $this->assertSame('provider-start:attempt-001', $result['context_scope']['run_key']);
        $this->assertSame(str_repeat('7', 64), $result['environment_contract']['environment_contract_hash']);
        $this->assertFalse($result['process_started']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertDatabaseCount('atlas_ledger_events', 0);
    }

    public function test_preview_flags_missing_authorization_without_throwing(): void
    {
        $this->createInvocationAuthorizedRun(['metadata' => []]);

        $result = app(AgentCodexExternalProcessInvokerDryRun::class)->preview($this->validInput());

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('codex_external_process_invocation_authorization_missing_or_mismatch', $result['blocked_reasons']);
        $this->assertFalse($result['process_started']);
        $this->assertDatabaseCount('atlas_ledger_events', 0);
    }

    public function test_preview_flags_missing_run_without_throwing(): void
    {
        $result = app(AgentCodexExternalProcessInvokerDryRun::class)->preview($this->validInput());

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('agent_run_not_found', $result['blocked_reasons']);
    }

    public function test_preview_flags_malformed_input_without_throwing(): void
    {
        $result = app(AgentCodexExternalProcessInvokerDryRun::class)->preview([]);

        $this->assertSame('blocked', $result['status']);
        $this->assertNotEmpty($result['blocked_reasons']);
        $this->assertNull($result['command_preview']);
    }

    public function test_preview_never_mutates_run_status(): void
    {
        $this->createInvocationAuthorizedRun();

        app(AgentCodexExternalProcessInvokerDryRun::class)->preview($this->validInput());

        $run = AtlasSelfConstructionAgentRun::query()->where('run_key', 'provider-start:attempt-001')->first();
        $this->assertSame('adapter_invocation_prepared', $run->status);
        $this->assertArrayNotHasKey('codex_external_process_invoker_dry_run', (array) $run->metadata);
    }
}
