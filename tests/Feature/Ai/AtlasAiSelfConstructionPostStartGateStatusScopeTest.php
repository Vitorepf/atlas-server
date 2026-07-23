<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionPostStartGateStatusSection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesSelfConstructionControlPlaneTables;
use Tests\TestCase;

final class AtlasAiSelfConstructionPostStartGateStatusScopeTest extends TestCase
{
    use CreatesSelfConstructionControlPlaneTables;

    public function test_post_start_statuses_scope_observed_and_provider_counts_to_the_requested_run_identity(): void
    {
        $scope = [
            'workspace' => 'workspace-match',
            'target' => 'target-match',
            'actor' => 'actor-match',
            'session' => 'session-match',
            'packet' => 'packet-match',
            'receipt_hash' => str_repeat('a', 64),
        ];

        $this->createRun('provider-start:scope-match', $scope);

        foreach ([
            'workspace' => 'workspace-intruder',
            'target' => 'target-intruder',
            'actor' => 'actor-intruder',
            'session' => 'session-intruder',
            'packet' => 'packet-intruder',
            'receipt_hash' => str_repeat('b', 64),
        ] as $field => $intruderValue) {
            $intruder = $scope;
            $intruder[$field] = $intruderValue;
            $this->createRun('provider-start:scope-intruder-'.$field, $intruder, CarbonImmutable::now()->addSecond());
        }

        $section = new ReadinessProjectionPostStartGateStatusSection;

        foreach ($this->statusContracts() as $contract) {
            $result = $section->{$contract['method']}($scope);
            $status = $result[$contract['envelope_key']];

            $this->assertSame(1, $status[$contract['observed_count_key']], $contract['method']);
            $this->assertSame(1, $status[$contract['provider_count_key']], $contract['method']);
            $this->assertSame('packet-match', $status[$contract['latest_key']]['packet_id'], $contract['method']);
        }
    }

    public function test_post_start_status_derives_latest_and_counts_from_one_agent_run_snapshot(): void
    {
        $scope = [
            'workspace' => 'workspace-snapshot',
            'target' => 'target-snapshot',
            'actor' => 'actor-snapshot',
            'session' => 'session-snapshot',
            'packet' => 'packet-snapshot',
            'receipt_hash' => str_repeat('c', 64),
        ];
        $this->createRun('provider-start:snapshot-original', $scope);

        $injected = false;
        DB::listen(function (QueryExecuted $query) use (&$injected, $scope): void {
            $sql = strtolower(ltrim($query->sql));
            if ($injected || ! str_starts_with($sql, 'select * from') || ! str_contains($sql, 'atlas_self_construction_agent_runs')) {
                return;
            }

            $injected = true;
            $this->createRun('provider-start:snapshot-intruder', $scope, CarbonImmutable::now()->addSecond());
        });

        $result = (new ReadinessProjectionPostStartGateStatusSection)
            ->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorPlanGateStatus($scope);
        $status = $result['agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_status'];

        $this->assertTrue($injected);
        $this->assertSame(1, $status['codex_real_invoker_post_start_executor_plan_recorded_run_count']);
        $this->assertSame(1, $status['provider_start_runs_with_codex_real_invoker_executor_plan_count']);
        $this->assertSame('provider-start:snapshot-original', $status['latest_codex_real_invoker_post_start_executor_plan']['run_key']);
    }

