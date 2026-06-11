<?php

namespace App\Services\Ai\Programming;

use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevRuntimeIntelligenceService;
use App\Services\Ai\Support\AiStringListNormalizer;
use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceHandoffPackService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceExecutionGateService;
use RuntimeException;

/**
 * Atlas Dev Runtime (Meta 7).
 *
 * Camada antes da Obra. Aceita bugs, debug, review e features simples/médias
 * pelos flows canônicos programming.dev / programming.review / programming.repair.
 * Não cria Obra, não promove Forge — apenas valida o payload entrante,
 * normaliza decision_mode/provider, exige workspace para mode=programming e
 * emite um slice atlas_dev_runtime (schema v1) que viaja com o payload e é
 * exposto pelo AiTraceResource.
 *
 * Canônico em:
 *   - docs/engineering-knowledge-base/atlas-ai-conversation-surface-and-atlas-dev-v1.md
 *   - docs/engineering-knowledge-base/atlas-code-multi-project-workspace-os.md
 *   - docs/engineering-knowledge-base/open-brain-context-injection.md
 */
class AtlasDevRuntimeService
{
    public const SCHEMA_VERSION = 'atlas.dev_runtime.v1';

    public const FLOW_DEV = 'programming.dev';

    public const FLOW_REVIEW = 'programming.review';

    public const FLOW_REPAIR = 'programming.repair';

    private const SUPPORTED_FLOWS = [
        self::FLOW_DEV,
        self::FLOW_REVIEW,
        self::FLOW_REPAIR,
    ];

    private const TASK_FLOW_MAP = [
        'dev' => self::FLOW_DEV,
        'plan' => self::FLOW_DEV,
        'direct' => self::FLOW_DEV,
        'review' => self::FLOW_REVIEW,
        'debug' => self::FLOW_REPAIR,
        'repair' => self::FLOW_REPAIR,
    ];

    public const EXPECTED_ARTIFACTS = ['plan', 'diff_or_reason', 'tests_or_reason', 'risks'];

    public const REQUIRES_WORKSPACE_CODE = 'programming_requires_workspace';

    public const REQUIRES_WORKSPACE_MESSAGE = 'Atlas Dev exige um Workspace selecionado para tarefas de Programação.';

    private const ATLAS_AI_SURFACES = [
        'atlas_app',
        'atlas_desktop_ai',
        'atlas_api_interaction',
        'atlas_cli_dev',
    ];

    public function __construct(
        private readonly ?AtlasWorkspaceIntelligenceExecutionGateService $workspaceExecutionGate = null,
        private readonly ?AtlasWorkspaceHandoffPackService $workspaceHandoffPack = null,
    ) {}

    /**
     * Aplica o runtime ao payload de entrada do AiInteractionController.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     *
     * @throws RuntimeException quando o payload pede programação sem workspace
     */
    public function apply(array $data): array
    {
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];

        if (! $this->isDevRuntimeCandidate($payload)) {
            return $data;
        }

        $mode = $this->normalizeMode($payload);

        if ($mode !== 'programming') {
            return $data;
        }

        $task = $this->normalizeTask($payload);
        $flowId = $this->normalizeFlowId($payload, $task);
        $workspace = $this->extractWorkspace($payload);

        if ($workspace === null) {
            throw new RuntimeException(self::REQUIRES_WORKSPACE_MESSAGE);
        }

        [$decisionMode, $manualProvider] = $this->normalizeDecisionMode($payload);
        $workspaceSource = $this->workspaceSource($payload, $workspace);
        $explicitFlowId = AiValueNormalizer::trimmedScalarStringOrNull($payload['flow_id'] ?? null);

        $payload['atlas_mode'] = 'programming';
        $payload['routing_task'] = $task;
        // Preserva flow_id explícito do operador para auditoria (mesmo quando
        // o Domain Catalog marcou unresolved). Atlas Dev Runtime só escreve
        // o flow canon quando o payload não trouxe um.
        if ($explicitFlowId === null) {
            $payload['flow_id'] = $flowId;
        }
        // domain_id é responsabilidade do DomainCatalogSurfaceSelectionService;
        // não sobrescrevemos quando a seleção marcou unresolved.
        $payload['workspace'] = $workspace;
        $payload['decision_mode'] = $decisionMode;

        $payload['atlas_dev_runtime'] = [
            'schema_version' => self::SCHEMA_VERSION,
            'enabled' => true,
            'flow_id' => $flowId,
            'mode' => 'programming',
            'task' => $task,
            'workspace' => $workspace,
            'decision_mode' => $decisionMode,
            'provider' => $manualProvider,
            'expected_artifacts' => self::EXPECTED_ARTIFACTS,
            'requires_obra' => false,
            'workspace_source' => $workspaceSource,
            'open_brain_policy' => 'auto',
        ];
        $artifactAgentPacket = $this->artifactAgentPacket($payload);
        if ($artifactAgentPacket !== null) {
            $payload['atlas_dev_runtime']['artifact_agent_packet'] = $this->safeArtifactAgentPacket($artifactAgentPacket);
        }
        $artifactAgentPacketAllowed = $this->artifactAgentPacketAllowed($artifactAgentPacket, $workspace);
        if ($artifactAgentPacket !== null && $artifactAgentPacketAllowed !== true) {
            $payload['atlas_dev_runtime']['artifact_agent_packet_blockers'] = $artifactAgentPacketAllowed;
        }

