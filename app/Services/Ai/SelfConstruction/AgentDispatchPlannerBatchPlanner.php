<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use App\Services\Ai\SelfConstruction\Support\HashesPayloadCanonically;

/**
 * Compose the Dispatch Planner sub-services into a single batch plan
 * that names planned dispatches without authorizing or executing any.
 *
 * Pure projection: never claims, never dispatches, never calls
 * providers, never spends tokens, never writes the evidence ledger.
 * The output is an advisory plan; the runtime path must be promoted
 * separately through Runtime Pilot Certification.
 */
final class AgentDispatchPlannerBatchPlanner
{
    use HashesPayloadCanonically;
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_dispatch_planner_batch_plan.v1';

    public const MODE = 'read_only_agent_dispatch_planner_batch_plan';

    public function __construct(
        private readonly AgentDispatchPlannerCandidateSelector $selector = new AgentDispatchPlannerCandidateSelector,
        private readonly AgentDispatchPlannerEligibilityEvaluator $eligibility = new AgentDispatchPlannerEligibilityEvaluator,
        private readonly AgentDispatchPlannerScopeConflictAnalyzer $scopeAnalyzer = new AgentDispatchPlannerScopeConflictAnalyzer,
        private readonly AgentDispatchPlannerGovernancePrecheck $governance = new AgentDispatchPlannerGovernancePrecheck,
        private readonly AgentDispatchPlannerDryRunReceiptBuilder $receipts = new AgentDispatchPlannerDryRunReceiptBuilder,
        private readonly AgentRuntimeRegistryQuarantineRepository $quarantine = new AgentRuntimeRegistryQuarantineRepository,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function plan(array $options = []): array
    {
        $now = CarbonImmutable::now()->toIso8601String();
        $batchId = (string) ($options['batch_id'] ?? Str::uuid());
        $persistReceipts = (bool) ($options['persist_receipts'] ?? false);
        $matchingPolicy = (string) ($options['matching_policy'] ?? 'capability_first');
        $maxDispatches = max(0, (int) ($options['max_dispatches'] ?? 10));

        $selection = $this->selector->select($options['selection'] ?? []);
        $tasks = (array) $selection['candidate_tasks'];
        $agents = (array) $selection['candidate_agents'];

        $quarantinedAgentIds = $this->quarantine->activeAgentIds();

        $eligibility = $this->eligibility->evaluate($tasks, $agents, [
            'quarantined_agents' => $quarantinedAgentIds,
        ]);
        $scopeAnalysis = $this->scopeAnalyzer->analyze($tasks, $options['scope_options'] ?? []);
        $governance = $this->governance->precheck($tasks, $agents, array_merge(
            ['quarantined_agents' => $quarantinedAgentIds, 'use_live_quarantine' => false],
            (array) ($options['governance'] ?? []),
        ));

        $scopeIndex = [];
        foreach ((array) $scopeAnalysis['analyses'] as $analysis) {
            $scopeIndex[$this->scalarString($analysis['task_packet_id'] ?? '')] = $analysis;
        }
        $taskGovIndex = [];
        foreach ((array) $governance['task_blockers'] as $blocker) {
            $taskGovIndex[$this->scalarString($blocker['task_packet_id'] ?? '')] = $blocker;
        }
        $agentGovIndex = [];
        foreach ((array) $governance['agent_blockers'] as $blocker) {
            $agentGovIndex[$this->scalarString($blocker['agent_id'] ?? '')] = $blocker;
        }
        $taskIndex = [];
        foreach ($tasks as $task) {
            $taskIndex[$this->scalarString($task['task_packet_id'] ?? '')] = $task;
        }

        $plannedDispatches = [];
        $blockedDispatches = [];
        $taskAssigned = [];
        $agentCapacityUsed = [];

        foreach ((array) $eligibility['evaluation_matrix'] as $row) {
            $taskId = $this->scalarString($row['task_packet_id'] ?? '');
            if ($taskId === '' || isset($taskAssigned[$taskId])) {
                continue;
            }
            $task = $taskIndex[$taskId] ?? [];
            $scopeRow = $scopeIndex[$taskId] ?? null;
            $taskGov = $taskGovIndex[$taskId] ?? null;

            $candidates = (array) ($row['evaluations'] ?? []);
            usort($candidates, function (array $a, array $b): int {
                $aSlots = (int) ($a['free_slots'] ?? 0);
                $bSlots = (int) ($b['free_slots'] ?? 0);
                $cmp = $bSlots <=> $aSlots;
                if ($cmp !== 0) {
                    return $cmp;
                }

                return strcmp($this->scalarString($a['agent_id'] ?? ''), $this->scalarString($b['agent_id'] ?? ''));
            });

            $chosen = null;
            $taskBlockers = [];

            if ($scopeRow !== null && ($scopeRow['conflict_status'] ?? '') === 'conflict') {
                $taskBlockers[] = 'scope_conflict';
            }
            if ($taskGov !== null && ! (bool) ($taskGov['is_governance_clear'] ?? false)) {
                foreach ((array) ($taskGov['reasons'] ?? []) as $r) {
                    $taskBlockers[] = 'governance:'.$this->scalarString($r);
                }
            }
            if ((array) $governance['global_blockers'] !== []) {
                foreach ((array) $governance['global_blockers'] as $r) {
                    $taskBlockers[] = 'governance:'.$this->scalarString($r);
                }
            }

            foreach ($candidates as $candidate) {
                $agentId = $this->scalarString($candidate['agent_id'] ?? '');
                if ($agentId === '') {
                    continue;
                }
                $agentGov = $agentGovIndex[$agentId] ?? null;
                $agentGovClear = $agentGov === null || (bool) ($agentGov['is_governance_clear'] ?? false);
                $alreadyUsed = (int) ($agentCapacityUsed[$agentId] ?? 0);
                $freeSlots = (int) ($candidate['free_slots'] ?? 0);

                if (! (bool) ($candidate['is_eligible'] ?? false)) {
                    continue;
                }
                if (! $agentGovClear) {
                    continue;
                }
                if ($alreadyUsed >= max(1, $freeSlots)) {
                    continue;
                }

                $chosen = $candidate;
                $agentCapacityUsed[$agentId] = $alreadyUsed + 1;
                break;
            }

            if ($chosen === null) {
                $blockedDispatches[] = [
                    'task_packet_id' => $taskId,
                    'reasons' => array_values(array_unique(array_merge(
                        $taskBlockers,
                        ['no_eligible_agent']
                    ))),
                ];

                continue;
            }
            if ($taskBlockers !== []) {
                $blockedDispatches[] = [
                    'task_packet_id' => $taskId,
                    'agent_id' => $this->scalarString($chosen['agent_id'] ?? ''),
                    'reasons' => array_values(array_unique($taskBlockers)),
                ];

                continue;
            }

            $plannedDispatch = [
                'task_packet_id' => $taskId,
                'task_packet_hash' => $this->scalarString($task['task_packet_hash'] ?? ''),
                'agent_id' => $this->scalarString($chosen['agent_id'] ?? ''),
                'risk_level' => $this->scalarString($task['risk_level'] ?? 'low'),
                'workspace_policy' => $this->scalarString($task['workspace_policy'] ?? 'none'),
                'requires_lease' => (bool) ($task['requires_lease'] ?? false),
                'dry_run_only' => (bool) ($task['dry_run_only'] ?? false),
                'scope_lock' => (array) ($task['scope_lock'] ?? []),
                'evidence_refs' => $this->stringList((array) ($task['evidence_refs'] ?? [])),
                'matching_policy' => $matchingPolicy,
                'capability_match' => (array) ($chosen['capability_match'] ?? []),
            ];
            $receiptResult = null;
            if ($persistReceipts) {
                $receiptResult = $this->receipts->build($plannedDispatch, [
                    'matching_policy' => $matchingPolicy,
                ]);
                if (($receiptResult['status'] ?? '') === 'ok') {
                    $plannedDispatch['receipt_id'] = $this->scalarString($receiptResult['receipt_id'] ?? '');
                    $plannedDispatch['receipt_hash'] = $this->scalarString($receiptResult['receipt_hash'] ?? '');
                }
            }

            $plannedDispatches[] = $plannedDispatch;
            $taskAssigned[$taskId] = true;

            if ($maxDispatches > 0 && count($plannedDispatches) >= $maxDispatches) {
                break;
            }
        }

        foreach ($tasks as $task) {
            $taskId = $this->scalarString($task['task_packet_id'] ?? '');
            if ($taskId === '' || isset($taskAssigned[$taskId])) {
                continue;
            }
            $already = array_filter($blockedDispatches, fn (array $b): bool => $this->scalarString($b['task_packet_id'] ?? '') === $taskId);
            if ($already !== []) {
                continue;
            }
            $blockedDispatches[] = [
                'task_packet_id' => $taskId,
                'reasons' => ['no_eligible_agent'],
            ];
        }

        $status = $plannedDispatches !== [] && (array) $governance['global_blockers'] === []
            ? 'planned'
            : 'blocked';

        $hashPayload = [
            'selection_hash' => (string) ($selection['selection_hash'] ?? ''),
            'eligibility_hash' => (string) ($eligibility['eligibility_hash'] ?? ''),
            'scope_hash' => (string) ($scopeAnalysis['analysis_hash'] ?? ''),
            'governance_hash' => (string) ($governance['governance_hash'] ?? ''),
            'matching_policy' => $matchingPolicy,
            'planned' => array_map(fn (array $p): array => [
                'task_packet_id' => $this->scalarString($p['task_packet_id'] ?? ''),
                'agent_id' => $this->scalarString($p['agent_id'] ?? ''),
            ], $plannedDispatches),
            'blocked' => array_map(fn (array $b): array => [
                'task_packet_id' => $this->scalarString($b['task_packet_id'] ?? ''),
                'reasons' => $this->stringList((array) ($b['reasons'] ?? [])),
            ], $blockedDispatches),
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'batch_id' => $batchId,
            'planned_at' => $now,
            'status' => $status,
            'matching_policy' => $matchingPolicy,
            'selection' => $selection,
            'eligibility' => $eligibility,
            'scope_analysis' => $scopeAnalysis,
            'governance' => $governance,
            'planned_dispatches' => $plannedDispatches,
            'blocked_dispatches' => $blockedDispatches,
            'planned_dispatch_count' => count($plannedDispatches),
            'blocked_dispatch_count' => count($blockedDispatches),
            'global_blockers' => (array) $governance['global_blockers'],
            'batch_hash' => $this->stableHash($hashPayload),
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'claim_real_allowed' => false,
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function runtimeFlags(): array
    {
        return [
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'claim_real_allowed' => false,
        ];
    }


    private function scalarString(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return trim((string) $value);
        }

        return hash('sha256', (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $string = $this->scalarString($value);
            if ($string !== '') {
                $out[] = $string;
            }
        }

        return array_values($out);
    }
}
