<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\AgentCodexSignedRealInvokerReleaseGate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\Concerns\CreatesSelfConstructionControlPlaneTables;
use Tests\Concerns\MakesSelfConstructionAgentRuns;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentCodexSignedRealInvokerReleaseGateTest extends TestCase
{
    use CreatesSelfConstructionControlPlaneTables;
    use MakesSelfConstructionAgentRuns;

    public function test_signed_release_authorizes_without_starting_codex(): void
    {
        $this->createPreflightPassedRun();

        $result = app(AgentCodexSignedRealInvokerReleaseGate::class)
            ->authorizeSignedRelease($this->validInput());

        $this->assertSame('codex_signed_real_invoker_release_authorized', $result['status']);
        $this->assertFalse($result['idempotent']);
        $this->assertTrue($result['signed_real_invoker_release_authorized']);
        $this->assertFalse($result['external_process_started']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['provider_started']);
        $this->assertFalse($result['dispatch_allowed']);

        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-signed-real-invoker-release-001',
        ]);
    }

    public function test_signed_release_is_idempotent_for_same_release_id(): void
    {
        $this->createPreflightPassedRun();
        $gate = app(AgentCodexSignedRealInvokerReleaseGate::class);

        $first = $gate->authorizeSignedRelease($this->validInput());
        $second = $gate->authorizeSignedRelease($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_signed_release_rejects_duplicate_release_for_different_id(): void
    {
        $this->createPreflightPassedRun();
        $gate = app(AgentCodexSignedRealInvokerReleaseGate::class);
        $gate->authorizeSignedRelease($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_signed_real_invoker_release_already_authorized');

        $gate->authorizeSignedRelease(array_merge($this->validInput(), [
            'signed_real_invoker_release_id' => 'codex-signed-real-invoker-release-002',
        ]));
    }

    public function test_signed_release_rejects_missing_preflight_metadata(): void
    {
        $this->createPreflightPassedRun(['metadata' => []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_release_preflight_missing_or_mismatch');

        app(AgentCodexSignedRealInvokerReleaseGate::class)
            ->authorizeSignedRelease($this->validInput());
    }

    public function test_signed_release_rejects_missing_signature_verification_hash(): void
    {
        $this->createPreflightPassedRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing_signature_verification_report_hash');

        app(AgentCodexSignedRealInvokerReleaseGate::class)
            ->authorizeSignedRelease(array_merge($this->validInput(), [
                'signature_verification_report_hash' => '',
            ]));
    }

    public function test_signed_release_rejects_preflight_already_started_flag(): void
    {
        $this->createPreflightPassedRun([
            'metadata' => $this->metadataWithPreflight(['external_process_started' => true]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('external_process_started_already_true');

        app(AgentCodexSignedRealInvokerReleaseGate::class)
            ->authorizeSignedRelease($this->validInput());
    }

    public function test_signed_release_rolls_back_metadata_when_ledger_write_fails(): void
    {
        $this->createPreflightPassedRun();
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 8)->primary();
        });

        try {
            app(AgentCodexSignedRealInvokerReleaseGate::class)
                ->authorizeSignedRelease($this->validInput());

            $this->fail('Expected ledger write failure.');
        } catch (\Throwable) {
            $run = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'provider-start:attempt-001')
                ->firstOrFail();

            $this->assertNull(data_get($run->metadata, 'codex_signed_real_invoker_release'));
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createPreflightPassedRun(array $overrides = []): void
    {
        $this->makeAgentRun(array_merge([
            'run_key' => 'provider-start:attempt-001',
            'status' => 'adapter_invocation_prepared',
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
            'reason' => 'authorize_signed_real_invoker_release_without_starting_codex',
        ];
    }

}
