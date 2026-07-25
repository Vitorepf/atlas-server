<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticWorkcell\Support;

use App\Services\Ai\AgenticWorkcell\AtlasAgenticWorkcellRuntimeService;
use App\Services\Ai\EngineeringKernel\EngineeringRoleRoster;
use App\Services\Ai\EngineeringKernel\ExecutionOrder;
use App\Services\Ai\EngineeringKernel\PressureLayerGuards;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\AiValueNormalizer;
use Illuminate\Support\Collection;

/**
 * Pure design-artifact / admission / control-plane helpers for AAWR workcell design.
 *
 * Extracted from AtlasAgenticWorkcellRuntimeService private pure residual:
 * org design, pressure-layer advisory roster, task graph, context packs,
 * execution schedule, verification plan, evidence ledger, memory packet,
 * counterfactual replay, learning policy, control-plane summary, workcell
 * admission receipt, learning candidates, blocked envelope, and collection
 * average/counts helpers.
 *
 * No I/O, no DI, no provider calls, no clock, no filesystem, no DB.
 */
final class AgenticWorkcellDesignArtifactsSupport
{
    private function __construct()
    {
    }

    /**
     * @return array<string,mixed>
     */
    public static function orgDesign(string $topology, string $domain, string $flowId, int $complexity, int $risk, array $input): array
    {
        return [
            'schema_version' => 'atlas.agentic_workcell.org_design.v1',
            'topology' => $topology,
            'domain' => $domain,
            'flow_id' => $flowId,
            'complexity_score' => $complexity,
            'risk_score' => $risk,
            'organizational_principles' => [
                'minimal_context_per_role',
                'explicit_ownership_boundaries',
                'parallelism_only_when_non_overlapping',
                'independent_verification_required',
                'evidence_before_completion',
                'outcome_learning_after_close',
            ],
            'operator_review_required' => $risk >= 8 || in_array($domain, ['finance', 'strategy'], true),
            'topology_reason' => 'selected_by_complexity_risk_domain_and_areg_budget',
            'org_design_hash' => MissionCanonicalHash::sha256([$topology, $domain, $flowId, $complexity, $risk, $input['source'] ?? null]),
        ];
    }

