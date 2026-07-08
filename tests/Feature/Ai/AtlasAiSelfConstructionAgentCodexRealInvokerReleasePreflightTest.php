<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerReleasePreflight;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\Concerns\CreatesSelfConstructionControlPlaneTables;
use Tests\Concerns\MakesSelfConstructionAgentRuns;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentCodexRealInvokerReleasePreflightTest extends TestCase
{
    use CreatesSelfConstructionControlPlaneTables;
    use MakesSelfConstructionAgentRuns;

    public function test_release_preflight_records_without_starting_codex(): void
    {
        $this->createDryRunReadyRun();

        $result = app(AgentCodexRealInvokerReleasePreflight::class)
            ->recordPreflight($this->validInput());

        $this->assertSame('codex_real_invoker_release_preflight_recorded', $result['status']);
        $this->assertFalse($result['idempotent']);
        $this->assertTrue($result['real_invoker_release_preflight_passed']);
        $this->assertFalse($result['external_process_started']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['provider_started']);
        $this->assertFalse($result['dispatch_allowed']);

        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'codex-real-invoker-preflight-001',
        ]);
    }

    public function test_release_preflight_is_idempotent_for_same_preflight_id(): void
    {
        $this->createDryRunReadyRun();
        $preflight = app(AgentCodexRealInvokerReleasePreflight::class);

        $first = $preflight->recordPreflight($this->validInput());
        $second = $preflight->recordPreflight($this->validInput());

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['agent_run_id'], $second['agent_run_id']);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_release_preflight_rejects_duplicate_preflight_for_different_id(): void
    {
        $this->createDryRunReadyRun();
        $preflight = app(AgentCodexRealInvokerReleasePreflight::class);
        $preflight->recordPreflight($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_real_invoker_release_preflight_already_recorded');

        $preflight->recordPreflight(array_merge($this->validInput(), [
            'real_invoker_release_preflight_id' => 'codex-real-invoker-preflight-002',
        ]));
    }

    public function test_release_preflight_rejects_missing_dry_run_metadata(): void
    {
        $this->createDryRunReadyRun(['metadata' => []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codex_external_process_invoker_dry_run_missing_or_mismatch');

        app(AgentCodexRealInvokerReleasePreflight::class)
            ->recordPreflight($this->validInput());
    }

    public function test_release_preflight_rejects_missing_operator_release_preflight_receipt_hash(): void
    {
        $this->createDryRunReadyRun();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing_operator_release_preflight_receipt_hash');

        app(AgentCodexRealInvokerReleasePreflight::class)
            ->recordPreflight(array_merge($this->validInput(), [
                'operator_release_preflight_receipt_hash' => '',
            ]));
    }

    public function test_release_preflight_rejects_dry_run_already_started_flag(): void
    {
        $this->createDryRunReadyRun([
            'metadata' => $this->metadataWithDryRun(['external_process_started' => true]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('external_process_started_already_true');

        app(AgentCodexRealInvokerReleasePreflight::class)
            ->recordPreflight($this->validInput());
    }

    public function test_release_preflight_rolls_back_metadata_when_ledger_write_fails(): void
    {
        $this->createDryRunReadyRun();
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 8)->primary();
        });

        try {
            app(AgentCodexRealInvokerReleasePreflight::class)
                ->recordPreflight($this->validInput());

            $this->fail('Expected ledger write failure.');
        } catch (\Throwable) {
            $run = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'provider-start:attempt-001')
                ->firstOrFail();

            $this->assertNull(data_get($run->metadata, 'codex_real_invoker_release_preflight'));
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createDryRunReadyRun(array $overrides = []): void
    {
        $this->makeAgentRun(array_merge([
            'run_key' => 'provider-start:attempt-001',
            'status' => 'adapter_invocation_prepared',
            'summary' => 'Codex external process invoker dry-run prepared; real invoker remains disabled.',
            'metadata' => $this->metadataWithDryRun(),
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $dryRunOverrides
     * @return array<string,mixed>
     */
    private function metadataWithDryRun(array $dryRunOverrides = []): array
    {
        return [
            'codex_external_process_invoker_dry_run' => array_merge([
                'dry_run_id' => 'codex-invoker-dry-run-001',
                'invocation_authorization_id' => 'codex-invocation-auth-001',
                'runtime_driver_id' => 'codex-runtime-driver-001',
                'spawn_executor_id' => 'codex-spawn-executor-001',
                'spawn_enablement_id' => 'codex-spawn-enable-001',
                'supervised_start_id' => 'codex-supervised-start-001',
                'process_start_release_id' => 'codex-start-release-001',
                'codex_execution_id' => 'codex-execution-001',
                'operator_dry_run_receipt_hash' => str_repeat('b', 64),
                'invoker_contract_hash' => str_repeat('c', 64),
                'process_command_hash' => str_repeat('6', 64),
                'environment_contract_hash' => str_repeat('7', 64),
                'termination_policy_hash' => str_repeat('8', 64),
                'stdout_stderr_sink_hash' => str_repeat('e', 64),
                'liveness_probe_hash' => str_repeat('f', 64),
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'dry_run_ready_pending_real_invoker_release',
                'external_process_invoker_dry_run_prepared' => true,
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
            ], $dryRunOverrides),
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
            'operator_release_preflight_receipt_hash' => str_repeat('1', 64),
            'real_invoker_contract_hash' => str_repeat('2', 64),
            'process_command_hash' => str_repeat('6', 64),
            'environment_contract_hash' => str_repeat('7', 64),
            'termination_policy_hash' => str_repeat('8', 64),
            'stdout_stderr_sink_hash' => str_repeat('e', 64),
            'liveness_probe_hash' => str_repeat('f', 64),
            'rollback_plan_hash' => str_repeat('3', 64),
            'max_runtime_policy_hash' => str_repeat('4', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'record_real_invoker_release_preflight_without_starting_codex',
        ];
    }


    // ── evaluateReleaseReadiness() ───────────────────────────────────────────

    private function freshProof(int $ageMinutes = 5): array
    {
        return ['present' => true, 'age_minutes' => $ageMinutes];
    }

    private function readinessFacts(array $overrides = []): array
    {
        return array_merge([
            'scope_proof' => $this->freshProof(),
            'launch_proof' => $this->freshProof(),
            'rollback_plan' => $this->freshProof(),
            'queue_health' => $this->freshProof(),
        ], $overrides);
    }

    public function test_release_ready_when_all_four_proofs_present_and_fresh(): void
    {
        $result = app(AgentCodexRealInvokerReleasePreflight::class)->evaluateReleaseReadiness($this->readinessFacts());

        $this->assertTrue($result['release_ready']);
        $this->assertSame([], $result['missing_proofs']);
        $this->assertNull($result['next_repair_hint']);
        $this->assertFalse($result['dispatch_allowed']);
    }

    public function test_release_blocked_when_scope_proof_missing(): void
    {
        $result = app(AgentCodexRealInvokerReleasePreflight::class)->evaluateReleaseReadiness(
            $this->readinessFacts(['scope_proof' => null]),
        );

        $this->assertFalse($result['release_ready']);
        $this->assertContains('scope_proof', $result['missing_proofs']);
        $this->assertNotNull($result['next_repair_hint']);
    }

    public function test_release_blocked_when_launch_proof_stale(): void
    {
        $result = app(AgentCodexRealInvokerReleasePreflight::class)->evaluateReleaseReadiness(
            $this->readinessFacts(['launch_proof' => $this->freshProof(120)]),
        );

        $this->assertFalse($result['release_ready']);
        $this->assertContains('launch_proof', $result['missing_proofs']);
    }

    public function test_release_blocked_when_rollback_plan_not_present(): void
    {
        $result = app(AgentCodexRealInvokerReleasePreflight::class)->evaluateReleaseReadiness(
            $this->readinessFacts(['rollback_plan' => ['present' => false]]),
        );

        $this->assertFalse($result['release_ready']);
        $this->assertContains('rollback_plan', $result['missing_proofs']);
    }

    public function test_release_blocked_when_queue_health_missing(): void
    {
        $result = app(AgentCodexRealInvokerReleasePreflight::class)->evaluateReleaseReadiness(
            $this->readinessFacts(['queue_health' => null]),
        );

        $this->assertFalse($result['release_ready']);
        $this->assertContains('queue_health', $result['missing_proofs']);
    }

    public function test_custom_max_proof_age_minutes_is_respected(): void
    {
        $result = app(AgentCodexRealInvokerReleasePreflight::class)->evaluateReleaseReadiness(
            $this->readinessFacts(['launch_proof' => $this->freshProof(20), 'max_proof_age_minutes' => 10]),
        );

        $this->assertFalse($result['release_ready']);
        $this->assertContains('launch_proof', $result['missing_proofs']);
    }

    public function test_multiple_missing_proofs_all_listed(): void
    {
        $result = app(AgentCodexRealInvokerReleasePreflight::class)->evaluateReleaseReadiness(
            $this->readinessFacts(['scope_proof' => null, 'queue_health' => null]),
        );

        $this->assertFalse($result['release_ready']);
        $this->assertContains('scope_proof', $result['missing_proofs']);
        $this->assertContains('queue_health', $result['missing_proofs']);
        $this->assertCount(2, $result['missing_proofs']);
    }
}
