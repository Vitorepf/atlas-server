<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticWorkcell;

use App\Models\AtlasAgenticWorkcell;
use App\Models\AtlasAgenticWorkcellEvent;
use App\Models\AtlasAgenticWorkcellOrgPattern;
use App\Models\AtlasAgenticWorkcellOutcome;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\RuntimeEfficiency\AtlasRuntimeEfficiencyGovernorService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class AtlasAgenticWorkcellRuntimeService
{
    public const WORKCELL_SCHEMA = 'atlas.agentic_workcell.v1';

    public const EVENT_SCHEMA = 'atlas.agentic_workcell.event.v1';

    public const OUTCOME_SCHEMA = 'atlas.agentic_workcell.outcome.v1';

    public const ORG_PATTERN_SCHEMA = 'atlas.agentic_workcell.org_pattern.v1';

    public const CONTROL_PLANE_SCHEMA = 'atlas.agentic_workcell.control_plane.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_WATCH = 'watch';

    public const STATUS_BLOCKED = 'blocked';

    public const LEVEL_L1 = 'AAWR-L1 Structured Delegation';

    public const LEVEL_L2 = 'AAWR-L2 Context-Isolated Workcells';

    public const LEVEL_L3 = 'AAWR-L3 Verified Parallel Execution';

    public const LEVEL_L4 = 'AAWR-L4 Adaptive Workcell Intelligence';

    public const LEVEL_L5 = 'AAWR-L5 Organizational Intelligence Engine';

    /** @var list<string> */
    private const TOPOLOGIES = [
        'solo_agent',
        'lead_workers',
        'parallel_scouts',
        'debate_council',
        'tournament',
        'red_blue_team',
        'mapreduce_research',
        'forge_milestone_crew',
        'critic_chain',
        'tool_builder_loop',
    ];

    public function __construct(
        private readonly ?AtlasRuntimeEfficiencyGovernorService $areg = null,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function design(array $input): array
    {
        $objective = $this->objective($input);
        $domain = $this->normalizeDomain($this->stringValue($input['domain'] ?? null) ?? $this->classifyDomain($objective));
        $flowId = $this->stringValue($input['flow_id'] ?? null) ?? $this->flowForDomain($domain, $input);
        $surfaceId = $this->stringValue($input['surface_id'] ?? null) ?? 'atlas_ai';
        $evidenceRefs = $this->stringList($input['evidence_refs'] ?? []);
        $contextRefs = $this->stringList($input['context_refs'] ?? []);
        $aregDecision = $this->aregDecision($objective, $domain, $flowId, $surfaceId, $evidenceRefs, $contextRefs, $input);
        $complexity = (int) ($aregDecision['complexity_score'] ?? $this->complexityScore($objective, $domain));
        $risk = (int) ($aregDecision['risk_score'] ?? $this->riskScore($objective, $domain, $evidenceRefs, $input));
        $topology = $this->chooseTopology($objective, $domain, $flowId, $complexity, $risk, $input, $aregDecision);
        $status = $this->status($topology, $risk, $evidenceRefs, $input);
        $orgDesign = $this->orgDesign($topology, $domain, $flowId, $complexity, $risk, $input);
        $roleRoster = $this->roleRoster($topology, $domain, $flowId, $risk, $input);
        $taskGraph = $this->taskGraph($objective, $topology, $domain, $flowId, $roleRoster, $input);
        $contextPacks = $this->contextPacks($objective, $domain, $flowId, $roleRoster, $contextRefs, $evidenceRefs, $input);
        $executionSchedule = $this->executionSchedule($topology, $roleRoster, $taskGraph, $risk);
        $verificationPlan = $this->verificationPlan($topology, $domain, $flowId, $risk, $roleRoster);
        $evidenceLedger = $this->evidenceLedger($objective, $roleRoster, $taskGraph, $evidenceRefs);
        $memoryPacket = $this->memoryPacket($objective, $domain, $flowId, $topology, $roleRoster);
        $counterfactualReplay = $this->counterfactualReplay($objective, $domain, $flowId, $topology, $complexity, $risk);
        $learningPolicy = $this->learningPolicy($domain, $flowId, $topology, $risk);
        $controlPlaneSummary = $this->controlPlaneSummary($topology, $roleRoster, $taskGraph, $verificationPlan, $status);

        $payload = [
            'schema_version' => self::WORKCELL_SCHEMA,
            'status' => $status,
            'surface_id' => $surfaceId,
            'domain' => $domain,
            'flow_id' => $flowId,
            'topology' => $topology,
            'maturity_level' => self::LEVEL_L5,
            'objective_hash' => MissionCanonicalHash::sha256(['objective' => $objective]),
            'objective' => $objective,
            'areg_decision' => $aregDecision,
            'org_design' => $orgDesign,
            'role_roster' => $roleRoster,
            'task_graph' => $taskGraph,
            'context_packs' => $contextPacks,
            'execution_schedule' => $executionSchedule,
            'verification_plan' => $verificationPlan,
            'evidence_ledger' => $evidenceLedger,
            'memory_packet' => $memoryPacket,
            'counterfactual_replay' => $counterfactualReplay,
            'learning_policy' => $learningPolicy,
            'control_plane_summary' => $controlPlaneSummary,
            'claim_policy' => $this->claimPolicy(),
        ];
        $payload['workcell_hash'] = MissionCanonicalHash::sha256($payload);

        $record = null;
        if (Schema::hasTable('atlas_agentic_workcells')) {
            $record = AtlasAgenticWorkcell::query()->create($payload);
        }

        return [
            ...$payload,
            'workcell_id' => $record?->id,
            'writes' => $record !== null,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function recordEvent(array $input): array
    {
        $workcell = $this->workcell($input['workcell_id'] ?? null);
        if (! $workcell instanceof AtlasAgenticWorkcell) {
            return $this->blocked(self::EVENT_SCHEMA, 'missing_workcell', 'AAWR event requires an existing workcell.');
        }
        $payload = [
            'workcell_id' => $workcell->id,
            'schema_version' => self::EVENT_SCHEMA,
            'event_type' => $this->stringValue($input['event_type'] ?? null) ?? 'workcell_observation',
            'status' => $this->stringValue($input['status'] ?? null) ?? 'observed',
            'payload' => $this->sanitizePayload(is_array($input['payload'] ?? null) ? $input['payload'] : []),
            'evidence_refs' => $this->stringList($input['evidence_refs'] ?? []),
        ];
        $payload['event_hash'] = MissionCanonicalHash::sha256($payload);
        $record = AtlasAgenticWorkcellEvent::query()->create($payload);

        return [
            'schema_version' => self::EVENT_SCHEMA,
            'status' => $payload['status'],
            'event_id' => $record->id,
            'workcell_id' => $workcell->id,
            'event_hash' => $record->event_hash,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function closeOutcome(array $input): array
    {
        $workcell = $this->workcell($input['workcell_id'] ?? null);
        if (! $workcell instanceof AtlasAgenticWorkcell) {
            return $this->blocked(self::OUTCOME_SCHEMA, 'missing_workcell', 'AAWR outcome requires an existing workcell.');
        }
        $evidenceRefs = $this->stringList($input['evidence_refs'] ?? []);
        $qualityScore = $this->numericOrNull($input['quality_score'] ?? null);
        $roiScore = $this->numericOrNull($input['coordination_roi_score'] ?? null);
        $signals = is_array($input['signals'] ?? null) ? $input['signals'] : [];
        $status = $evidenceRefs === [] ? self::STATUS_BLOCKED : ($this->stringValue($input['status'] ?? null) ?? self::STATUS_READY);
        $payload = [
            'workcell_id' => $workcell->id,
            'schema_version' => self::OUTCOME_SCHEMA,
            'status' => $status,
            'quality_score' => $qualityScore,
            'coordination_roi_score' => $roiScore,
            'signals' => $signals,
            'learning_candidates' => $this->learningCandidates($workcell, $qualityScore, $roiScore, $signals),
            'evidence_refs' => $evidenceRefs,
        ];
        $payload['outcome_hash'] = MissionCanonicalHash::sha256($payload);
        $record = AtlasAgenticWorkcellOutcome::query()->create($payload);
        $pattern = $this->compileOrgPattern($workcell, $record);

        return [
            'schema_version' => self::OUTCOME_SCHEMA,
            'status' => $status,
            'outcome_id' => $record->id,
            'workcell_id' => $workcell->id,
            'outcome_hash' => $record->outcome_hash,
            'learning_candidates' => $payload['learning_candidates'],
            'compiled_org_pattern' => $pattern,
            'claim_policy' => $this->claimPolicy(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function controlPlane(int $hours = 24): array
    {
        $since = CarbonImmutable::now()->subHours(max(1, $hours));
        $hasTable = Schema::hasTable('atlas_agentic_workcells');
        $workcells = $hasTable
            ? AtlasAgenticWorkcell::query()->where('created_at', '>=', $since)->latest()->limit(200)->get()
            : collect();
        $outcomes = Schema::hasTable('atlas_agentic_workcell_outcomes')
            ? AtlasAgenticWorkcellOutcome::query()->where('created_at', '>=', $since)->latest()->limit(100)->get()
            : collect();
        $patterns = Schema::hasTable('atlas_agentic_workcell_org_patterns')
            ? AtlasAgenticWorkcellOrgPattern::query()->where('created_at', '>=', $since)->latest()->limit(50)->get()
            : collect();
        $payload = [
            'schema_version' => self::CONTROL_PLANE_SCHEMA,
            'status' => $hasTable ? ($workcells->where('status', self::STATUS_BLOCKED)->isNotEmpty() ? self::STATUS_WATCH : self::STATUS_READY) : 'missing',
            'summary' => [
                'workcells_total' => $workcells->count(),
                'ready' => $workcells->where('status', self::STATUS_READY)->count(),
                'watch' => $workcells->where('status', self::STATUS_WATCH)->count(),
                'blocked' => $workcells->where('status', self::STATUS_BLOCKED)->count(),
                'outcomes_total' => $outcomes->count(),
                'org_patterns_total' => $patterns->count(),
                'average_quality_score' => $this->average($outcomes, 'quality_score'),
                'average_coordination_roi_score' => $this->average($outcomes, 'coordination_roi_score'),
            ],
            'by_topology' => $this->countsBy($workcells, 'topology'),
            'by_flow' => $this->countsBy($workcells, 'flow_id'),
            'recent_workcells' => $workcells->take(20)->map(fn (AtlasAgenticWorkcell $workcell): array => [
                'workcell_id' => (string) $workcell->id,
                'status' => (string) $workcell->status,
                'domain' => $workcell->domain,
                'flow_id' => $workcell->flow_id,
                'topology' => (string) $workcell->topology,
                'maturity_level' => (string) $workcell->maturity_level,
                'objective_hash' => (string) $workcell->objective_hash,
                'workcell_hash' => (string) $workcell->workcell_hash,
                'created_at' => $workcell->created_at?->toJSON(),
            ])->values()->all(),
            'recent_patterns' => $patterns->take(10)->map(fn (AtlasAgenticWorkcellOrgPattern $pattern): array => [
                'pattern_id' => (string) $pattern->id,
                'status' => (string) $pattern->status,
                'flow_id' => $pattern->flow_id,
                'topology' => (string) $pattern->topology,
                'pattern_hash' => (string) $pattern->pattern_hash,
            ])->values()->all(),
            'claim_policy' => $this->claimPolicy(),
        ];
        $payload['control_plane_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function claimPolicy(): array
    {
        return [
            'planning_only' => true,
            'provider_invoked' => false,
            'agents_spawned' => false,
            'external_execution_performed' => false,
            'benchmark_not_run' => true,
            'requires_areg_budget' => true,
            'requires_independent_verification' => true,
            'does_not_bypass_dev_or_forge' => true,
        ];
    }

    private function objective(array $input): string
    {
        return $this->stringValue($input['objective'] ?? $input['prompt'] ?? $input['input_text'] ?? null) ?? 'AAWR workcell objective';
    }

    private function normalizeDomain(string $domain): string
    {
        return match ($domain) {
            'dev', 'code', 'coding', 'software' => 'programming',
            'strategic' => 'strategy',
            default => $domain,
        };
    }

    private function classifyDomain(string $objective): string
    {
        $lower = Str::lower($objective);

        return match (true) {
            str_contains($lower, 'bug') || str_contains($lower, 'codigo') || str_contains($lower, 'código') || str_contains($lower, 'forge') || str_contains($lower, 'runtime') => 'programming',
            str_contains($lower, 'pesquisa') || str_contains($lower, 'mercado') || str_contains($lower, 'paper') => 'research',
            str_contains($lower, 'finance') || str_contains($lower, 'invest') || str_contains($lower, 'carteira') => 'finance',
            str_contains($lower, 'marketing') || str_contains($lower, 'campanha') || str_contains($lower, 'copy') => 'marketing',
            str_contains($lower, 'estrateg') || str_contains($lower, 'decis') => 'strategy',
            default => 'conversation',
        };
    }

    private function flowForDomain(string $domain, array $input): string
    {
        $task = $this->stringValue($input['task'] ?? null);
        if ($domain === 'programming') {
            return match ($task) {
                'forge' => 'atlas_forge',
                'debug' => 'atlas_debug',
                'review' => 'atlas_review',
                default => 'atlas_dev',
            };
        }

        return match ($domain) {
            'research' => 'atlas_research',
            'finance' => 'atlas_finance',
            'marketing' => 'atlas_marketing',
            'strategy' => 'atlas_strategy',
            default => 'atlas_conversation',
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function aregDecision(string $objective, string $domain, string $flowId, string $surfaceId, array $evidenceRefs, array $contextRefs, array $input): array
    {
        $areg = $this->areg ?? app(AtlasRuntimeEfficiencyGovernorService::class);

        return $areg->govern([
            'prompt' => $objective,
            'domain' => $domain,
            'flow_id' => $flowId,
            'surface_id' => $surfaceId,
            'evidence_refs' => $evidenceRefs,
            'context_refs' => $contextRefs,
            'external_execution_requested' => (bool) data_get($input, 'external_execution_requested', false),
            'source' => 'aawr',
        ]);
    }

    private function complexityScore(string $objective, string $domain): int
    {
        $score = str_word_count($objective) > 80 ? 7 : (str_word_count($objective) > 25 ? 5 : 3);
        foreach (['enterprise', 'completo', 'robusto', 'multi', 'obra', 'meses', 'autonom', 'certificacao', 'research', 'mercado'] as $signal) {
            if (str_contains(Str::lower($objective.' '.$domain), $signal)) {
                $score++;
            }
        }

        return max(1, min(10, $score));
    }

    private function riskScore(string $objective, string $domain, array $evidenceRefs, array $input): int
    {
        $score = match ($domain) {
            'finance', 'cyber', 'security' => 7,
            'programming' => 5,
            'strategy' => 6,
            default => 3,
        };
        if ($evidenceRefs === [] && in_array($domain, ['programming', 'research', 'finance', 'strategy'], true)) {
            $score++;
        }
        if ((bool) data_get($input, 'external_execution_requested', false)) {
            $score = 10;
        }

        return max(1, min(10, $score));
    }

    private function chooseTopology(string $objective, string $domain, string $flowId, int $complexity, int $risk, array $input, array $aregDecision): string
    {
        $forced = $this->stringValue($input['topology'] ?? null);
        if ($forced !== null && in_array($forced, self::TOPOLOGIES, true)) {
            return $forced;
        }
        $lower = Str::lower($objective.' '.$flowId.' '.$domain);
        if (($aregDecision['path'] ?? null) === AtlasRuntimeEfficiencyGovernorService::PATH_BLOCKED || $risk >= 10) {
            return 'critic_chain';
        }
        if (str_contains($lower, 'ferramenta') || str_contains($lower, 'capability') || str_contains($lower, 'tool')) {
            return 'tool_builder_loop';
        }
        if ($flowId === 'atlas_forge' || str_contains($lower, 'obra') || str_contains($lower, 'meses')) {
            return 'forge_milestone_crew';
        }
        if ($domain === 'research' || str_contains($lower, 'pesquisa profunda')) {
            return 'mapreduce_research';
        }
        if (in_array($domain, ['strategy', 'finance'], true) || $risk >= 8) {
            return 'red_blue_team';
        }
        if ($complexity >= 8) {
            return 'lead_workers';
        }
        if ($complexity >= 6) {
            return 'parallel_scouts';
        }

        return 'solo_agent';
    }

    private function status(string $topology, int $risk, array $evidenceRefs, array $input): string
    {
        if ((bool) data_get($input, 'external_execution_requested', false) && $risk >= 10) {
            return self::STATUS_BLOCKED;
        }
        if ($risk >= 7 && $evidenceRefs === []) {
            return self::STATUS_WATCH;
        }
        if ($topology === 'critic_chain' && $risk >= 10) {
            return self::STATUS_BLOCKED;
        }

        return self::STATUS_READY;
    }

    /**
     * @return array<string,mixed>
     */
    private function orgDesign(string $topology, string $domain, string $flowId, int $complexity, int $risk, array $input): array
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
     * @return list<array<string,mixed>>
     */
    private function roleRoster(string $topology, string $domain, string $flowId, int $risk, array $input): array
    {
        $roles = match ($topology) {
            'solo_agent' => ['lead_executor', 'independent_verifier'],
            'parallel_scouts' => ['lead_synthesizer', 'context_scout', 'risk_scout', 'implementation_scout', 'independent_verifier'],
            'mapreduce_research' => ['lead_synthesizer', 'source_scout', 'counter_source_scout', 'domain_analyst', 'evidence_auditor', 'final_synthesizer'],
            'red_blue_team' => ['lead_decision_owner', 'blue_team_builder', 'red_team_critic', 'policy_reviewer', 'evidence_auditor', 'final_adjudicator'],
            'forge_milestone_crew' => ['lead_architect', 'codebase_cartographer', 'backend_worker', 'frontend_worker', 'test_engineer', 'documentation_engineer', 'policy_reviewer', 'evidence_auditor', 'final_certifier'],
            'tool_builder_loop' => ['capability_gap_analyst', 'tool_designer', 'sandbox_builder', 'simulation_verifier', 'policy_reviewer', 'release_certifier'],
            'critic_chain' => ['lead_triage', 'policy_critic', 'evidence_critic', 'safety_critic', 'operator_handoff'],
            default => ['lead_planner', 'worker', 'critic', 'verifier', 'synthesizer'],
        };

        return collect($roles)
            ->map(function (string $role, int $index) use ($domain, $flowId, $risk): array {
                return [
                    'role_id' => $role,
                    'agent_index' => $index + 1,
                    'domain' => $domain,
                    'flow_id' => $flowId,
                    'context_scope' => $this->roleContextScope($role),
                    'output_contract' => $this->roleOutputContract($role),
                    'tool_boundary' => $this->roleToolBoundary($role, $risk),
                    'forbidden_actions' => ['spawn_provider_directly', 'mutate_files_outside_ownership', 'declare_completion_without_evidence'],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function taskGraph(string $objective, string $topology, string $domain, string $flowId, array $roles, array $input): array
    {
        $leadTaskId = 'task_01_'.(string) data_get($roles, '0.role_id', 'lead_synthesizer');

        $tasks = collect($roles)->map(function (array $role, int $index) use ($objective, $leadTaskId): array {
            $roleId = (string) $role['role_id'];

            return [
                'task_id' => 'task_'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT).'_'.$roleId,
                'role_id' => $roleId,
                'objective_hash' => MissionCanonicalHash::sha256([$objective, $roleId]),
                'depends_on' => $this->taskDependencies($roleId, $index, $leadTaskId),
                'expected_artifacts' => $this->expectedArtifacts($roleId),
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
     * @return array<string,mixed>
     */
    private function contextPacks(string $objective, string $domain, string $flowId, array $roles, array $contextRefs, array $evidenceRefs, array $input): array
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
     * @return array<string,mixed>
     */
    private function executionSchedule(string $topology, array $roles, array $taskGraph, int $risk): array
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
     * @return array<string,mixed>
     */
    private function verificationPlan(string $topology, string $domain, string $flowId, int $risk, array $roles): array
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
     * @return array<string,mixed>
     */
    private function evidenceLedger(string $objective, array $roles, array $taskGraph, array $evidenceRefs): array
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
     * @return array<string,mixed>
     */
    private function memoryPacket(string $objective, string $domain, string $flowId, string $topology, array $roles): array
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
    private function counterfactualReplay(string $objective, string $domain, string $flowId, string $selectedTopology, int $complexity, int $risk): array
    {
        $candidates = collect(self::TOPOLOGIES)
            ->map(fn (string $topology): array => $this->scoreTopology($topology, $selectedTopology, $domain, $flowId, $complexity, $risk))
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
    private function learningPolicy(string $domain, string $flowId, string $topology, int $risk): array
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
     * @return array<string,mixed>
     */
    private function controlPlaneSummary(string $topology, array $roles, array $taskGraph, array $verificationPlan, string $status): array
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
     * @return array<int,array<string,mixed>>
     */
    private function learningCandidates(AtlasAgenticWorkcell $workcell, ?float $qualityScore, ?float $roiScore, array $signals): array
    {
        $candidates = [];
        if ($qualityScore !== null && $qualityScore < 0.65) {
            $candidates[] = ['kind' => 'change_topology', 'reason' => 'low_quality_score', 'current_topology' => $workcell->topology];
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
     * @return array<string,mixed>|null
     */
    private function compileOrgPattern(AtlasAgenticWorkcell $workcell, AtlasAgenticWorkcellOutcome $outcome): ?array
    {
        if (! Schema::hasTable('atlas_agentic_workcell_org_patterns')) {
            return null;
        }
        $status = ($outcome->quality_score ?? 0) >= 0.70 && ($outcome->coordination_roi_score ?? 0) >= 0.50 ? self::STATUS_READY : self::STATUS_WATCH;
        $payload = [
            'schema_version' => self::ORG_PATTERN_SCHEMA,
            'status' => $status,
            'domain' => $workcell->domain,
            'flow_id' => $workcell->flow_id,
            'topology' => $workcell->topology,
            'pattern' => [
                'org_design' => $workcell->org_design,
                'role_roster' => $workcell->role_roster,
                'execution_schedule' => $workcell->execution_schedule,
                'verification_plan' => $workcell->verification_plan,
            ],
            'quality_stats' => [
                'quality_score' => $outcome->quality_score,
                'coordination_roi_score' => $outcome->coordination_roi_score,
                'outcome_hash' => $outcome->outcome_hash,
            ],
            'evidence_refs' => $outcome->evidence_refs ?? [],
        ];
        $payload['pattern_hash'] = MissionCanonicalHash::sha256($payload);
        $record = AtlasAgenticWorkcellOrgPattern::query()->create($payload);

        return [
            ...$payload,
            'pattern_id' => $record->id,
            'writes' => true,
        ];
    }

    private function roleContextScope(string $roleId): string
    {
        return match (true) {
            str_contains($roleId, 'verifier') || str_contains($roleId, 'critic') || str_contains($roleId, 'reviewer') => 'read_only_evidence_and_outputs',
            str_contains($roleId, 'worker') || str_contains($roleId, 'builder') => 'owned_work_packet_only',
            str_contains($roleId, 'scout') || str_contains($roleId, 'cartographer') => 'retrieval_and_mapping_only',
            default => 'goal_and_coordination_context',
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function roleOutputContract(string $roleId): array
    {
        return [
            'must_return' => ['summary', 'evidence_refs', 'confidence', 'blockers', 'next_action'],
            'role_specific_artifact' => str_contains($roleId, 'verifier') || str_contains($roleId, 'critic') ? 'verification_report' : 'work_product_or_findings',
            'forbidden_output' => ['unsupported_completion_claim', 'hidden_assumptions', 'raw_secret_or_credential'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function roleToolBoundary(string $roleId, int $risk): array
    {
        $readOnly = str_contains($roleId, 'critic') || str_contains($roleId, 'auditor') || str_contains($roleId, 'reviewer') || str_contains($roleId, 'scout');

        return [
            'read_only' => $readOnly,
            'writes_allowed' => ! $readOnly && $risk < 9,
            'requires_receipt' => true,
            'external_side_effects_allowed' => false,
        ];
    }

    /**
     * @return list<string>
     */
    private function taskDependencies(string $roleId, int $index, string $leadTaskId): array
    {
        if ($index === 0) {
            return [];
        }
        if (str_contains($roleId, 'verifier') || str_contains($roleId, 'auditor') || str_contains($roleId, 'certifier') || str_contains($roleId, 'synthesizer') || str_contains($roleId, 'adjudicator') || str_contains($roleId, 'critic')) {
            return [$leadTaskId];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function expectedArtifacts(string $roleId): array
    {
        return match (true) {
            str_contains($roleId, 'verifier') => ['verification_report', 'failed_or_passed_checks'],
            str_contains($roleId, 'critic') => ['risk_report', 'counterarguments'],
            str_contains($roleId, 'auditor') => ['evidence_manifest', 'missing_evidence'],
            str_contains($roleId, 'worker') || str_contains($roleId, 'builder') => ['work_product', 'changed_artifacts_or_plan'],
            default => ['findings', 'handoff_packet'],
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function scoreTopology(string $topology, string $selectedTopology, string $domain, string $flowId, int $complexity, int $risk): array
    {
        $quality = match ($topology) {
            'solo_agent' => 0.62,
            'lead_workers' => 0.78,
            'parallel_scouts' => 0.76,
            'debate_council' => 0.80,
            'tournament' => 0.81,
            'red_blue_team' => 0.86,
            'mapreduce_research' => 0.88,
            'forge_milestone_crew' => 0.90,
            'critic_chain' => 0.74,
            'tool_builder_loop' => 0.84,
            default => 0.70,
        };
        $cost = match ($topology) {
            'solo_agent' => 0.06,
            'critic_chain' => 0.12,
            'parallel_scouts' => 0.20,
            'lead_workers' => 0.24,
            'debate_council', 'tournament', 'red_blue_team' => 0.30,
            'mapreduce_research', 'tool_builder_loop' => 0.34,
            'forge_milestone_crew' => 0.42,
            default => 0.20,
        };
        $domainBonus = match (true) {
            $domain === 'research' && $topology === 'mapreduce_research' => 0.10,
            $flowId === 'atlas_forge' && $topology === 'forge_milestone_crew' => 0.12,
            in_array($domain, ['finance', 'strategy'], true) && $topology === 'red_blue_team' => 0.10,
            default => 0.0,
        };
        $riskPenalty = $risk >= 8 && $topology === 'solo_agent' ? 0.25 : 0.0;
        $complexityPenalty = $complexity >= 8 && in_array($topology, ['solo_agent', 'critic_chain'], true) ? 0.18 : 0.0;
        $utility = $quality + $domainBonus - $cost - $riskPenalty - $complexityPenalty;

        return [
            'topology' => $topology,
            'selected' => $topology === $selectedTopology,
            'predicted_quality' => round($quality + $domainBonus, 2),
            'coordination_cost' => round($cost, 2),
            'utility_score' => round(max(0, min(1, $utility)), 3),
        ];
    }

    /**
     * @param  Collection<int,object>  $rows
     */
    private function average(Collection $rows, string $field): ?float
    {
        return $rows->isEmpty() ? null : round((float) $rows->avg($field), 2);
    }

    /**
     * @param  Collection<int,object>  $rows
     * @return array<string,int>
     */
    private function countsBy(Collection $rows, string $field): array
    {
        return $rows
            ->groupBy(fn (object $row): string => (string) ($row->{$field} ?? 'unknown'))
            ->map(fn (Collection $group): int => $group->count())
            ->sortKeys()
            ->all();
    }

    private function workcell(mixed $id): ?AtlasAgenticWorkcell
    {
        $id = $this->stringValue($id);
        if ($id === null || ! Schema::hasTable('atlas_agentic_workcells')) {
            return null;
        }

        return AtlasAgenticWorkcell::query()->find($id);
    }

    /**
     * @return array<string,mixed>
     */
    private function sanitizePayload(array $payload): array
    {
        unset($payload['raw_prompt'], $payload['provider_raw_output'], $payload['secret'], $payload['credential']);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function blocked(string $schema, string $reason, string $message): array
    {
        return [
            'schema_version' => $schema,
            'status' => self::STATUS_BLOCKED,
            'blocker' => [
                'reason' => $reason,
                'message' => $message,
            ],
            'claim_policy' => $this->claimPolicy(),
        ];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->filter(fn (mixed $item): bool => is_scalar($item) && trim((string) $item) !== '')
            ->map(fn (mixed $item): string => trim((string) $item))
            ->values()
            ->all();
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function numericOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