        $workspaceGate = $this->workspaceExecutionGate?->gate(
            workspace: $workspace,
            mode: 'dev',
            task: AiValueNormalizer::trimmedScalarStringOrNull($payload['input_text'] ?? null)
                ?? AiValueNormalizer::trimmedScalarStringOrNull($payload['prompt'] ?? null)
                ?? $flowId,
        );
        $expectedFiles = AiStringListNormalizer::uniqueTrimmedScalarValues($payload['expected_files'] ?? []);
        $workspaceContextSelection = $this->workspaceContextSelection(
            $workspaceGate,
            $expectedFiles,
            AiValueNormalizer::trimmedScalarStringOrNull($payload['risk_band'] ?? null),
        );
        $payload['atlas_dev_runtime']['workspace_context_selection'] = $workspaceContextSelection;

        $runtimeIntelligence = (new DevRuntimeIntelligenceService)->preview([
            'run_id' => AiValueNormalizer::trimmedScalarStringOrNull($payload['run_id'] ?? null)
                ?? AiValueNormalizer::trimmedScalarStringOrNull($payload['trace_id'] ?? null)
                ?? 'dev-runtime-preview',
            'task_id' => AiValueNormalizer::trimmedScalarStringOrNull($payload['task_id'] ?? null) ?? $flowId,
            'objective' => AiValueNormalizer::trimmedScalarStringOrNull($payload['input_text'] ?? null)
                ?? AiValueNormalizer::trimmedScalarStringOrNull($payload['prompt'] ?? null)
                ?? 'Atlas Dev programming request',
            'task_class' => $task === 'debug' ? 'debug' : ($task === 'review' ? 'review' : 'feature'),
            'risk_band' => AiValueNormalizer::trimmedScalarStringOrNull($payload['risk_band'] ?? null) ?? 'medium',
            'workspace_slug' => $workspace,
            'allowed_files' => AiStringListNormalizer::uniqueTrimmedScalarValues(data_get($artifactAgentPacket, 'allowed_paths', data_get($payload, 'tool_permissions.allowed_files', []))),
            'forbidden_files' => AiStringListNormalizer::uniqueTrimmedScalarValues(data_get($artifactAgentPacket, 'forbidden_paths', data_get($payload, 'tool_permissions.forbidden_files', []))),
            'context_refs' => $this->mergeStrings(
                AiStringListNormalizer::uniqueTrimmedScalarValues(data_get($artifactAgentPacket, 'context_refs', $payload['context_refs'] ?? [])),
                (array) ($workspaceContextSelection['context_refs'] ?? []),
            ),
            'expected_files' => $expectedFiles,
            'suggested_tests' => $this->mergeStrings(
                AiStringListNormalizer::uniqueTrimmedScalarValues(data_get($artifactAgentPacket, 'test_plan', $payload['suggested_tests'] ?? [])),
                (array) ($workspaceContextSelection['suggested_tests'] ?? []),
            ),
            'acceptance_criteria' => AiStringListNormalizer::uniqueTrimmedScalarValues(data_get($artifactAgentPacket, 'done_when', $payload['acceptance_criteria'] ?? [])),
            'required_evidence' => self::EXPECTED_ARTIFACTS,
            'source' => $artifactAgentPacket !== null
                ? 'AtlasDevRuntimeService:artifact_agent_packet+awis_context_loading_plan'
                : 'AtlasDevRuntimeService:awis_context_loading_plan',
        ]);
        $payload['atlas_dev_runtime_intelligence'] = $runtimeIntelligence;
        if (is_array($workspaceGate)) {
            $payload['atlas_dev_runtime']['workspace_execution_gate'] = $workspaceGate;
            $payload['atlas_dev_runtime']['workspace_next_session_brain'] = [
                'schema_version' => 'atlas.dev_runtime.workspace_next_session_brain.v1',
                'brain_hash' => data_get($workspaceGate, 'execution_context.workspace_next_session_brain_hash'),
                'load_order' => array_values((array) data_get($workspaceGate, 'execution_context.load_order', [])),
                'focused_repositories' => array_values((array) data_get($workspaceGate, 'execution_context.focused_repositories', [])),
                'focused_areas' => array_values((array) data_get($workspaceGate, 'execution_context.focused_areas', [])),
                'execution_priority' => array_values((array) data_get($workspaceGate, 'execution_context.execution_priority', [])),
                'context_loading_plan' => (array) data_get($workspaceGate, 'execution_context.context_loading_plan', []),
                'provider_safe' => (bool) data_get($workspaceGate, 'execution_context.provider_safe', false),
                'raw_content_returned' => false,
            ];
        }
        $handoffPack = ($this->workspaceHandoffPack ?? app(AtlasWorkspaceHandoffPackService::class))->build(
            workspace: $workspace,
            task: AiValueNormalizer::trimmedScalarStringOrNull($payload['input_text'] ?? null)
                ?? AiValueNormalizer::trimmedScalarStringOrNull($payload['prompt'] ?? null)
                ?? $flowId,
            consumer: 'atlas_dev',
        );
        $payload['atlas_dev_runtime']['workspace_handoff_pack'] = $handoffPack;

