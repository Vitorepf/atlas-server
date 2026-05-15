<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Read-only batch runner that calls every certification status
 * projection through the readiness service (no shell, no subprocess,
 * no runtime invocation). Returns a per-status report and aggregate
 * counts.
 *
 * Read-only: never starts processes, never calls Codex CLI/app, never
 * spawns subprocesses, never invokes adapters, never dispatches work,
 * never spends tokens, never advances the next required slice, never
 * enables self-programming, never writes the ledger.
 */
final class AgentControlPlaneCertificationStatusBatchService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_certification_status_batch.v1';

    public const MODE = 'read_only_agent_control_plane_certification_status_batch';

    public const STATUS_PROJECTIONS = [
        'chain_integrity_certification' => 'agentControlPlaneChainIntegrityCertificationStatus',
        'deterministic_chain_replay' => 'agentControlPlaneDeterministicChainReplayStatus',
        'replay_snapshot_store' => 'agentControlPlaneReplaySnapshotStoreStatus',
        'replay_diff' => 'agentControlPlaneReplayDiffStatus',
        'macro_sprint_promotion_gate' => 'agentControlPlaneMacroSprintPromotionGateStatus',
        'certification_baseline' => 'agentControlPlaneCertificationBaselineStatus',
        'certification_scenario_simulator' => 'agentControlPlaneCertificationScenarioSimulatorStatus',
        'release_dossier' => 'agentControlPlaneReleaseDossierStatus',
        'certification_mutation_guard' => 'agentControlPlaneCertificationMutationGuardStatus',
        'certification_evidence_query' => 'agentControlPlaneCertificationEvidenceQueryStatus',
        'certification_scenario_corpus' => 'agentControlPlaneCertificationScenarioCorpusStatus',
        'certification_fuzz_harness' => 'agentControlPlaneCertificationFuzzHarnessStatus',
        'multi_snapshot_comparison' => 'agentControlPlaneMultiSnapshotComparisonStatus',
        'release_dossier_exporter' => 'agentControlPlaneReleaseDossierExporterStatus',
        'certification_coverage_report' => 'agentControlPlaneCertificationCoverageReportStatus',
        'certification_status_batch' => 'agentControlPlaneCertificationStatusBatchSelfStatus',
        'runtime_evidence_journal' => 'agentControlPlaneRuntimeEvidenceJournalStatus',
        'execution_workspace_runtime' => 'agentControlPlaneExecutionWorkspaceRuntimeStatus',
        'governance_approval_runtime' => 'agentControlPlaneGovernanceApprovalRuntimeStatus',
        'automatic_cost_import_runtime' => 'agentControlPlaneAutomaticCostImportRuntimeStatus',
        'automatic_work_product_collection_runtime' => 'agentControlPlaneAutomaticWorkProductCollectionRuntimeStatus',
        'adapter_execution_runtime_boundary' => 'agentControlPlaneAdapterExecutionRuntimeBoundaryStatus',
        'dispatch_planner_runtime' => 'agentControlPlaneDispatchPlannerRuntimeStatus',
        'validation_gate_runtime' => 'agentControlPlaneValidationGateRuntimeStatus',
        'merge_review_runtime' => 'agentControlPlaneMergeReviewRuntimeStatus',
        'task_packet_builder' => 'agentControlPlaneTaskPacketBuilderStatus',
        'claim_lease_simulator' => 'agentControlPlaneClaimLeaseSimulatorStatus',
        'scope_lock_planner' => 'agentControlPlaneScopeLockPlannerStatus',
        'evidence_ledger_dry_run' => 'agentControlPlaneEvidenceLedgerDryRunStatus',
        'continuation_summary_builder' => 'agentControlPlaneContinuationSummaryBuilderStatus',
        'work_product_manifest_planner' => 'agentControlPlaneWorkProductManifestPlannerStatus',
        'cost_import_dry_run' => 'agentControlPlaneCostImportDryRunStatus',
        'multi_agent_parallelism_planner' => 'agentControlPlaneMultiAgentParallelismPlannerStatus',
        'runtime_pilot_orchestrator' => 'agentControlPlaneRuntimePilotOrchestratorStatus',
        'runtime_pilot_certification' => 'agentControlPlaneRuntimePilotCertificationStatus',
        'task_packet_queue' => 'agentControlPlaneTaskPacketQueueStatus',
        'claim_lease_runtime' => 'agentControlPlaneClaimLeaseRuntimeStatus',
        'scope_lock_runtime_validator' => 'agentControlPlaneScopeLockRuntimeValidatorStatus',
        'task_queue_orchestrator' => 'agentControlPlaneTaskQueueOrchestratorStatus',
        'task_queue_lease_certification' => 'agentControlPlaneTaskQueueLeaseCertificationStatus',
        'agent_runtime_registry' => 'agentControlPlaneAgentRuntimeRegistryStatus',
        'agent_runtime_registry_heartbeat' => 'agentControlPlaneAgentRuntimeRegistryHeartbeatStatus',
        'agent_runtime_registry_capability_catalog' => 'agentControlPlaneAgentRuntimeRegistryCapabilityCatalogStatus',
        'agent_runtime_registry_availability' => 'agentControlPlaneAgentRuntimeRegistryAvailabilityStatus',
        'agent_runtime_registry_task_matcher' => 'agentControlPlaneAgentRuntimeRegistryTaskMatcherStatus',
        'agent_runtime_registry_load_balancing' => 'agentControlPlaneAgentRuntimeRegistryLoadBalancingStatus',
        'agent_runtime_registry_quarantine' => 'agentControlPlaneAgentRuntimeRegistryQuarantineStatus',
        'agent_runtime_registry_handoff' => 'agentControlPlaneAgentRuntimeRegistryHandoffStatus',
        'agent_runtime_registry_orchestrator' => 'agentControlPlaneAgentRuntimeRegistryOrchestratorStatus',
        'agent_runtime_registry_certification' => 'agentControlPlaneAgentRuntimeRegistryCertificationStatus',
    ];

    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $readiness,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function run(array $options = []): array
    {
        $skipBatchSelf = (bool) ($options['skip_batch_self'] ?? true);
        $onlyKeys = array_values(array_filter(
            (array) ($options['only_keys'] ?? []),
            static fn (mixed $key): bool => is_string($key) && $key !== '',
        ));
        $statuses = [];
        $passed = 0;
        $failed = 0;
        $blockedStatuses = ['blocked', 'failed', 'persist_failed'];

        foreach (self::STATUS_PROJECTIONS as $key => $method) {
            if ($onlyKeys !== [] && ! in_array($key, $onlyKeys, true)) {
                continue;
            }
            if ($key === 'certification_status_batch' && $skipBatchSelf) {
                continue;
            }
            if (! method_exists($this->readiness, $method)) {
                $statuses[] = [
                    'key' => $key,
                    'method' => $method,
                    'status' => 'method_missing',
                    'passed' => false,
                ];
                $failed++;

                continue;
            }

            try {
                $payload = $this->readiness->{$method}($this->projectionOptions($key, $options));
                $statusValue = (string) data_get($payload, 'status', 'unknown');
                $isPassed = ! in_array($statusValue, $blockedStatuses, true);
                $statuses[] = [
                    'key' => $key,
                    'method' => $method,
                    'status' => $statusValue,
                    'passed' => $isPassed,
                ];
                if ($isPassed) {
                    $passed++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $statuses[] = [
                    'key' => $key,
                    'method' => $method,
                    'status' => 'exception',
                    'passed' => false,
                    'error' => $e->getMessage(),
                ];
                $failed++;
            }
        }

        $checked = count($statuses);
        $batchStatus = $failed === 0 ? 'passed' : 'failed';

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'batch_id' => (string) Str::uuid(),
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'status' => $batchStatus,
            'read_only' => true,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'external_provider_call' => false,
            'token_spend' => false,
            'process_started' => false,
            'self_programming_allowed' => false,
            'completion_claim_allowed' => false,
            'checked_count' => $checked,
            'passed_count' => $passed,
            'failed_count' => $failed,
            'statuses' => $statuses,
            'options_applied' => [
                'skip_batch_self' => $skipBatchSelf,
                'only_keys' => $onlyKeys,
            ],
            'non_execution_guarantees' => [
                'status_batch_does_not_start_codex',
                'status_batch_does_not_call_codex_cli_or_app',
                'status_batch_does_not_spawn_subprocess',
                'status_batch_does_not_invoke_adapter',
                'status_batch_does_not_execute_adapter',
                'status_batch_does_not_call_provider',
                'status_batch_does_not_dispatch_work',
                'status_batch_does_not_spend_tokens',
                'status_batch_does_not_enable_self_programming',
                'status_batch_does_not_write_ledger',
                'status_batch_does_not_mutate_pointer',
                'status_batch_does_not_promote_completion_claim',
                'status_batch_does_not_invoke_shell',
            ],
            'human_summary' => $batchStatus === 'passed'
                ? sprintf('Status batch passed for all %d projections.', $checked)
                : sprintf('Status batch failed: %d of %d projections were not in a healthy state.', $failed, $checked),
        ];

        $payload['batch_hash'] = $this->stableHash($this->normalizeForBatchHash($payload));

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeForBatchHash(array $payload): array
    {
        $clone = $payload;
        unset($clone['batch_id'], $clone['generated_at'], $clone['batch_hash']);

        return $this->recursivelyKsort($clone);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function projectionOptions(string $key, array $options): array
    {
        if ($key !== 'release_dossier') {
            return [];
        }

        return [
            // The batch already verifies the full scenario simulator as its
            // own projection. Keep the dossier pass focused on composing the
            // current release evidence instead of running the synthetic suite
            // twice in the same batch.
            'skip_simulator' => ! (bool) ($options['full_release_dossier_simulator'] ?? false),
        ];
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<mixed, mixed>
     */
    private function recursivelyKsort(array $value): array
    {
        $isAssoc = $value !== [] && array_keys($value) !== range(0, count($value) - 1);
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->recursivelyKsort($entry);
            }
        }
        if ($isAssoc) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @param  array<mixed, mixed>  $payload
     */
    private function stableHash(array $payload): string
    {
        $payload = $this->recursivelyKsort($payload);

        return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
