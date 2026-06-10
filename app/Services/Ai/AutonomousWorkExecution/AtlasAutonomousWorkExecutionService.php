<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousWorkExecution;

use App\Models\AtlasAweosCertifiedOutcome;
use App\Models\AtlasAweosEvent;
use App\Models\AtlasAweosExecution;
use App\Services\Ai\Aemor\AtlasAemorRuntimeService;
use App\Services\Ai\AgenticWorkcell\AtlasAgenticWorkcellRuntimeService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\PersistentContext\AtlasPersistentContextRuntimeService;
use App\Services\Ai\RuntimeEfficiency\AtlasRuntimeEfficiencyGovernorService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\VerifiedExecution\AtlasVerifiedExecutionRuntimeService;
use App\Services\Engineering\AtlasVerifiedEvolutionRuntimeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

final class AtlasAutonomousWorkExecutionService
{
    /**
     * AWEOS is the concrete loop that combines:
     * Perfect Context Spine, Certified Outcome Layer, Long-Horizon Dev/Forge
     * Operating System, Atlas Mission Control and Execution Memory Guard.
     */
    public const EXECUTION_SCHEMA = 'atlas.aweos.execution.v1';

    public const EVENT_SCHEMA = 'atlas.aweos.event.v1';

    public const CERTIFIED_OUTCOME_SCHEMA = 'atlas.aweos.certified_outcome.v1';

    public const CONTROL_PLANE_SCHEMA = 'atlas.aweos.control_plane.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_WATCH = 'watch';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_CERTIFIED = 'certified';

    public const LEVEL_MAX = 'AWEOS-L10 Autonomous Work Operating System';