    /**
     * Cognitive Pressure Layer advisory guards (read-only; not counted execution roster).
     *
     * @return list<array<string,mixed>>
     */
    public static function pressureLayerAdvisoryRoster(string $domain, string $flowId): array
    {
        return collect(PressureLayerGuards::advisoryRoles())
            ->map(fn (array $guard): array => [
                'role_id' => (string) $guard['role_id'],
                'advisory' => true,
                'read_only' => true,
                'tier' => (string) ($guard['tier'] ?? 'width'),
                'domain' => $domain,
                'flow_id' => $flowId,
                'prevents' => (string) ($guard['prevents'] ?? ''),
                'signal' => (string) ($guard['signal'] ?? ''),
                'forbidden_actions' => ['mutate_files', 'spawn_provider_directly', 'fabricate_verdict'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string,mixed>>  $roles
     * @return array<string,mixed>
     */
    public static function taskGraph(string $objective, string $topology, string $domain, string $flowId, array $roles, array $input): array
    {
        $leadTaskId = 'task_01_'.(string) data_get($roles, '0.role_id', 'lead_synthesizer');

        $tasks = collect($roles)->map(function (array $role, int $index) use ($objective, $leadTaskId): array {
            $roleId = (string) $role['role_id'];

            return [
                'task_id' => 'task_'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT).'_'.$roleId,
                'role_id' => $roleId,
                'objective_hash' => MissionCanonicalHash::sha256([$objective, $roleId]),
                'depends_on' => AgenticWorkcellRoleContractSupport::taskDependencies($roleId, $index, $leadTaskId),
                'expected_artifacts' => AgenticWorkcellRoleContractSupport::expectedArtifacts($roleId),
                'acceptance_criteria' => ['output_contract_satisfied', 'evidence_refs_declared', 'no_scope_overreach'],
            ];
        })->values();

        return [
            'schema_version' => 'atlas.agentic_workcell.task_graph.v1',
            'topology' => $topology,
            'domain' => $domain,
            'flow_id' => $flowId,
            'tasks' => $tasks->all(),
            'dependency_edges' => $tasks->flatMap(fn (array $task): array => collect($task['depends_on'])->map(fn (string $dep): array => ['from' => $dep, 'to' => $task['task_id']])->all())->values()->all(),
            'conflict_policy' => [
                'parallel_tasks_must_have_disjoint_write_scope' => true,
                'shared_files_require_serial_merge_or_single_owner' => true,
                'reviewers_are_read_only' => true,
            ],
            'task_graph_hash' => MissionCanonicalHash::sha256([$topology, $domain, $flowId, $tasks->pluck('task_id')->all()]),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $roles
     * @param  list<string>  $contextRefs
     * @param  list<string>  $evidenceRefs
     * @return array<string,mixed>
     */
    public static function contextPacks(string $objective, string $domain, string $flowId, array $roles, array $contextRefs, array $evidenceRefs, array $input): array
    {
        $packs = collect($roles)->map(fn (array $role): array => [
            'schema_version' => 'atlas.agentic_workcell.context_pack.v1',
            'role_id' => $role['role_id'],
            'objective_hash' => MissionCanonicalHash::sha256([$objective, $role['role_id']]),
            'included_context_refs' => array_slice($contextRefs, 0, 12),
            'evidence_refs' => $evidenceRefs,
            'must_keep' => [
                ['kind' => 'goal', 'value_hash' => MissionCanonicalHash::sha256(['goal' => $objective])],
                ['kind' => 'domain', 'value' => $domain],
                ['kind' => 'flow_id', 'value' => $flowId],
                ['kind' => 'role_boundary', 'value' => $role['role_id']],
            ],
            'forbidden_context' => ['unbounded_chat_history', 'raw_provider_transcript', 'irrelevant_tool_manuals'],
            'request_more_context_contract' => [
                'requires_reason' => true,
                'requires_expected_value' => true,
                'approved_by' => 'AREG',
            ],
        ])->values()->all();

        return [
            'schema_version' => 'atlas.agentic_workcell.context_packs.v1',
            'packs' => $packs,
            'pack_count' => count($packs),
            'context_isolation_required' => true,
            'context_packs_hash' => MissionCanonicalHash::sha256([$domain, $flowId, $packs]),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $roles
     * @return array<string,mixed>
     */
    public static function executionSchedule(string $topology, array $roles, array $taskGraph, int $risk): array
    {
        $tasks = collect((array) ($taskGraph['tasks'] ?? []));
        $parallel = ! in_array($topology, ['solo_agent', 'critic_chain'], true);
        $groups = $parallel
            ? [
                ['group_id' => 'g1_context_and_design', 'tasks' => $tasks->take(max(1, min(3, $tasks->count())))->pluck('task_id')->all()],
                ['group_id' => 'g2_execution_or_analysis', 'tasks' => $tasks->slice(3)->take(max(1, $tasks->count() - 5))->pluck('task_id')->all()],
                ['group_id' => 'g3_verification_and_synthesis', 'tasks' => $tasks->slice(max(0, $tasks->count() - 2))->pluck('task_id')->all()],
            ]
            : [['group_id' => 'g1_serial', 'tasks' => $tasks->pluck('task_id')->all()]];

        return [
            'schema_version' => 'atlas.agentic_workcell.execution_schedule.v1',
            'topology' => $topology,
            'parallelism_allowed' => $parallel,
            'max_parallel_agents' => $parallel ? min(8, max(2, count($roles) - 2)) : 1,
            'groups' => array_values(array_filter($groups, fn (array $group): bool => $group['tasks'] !== [])),
            'coordination_gates' => ['scope_lock_before_work', 'merge_after_verification', 'lead_synthesis_after_evidence'],
            'risk_mode' => $risk >= 8 ? 'strict' : 'standard',
            'schedule_hash' => MissionCanonicalHash::sha256([$topology, $roles, $groups, $risk]),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $roles
     * @return array<string,mixed>
     */
    public static function verificationPlan(string $topology, string $domain, string $flowId, int $risk, array $roles): array
    {
        $checks = match ($domain) {
            'programming' => ['focused_tests', 'diff_review', 'ownership_overlap_check', 'receipt_check'],
            'research' => ['source_coverage', 'counter_source_review', 'claim_uncertainty_audit'],
            'finance' => ['freshness_check', 'risk_disclosure', 'no_external_action'],
            'strategy' => ['assumption_check', 'red_blue_adjudication', 'operator_review'],
            default => ['response_shape_check', 'evidence_refs_check'],
        };
        if ($risk >= 8) {
            $checks[] = 'policy_gate';
            $checks[] = 'adversarial_verification';
        }

        return [
            'schema_version' => 'atlas.agentic_workcell.verification_plan.v1',
            'independent_verifier_required' => true,
            'critic_required' => ! in_array($topology, ['solo_agent'], true) || $risk >= 6,
            'evidence_auditor_required' => count($roles) >= 4 || $risk >= 7,
            'checks' => array_values(array_unique($checks)),
            'completion_allowed_without_evidence' => false,
            'verification_hash' => MissionCanonicalHash::sha256([$topology, $domain, $flowId, $risk, $checks]),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $roles
     * @param  list<string>  $evidenceRefs
     * @return array<string,mixed>
     */
    public static function evidenceLedger(string $objective, array $roles, array $taskGraph, array $evidenceRefs): array
    {
        return [
            'schema_version' => 'atlas.agentic_workcell.evidence_ledger.v1',
            'required_receipts' => ['workcell_design', 'role_context_pack', 'task_output', 'verification_result', 'final_synthesis'],
            'initial_evidence_refs' => $evidenceRefs,
            'role_receipt_requirements' => collect($roles)->mapWithKeys(fn (array $role): array => [(string) $role['role_id'] => ['context_pack_hash', 'output_hash', 'evidence_refs']])->all(),
            'task_count' => count((array) ($taskGraph['tasks'] ?? [])),
            'objective_hash' => MissionCanonicalHash::sha256(['objective' => $objective]),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $roles
     * @return array<string,mixed>
     */
    public static function memoryPacket(string $objective, string $domain, string $flowId, string $topology, array $roles): array
    {
        return [
            'schema_version' => 'atlas.agentic_workcell.memory_packet.v1',
            'must_persist' => ['goal', 'topology', 'role_roster', 'task_graph', 'verification_plan', 'blockers', 'outcome'],
            'must_not_persist_without_review' => ['provider_raw_output', 'unverified_claim', 'temporary_speculation'],
            'scope' => $domain.':'.$flowId,
            'topology' => $topology,
            'role_ids' => collect($roles)->pluck('role_id')->values()->all(),
            'objective_hash' => MissionCanonicalHash::sha256(['objective' => $objective]),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function counterfactualReplay(string $objective, string $domain, string $flowId, string $selectedTopology, int $complexity, int $risk): array
    {
        $candidates = collect(AgenticWorkcellTopologyPolicySupport::TOPOLOGIES)
            ->map(fn (string $topology): array => AgenticWorkcellTopologyPolicySupport::scoreTopology($topology, $selectedTopology, $domain, $flowId, $complexity, $risk))
            ->sortByDesc('utility_score')
            ->values()
            ->all();

        return [
            'schema_version' => 'atlas.agentic_workcell.counterfactual_replay.v1',
            'selected_topology' => $selectedTopology,
            'winning_topology' => (string) data_get($candidates, '0.topology', $selectedTopology),
            'candidates' => $candidates,
            'objective_hash' => MissionCanonicalHash::sha256(['objective' => $objective]),
            'replay_hash' => MissionCanonicalHash::sha256([$domain, $flowId, $selectedTopology, $candidates]),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function learningPolicy(string $domain, string $flowId, string $topology, int $risk): array
    {
        return [
            'schema_version' => 'atlas.agentic_workcell.learning_policy.v1',
            'records_outcome' => true,
            'compiles_org_pattern' => true,
            'anti_false_learning_gate' => [
                'requires_evidence_refs' => true,
                'requires_quality_and_roi' => true,
                'operator_review_required_when_risk_high' => $risk >= 8,
            ],
            'future_policy_inputs' => ['topology_success_rate', 'role_utility', 'context_pack_roi', 'verification_findings'],
            'scope' => $domain.':'.$flowId.':'.$topology,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $roles
     * @return array<string,mixed>
     */
    public static function controlPlaneSummary(string $topology, array $roles, array $taskGraph, array $verificationPlan, string $status): array
    {
        return [
            'schema_version' => 'atlas.agentic_workcell.control_summary.v1',
            'status' => $status,
            'topology' => $topology,
            'role_count' => count($roles),
            'task_count' => count((array) ($taskGraph['tasks'] ?? [])),
            'verification_check_count' => count((array) ($verificationPlan['checks'] ?? [])),
            'independent_verification_required' => true,
        ];
    }

    /**
     * Pure admission receipt — never executes. ExecutionOrder is a value object parse only.
     *
     * @param  list<array<string,mixed>>  $roles
     * @param  list<string>  $evidenceRefs
     * @return array<string,mixed>
     */
    public static function workcellAdmission(array $input, string $topology, array $roles, int $risk, array $evidenceRefs): array
    {
        $requestedTopology = AiValueNormalizer::trimmedScalarStringOrNull($input['topology'] ?? null);
        if ($requestedTopology !== null && ! in_array($requestedTopology, AgenticWorkcellTopologyPolicySupport::TOPOLOGIES, true)) {
            return [
                'schema_version' => 'atlas.agentic_workcell.admission.v1',
                'status' => 'blocked',
                'reason' => 'unsupported_workcell_topology',
                'requested_topology' => $requestedTopology,
            ];
        }
        $rawOrder = $input['execution_order'] ?? null;
        if ($rawOrder === null) {
            return [
                'schema_version' => 'atlas.agentic_workcell.admission.v1',
                'status' => 'legacy_planning_only',
                'reason' => 'execution_order_not_supplied',
                'requires_execution_order_before_execution' => true,
            ];
        }
        if (! is_array($rawOrder)) {
            return ['schema_version' => 'atlas.agentic_workcell.admission.v1', 'status' => 'blocked', 'reason' => 'execution_order_invalid'];
        }

        try {
            $order = ExecutionOrder::fromArray($rawOrder);
        } catch (\Throwable $exception) {
            return [
                'schema_version' => 'atlas.agentic_workcell.admission.v1',
                'status' => 'blocked',
                'reason' => 'execution_order_rejected',
                'detail' => $exception->getMessage(),
            ];
        }

        $orderRoleIds = array_keys($order->roleRoster);
        $workcellRoleIds = array_values(array_map(static fn (array $role): string => (string) $role['role_id'], $roles));
        $blockers = [];
        $mappedTopology = AgenticWorkcellTopologyPolicySupport::executionOrderTopologyMap($order->workTopology);
        if ($mappedTopology !== $topology) {
            $blockers[] = 'execution_order_topology_mismatch';
        }
        if ($orderRoleIds !== EngineeringRoleRoster::OFFICIAL_ROLES || $workcellRoleIds !== EngineeringRoleRoster::OFFICIAL_ROLES) {
            $blockers[] = 'official_22_role_roster_required';
        }
        if ($order->roleRoster !== [] && count(array_unique($orderRoleIds)) !== count($orderRoleIds)) {
            $blockers[] = 'role_ownership_overlap';
        }
        if ($order->allowedScope === []) {
            $blockers[] = 'allowed_scope_required';
        }
        if ($order->authorityEnvelope['kind'] === '') {
            $blockers[] = 'authority_required';
        }
        if ($order->evidencePolicy === [] || $order->evidencePolicy['acceptance_event_id'] === '') {
            $blockers[] = 'evidence_policy_required';
        }
        $identities = [];
        foreach ($order->roleRoster as $entry) {
            foreach (['builder_id', 'verifier_id', 'final_certifier_id'] as $identityKey) {
                $identity = trim((string) ($entry[$identityKey] ?? ''));
                if ($identity !== '') {
                    $identities[$identityKey][] = $identity;
                }
            }
        }
        $identityValues = [];
        foreach ($identities as $values) {
            $identityValues = [...$identityValues, ...$values];
        }
        if ($identityValues !== [] && count(array_unique($identityValues)) !== count($identityValues)) {
            $blockers[] = 'role_witness_identity_overlap';
        }
        $suppliedSandboxes = is_array($input['candidate_sandboxes'] ?? null) ? $input['candidate_sandboxes'] : [];
        $sandboxRefs = array_values(array_filter(array_map(static fn (mixed $sandbox): string => is_array($sandbox) ? trim((string) ($sandbox['sandbox_ref'] ?? '')) : '', $suppliedSandboxes)));
        if ($sandboxRefs !== [] && count(array_unique($sandboxRefs)) !== count($sandboxRefs)) {
            $blockers[] = 'candidate_sandbox_shared';
        }
        if (array_key_exists('mode', $input) && (string) $input['mode'] !== $order->mode) {
            $blockers[] = 'mode_quality_bar_mismatch';
        }
        $candidateApproaches = array_values(array_unique(array_filter(array_map(
            static fn (mixed $approach): string => is_array($approach)
                ? trim((string) ($approach['approach_id'] ?? $approach['id'] ?? ''))
                : trim((string) $approach),
            is_array($input['candidate_approaches'] ?? null) ? $input['candidate_approaches'] : [],
        ))));
        $verifierFamilies = array_values(array_unique(array_filter(array_map(
            static fn (mixed $family): string => is_array($family)
                ? trim((string) ($family['family_id'] ?? $family['id'] ?? ''))
                : trim((string) $family),
            is_array($input['verifier_families'] ?? null) ? $input['verifier_families'] : [],
        ))));
        $r5CompetitionRequired = $order->riskClass === 'R5';
        if ($r5CompetitionRequired && $order->workTopology !== 'candidate_set') {
            $blockers[] = 'r5_candidate_set_topology_required';
        }
        if ($r5CompetitionRequired && count($candidateApproaches) < 2) {
            $blockers[] = 'r5_competing_approaches_required';
        }
        if ($r5CompetitionRequired && count($verifierFamilies) < 2) {
            $blockers[] = 'r5_distinct_verifier_families_required';
        }
        if ($evidenceRefs === [] && $order->riskClass !== 'R0') {
            $blockers[] = 'initial_evidence_refs_required';
        }

        $candidateCount = in_array($topology, ['parallel_scouts', 'tournament', 'red_blue_team', 'mapreduce_research', 'forge_milestone_crew'], true) ? 3 : 1;
        $sandboxes = array_map(static fn (int $index): array => [
            'candidate_id' => 'candidate-'.$index,
            'sandbox_ref' => 'isolated:'.$order->deliveryId.':candidate-'.$index,
            'owner' => 'candidate-'.$index,
            'integration' => 'serial_only',
        ], range(1, $candidateCount));

        return [
            'schema_version' => 'atlas.agentic_workcell.admission.v1',
            'status' => $blockers === [] ? 'admitted' : 'blocked',
            'blockers' => array_values(array_unique($blockers)),
            'execution_order_hash' => $order->canonicalHash(),
            'product_intent_verdict_hash' => $order->productIntentVerdictHash,
            'spec_hash' => $order->specHash,
            'world_model_snapshot_hash' => $order->worldModelSnapshotHash,
            'risk_class' => $order->riskClass,
            'required_depth' => EngineeringRoleRoster::depthProfile($order->riskClass),
            'execution_order_topology' => $order->workTopology,
            'aawr_topology' => $mappedTopology,
            'evidence_policy' => $order->evidencePolicy,
            'authority_kind' => $order->authorityEnvelope['kind'],
            'allowed_scope' => $order->allowedScope,
            'forbidden_scope' => $order->forbiddenScope,
            'candidate_sandboxes' => $sandboxes,
            'integration_lane' => ['mode' => 'serial', 'protected' => true],
            'candidate_competition' => [
                'required' => $r5CompetitionRequired,
                'status' => $r5CompetitionRequired && count($candidateApproaches) >= 2 && count($verifierFamilies) >= 2 ? 'configured' : ($r5CompetitionRequired ? 'blocked' : 'not_required'),
                'approaches' => $candidateApproaches,
                'verifier_families' => $verifierFamilies,
                'independent_verifier_families' => count($verifierFamilies) >= 2,
                'candidates' => $r5CompetitionRequired
                    ? array_map(static fn (int $index): array => [
                        'candidate_id' => 'candidate-'.$index,
                        'approach_id' => $candidateApproaches[$index - 1] ?? $candidateApproaches[($index - 1) % max(1, count($candidateApproaches))] ?? null,
                        'verifier_family' => $verifierFamilies[$index - 1] ?? $verifierFamilies[($index - 1) % max(1, count($verifierFamilies))] ?? null,
                    ], range(1, max(2, min($candidateCount, count($candidateApproaches)))))
                    : [],
            ],
            'judge_context' => ['includes' => ['frozen_spec', 'candidate_artifact', 'independent_evidence'], 'excludes' => ['author_defense']],
        ];
    }

    /**
     * @param  array<string,mixed>  $signals
     * @return list<array<string,mixed>>
     */
    public static function learningCandidates(?string $currentTopology, ?float $qualityScore, ?float $roiScore, array $signals): array
    {
        $candidates = [];
        if ($qualityScore !== null && $qualityScore < 0.65) {
            $candidates[] = ['kind' => 'change_topology', 'reason' => 'low_quality_score', 'current_topology' => $currentTopology];
        }
        if ($roiScore !== null && $roiScore < 0.45) {
            $candidates[] = ['kind' => 'reduce_agent_count_or_context', 'reason' => 'low_coordination_roi'];
        }
        if (($signals['verification_failed'] ?? false) === true) {
            $candidates[] = ['kind' => 'strengthen_verifier_or_red_team', 'reason' => 'verification_failed'];
        }
        if ($candidates === []) {
            $candidates[] = ['kind' => 'promote_org_pattern_candidate', 'reason' => 'quality_and_roi_acceptable'];
        }

        return $candidates;
    }

    /**
     * @return array<string,mixed>
     */
    public static function blocked(string $schema, string $reason, string $message): array
    {
        return [
            'schema_version' => $schema,
            'status' => AtlasAgenticWorkcellRuntimeService::STATUS_BLOCKED,
            'blocker' => [
                'reason' => $reason,
                'message' => $message,
            ],
            'claim_policy' => AgenticWorkcellTopologyPolicySupport::claimPolicy(),
        ];
    }

    /**
     * @param  Collection<int,object>  $rows
     */
    public static function average(Collection $rows, string $field): ?float
    {
        return $rows->isEmpty() ? null : round(AiValueNormalizer::finiteFloatOrNull($rows->avg($field)) ?? 0.0, 2);
    }

    /**
     * @param  Collection<int,object>  $rows
     * @return array<string,int>
     */
    public static function countsBy(Collection $rows, string $field): array
    {
        return $rows
            ->groupBy(fn (object $row): string => (string) ($row->{$field} ?? 'unknown'))
            ->map(fn (Collection $group): int => $group->count())
            ->sortKeys()
            ->all();
    }

    public static function numericOrNull(mixed $value): ?float
    {
        return AiValueNormalizer::finiteFloatOrNull($value);
    }
}