    /**
     * @return list<array{method: string, envelope_key: string, observed_count_key: string, provider_count_key: string, latest_key: string}>
     */
    private function statusContracts(): array
    {
        return [
            [
                'method' => 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorPlanGateStatus',
                'envelope_key' => 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_status',
                'observed_count_key' => 'codex_real_invoker_post_start_executor_plan_recorded_run_count',
                'provider_count_key' => 'provider_start_runs_with_codex_real_invoker_executor_plan_count',
                'latest_key' => 'latest_codex_real_invoker_post_start_executor_plan',
            ],
            [
                'method' => 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartEnvelopeGateStatus',
                'envelope_key' => 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate_status',
                'observed_count_key' => 'codex_real_invoker_post_start_process_start_envelope_recorded_run_count',
                'provider_count_key' => 'provider_start_runs_with_codex_real_invoker_process_start_envelope_count',
                'latest_key' => 'latest_codex_real_invoker_post_start_process_start_envelope',
            ],
            [
                'method' => 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartStartExecutionGateStatus',
                'envelope_key' => 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_status',
                'observed_count_key' => 'codex_real_invoker_post_start_start_execution_gate_recorded_run_count',
                'provider_count_key' => 'provider_start_runs_with_codex_real_invoker_start_execution_gate_count',
                'latest_key' => 'latest_codex_real_invoker_post_start_start_execution_gate',
            ],
            [
                'method' => 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessStartAuthorizationGateStatus',
                'envelope_key' => 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_status',
                'observed_count_key' => 'codex_real_invoker_post_start_final_process_start_authorization_recorded_run_count',
                'provider_count_key' => 'provider_start_runs_with_codex_real_invoker_final_process_start_authorization_count',
                'latest_key' => 'latest_codex_real_invoker_post_start_final_process_start_authorization',
            ],
            [
                'method' => 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGateStatus',
                'envelope_key' => 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_status',
                'observed_count_key' => 'codex_real_invoker_post_start_actual_process_start_rehearsal_recorded_run_count',
                'provider_count_key' => 'provider_start_runs_with_codex_real_invoker_actual_process_start_rehearsal_count',
                'latest_key' => 'latest_codex_real_invoker_post_start_actual_process_start_rehearsal',
            ],
            [
                'method' => 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGateStatus',
                'envelope_key' => 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_status',
                'observed_count_key' => 'codex_real_invoker_post_start_executor_fresh_release_recorded_run_count',
                'provider_count_key' => 'provider_start_runs_with_codex_real_invoker_executor_fresh_release_count',
                'latest_key' => 'latest_codex_real_invoker_post_start_executor_fresh_release',
            ],
            [
                'method' => 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartActivationGateStatus',
                'envelope_key' => 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate_status',
                'observed_count_key' => 'codex_real_invoker_post_start_supervised_start_activation_recorded_run_count',
                'provider_count_key' => 'provider_start_runs_with_codex_real_invoker_supervised_start_activation_count',
                'latest_key' => 'latest_codex_real_invoker_post_start_supervised_start_activation',
            ],
            [
                'method' => 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartGuardedProcessStartExecutorGateStatus',
                'envelope_key' => 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_status',
                'observed_count_key' => 'codex_real_invoker_post_start_guarded_process_start_recorded_run_count',
                'provider_count_key' => 'provider_start_runs_with_codex_real_invoker_guarded_process_start_count',
                'latest_key' => 'latest_codex_real_invoker_post_start_guarded_process_start',
            ],
        ];
    }

    /**
     * @param  array{workspace: string, target: string, actor: string, session: string, packet: string, receipt_hash: string}  $scope
     */
    private function createRun(string $runKey, array $scope, ?CarbonImmutable $updatedAt = null): void
    {
        AtlasSelfConstructionAgentRun::query()->create([
            'run_key' => $runKey,
            'packet_id' => $scope['packet'],
            'actor' => $scope['actor'],
            'provider' => 'codex',
            'provider_role' => 'implementation',
            'session_id' => $scope['session'],
            'workspace_id' => $scope['workspace'],
            'status' => 'adapter_invocation_prepared',
            'liveness' => 'alive',
            'completion_evidence_hash' => $scope['receipt_hash'],
            'metadata' => $this->postStartMetadata($scope['target']),
        ]);

        if ($updatedAt !== null) {
            DB::table('atlas_self_construction_agent_runs')
                ->where('run_key', $runKey)
                ->update(['updated_at' => $updatedAt]);
        }
    }

    /** @return array<string, mixed> */
    private function postStartMetadata(string $target): array
    {
        return [
            'target' => $target,
            'codex_real_invoker_post_start_executor_plan' => ['real_invoker_executor_plan_id' => 'executor-plan'],
            'codex_real_invoker_executor_plan' => ['real_invoker_executor_plan_id' => 'executor-plan'],
            'codex_real_invoker_post_start_process_start_envelope' => ['real_invoker_process_start_envelope_id' => 'process-envelope'],
            'codex_real_invoker_process_start_envelope' => ['real_invoker_process_start_envelope_id' => 'process-envelope'],
            'codex_real_invoker_post_start_start_execution_gate' => ['real_invoker_start_execution_gate_id' => 'start-execution'],
            'codex_real_invoker_start_execution_gate' => ['real_invoker_start_execution_gate_id' => 'start-execution'],
            'codex_real_invoker_post_start_final_process_start_authorization' => ['real_invoker_final_process_start_authorization_id' => 'final-authorization'],
            'codex_real_invoker_final_process_start_authorization' => ['real_invoker_final_process_start_authorization_id' => 'final-authorization'],
            'codex_real_invoker_post_start_actual_process_start_rehearsal' => ['real_invoker_actual_process_start_rehearsal_id' => 'actual-rehearsal'],
            'codex_real_invoker_actual_process_start_rehearsal' => ['real_invoker_actual_process_start_rehearsal_id' => 'actual-rehearsal'],
            'codex_real_invoker_post_start_executor_fresh_release' => ['real_invoker_executor_fresh_release_id' => 'fresh-release'],
            'codex_real_invoker_executor_fresh_release' => ['real_invoker_executor_fresh_release_id' => 'fresh-release'],
            'codex_real_invoker_post_start_supervised_start_activation' => ['real_invoker_supervised_start_activation_id' => 'supervised-activation'],
            'codex_real_invoker_supervised_start_activation' => ['real_invoker_supervised_start_activation_id' => 'supervised-activation'],
            'codex_real_invoker_post_start_guarded_process_start' => ['real_invoker_guarded_process_start_id' => 'guarded-process-start'],
            'codex_real_invoker_guarded_process_start' => ['real_invoker_guarded_process_start_id' => 'guarded-process-start'],
        ];
    }
}