    public function __construct(
        private readonly ?AtlasPersistentContextRuntimeService $persistentContext = null,
        private readonly ?AtlasRuntimeEfficiencyGovernorService $runtimeEfficiency = null,
        private readonly ?AtlasAgenticWorkcellRuntimeService $agenticWorkcell = null,
        private readonly ?AtlasAemorRuntimeService $aemor = null,
        private readonly ?AtlasVerifiedExecutionRuntimeService $verifiedExecution = null,
        private readonly ?AtlasVerifiedEvolutionRuntimeService $verifiedEvolution = null,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function run(array $input): array
    {
        $objective = $this->objective($input);
        $surfaceId = $this->stringValue($input['surface_id'] ?? null) ?? 'atlas_ai';
        $domain = $this->normalizeDomain($this->stringValue($input['domain'] ?? null) ?? $this->classifyDomain($objective));
        $flowId = $this->stringValue($input['flow_id'] ?? null) ?? $this->flowForDomain($domain, $input);
        $workspace = $this->stringValue($input['workspace'] ?? null) ?? base_path();
        $evidenceRefs = $this->stringList($input['evidence_refs'] ?? []);
        $contextRefs = $this->stringList($input['context_refs'] ?? []);

        $apcr = $this->buildPersistentContext($objective, $surfaceId, $domain, $flowId, $workspace, $evidenceRefs, $input);
        $areg = $this->buildRuntimeEfficiency($objective, $surfaceId, $domain, $flowId, $evidenceRefs, $contextRefs, $apcr, $input);
        $aawr = $this->buildAgenticWorkcell($objective, $surfaceId, $domain, $flowId, $evidenceRefs, $contextRefs, $areg, $input);
        $aemorEpisode = $this->openAemorEpisode($objective, $surfaceId, $domain, $flowId, $workspace, $evidenceRefs, $apcr, $input);

        $executionPlan = $this->executionPlan($objective, $domain, $flowId, $apcr, $areg, $aawr, $input);
        $toolOrchestration = $this->toolOrchestration($domain, $flowId, $areg, $executionPlan, $input);
        $repairRecoveryLoop = $this->repairRecoveryLoop($domain, $flowId, $executionPlan, $areg);
        $operatorDecisionEconomy = $this->operatorDecisionEconomy($objective, $domain, $flowId, $areg, $aawr, $executionPlan);
        $continuationEngine = $this->continuationEngine($objective, $domain, $flowId, $apcr, $aemorEpisode, $executionPlan);
        $strategicNextAction = $this->strategicNextAction($domain, $flowId, $executionPlan, $operatorDecisionEconomy);
        $missionControl = $this->missionControl($domain, $flowId, $executionPlan, $operatorDecisionEconomy, $continuationEngine);
        $status = $this->status($apcr, $areg, $aawr, $executionPlan);

        $payload = [
            'schema_version' => self::EXECUTION_SCHEMA,
            'status' => $status,
            'maturity_level' => self::LEVEL_MAX,
            'surface_id' => $surfaceId,
            'domain' => $domain,
            'flow_id' => $flowId,
            'objective_hash' => MissionCanonicalHash::sha256(['objective' => $objective]),
            'objective' => $objective,
            'mission_id' => $this->uuidOrNull($input['mission_id'] ?? null),
            'persistent_context_pack_id' => $this->uuidOrNull($apcr['persistent_context_pack_id'] ?? null),
            'persistent_context_hash' => $this->stringValue($apcr['persistent_context_hash'] ?? null),
            'runtime_efficiency_decision_id' => $this->uuidOrNull($areg['decision_id'] ?? null),
            'workcell_id' => $this->uuidOrNull($aawr['workcell_id'] ?? null),
            'aemor_episode_id' => $this->uuidOrNull($aemorEpisode['episode_id'] ?? null),
            'persistent_context' => $apcr,
            'runtime_efficiency' => $areg,
            'agentic_workcell' => $aawr,
            'aemor_episode' => $aemorEpisode,
            'execution_plan' => $executionPlan,
            'tool_orchestration' => $toolOrchestration,
            'repair_recovery_loop' => $repairRecoveryLoop,
            'operator_decision_economy' => $operatorDecisionEconomy,
            'continuation_engine' => $continuationEngine,
            'strategic_next_action' => $strategicNextAction,
            'mission_control' => $missionControl,
            'evidence_refs' => $evidenceRefs,
            'claim_policy' => $this->claimPolicy(),
        ];
        $payload['execution_hash'] = MissionCanonicalHash::sha256($payload);

        $record = null;
        $verifiedExecution = null;
        if (DatabaseTableAvailability::has('atlas_aweos_executions')) {
            $record = AtlasAweosExecution::query()->create($payload);
            $this->recordEvent([
                'execution_id' => $record->id,
                'event_type' => 'execution_planned',
                'status' => $status,
                'payload' => [
                    'execution_hash' => $payload['execution_hash'],
                    'flow_id' => $flowId,
                    'domain' => $domain,
                ],
                'evidence_refs' => $evidenceRefs,
            ]);
            $verifiedExecution = $this->buildVerifiedExecution($record, $objective, $surfaceId, $domain, $flowId, $workspace, $evidenceRefs, $input);
        } else {
            $verifiedExecution = $this->buildVerifiedExecution(null, $objective, $surfaceId, $domain, $flowId, $workspace, $evidenceRefs, $input);
        }

        return [
            ...$payload,
            'execution_id' => $record?->id,
            'verified_execution' => $verifiedExecution,
            'writes' => $record !== null,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function recordEvent(array $input): array
    {
        $execution = $this->execution($input['execution_id'] ?? null);
        if (! $execution instanceof AtlasAweosExecution) {
            return $this->blocked(self::EVENT_SCHEMA, 'missing_execution', 'AWEOS event requires an existing execution.');
        }

        $payload = [
            'execution_id' => $execution->id,
            'schema_version' => self::EVENT_SCHEMA,
            'event_type' => $this->stringValue($input['event_type'] ?? null) ?? 'observation',
            'status' => $this->stringValue($input['status'] ?? null) ?? 'observed',
            'payload' => $this->sanitizePayload(is_array($input['payload'] ?? null) ? $input['payload'] : []),
            'evidence_refs' => $this->stringList($input['evidence_refs'] ?? []),
        ];
        $payload['event_hash'] = MissionCanonicalHash::sha256($payload);
        $record = AtlasAweosEvent::query()->create($payload);

        return [
            'schema_version' => self::EVENT_SCHEMA,
            'status' => $payload['status'],
            'event_id' => $record->id,
            'execution_id' => $execution->id,
            'event_hash' => $record->event_hash,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function certifyOutcome(array $input): array
    {
        $execution = $this->execution($input['execution_id'] ?? null);
        if (! $execution instanceof AtlasAweosExecution) {
            return $this->blocked(self::CERTIFIED_OUTCOME_SCHEMA, 'missing_execution', 'AWEOS outcome certification requires an existing execution.');
        }

        $evidenceRefs = $this->stringList($input['evidence_refs'] ?? []);
        $claim = $this->stringValue($input['claim'] ?? null) ?? 'AWEOS execution outcome certified.';
        $commandLedger = is_array($input['command_ledger'] ?? null) ? $input['command_ledger'] : [];
        $testImpact = is_array($input['test_impact'] ?? null) ? $input['test_impact'] : [];
        $qualityScore = $this->numericOrNull($input['quality_score'] ?? null) ?? ($evidenceRefs === [] ? 0.0 : 0.82);
        $certificationLevel = $this->certificationLevel($evidenceRefs, $commandLedger, $testImpact, $qualityScore);
        $status = $certificationLevel === 'blocked' ? self::STATUS_BLOCKED : self::STATUS_CERTIFIED;
        $aemorOutcome = $this->closeAemorOutcome($execution, $claim, $evidenceRefs, $qualityScore);
        $aregOutcome = $this->recordAregOutcome($execution, $evidenceRefs, $qualityScore);
        $aawrOutcome = $this->closeAawrOutcome($execution, $evidenceRefs, $qualityScore);

        $payload = [
            'execution_id' => $execution->id,
            'schema_version' => self::CERTIFIED_OUTCOME_SCHEMA,
            'status' => $status,
            'certification_level' => $certificationLevel,
            'claim_hash' => MissionCanonicalHash::sha256(['claim' => $claim]),
            'claim' => $claim,
            'patch_boundary' => is_array($input['patch_boundary'] ?? null) ? $input['patch_boundary'] : $this->patchBoundary($execution),
            'command_ledger' => $commandLedger,
            'test_impact' => $testImpact,
            'evidence_bundle' => $this->evidenceBundle($execution, $evidenceRefs, $commandLedger, $testImpact),
            'replay_manifest' => $this->replayManifest($execution, $evidenceRefs, $commandLedger, $testImpact),
            'learning_decision' => $this->learningDecision($certificationLevel, $evidenceRefs, $qualityScore),
            'aemor_outcome' => $aemorOutcome,
            'areg_outcome' => $aregOutcome,
            'aawr_outcome' => $aawrOutcome,
            'evidence_refs' => $evidenceRefs,
        ];
        $payload['outcome_hash'] = MissionCanonicalHash::sha256($payload);

        $record = AtlasAweosCertifiedOutcome::query()->create($payload);
        $execution->forceFill([
            'status' => $status,
            'mission_control' => [
                ...((array) $execution->mission_control),
                'certification_level' => $certificationLevel,
                'certified_outcome_hash' => $payload['outcome_hash'],
            ],
        ])->save();

        $this->recordEvent([
            'execution_id' => $execution->id,
            'event_type' => 'outcome_certified',
            'status' => $status,
            'payload' => [
                'certification_level' => $certificationLevel,
                'outcome_hash' => $payload['outcome_hash'],
            ],
            'evidence_refs' => $evidenceRefs,
        ]);

        return [
            ...$payload,
            'outcome_id' => $record->id,
            'claim_policy' => $this->claimPolicy(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function controlPlane(int $hours = 24): array
    {
        $since = CarbonImmutable::now()->subHours(max(1, $hours));
        $executions = DatabaseTableAvailability::has('atlas_aweos_executions')
            ? AtlasAweosExecution::query()->where('created_at', '>=', $since)->latest()->limit(200)->get()
            : collect();
        $outcomes = DatabaseTableAvailability::has('atlas_aweos_certified_outcomes')
            ? AtlasAweosCertifiedOutcome::query()->where('created_at', '>=', $since)->latest()->limit(100)->get()
            : collect();
        $payload = [
            'schema_version' => self::CONTROL_PLANE_SCHEMA,
            'status' => DatabaseTableAvailability::has('atlas_aweos_executions') ? ($executions->where('status', self::STATUS_BLOCKED)->isNotEmpty() ? self::STATUS_WATCH : self::STATUS_READY) : 'missing',
            'summary' => [
                'executions_total' => $executions->count(),
                'ready' => $executions->where('status', self::STATUS_READY)->count(),
                'watch' => $executions->where('status', self::STATUS_WATCH)->count(),
                'blocked' => $executions->where('status', self::STATUS_BLOCKED)->count(),
                'certified' => $executions->where('status', self::STATUS_CERTIFIED)->count(),
                'certified_outcomes_total' => $outcomes->count(),
                'gold_outcomes' => $outcomes->where('certification_level', 'gold')->count(),
                'silver_outcomes' => $outcomes->where('certification_level', 'silver')->count(),
                'bronze_outcomes' => $outcomes->where('certification_level', 'bronze')->count(),
            ],
            'by_flow' => $this->countsBy($executions, 'flow_id'),
            'by_domain' => $this->countsBy($executions, 'domain'),
            'recent_executions' => $executions->take(20)->map(fn (AtlasAweosExecution $execution): array => [
                'execution_id' => (string) $execution->id,
                'status' => (string) $execution->status,
                'maturity_level' => (string) $execution->maturity_level,
                'domain' => $execution->domain,
                'flow_id' => $execution->flow_id,
                'objective_hash' => (string) $execution->objective_hash,
                'execution_hash' => (string) $execution->execution_hash,
                'next_action' => data_get($execution->strategic_next_action, 'next_action'),
                'created_at' => $execution->created_at?->toJSON(),
            ])->values()->all(),
            'operator_queue' => $executions->take(20)->flatMap(fn (AtlasAweosExecution $execution): array => (array) data_get($execution->operator_decision_economy, 'decisions', []))->values()->all(),
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
            'orchestrates_existing_runtimes' => true,
            'provider_invoked_directly' => false,
            'external_execution_performed_directly' => false,
            'benchmark_not_run' => true,
            'requires_apcr_context' => true,
            'requires_areg_budget' => true,
            'requires_aawr_workcell' => true,
            'requires_aemor_outcome_for_learning' => true,
            'completion_requires_certified_outcome' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function objective(array $input): string
    {
        $objective = trim((string) ($input['objective'] ?? $input['prompt'] ?? $input['input_text'] ?? ''));

        return $objective !== '' ? $objective : 'AWEOS autonomous work execution';
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function buildPersistentContext(string $objective, string $surfaceId, string $domain, string $flowId, string $workspace, array $evidenceRefs, array $input): array
    {
        try {
            $runtime = $this->persistentContext ?? app(AtlasPersistentContextRuntimeService::class);

            return $runtime->build([
                'prompt' => $objective,
                'surface_id' => $surfaceId,
                'domain' => $domain,
                'flow_id' => $flowId,
                'workspace' => $workspace,
                'evidence_refs' => $evidenceRefs,
                'scope_type' => $this->stringValue($input['scope_type'] ?? null) ?? 'workspace',
                'scope_id' => $this->stringValue($input['scope_id'] ?? null),
                'source_type' => 'aweos',
            ]);
        } catch (Throwable $exception) {
            return $this->degraded('atlas.persistent_context.runtime.v1', 'apcr_threw', $exception);
        }
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function buildRuntimeEfficiency(string $objective, string $surfaceId, string $domain, string $flowId, array $evidenceRefs, array $contextRefs, array $apcr, array $input): array
    {
        try {
            $runtime = $this->runtimeEfficiency ?? app(AtlasRuntimeEfficiencyGovernorService::class);

            return $runtime->govern([
                'prompt' => $objective,
                'surface_id' => $surfaceId,
                'domain' => $domain,
                'flow_id' => $flowId,
                'evidence_refs' => $evidenceRefs,
                'context_refs' => array_values(array_unique([
                    ...$contextRefs,
                    ...array_filter([$this->stringValue($apcr['context_pack_hash'] ?? null)]),
                ])),
                'source' => 'aweos',
            ]);
        } catch (Throwable $exception) {
            return $this->degraded(AtlasRuntimeEfficiencyGovernorService::SCHEMA_VERSION, 'areg_threw', $exception);
        }
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function buildAgenticWorkcell(string $objective, string $surfaceId, string $domain, string $flowId, array $evidenceRefs, array $contextRefs, array $areg, array $input): array
    {
        try {
            $runtime = $this->agenticWorkcell ?? app(AtlasAgenticWorkcellRuntimeService::class);

            return $runtime->design([
                'objective' => $objective,
                'surface_id' => $surfaceId,
                'domain' => $domain,
                'flow_id' => $flowId,
                'evidence_refs' => $evidenceRefs,
                'context_refs' => $contextRefs,
                'areg_decision_hash' => $areg['decision_hash'] ?? null,
                'source' => 'aweos',
            ]);
        } catch (Throwable $exception) {
            return $this->degraded(AtlasAgenticWorkcellRuntimeService::WORKCELL_SCHEMA, 'aawr_threw', $exception);
        }
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function openAemorEpisode(string $objective, string $surfaceId, string $domain, string $flowId, string $workspace, array $evidenceRefs, array $apcr, array $input): array
    {
        try {
            $runtime = $this->aemor ?? app(AtlasAemorRuntimeService::class);

            return $runtime->openEpisode([
                'objective' => $objective,
                'surface_id' => $surfaceId,
                'domain' => $domain,
                'flow_id' => $flowId,
                'workspace' => $workspace,
                'persistent_context_hash' => $this->stringValue($apcr['persistent_context_hash'] ?? null),
                'apcr_pack_id' => $this->uuidOrNull($apcr['persistent_context_pack_id'] ?? null),
                'evidence_refs' => $evidenceRefs,
                'source' => 'aweos',
            ]);
        } catch (Throwable $exception) {
            return $this->degraded(AtlasAemorRuntimeService::EPISODE_SCHEMA, 'aemor_threw', $exception);
        }
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function buildVerifiedExecution(?AtlasAweosExecution $execution, string $objective, string $surfaceId, string $domain, string $flowId, string $workspace, array $evidenceRefs, array $input): array
    {
        try {
            $runtime = $this->verifiedExecution ?? app(AtlasVerifiedExecutionRuntimeService::class);
            $verifiedEvolution = $this->verifiedEvolution ?? app(AtlasVerifiedEvolutionRuntimeService::class);
            $expectedFiles = $this->stringList($input['expected_files'] ?? []);
            $target = $this->stringValue($input['target'] ?? null) ?? ($expectedFiles[0] ?? null);
            if ($target !== null) {
                $contract = $verifiedEvolution->executionContract($objective, $target);
                $payload = $runtime->planFromVerifiedEvolutionContract($contract, [
                    'aweos_execution_id' => $execution?->id,
                    'workspace' => $workspace,
                    'evidence_refs' => array_values(array_unique([
                        ...$evidenceRefs,
                        ...array_filter([$execution?->execution_hash]),
                    ])),
                ]);

                return [
                    ...$payload,
                    'verified_evolution_contract' => $contract,
                    'aweos_bridge' => [
                        'schema_version' => 'atlas.aweos.verified_evolution_bridge.v1',
                        'status' => ($payload['status'] ?? null) === AtlasVerifiedExecutionRuntimeService::STATUS_READY ? self::STATUS_READY : self::STATUS_BLOCKED,
                        'target' => $target,
                        'uses_verified_evolution_contract' => true,
                    ],
                ];
            }

            return $runtime->plan([
                'objective' => $objective,
                'surface_id' => $surfaceId,
                'domain' => $domain,
                'flow_id' => $flowId,
                'workspace' => $workspace,
                'aweos_execution_id' => $execution?->id,
                'expected_files' => $expectedFiles,
                'expected_commands' => $this->stringList($input['expected_tests'] ?? $this->defaultTests($domain)),
                'evidence_refs' => array_values(array_unique([
                    ...$evidenceRefs,
                    ...array_filter([$execution?->execution_hash]),
                ])),
                'source' => 'aweos',
            ]);
        } catch (Throwable $exception) {
            return $this->degraded(AtlasVerifiedExecutionRuntimeService::EXECUTION_SCHEMA, 'aver_threw', $exception);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function executionPlan(string $objective, string $domain, string $flowId, array $apcr, array $areg, array $aawr, array $input): array
    {
        $risk = (int) ($areg['risk_score'] ?? 5);
        $steps = [
            ['id' => 'step_01_context', 'kind' => 'context', 'action' => 'load_apcr_context_pack', 'required_hash' => $apcr['persistent_context_hash'] ?? null],
            ['id' => 'step_02_budget', 'kind' => 'governance', 'action' => 'apply_areg_budget_and_tool_policy', 'required_hash' => $areg['decision_hash'] ?? null],
            ['id' => 'step_03_workcell', 'kind' => 'organization', 'action' => 'execute_or_display_aawr_workcell_contract', 'required_hash' => $aawr['workcell_hash'] ?? null],
            ['id' => 'step_04_runtime', 'kind' => 'execution', 'action' => $this->runtimeTarget($domain, $flowId), 'required_receipts' => ['task_output', 'command_ledger', 'evidence_refs']],
            ['id' => 'step_05_verify', 'kind' => 'verification', 'action' => 'run_certification_and_replay_gate', 'required_receipts' => ['test_result', 'replay_manifest', 'certified_outcome']],
        ];

        return [
            'schema_version' => 'atlas.aweos.execution_plan.v1',
            'status' => $risk >= 9 ? self::STATUS_BLOCKED : self::STATUS_READY,
            'runtime_target' => $this->runtimeTarget($domain, $flowId),
            'objective_hash' => MissionCanonicalHash::sha256(['objective' => $objective]),
            'expected_files' => $this->stringList($input['expected_files'] ?? []),
            'expected_tests' => $this->stringList($input['expected_tests'] ?? $this->defaultTests($domain)),
            'rollback_strategy' => [
                'requires_clean_diff_review' => true,
                'requires_command_ledger' => true,
                'requires_evidence_before_memory' => true,
            ],
            'completion_gate' => [
                'requires_certified_outcome' => true,
                'minimum_level' => $risk >= 7 ? 'silver' : 'bronze',
                'completion_without_evidence_allowed' => false,
            ],
            'steps' => $steps,
            'plan_hash' => MissionCanonicalHash::sha256([$domain, $flowId, $steps]),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function toolOrchestration(string $domain, string $flowId, array $areg, array $executionPlan, array $input): array
    {
        return [
            'schema_version' => 'atlas.aweos.tool_orchestration.v1',
            'status' => $executionPlan['status'],
            'tool_budget' => (int) ($areg['tool_budget'] ?? 0),
            'allowed_tool_groups' => (array) data_get($areg, 'tool_policy.allowed_tool_groups', []),
            'runtime_target' => $executionPlan['runtime_target'],
            'tool_selection_policy' => [
                'prefer_simple_unix_style_tools' => true,
                'specialized_tool_requires_roi_reason' => true,
                'external_side_effects_allowed' => false,
                'create_new_tool_only_when_gap_repeats' => true,
            ],
            'capability_gap_detection' => [
                'enabled' => true,
                'route_gap_to_intelligence_factory' => true,
                'requires_aemor_outcome_before_default' => true,
            ],
            'orchestration_hash' => MissionCanonicalHash::sha256([$domain, $flowId, $areg['decision_hash'] ?? null, $executionPlan['plan_hash'] ?? null]),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function repairRecoveryLoop(string $domain, string $flowId, array $executionPlan, array $areg): array
    {
        return [
            'schema_version' => 'atlas.aweos.repair_recovery_loop.v1',
            'enabled' => true,
            'failure_classifier' => ['test_failure', 'context_missing', 'policy_blocked', 'tool_failure', 'scope_drift', 'provider_low_quality'],
            'safe_resume_modes' => ['execute', 'read_only', 'repair', 'review', 'ask_human', 'blocked', 'escalate_to_forge'],
            'stop_conditions' => ['certified_outcome_passed', 'operator_blocked', 'safety_gate_blocked', 'max_repair_attempts'],
            'max_repair_attempts' => ((int) ($areg['risk_score'] ?? 5)) >= 8 ? 1 : 3,
            'fresh_context_required_after_failure' => true,
            'repair_hash' => MissionCanonicalHash::sha256([$domain, $flowId, $executionPlan['plan_hash'] ?? null]),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function operatorDecisionEconomy(string $objective, string $domain, string $flowId, array $areg, array $aawr, array $executionPlan): array
    {
        $decisions = [];
        if (($executionPlan['status'] ?? null) === self::STATUS_BLOCKED) {
            $decisions[] = ['id' => 'blocked_execution', 'question' => 'Autorizar replanejamento ou pausar?', 'risk' => 'high'];
        }
        if ((int) ($areg['risk_score'] ?? 0) >= 7) {
            $decisions[] = ['id' => 'high_risk_approval', 'question' => 'Aprovar execucao em modo restrito?', 'risk' => 'high'];
        }
        if (($aawr['topology'] ?? null) === 'forge_milestone_crew') {
            $decisions[] = ['id' => 'forge_promotion_review', 'question' => 'Confirmar Obra/Forge como runtime principal?', 'risk' => 'medium'];
        }
        if ($decisions === []) {
            $decisions[] = ['id' => 'continue_next_safe_action', 'question' => 'Continuar com o proximo passo seguro?', 'risk' => 'low'];
        }

        return [
            'schema_version' => 'atlas.aweos.operator_decision_economy.v1',
            'status' => self::STATUS_READY,
            'principle' => 'ask_the_smallest_human_decision_needed',
            'human_prompt_reduction_target' => 'operator_decisions_not_context_retyping',
            'decisions' => $decisions,
            'objective_hash' => MissionCanonicalHash::sha256(['objective' => $objective]),
            'decision_economy_hash' => MissionCanonicalHash::sha256([$domain, $flowId, $decisions]),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function continuationEngine(string $objective, string $domain, string $flowId, array $apcr, array $aemorEpisode, array $executionPlan): array
    {
        return [
            'schema_version' => 'atlas.aweos.continuation_engine.v1',
            'status' => self::STATUS_READY,
            'resume_contract' => [
                'requires_persistent_context_hash' => true,
                'requires_aemor_episode' => true,
                'requires_freshness_check' => true,
                'requires_next_safe_action' => true,
            ],
            'persistent_context_hash' => $apcr['persistent_context_hash'] ?? null,
            'aemor_episode_hash' => $aemorEpisode['episode_hash'] ?? null,
            'next_context_refresh' => data_get($apcr, 'sufficiency.status') === 'blocked' ? 'required_before_execution' : 'before_write',
            'continuation_pack' => [
                'objective_hash' => MissionCanonicalHash::sha256(['objective' => $objective]),
                'flow_id' => $flowId,
                'runtime_target' => $executionPlan['runtime_target'] ?? null,
                'next_step' => data_get($executionPlan, 'steps.0.id'),
            ],
            'continuation_hash' => MissionCanonicalHash::sha256([$domain, $flowId, $apcr['persistent_context_hash'] ?? null, $executionPlan['plan_hash'] ?? null]),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function strategicNextAction(string $domain, string $flowId, array $executionPlan, array $operatorDecisionEconomy): array
    {
        $blocked = ($executionPlan['status'] ?? null) === self::STATUS_BLOCKED;

        return [
            'schema_version' => 'atlas.aweos.strategic_next_action.v1',
            'status' => $blocked ? self::STATUS_BLOCKED : self::STATUS_READY,
            'next_action' => $blocked ? 'resolve_blocker_or_replan' : 'execute_first_work_packet_with_receipts',
            'reason' => $blocked ? 'execution_plan_blocked' : 'context_budget_workcell_and_verification_ready',
            'operator_decision_id' => data_get($operatorDecisionEconomy, 'decisions.0.id'),
            'next_action_hash' => MissionCanonicalHash::sha256([$domain, $flowId, $executionPlan['plan_hash'] ?? null, data_get($operatorDecisionEconomy, 'decisions.0.id')]),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function missionControl(string $domain, string $flowId, array $executionPlan, array $operatorDecisionEconomy, array $continuationEngine): array
    {
        return [
            'schema_version' => 'atlas.aweos.mission_control.v1',
            'status' => $executionPlan['status'] ?? self::STATUS_READY,
            'surface_name' => 'Atlas Mission Control',
            'cards' => [
                ['id' => 'context', 'status' => $continuationEngine['status'] ?? self::STATUS_READY, 'label' => 'Contexto persistente'],
                ['id' => 'plan', 'status' => $executionPlan['status'] ?? self::STATUS_READY, 'label' => 'Plano operacional'],
                ['id' => 'operator', 'status' => self::STATUS_READY, 'label' => 'Decisoes pendentes', 'count' => count((array) ($operatorDecisionEconomy['decisions'] ?? []))],
            ],
            'domain' => $domain,
            'flow_id' => $flowId,
            'mission_control_hash' => MissionCanonicalHash::sha256([$domain, $flowId, $executionPlan['plan_hash'] ?? null, $operatorDecisionEconomy['decision_economy_hash'] ?? null]),
        ];
    }

    private function status(array $apcr, array $areg, array $aawr, array $executionPlan): string
    {
        if (($executionPlan['status'] ?? null) === self::STATUS_BLOCKED || ($areg['status'] ?? null) === self::STATUS_BLOCKED) {
            return self::STATUS_BLOCKED;
        }
        if (($apcr['status'] ?? null) === self::STATUS_BLOCKED || ($aawr['status'] ?? null) === self::STATUS_BLOCKED) {
            return self::STATUS_WATCH;
        }

        return self::STATUS_READY;
    }

    private function certificationLevel(array $evidenceRefs, array $commandLedger, array $testImpact, float $qualityScore): string
    {
        if ($evidenceRefs === []) {
            return 'blocked';
        }
        if ($qualityScore >= 0.90 && $commandLedger !== [] && $testImpact !== []) {
            return 'gold';
        }
        if ($qualityScore >= 0.75 && ($commandLedger !== [] || $testImpact !== [])) {
            return 'silver';
        }

        return 'bronze';
    }

    /**
     * @return array<string,mixed>|null
     */
    private function closeAemorOutcome(AtlasAweosExecution $execution, string $claim, array $evidenceRefs, float $qualityScore): ?array
    {
        if (! is_string($execution->aemor_episode_id)) {
            return null;
        }
        try {
            $runtime = $this->aemor ?? app(AtlasAemorRuntimeService::class);

            return $runtime->closeOutcome([
                'episode_id' => $execution->aemor_episode_id,
                'status' => $evidenceRefs === [] ? self::STATUS_BLOCKED : 'succeeded',
                'summary' => $claim,
                'metrics' => ['quality_score' => $qualityScore],
                'evidence_refs' => $evidenceRefs,
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function recordAregOutcome(AtlasAweosExecution $execution, array $evidenceRefs, float $qualityScore): ?array
    {
        if (! is_string($execution->runtime_efficiency_decision_id)) {
            return null;
        }
        try {
            $runtime = $this->runtimeEfficiency ?? app(AtlasRuntimeEfficiencyGovernorService::class);

            return $runtime->recordOutcome([
                'decision_id' => $execution->runtime_efficiency_decision_id,
                'quality_score' => $qualityScore,
                'context_roi_score' => 0.80,
                'status' => $evidenceRefs === [] ? self::STATUS_BLOCKED : self::STATUS_READY,
                'evidence_refs' => $evidenceRefs,
                'signals' => ['aweos_certified_outcome' => true],
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function closeAawrOutcome(AtlasAweosExecution $execution, array $evidenceRefs, float $qualityScore): ?array
    {
        if (! is_string($execution->workcell_id)) {
            return null;
        }
        try {
            $runtime = $this->agenticWorkcell ?? app(AtlasAgenticWorkcellRuntimeService::class);

            return $runtime->closeOutcome([
                'workcell_id' => $execution->workcell_id,
                'quality_score' => $qualityScore,
                'coordination_roi_score' => 0.75,
                'evidence_refs' => $evidenceRefs,
                'signals' => ['aweos_certified_outcome' => true],
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function patchBoundary(AtlasAweosExecution $execution): array
    {
        return [
            'scope' => 'declared_by_runtime_target',
            'runtime_target' => data_get($execution->execution_plan, 'runtime_target'),
            'forbidden' => ['unrelated_refactor', 'secret_exposure', 'test_weakening'],
            'requires_diff_review' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function evidenceBundle(AtlasAweosExecution $execution, array $evidenceRefs, array $commandLedger, array $testImpact): array
    {
        return [
            'execution_hash' => $execution->execution_hash,
            'context_hash' => $execution->persistent_context_hash,
            'plan_hash' => data_get($execution->execution_plan, 'plan_hash'),
            'evidence_refs' => $evidenceRefs,
            'command_ledger_present' => $commandLedger !== [],
            'test_impact_present' => $testImpact !== [],
            'completion_without_evidence_allowed' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function replayManifest(AtlasAweosExecution $execution, array $evidenceRefs, array $commandLedger, array $testImpact): array
    {
        $manifest = [
            'schema_version' => 'atlas.aweos.replay_manifest.v1',
            'execution_hash' => $execution->execution_hash,
            'objective_hash' => $execution->objective_hash,
            'context_hash' => $execution->persistent_context_hash,
            'workcell_id' => $execution->workcell_id,
            'runtime_target' => data_get($execution->execution_plan, 'runtime_target'),
            'commands' => $commandLedger,
            'tests' => $testImpact,
            'evidence_refs' => $evidenceRefs,
        ];
        $manifest['replay_hash'] = MissionCanonicalHash::sha256($manifest);

        return $manifest;
    }

    /**
     * @return array<string,mixed>
     */
    private function learningDecision(string $certificationLevel, array $evidenceRefs, float $qualityScore): array
    {
        return [
            'status' => in_array($certificationLevel, ['silver', 'gold'], true) ? 'candidate' : 'quarantined',
            'promotion_allowed' => false,
            'requires_aemor_judgment_guard' => true,
            'requires_human_review_for_policy_change' => true,
            'reason' => $evidenceRefs === [] ? 'missing_evidence' : 'certified_outcome_recorded',
            'quality_score' => $qualityScore,
        ];
    }

    /**
     * @return list<string>
     */
    private function defaultTests(string $domain): array
    {
        return match ($domain) {
            'programming' => ['focused_feature_or_unit_tests', 'diff_review'],
            'research' => ['source_coverage_audit', 'claim_uncertainty_audit'],
            'finance' => ['freshness_and_risk_disclosure'],
            default => ['evidence_refs_check', 'certification_check'],
        };
    }

    private function runtimeTarget(string $domain, string $flowId): string
    {
        if (str_contains($flowId, 'forge')) {
            return 'atlas_forge';
        }

        return match ($domain) {
            'programming' => 'atlas_dev',
            'research' => 'atlas_research',
            'finance' => 'atlas_finance',
            'marketing' => 'atlas_marketing',
            'strategy' => 'atlas_strategy',
            default => 'atlas_ai',
        };
    }

    private function flowForDomain(string $domain, array $input): string
    {
        return match ($domain) {
            'programming' => (str_contains(Str::lower((string) ($input['objective'] ?? '')), 'obra') ? 'atlas_forge' : 'atlas_dev'),
            'research' => 'research.deep',
            'finance' => 'finance.analysis',
            'marketing' => 'marketing.strategy',
            'strategy' => 'strategy.decision',
            default => 'conversation.general',
        };
    }

    private function classifyDomain(string $objective): string
    {
        $lower = Str::lower($objective);

        return match (true) {
            str_contains($lower, 'codigo') || str_contains($lower, 'código') || str_contains($lower, 'debug') || str_contains($lower, 'teste') || str_contains($lower, 'program') => 'programming',
            str_contains($lower, 'pesquisa') || str_contains($lower, 'research') => 'research',
            str_contains($lower, 'finance') || str_contains($lower, 'invest') || str_contains($lower, 'carteira') => 'finance',
            str_contains($lower, 'marketing') || str_contains($lower, 'campanha') => 'marketing',
            str_contains($lower, 'estrateg') || str_contains($lower, 'decis') => 'strategy',
            default => 'conversation',
        };
    }

    private function normalizeDomain(string $domain): string
    {
        return Str::of($domain)->lower()->replace([' ', '-'], '_')->toString();
    }

    /**
     * @param  mixed  $value
     */
    private function stringValue($value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function stringList($value): array
    {
        return array_values(array_filter((array) $value, 'is_string'));
    }

    /**
     * @param  mixed  $value
     */
    private function uuidOrNull($value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? $value : null;
    }

    /**
     * @param  mixed  $value
     */
    private function numericOrNull($value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param  mixed  $id
     */
    private function execution($id): ?AtlasAweosExecution
    {
        return is_string($id) && DatabaseTableAvailability::has('atlas_aweos_executions')
            ? AtlasAweosExecution::query()->find($id)
            : null;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function sanitizePayload(array $payload): array
    {
        unset($payload['raw_prompt'], $payload['response_text'], $payload['secret'], $payload['token']);

        return $payload;
    }

    /**
     * @param  Collection<int,object>  $records
     * @return array<string,int>
     */
    private function countsBy(Collection $records, string $field): array
    {
        return $records->groupBy(fn (object $record): string => (string) ($record->{$field} ?? 'unknown'))
            ->map(fn (Collection $items): int => $items->count())
            ->sortKeys()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function degraded(string $schema, string $error, Throwable $exception): array
    {
        return [
            'schema_version' => $schema,
            'status' => 'degraded',
            'error' => $error,
            'exception_class' => $exception::class,
            'claim_policy' => ['provider_invoked' => false, 'external_execution_performed' => false],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function blocked(string $schema, string $reason, string $message): array
    {
        return [
            'schema_version' => $schema,
            'status' => self::STATUS_BLOCKED,
            'blockers' => [['id' => $reason, 'reason' => $message]],
            'claim_policy' => $this->claimPolicy(),
        ];
    }
}
