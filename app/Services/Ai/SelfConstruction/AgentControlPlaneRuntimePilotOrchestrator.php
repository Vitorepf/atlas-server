<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Orchestrates the full Agent Control Plane Runtime Pilot dry-run by
 * driving Task Packet Builder → Claim/Lease Simulator → Scope Lock Planner
 * → Evidence Ledger Dry-Run → Continuation Summary Builder → Work Product
 * Manifest Planner → Cost Import Dry-Run → Multi-Agent Parallelism Planner,
 * and stitching in chain integrity, replay and certification observatory
 * summaries.
 *
 * Read-only: never starts processes, never calls Codex CLI/app, never
 * spawns subprocesses, never invokes adapters, never dispatches work,
 * never spends tokens, never advances the next required slice, never
 * enables self-programming, never writes the ledger.
 */
final class AgentControlPlaneRuntimePilotOrchestrator
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_runtime_pilot_orchestrator.v1';

    public const MODE = 'read_only_agent_control_plane_runtime_pilot_orchestrator';

    public function __construct(
        private readonly AgentControlPlaneTaskPacketBuilder $taskPacketBuilder,
        private readonly AgentControlPlaneClaimLeaseSimulator $claimLeaseSimulator,
        private readonly AgentControlPlaneScopeLockPlanner $scopeLockPlanner,
        private readonly AgentControlPlaneEvidenceLedgerDryRun $evidenceLedger,
        private readonly AgentControlPlaneContinuationSummaryBuilder $continuationSummaryBuilder,
        private readonly AgentControlPlaneWorkProductManifestPlanner $workProductPlanner,
        private readonly AgentControlPlaneCostImportDryRun $costImport,
        private readonly AgentControlPlaneMultiAgentParallelismPlanner $multiAgentPlanner,
        private readonly AgentControlPlaneChainIntegrityAuditService $audit,
        private readonly AgentControlPlaneDeterministicChainReplayService $replay,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function run(array $input = []): array
    {
        $primary = (array) ($input['task_packet'] ?? $input);
        $additional = (array) ($input['additional_task_packets'] ?? []);
        $options = (array) ($input['options'] ?? []);

        $taskPacket = $this->taskPacketBuilder->build($primary);
        $claimLease = $this->claimLeaseSimulator->simulate($taskPacket, (array) ($options['claim_lease'] ?? []));
        $scopeLock = $this->scopeLockPlanner->plan($taskPacket, $claimLease, (array) ($options['scope_lock'] ?? []));
        $evidence = $this->evidenceLedger->plan($taskPacket, $scopeLock, (array) ($options['evidence'] ?? []));
        $continuation = $this->continuationSummaryBuilder->build($taskPacket, $evidence, (array) ($options['continuation'] ?? []));
        $workProduct = $this->workProductPlanner->plan($taskPacket, $scopeLock, (array) ($options['work_product'] ?? []));
        $cost = $this->costImport->plan($taskPacket, (array) ($options['cost_import'] ?? []));

        $allPackets = array_merge([$taskPacket], array_map(
            fn (array $entry): array => $this->taskPacketBuilder->build($entry),
            $additional,
        ));
        $parallelism = $this->multiAgentPlanner->plan($allPackets, (array) ($options['parallelism'] ?? []));

        $chainIntegrity = $this->summarizeAudit();
        $replay = $this->summarizeReplay();
        $certificationObservatory = $this->summarizeCertificationObservatory();

        $blockers = [];
        $warnings = [];
        foreach ([
            'task_packet' => $taskPacket,
            'claim_lease' => $claimLease,
            'scope_lock' => $scopeLock,
            'evidence' => $evidence,
            'continuation' => $continuation,
            'work_product' => $workProduct,
            'cost_import' => $cost,
            'parallelism' => $parallelism,
        ] as $key => $component) {
            $reasons = (array) data_get($component, 'blocking_reasons', []);
            foreach ($reasons as $reason) {
                $blockers[] = ['component' => $key, 'reason' => (string) $reason];
            }
        }

        if (! (bool) data_get($chainIntegrity, 'invariants_all_true', false)) {
            $warnings[] = ['component' => 'chain_integrity', 'reason' => 'invariants_not_all_true'];
        }
        if (! (bool) data_get($chainIntegrity, 'runtime_safety_all_false', false)) {
            $blockers[] = ['component' => 'chain_integrity', 'reason' => 'runtime_safety_not_all_false'];
        }
        if (! (bool) data_get($replay, 'invariants_all_true', false)) {
            $warnings[] = ['component' => 'replay', 'reason' => 'invariants_not_all_true'];
        }

        $pilotStatus = $blockers === [] ? 'available' : 'blocked';

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'pilot_id' => (string) Str::uuid(),
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'status' => $pilotStatus,
            'task_packet' => $taskPacket,
            'claim_lease_simulation' => $claimLease,
            'scope_lock_plan' => $scopeLock,
            'evidence_ledger_dry_run' => $evidence,
            'continuation_summary' => $continuation,
            'work_product_manifest_plan' => $workProduct,
            'cost_import_dry_run' => $cost,
            'multi_agent_parallelism_plan' => $parallelism,
            'chain_integrity_summary' => $chainIntegrity,
            'replay_summary' => $replay,
            'certification_observatory_summary' => $certificationObservatory,
            'blockers' => $blockers,
            'blocker_count' => count($blockers),
            'warnings' => $warnings,
            'read_only' => true,
            'runtime_disabled' => true,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'completion_claim_allowed' => false,
            'non_execution_guarantees' => [
                'runtime_pilot_orchestrator_does_not_start_codex',
                'runtime_pilot_orchestrator_does_not_call_codex_cli_or_app',
                'runtime_pilot_orchestrator_does_not_spawn_subprocess',
                'runtime_pilot_orchestrator_does_not_invoke_adapter',
                'runtime_pilot_orchestrator_does_not_call_provider',
                'runtime_pilot_orchestrator_does_not_dispatch_work',
                'runtime_pilot_orchestrator_does_not_spend_tokens',
                'runtime_pilot_orchestrator_does_not_enable_self_programming',
                'runtime_pilot_orchestrator_does_not_write_ledger',
                'runtime_pilot_orchestrator_does_not_mutate_pointer',
                'runtime_pilot_orchestrator_does_not_promote_completion_claim',
            ],
            'human_summary' => sprintf(
                'Runtime pilot %s for %d packet(s); %d blocker(s).',
                $pilotStatus,
                count($allPackets),
                count($blockers),
            ),
        ];

        $payload['pilot_hash'] = $this->stableHash($this->normalizeForHash($payload));
        $payload['pilot_hash'] = $this->stableHash([
            'task_packet_hash' => (string) data_get($taskPacket, 'task_packet_hash', ''),
            'scope_hash' => (string) data_get($taskPacket, 'scope_hash', ''),
            'acceptance_hash' => (string) data_get($taskPacket, 'acceptance_hash', ''),
            'scope_lock_plan_hash' => (string) data_get($scopeLock, 'scope_lock_plan_hash', ''),
            'evidence_hash' => (string) data_get($evidence, 'evidence_hash', ''),
            'continuation_hash' => (string) data_get($continuation, 'continuation_hash', ''),
            'work_product_manifest_hash' => (string) data_get($workProduct, 'work_product_manifest_hash', ''),
            'cost_import_plan_hash' => (string) data_get($cost, 'cost_import_plan_hash', ''),
            'parallelism_hash' => (string) data_get($parallelism, 'parallelism_hash', ''),
            'pilot_status' => $pilotStatus,
        ]);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function summarizeAudit(): array
    {
        try {
            $audit = $this->audit->audit();

            return [
                'audit_hash' => (string) data_get($audit, 'agent_control_plane_chain_integrity_certification_hash', ''),
                'chain_length' => (int) data_get($audit, 'chain_length', 0),
                'violation_count' => count((array) data_get($audit, 'violations', [])),
                'warning_count' => count((array) data_get($audit, 'warnings', [])),
                'invariants_all_true' => (bool) data_get($audit, 'invariants_all_true', false),
                'runtime_safety_all_false' => (bool) data_get($audit, 'runtime_safety.runtime_safety_all_false', false),
                'current_next_required_slice' => (string) data_get($audit, 'current_next_required_slice', ''),
            ];
        } catch (\Throwable $e) {
            return [
                'error' => 'audit_unavailable',
                'message' => $e->getMessage(),
                'invariants_all_true' => false,
                'runtime_safety_all_false' => true,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function summarizeReplay(): array
    {
        try {
            $replay = $this->replay->replay();

            return [
                'replay_hash' => (string) data_get($replay, 'replay_hash', ''),
                'deterministic_replay_hash' => (string) data_get($replay, 'deterministic_replay_hash', ''),
                'proof_bundle_hash' => (string) data_get($replay, 'proof_bundle_hash', ''),
                'replayed_slice_count' => (int) data_get($replay, 'replayed_slice_count', 0),
                'replayed_edge_count' => (int) data_get($replay, 'replayed_edge_count', 0),
                'violation_count' => count((array) data_get($replay, 'violations', [])),
                'invariants_all_true' => (bool) data_get($replay, 'invariants_all_true', false),
                'runtime_safety_all_false' => (bool) data_get($replay, 'runtime_safety.runtime_safety_all_false', false),
            ];
        } catch (\Throwable $e) {
            return [
                'error' => 'replay_unavailable',
                'message' => $e->getMessage(),
                'invariants_all_true' => false,
                'runtime_safety_all_false' => true,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function summarizeCertificationObservatory(): array
    {
        return [
            'workbench_layer' => 'certification',
            'observatory_layer_includes' => [
                'chain_integrity_certification',
                'deterministic_chain_replay',
                'replay_snapshot_store',
                'replay_diff',
                'macro_sprint_promotion_gate',
                'certification_baseline',
                'certification_scenario_simulator',
                'release_dossier',
                'certification_mutation_guard',
                'certification_evidence_query',
                'certification_scenario_corpus',
                'certification_fuzz_harness',
                'multi_snapshot_comparison',
                'release_dossier_exporter',
                'certification_coverage_report',
                'certification_status_batch',
            ],
            'runtime_pilot_simulator_integration' => 'read_only_pilot_runtime',
            'runtime_pilot_simulator_runtime_enabled' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeForHash(array $payload): array
    {
        $clone = $payload;
        unset($clone['pilot_id'], $clone['generated_at'], $clone['pilot_hash'], $clone['human_summary']);

        $idsToStrip = [
            'task_packet_id', 'claim_id', 'lease_id', 'scope_lock_plan_id',
            'evidence_plan_id', 'evidence_plan_hash', 'continuation_summary_id',
            'manifest_plan_id', 'cost_import_plan_id', 'parallelism_plan_id',
            'generated_at', 'human_summary',
            'claim_hash', 'lease_hash', 'simulation_hash',
        ];
        foreach (['task_packet', 'claim_lease_simulation', 'scope_lock_plan', 'evidence_ledger_dry_run', 'continuation_summary', 'work_product_manifest_plan', 'cost_import_dry_run', 'multi_agent_parallelism_plan'] as $key) {
            if (isset($clone[$key]) && is_array($clone[$key])) {
                foreach ($idsToStrip as $id) {
                    unset($clone[$key][$id]);
                }
            }
        }
        if (isset($clone['multi_agent_parallelism_plan']['lease_plan']) && is_array($clone['multi_agent_parallelism_plan']['lease_plan'])) {
            $clone['multi_agent_parallelism_plan']['lease_plan'] = array_map(static function ($entry) {
                if (is_array($entry)) {
                    unset($entry['lease_id_simulated']);
                }

                return $entry;
            }, $clone['multi_agent_parallelism_plan']['lease_plan']);
        }
        if (isset($clone['chain_integrity_summary']) && is_array($clone['chain_integrity_summary'])) {
            unset($clone['chain_integrity_summary']['audit_hash']);
        }
        if (isset($clone['replay_summary']) && is_array($clone['replay_summary'])) {
            unset($clone['replay_summary']['replay_hash'], $clone['replay_summary']['deterministic_replay_hash'], $clone['replay_summary']['proof_bundle_hash']);
        }

        return $this->recursivelyKsort($clone);
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