        $providerSafe = (bool) ($runtimeIntelligence['provider_safe'] ?? false);
        $workspaceAllowed = ! is_array($workspaceGate) || (bool) ($workspaceGate['allowed'] ?? false);
        $handoffAllowed = ($handoffPack['status'] ?? null) === 'ready'
            && (bool) data_get($handoffPack, 'claim_policy.safe_for_provider_prompt', false);
        $payload['atlas_dev_runtime']['provider_safe'] = $providerSafe && $workspaceAllowed && $handoffAllowed && $artifactAgentPacketAllowed === true;
        $payload['atlas_dev_runtime']['provider_execution_allowed'] = $providerSafe && $workspaceAllowed && $handoffAllowed && $artifactAgentPacketAllowed === true;
        $payload['atlas_dev_runtime']['native_capability_status'] = (string) data_get($runtimeIntelligence, 'native_capabilities.status', 'unknown');
        $payload['atlas_dev_runtime']['native_capability_blockers'] = data_get($runtimeIntelligence, 'native_capabilities.blockers', []);

        $data['payload'] = $payload;

        return $data;
    }

    /**
     * @return array<int,string>
     */
    public function supportedFlows(): array
    {
        return self::SUPPORTED_FLOWS;
    }

    /**
     * @param  array<string,mixed>|null  $workspaceGate
     * @return array<string,mixed>
     */
    private function workspaceContextSelection(?array $workspaceGate, array $expectedFiles = [], ?string $riskBand = null): array
    {
        $contextLoadingPlan = (array) data_get($workspaceGate, 'execution_context.context_loading_plan', []);
        $contextRefs = [];
        foreach ((array) data_get($workspaceGate, 'execution_context.focused_repositories', []) as $repository) {
            if (! is_array($repository)) {
                continue;
            }
            $repoKey = AiValueNormalizer::trimmedScalarStringOrNull($repository['repo_key'] ?? null);
            if ($repoKey !== null) {
                $contextRefs[] = 'awis_repo:'.$repoKey;
            }
        }

        foreach ((array) ($contextLoadingPlan['focused_manifest_refs'] ?? []) as $manifestRef) {
            if (! is_array($manifestRef)) {
                continue;
            }
            $repoKey = AiValueNormalizer::trimmedScalarStringOrNull($manifestRef['repo_key'] ?? null);
            if ($repoKey === null) {
                continue;
            }
            foreach (AiStringListNormalizer::uniqueTrimmedScalarValues($manifestRef['manifest_files'] ?? []) as $manifestFile) {
                $contextRefs[] = 'awis_manifest:'.$repoKey.':'.$manifestFile;
            }
        }

        foreach (AiStringListNormalizer::uniqueTrimmedScalarValues($contextLoadingPlan['stack_tags'] ?? []) as $stackTag) {
            $contextRefs[] = 'awis_stack:'.$stackTag;
        }

        $repositoryInventoryHash = AiValueNormalizer::trimmedScalarStringOrNull($contextLoadingPlan['repository_inventory_hash'] ?? null);
        if ($repositoryInventoryHash !== null) {
            $contextRefs[] = 'awis_cache:repository_inventory:'.$repositoryInventoryHash;
        }
        $workspaceWorkingSetHash = AiValueNormalizer::trimmedScalarStringOrNull($contextLoadingPlan['working_set_hash'] ?? data_get($contextLoadingPlan, 'cache_keys.workspace_working_set_hash'));
        if ($workspaceWorkingSetHash !== null) {
            $contextRefs[] = 'awis_cache:workspace_working_set:'.$workspaceWorkingSetHash;
        }
        $contextDeltaPlanHash = AiValueNormalizer::trimmedScalarStringOrNull($contextLoadingPlan['context_delta_plan_hash'] ?? data_get($contextLoadingPlan, 'cache_keys.context_delta_plan_hash'));
        if ($contextDeltaPlanHash !== null) {
            $contextRefs[] = 'awis_cache:context_delta_plan:'.$contextDeltaPlanHash;
        }
        $outcomeCommandMemoryHash = AiValueNormalizer::trimmedScalarStringOrNull($contextLoadingPlan['outcome_command_memory_hash'] ?? null);
        if ($outcomeCommandMemoryHash !== null) {
            $contextRefs[] = 'awis_cache:outcome_command_memory:'.$outcomeCommandMemoryHash;
        }
        $performanceHistogramHash = AiValueNormalizer::trimmedScalarStringOrNull($contextLoadingPlan['command_performance_histogram_hash'] ?? null);
        if ($performanceHistogramHash !== null) {
            $contextRefs[] = 'awis_cache:command_performance_histogram:'.$performanceHistogramHash;
        }
        $areaPerformanceIndexHash = AiValueNormalizer::trimmedScalarStringOrNull($contextLoadingPlan['area_performance_index_hash'] ?? null);
        if ($areaPerformanceIndexHash !== null) {
            $contextRefs[] = 'awis_cache:area_performance_index:'.$areaPerformanceIndexHash;
        }
        $stackPerformanceIndexHash = AiValueNormalizer::trimmedScalarStringOrNull($contextLoadingPlan['stack_performance_index_hash'] ?? null);
        if ($stackPerformanceIndexHash !== null) {
            $contextRefs[] = 'awis_cache:stack_performance_index:'.$stackPerformanceIndexHash;
        }
        $workspaceLearningSnapshotHash = AiValueNormalizer::trimmedScalarStringOrNull($contextLoadingPlan['learning_snapshot_hash'] ?? data_get($contextLoadingPlan, 'cache_keys.workspace_learning_snapshot_hash'));
        if ($workspaceLearningSnapshotHash !== null) {
            $contextRefs[] = 'awis_cache:workspace_learning_snapshot:'.$workspaceLearningSnapshotHash;
        }
        $executionOptimizationPolicyHash = AiValueNormalizer::trimmedScalarStringOrNull($contextLoadingPlan['execution_optimization_policy_hash'] ?? data_get($contextLoadingPlan, 'cache_keys.execution_optimization_policy_hash'));
        if ($executionOptimizationPolicyHash !== null) {
            $contextRefs[] = 'awis_cache:execution_optimization_policy:'.$executionOptimizationPolicyHash;
        }
        $executionPolicyEffectivenessIndexHash = AiValueNormalizer::trimmedScalarStringOrNull($contextLoadingPlan['execution_policy_effectiveness_index_hash'] ?? data_get($contextLoadingPlan, 'cache_keys.execution_policy_effectiveness_index_hash'));
        if ($executionPolicyEffectivenessIndexHash !== null) {
            $contextRefs[] = 'awis_cache:execution_policy_effectiveness:'.$executionPolicyEffectivenessIndexHash;
        }
        $executionRouteEffectivenessIndexHash = AiValueNormalizer::trimmedScalarStringOrNull($contextLoadingPlan['execution_route_effectiveness_index_hash'] ?? data_get($contextLoadingPlan, 'cache_keys.execution_route_effectiveness_index_hash'));
        if ($executionRouteEffectivenessIndexHash !== null) {
            $contextRefs[] = 'awis_cache:execution_route_effectiveness:'.$executionRouteEffectivenessIndexHash;
        }
        $validationDepthDecision = $this->validationDepthDecision(
            $contextLoadingPlan,
            $expectedFiles,
            $riskBand ?? AiValueNormalizer::trimmedScalarStringOrNull(data_get($workspaceGate, 'risk_band')),
        );
        $contextRefs = $this->mergeStrings($contextRefs, $this->validationDepthContextRefs($validationDepthDecision));

        $suggestedTests = $this->scopeRouteSuggestedTests($contextLoadingPlan, $expectedFiles);
        foreach ((array) data_get($workspaceGate, 'execution_context.execution_priority', []) as $priority) {
            if (! is_array($priority)) {
                continue;
            }
            $command = AiValueNormalizer::trimmedScalarStringOrNull($priority['command'] ?? null);
            if ($command !== null) {
                $suggestedTests[] = $command;
            }
        }
        $suggestedTests = $this->mergeStrings(AiStringListNormalizer::uniqueTrimmedScalarValues(data_get($contextLoadingPlan, 'execution_optimization_policy.preferred_commands', [])), $suggestedTests);
        $suggestedTests = $this->mergeStrings($suggestedTests, AiStringListNormalizer::uniqueTrimmedScalarValues(data_get($contextLoadingPlan, 'execution_optimization_policy.standard_commands', [])));
        $suggestedTests = $this->mergeStrings($suggestedTests, AiStringListNormalizer::uniqueTrimmedScalarValues($contextLoadingPlan['area_ranked_commands'] ?? []));
        $suggestedTests = $this->mergeStrings($suggestedTests, AiStringListNormalizer::uniqueTrimmedScalarValues($contextLoadingPlan['outcome_ranked_commands'] ?? []));
        $suggestedTests = $this->mergeStrings($suggestedTests, AiStringListNormalizer::uniqueTrimmedScalarValues($contextLoadingPlan['command_hints'] ?? []));
        $avoidTests = $this->mergeStrings(
            AiStringListNormalizer::uniqueTrimmedScalarValues($contextLoadingPlan['avoid_commands'] ?? []),
            AiStringListNormalizer::uniqueTrimmedScalarValues($contextLoadingPlan['slow_commands'] ?? []),
            AiStringListNormalizer::uniqueTrimmedScalarValues(data_get($contextLoadingPlan, 'execution_optimization_policy.blocked_commands', [])),
        );
        $suggestedTests = array_values(array_filter(
            $suggestedTests,
            static fn (string $command): bool => ! in_array($command, $avoidTests, true),
        ));
        $contextRefs = $this->mergeStrings(
            $contextRefs,
            $this->scopeRouteSelectionRefs($contextLoadingPlan, $expectedFiles, $suggestedTests),
        );

        return [
            'schema_version' => 'atlas.dev_runtime.awis_context_selection.v1',
            'source' => 'workspace_next_session_brain.context_loading_plan',
            'context_refs' => array_slice($this->mergeStrings([], $contextRefs), 0, 40),
            'suggested_tests' => array_slice($this->mergeStrings([], $suggestedTests), 0, 12),
            'repository_inventory_hash' => $repositoryInventoryHash,
            'workspace_working_set_hash' => $workspaceWorkingSetHash,
            'context_delta_plan_hash' => $contextDeltaPlanHash,
            'outcome_command_memory_hash' => $outcomeCommandMemoryHash,
            'command_performance_histogram_hash' => $performanceHistogramHash,
            'area_performance_index_hash' => $areaPerformanceIndexHash,
            'stack_performance_index_hash' => $stackPerformanceIndexHash,
            'workspace_learning_snapshot_hash' => $workspaceLearningSnapshotHash,
            'execution_optimization_policy_hash' => $executionOptimizationPolicyHash,
            'execution_policy_effectiveness_index_hash' => $executionPolicyEffectivenessIndexHash,
            'execution_route_effectiveness_index_hash' => $executionRouteEffectivenessIndexHash,
            'validation_depth_decision' => $validationDepthDecision,
            'provider_safe' => data_get($workspaceGate, 'execution_context.provider_safe') === true
                && data_get($workspaceGate, 'execution_context.context_loading_plan.provider_policy.raw_manifest_returned') === false
                && data_get($workspaceGate, 'execution_context.context_loading_plan.provider_policy.script_bodies_returned') === false
                && data_get($workspaceGate, 'execution_context.context_loading_plan.provider_policy.absolute_workspace_path_returned') === false,
            'raw_content_returned' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $contextLoadingPlan
     * @param  array<int,string>  $expectedFiles
     * @return array<int,string>
     */
    private function scopeRouteSuggestedTests(array $contextLoadingPlan, array $expectedFiles): array
    {
        if ($expectedFiles === []) {
            return [];
        }

        $commands = [];
        foreach ((array) data_get($contextLoadingPlan, 'execution_optimization_policy.scope_routing.area_routes', []) as $route) {
            if (! is_array($route)) {
                continue;
            }
            $key = AiValueNormalizer::trimmedScalarStringOrNull($route['key'] ?? null);
            if ($key === null || ! $this->filesMatchScope($expectedFiles, $key)) {
                continue;
            }
            $commands = $this->mergeStrings($commands, AiStringListNormalizer::uniqueTrimmedScalarValues($route['preferred_commands'] ?? []));
        }
        if ($commands !== []) {
            return $commands;
        }

        $stacks = $this->stacksForExpectedFiles($contextLoadingPlan, $expectedFiles);
        foreach ((array) data_get($contextLoadingPlan, 'execution_optimization_policy.scope_routing.stack_routes', []) as $route) {
            if (! is_array($route)) {
                continue;
            }
            $key = AiValueNormalizer::trimmedScalarStringOrNull($route['key'] ?? null);
            if ($key === null || ! in_array($key, $stacks, true)) {
                continue;
            }
            $commands = $this->mergeStrings($commands, AiStringListNormalizer::uniqueTrimmedScalarValues($route['preferred_commands'] ?? []));
        }

        return $commands;
    }

    /**
     * @param  array<string,mixed>  $contextLoadingPlan
     * @param  array<int,string>  $expectedFiles
     * @param  array<int,string>  $suggestedTests
     * @return array<int,string>
     */
    private function scopeRouteSelectionRefs(array $contextLoadingPlan, array $expectedFiles, array $suggestedTests): array
    {
        $selected = $this->matchingScopeRoute($contextLoadingPlan, $expectedFiles);
        if ($selected === null) {
            return [];
        }

        $refs = [];
        foreach (AiStringListNormalizer::uniqueTrimmedScalarValues($selected['commands'] ?? []) as $command) {
            if (! in_array($command, $suggestedTests, true)) {
                continue;
            }
            $refs[] = 'awis_execution_route_command:'.hash('sha256', $command).':'
                .$selected['kind'].':'.hash('sha256', (string) $selected['key']);
        }

        return $refs;
    }

    /**
     * @param  array<string,mixed>  $contextLoadingPlan
     * @param  array<int,string>  $expectedFiles
     * @return array<string,mixed>|null
     */
    private function matchingScopeRoute(array $contextLoadingPlan, array $expectedFiles): ?array
    {
        if ($expectedFiles === []) {
            return null;
        }

        foreach ((array) data_get($contextLoadingPlan, 'execution_optimization_policy.scope_routing.area_routes', []) as $route) {
            if (! is_array($route)) {
                continue;
            }
            $key = AiValueNormalizer::trimmedScalarStringOrNull($route['key'] ?? null);
            if ($key !== null && $this->filesMatchScope($expectedFiles, $key)) {
                return [
                    'kind' => 'area',
                    'key' => $key,
                    'route_ref' => AiValueNormalizer::trimmedScalarStringOrNull($route['route_ref'] ?? null) ?? 'area:'.hash('sha256', $key),
                    'commands' => AiStringListNormalizer::uniqueTrimmedScalarValues($route['preferred_commands'] ?? []),
                    'recommended_validation_tier' => AiValueNormalizer::trimmedScalarStringOrNull($route['recommended_validation_tier'] ?? null),
                    'validation_reason' => AiValueNormalizer::trimmedScalarStringOrNull($route['validation_reason'] ?? null),
                    'route_mode' => AiValueNormalizer::trimmedScalarStringOrNull($route['route_mode'] ?? null),
                    'feedback_effectiveness' => AiValueNormalizer::trimmedScalarStringOrNull($route['feedback_effectiveness'] ?? null),
                ];
            }
        }

        $stacks = $this->stacksForExpectedFiles($contextLoadingPlan, $expectedFiles);
        foreach ((array) data_get($contextLoadingPlan, 'execution_optimization_policy.scope_routing.stack_routes', []) as $route) {
            if (! is_array($route)) {
                continue;
            }
            $key = AiValueNormalizer::trimmedScalarStringOrNull($route['key'] ?? null);
            if ($key !== null && in_array($key, $stacks, true)) {
                return [
                    'kind' => 'stack',
                    'key' => $key,
                    'route_ref' => AiValueNormalizer::trimmedScalarStringOrNull($route['route_ref'] ?? null) ?? 'stack:'.hash('sha256', $key),
                    'commands' => AiStringListNormalizer::uniqueTrimmedScalarValues($route['preferred_commands'] ?? []),
                    'recommended_validation_tier' => AiValueNormalizer::trimmedScalarStringOrNull($route['recommended_validation_tier'] ?? null),
                    'validation_reason' => AiValueNormalizer::trimmedScalarStringOrNull($route['validation_reason'] ?? null),
                    'route_mode' => AiValueNormalizer::trimmedScalarStringOrNull($route['route_mode'] ?? null),
                    'feedback_effectiveness' => AiValueNormalizer::trimmedScalarStringOrNull($route['feedback_effectiveness'] ?? null),
                ];
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $contextLoadingPlan
     * @param  array<int,string>  $expectedFiles
     * @return array<string,mixed>
     */
    private function validationDepthDecision(array $contextLoadingPlan, array $expectedFiles, ?string $riskBand = null): array
    {
        $route = $this->matchingScopeRoute($contextLoadingPlan, $expectedFiles);
        $tier = AiValueNormalizer::trimmedScalarStringOrNull(data_get($route, 'recommended_validation_tier'))
            ?? AiValueNormalizer::trimmedScalarStringOrNull(data_get($contextLoadingPlan, 'execution_optimization_policy.validation_tier_routing.default_tier'))
            ?? 'standard';
        if (! in_array($tier, ['instant', 'standard', 'deep'], true)) {
            $tier = 'standard';
        }

        $reason = AiValueNormalizer::trimmedScalarStringOrNull(data_get($route, 'validation_reason')) ?? 'default_validation_depth';
        $risk = $riskBand !== null ? strtolower($riskBand) : 'unknown';
        if (in_array($risk, ['high', 'critical'], true) && $tier === 'instant') {
            $tier = 'standard';
            $reason = 'risk_band_upgraded_instant_to_standard';
        }

        return [
            'schema_version' => 'atlas.awis.validation_depth_decision.v1',
            'tier' => $tier,
            'reason' => $reason,
            'risk_band' => $risk,
            'route_kind' => AiValueNormalizer::trimmedScalarStringOrNull(data_get($route, 'kind')) ?? 'global',
            'route_ref' => AiValueNormalizer::trimmedScalarStringOrNull(data_get($route, 'route_ref')),
            'route_mode' => AiValueNormalizer::trimmedScalarStringOrNull(data_get($route, 'route_mode')) ?? 'global_default',
            'feedback_effectiveness' => AiValueNormalizer::trimmedScalarStringOrNull(data_get($route, 'feedback_effectiveness')) ?? 'unknown',
            'raw_logs_returned' => false,
            'raw_content_returned' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $decision
     * @return array<int,string>
     */
    private function validationDepthContextRefs(array $decision): array
    {
        $tier = AiValueNormalizer::trimmedScalarStringOrNull($decision['tier'] ?? null);
        if ($tier === null) {
            return [];
        }

        $refs = ['awis_validation_tier:'.$tier];
        $routeRef = AiValueNormalizer::trimmedScalarStringOrNull($decision['route_ref'] ?? null);
        if ($routeRef !== null) {
            $refs[] = 'awis_validation_route:'.$routeRef;
        }

        return $refs;
    }

    /**
     * @param  array<string,mixed>  $contextLoadingPlan
     * @param  array<int,string>  $expectedFiles
     * @return array<int,string>
     */
    private function stacksForExpectedFiles(array $contextLoadingPlan, array $expectedFiles): array
    {
        $manifestRefs = array_values(array_filter(
            (array) ($contextLoadingPlan['focused_manifest_refs'] ?? []),
            'is_array',
        ));
        $stacks = [];
        foreach ($manifestRefs as $manifestRef) {
            $repoKey = AiValueNormalizer::trimmedScalarStringOrNull($manifestRef['repo_key'] ?? null);
            if ($repoKey === null) {
                continue;
            }
            if (count($manifestRefs) === 1 || $this->filesMatchScope($expectedFiles, $repoKey)) {
                $stacks = $this->mergeStrings($stacks, AiStringListNormalizer::uniqueTrimmedScalarValues($manifestRef['stack'] ?? []));
            }
        }

        return $this->mergeStrings($stacks, AiStringListNormalizer::uniqueTrimmedScalarValues($contextLoadingPlan['stack_tags'] ?? []));
    }

    /**
     * @param  array<int,string>  $files
     */
    private function filesMatchScope(array $files, string $scope): bool
    {
        $scope = trim(str_replace('\\', '/', $scope), '/');
        if ($scope === '') {
            return false;
        }

        foreach ($files as $file) {
            $file = trim(str_replace('\\', '/', $file), '/');
            if ($file === $scope || str_starts_with($file, $scope.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function isDevRuntimeCandidate(array $payload): bool
    {
        $surface = AiValueNormalizer::trimmedScalarStringOrNull($payload['surface_id'] ?? null)
            ?? AiValueNormalizer::trimmedScalarStringOrNull($payload['app_surface'] ?? null);

        if ($surface === 'atlas_code') {
            return false;
        }

        if ($surface !== null && ! in_array($surface, self::ATLAS_AI_SURFACES, true)) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function normalizeMode(array $payload): string
    {
        $mode = AiValueNormalizer::trimmedScalarStringOrNull($payload['atlas_mode'] ?? null)
            ?? AiValueNormalizer::trimmedScalarStringOrNull($payload['current_mode'] ?? null)
            ?? AiValueNormalizer::trimmedScalarStringOrNull($payload['atlas_focus'] ?? null);

        return match ($mode) {
            'programming', 'dev', 'debug', 'programacao', 'programação' => 'programming',
            'operational', 'ops' => 'operational',
            default => 'general',
        };
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function normalizeTask(array $payload): string
    {
        $task = AiValueNormalizer::trimmedScalarStringOrNull($payload['routing_task'] ?? null)
            ?? AiValueNormalizer::trimmedScalarStringOrNull($payload['atlas_workflow_mode'] ?? null);

        return match ($task) {
            'plan', 'review', 'dev', 'debug' => $task,
            'repair' => 'debug',
            default => 'dev',
        };
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function normalizeFlowId(array $payload, string $task): string
    {
        $explicit = AiValueNormalizer::trimmedScalarStringOrNull($payload['flow_id'] ?? null);
        if ($explicit !== null && in_array($explicit, self::SUPPORTED_FLOWS, true)) {
            return $explicit;
        }

        return self::TASK_FLOW_MAP[$task] ?? self::FLOW_DEV;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function extractWorkspace(array $payload): ?string
    {
        $workspace = AiValueNormalizer::trimmedScalarStringOrNull($payload['workspace'] ?? null)
            ?? AiValueNormalizer::trimmedScalarStringOrNull(data_get($payload, 'tool_permissions.workspace'))
            ?? AiValueNormalizer::trimmedScalarStringOrNull(data_get($payload, 'forge_workspace.workspace_path'));

        return $workspace;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{0:string,1:?string}
     */
    private function normalizeDecisionMode(array $payload): array
    {
        $rawDecisionMode = AiValueNormalizer::trimmedScalarStringOrNull($payload['decision_mode'] ?? null);
        $manualProvider = AiValueNormalizer::trimmedScalarStringOrNull($payload['operator_requested_provider'] ?? null)
            ?? AiValueNormalizer::trimmedScalarStringOrNull($payload['requested_provider'] ?? null);

        if ($manualProvider === 'auto') {
            $manualProvider = null;
        }

        // 'manual' (legacy mobile/desktop) e variações viram 'manual_override'
        // canônico. Sem provider explícito = atlas_decide.
        $decisionMode = match ($rawDecisionMode) {
            'atlas_decide' => 'atlas_decide',
            'manual_override', 'manual', 'override' => 'manual_override',
            default => $manualProvider !== null ? 'manual_override' : 'atlas_decide',
        };

        if ($decisionMode === 'atlas_decide') {
            $manualProvider = null;
        }

        return [$decisionMode, $manualProvider];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function workspaceSource(array $payload, string $workspace): string
    {
        if (AiValueNormalizer::trimmedScalarStringOrNull($payload['workspace'] ?? null) === $workspace) {
            return 'payload.workspace';
        }

        if (AiValueNormalizer::trimmedScalarStringOrNull(data_get($payload, 'tool_permissions.workspace')) === $workspace) {
            return 'payload.tool_permissions.workspace';
        }

        if (AiValueNormalizer::trimmedScalarStringOrNull(data_get($payload, 'forge_workspace.workspace_path')) === $workspace) {
            return 'payload.forge_workspace.workspace_path';
        }

        return 'unknown';
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>|null
     */
    private function artifactAgentPacket(array $payload): ?array
    {
        $packet = data_get($payload, 'workspace_artifact_agent_packet');
        if (! is_array($packet)) {
            $packet = data_get($payload, 'artifact_agent_packet');
        }

        if (! is_array($packet)) {
            return null;
        }

        return ($packet['schema_version'] ?? null) === 'atlas.workspace_artifact_agent_packet.v1'
            ? $packet
            : null;
    }

    /**
     * @param  array<string,mixed>|null  $packet
     * @return true|list<string>
     */
    private function artifactAgentPacketAllowed(?array $packet, string $workspace): true|array
    {
        if ($packet === null) {
            return true;
        }

        $blockers = [];
        if (AiValueNormalizer::trimmedScalarStringOrNull($packet['workspace_id'] ?? null) !== $workspace) {
            $blockers[] = 'artifact_agent_packet_workspace_mismatch';
        }
        if (! in_array(AiValueNormalizer::trimmedScalarStringOrNull($packet['route_target'] ?? null), ['dev', 'repair'], true)) {
            $blockers[] = 'artifact_agent_packet_route_not_dev';
        }
        if ((bool) ($packet['raw_conversation_included'] ?? true) !== false) {
            $blockers[] = 'artifact_agent_packet_raw_conversation_included';
        }
        if ((bool) ($packet['artifact_body_included'] ?? true) !== false) {
            $blockers[] = 'artifact_agent_packet_body_included';
        }

        return $blockers === [] ? true : $blockers;
    }

    /**
     * @param  array<string,mixed>  $packet
     * @return array<string,mixed>
     */
    private function safeArtifactAgentPacket(array $packet): array
    {
        return [
            'schema_version' => 'atlas.workspace_artifact_agent_packet.v1',
            'workspace_id' => AiValueNormalizer::trimmedScalarStringOrNull($packet['workspace_id'] ?? null),
            'consumer' => AiValueNormalizer::trimmedScalarStringOrNull($packet['consumer'] ?? null),
            'route_target' => AiValueNormalizer::trimmedScalarStringOrNull($packet['route_target'] ?? null),
            'artifact_type' => AiValueNormalizer::trimmedScalarStringOrNull($packet['artifact_type'] ?? null),
            'artifact_hash' => AiValueNormalizer::trimmedScalarStringOrNull($packet['artifact_hash'] ?? null),
            'allowed_paths' => AiStringListNormalizer::uniqueTrimmedScalarValues($packet['allowed_paths'] ?? []),
            'forbidden_paths' => AiStringListNormalizer::uniqueTrimmedScalarValues($packet['forbidden_paths'] ?? []),
            'must_keep' => AiStringListNormalizer::uniqueTrimmedScalarValues($packet['must_keep'] ?? []),
            'context_refs' => AiStringListNormalizer::uniqueTrimmedScalarValues($packet['context_refs'] ?? []),
            'test_plan' => AiStringListNormalizer::uniqueTrimmedScalarValues($packet['test_plan'] ?? []),
            'done_when' => AiStringListNormalizer::uniqueTrimmedScalarValues($packet['done_when'] ?? []),
            'redaction' => AiValueNormalizer::trimmedScalarStringOrNull($packet['redaction'] ?? null) ?? 'provider_safe',
            'raw_conversation_included' => false,
            'artifact_body_included' => false,
        ];
    }

    /**
     * @param  array<int,string>  $left
     * @param  array<int,string>  $right
     * @return array<int,string>
     */
    private function mergeStrings(array $left, array $right): array
    {
        return array_values(array_unique(array_filter(array_merge($left, $right), 'is_string')));
    }
}
