<?php

namespace App\Services\Ai\Programming;

use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevRuntimeIntelligenceService;
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
        $explicitFlowId = $this->stringValue($payload['flow_id'] ?? null);

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
            task: $this->stringValue($payload['input_text'] ?? null)
                ?? $this->stringValue($payload['prompt'] ?? null)
                ?? $flowId,
        );
        $workspaceContextSelection = $this->workspaceContextSelection($workspaceGate);
        $payload['atlas_dev_runtime']['workspace_context_selection'] = $workspaceContextSelection;

        $runtimeIntelligence = (new DevRuntimeIntelligenceService)->preview([
            'run_id' => $this->stringValue($payload['run_id'] ?? null)
                ?? $this->stringValue($payload['trace_id'] ?? null)
                ?? 'dev-runtime-preview',
            'task_id' => $this->stringValue($payload['task_id'] ?? null) ?? $flowId,
            'objective' => $this->stringValue($payload['input_text'] ?? null)
                ?? $this->stringValue($payload['prompt'] ?? null)
                ?? 'Atlas Dev programming request',
            'task_class' => $task === 'debug' ? 'debug' : ($task === 'review' ? 'review' : 'feature'),
            'risk_band' => $this->stringValue($payload['risk_band'] ?? null) ?? 'medium',
            'workspace_slug' => $workspace,
            'allowed_files' => $this->arrayOfStrings(data_get($artifactAgentPacket, 'allowed_paths', data_get($payload, 'tool_permissions.allowed_files', []))),
            'forbidden_files' => $this->arrayOfStrings(data_get($artifactAgentPacket, 'forbidden_paths', data_get($payload, 'tool_permissions.forbidden_files', []))),
            'context_refs' => $this->mergeStrings(
                $this->arrayOfStrings(data_get($artifactAgentPacket, 'context_refs', $payload['context_refs'] ?? [])),
                (array) ($workspaceContextSelection['context_refs'] ?? []),
            ),
            'expected_files' => $this->arrayOfStrings($payload['expected_files'] ?? []),
            'suggested_tests' => $this->mergeStrings(
                $this->arrayOfStrings(data_get($artifactAgentPacket, 'test_plan', $payload['suggested_tests'] ?? [])),
                (array) ($workspaceContextSelection['suggested_tests'] ?? []),
            ),
            'acceptance_criteria' => $this->arrayOfStrings(data_get($artifactAgentPacket, 'done_when', $payload['acceptance_criteria'] ?? [])),
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
            task: $this->stringValue($payload['input_text'] ?? null)
                ?? $this->stringValue($payload['prompt'] ?? null)
                ?? $flowId,
            consumer: 'atlas_dev',
        );
        $payload['atlas_dev_runtime']['workspace_handoff_pack'] = $handoffPack;

        $providerSafe = (bool) ($runtimeIntelligence['provider_safe'] ?? false);
        $workspaceAllowed = ! is_array($workspaceGate) || (bool) ($workspaceGate['allowed'] ?? false);
        $handoffAllowed = ($handoffPack['status'] ?? null) === 'ready'
            && (bool) data_get($handoffPack, 'claim_policy.safe_for_provider_prompt', false);
        $payload['atlas_dev_runtime']['provider_safe'] = $providerSafe && $workspaceAllowed && $handoffAllowed && $artifactAgentPacketAllowed === true;
        $payload['atlas_dev_runtime']['provider_execution_allowed'] = $providerSafe && $workspaceAllowed && $artifactAgentPacketAllowed === true;
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
    private function workspaceContextSelection(?array $workspaceGate): array
    {
        $contextLoadingPlan = (array) data_get($workspaceGate, 'execution_context.context_loading_plan', []);
        $contextRefs = [];
        foreach ((array) data_get($workspaceGate, 'execution_context.focused_repositories', []) as $repository) {
            if (! is_array($repository)) {
                continue;
            }
            $repoKey = $this->stringValue($repository['repo_key'] ?? null);
            if ($repoKey !== null) {
                $contextRefs[] = 'awis_repo:'.$repoKey;
            }
        }

        foreach ((array) ($contextLoadingPlan['focused_manifest_refs'] ?? []) as $manifestRef) {
            if (! is_array($manifestRef)) {
                continue;
            }
            $repoKey = $this->stringValue($manifestRef['repo_key'] ?? null);
            if ($repoKey === null) {
                continue;
            }
            foreach ($this->arrayOfStrings($manifestRef['manifest_files'] ?? []) as $manifestFile) {
                $contextRefs[] = 'awis_manifest:'.$repoKey.':'.$manifestFile;
            }
        }

        foreach ($this->arrayOfStrings($contextLoadingPlan['stack_tags'] ?? []) as $stackTag) {
            $contextRefs[] = 'awis_stack:'.$stackTag;
        }

        $repositoryInventoryHash = $this->stringValue($contextLoadingPlan['repository_inventory_hash'] ?? null);
        if ($repositoryInventoryHash !== null) {
            $contextRefs[] = 'awis_cache:repository_inventory:'.$repositoryInventoryHash;
        }

        $suggestedTests = [];
        foreach ((array) data_get($workspaceGate, 'execution_context.execution_priority', []) as $priority) {
            if (! is_array($priority)) {
                continue;
            }
            $command = $this->stringValue($priority['command'] ?? null);
            if ($command !== null) {
                $suggestedTests[] = $command;
            }
        }
        $suggestedTests = $this->mergeStrings($suggestedTests, $this->arrayOfStrings($contextLoadingPlan['command_hints'] ?? []));

        return [
            'schema_version' => 'atlas.dev_runtime.awis_context_selection.v1',
            'source' => 'workspace_next_session_brain.context_loading_plan',
            'context_refs' => array_slice($this->mergeStrings([], $contextRefs), 0, 24),
            'suggested_tests' => array_slice($this->mergeStrings([], $suggestedTests), 0, 12),
            'repository_inventory_hash' => $repositoryInventoryHash,
            'provider_safe' => data_get($workspaceGate, 'execution_context.provider_safe') === true
                && data_get($workspaceGate, 'execution_context.context_loading_plan.provider_policy.raw_manifest_returned') === false
                && data_get($workspaceGate, 'execution_context.context_loading_plan.provider_policy.script_bodies_returned') === false
                && data_get($workspaceGate, 'execution_context.context_loading_plan.provider_policy.absolute_workspace_path_returned') === false,
            'raw_content_returned' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function isDevRuntimeCandidate(array $payload): bool
    {
        $surface = $this->stringValue($payload['surface_id'] ?? null)
            ?? $this->stringValue($payload['app_surface'] ?? null);

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
        $mode = $this->stringValue($payload['atlas_mode'] ?? null)
            ?? $this->stringValue($payload['current_mode'] ?? null)
            ?? $this->stringValue($payload['atlas_focus'] ?? null);

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
        $task = $this->stringValue($payload['routing_task'] ?? null)
            ?? $this->stringValue($payload['atlas_workflow_mode'] ?? null);

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
        $explicit = $this->stringValue($payload['flow_id'] ?? null);
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
        $workspace = $this->stringValue($payload['workspace'] ?? null)
            ?? $this->stringValue(data_get($payload, 'tool_permissions.workspace'))
            ?? $this->stringValue(data_get($payload, 'forge_workspace.workspace_path'));

        return $workspace;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{0:string,1:?string}
     */
    private function normalizeDecisionMode(array $payload): array
    {
        $rawDecisionMode = $this->stringValue($payload['decision_mode'] ?? null);
        $manualProvider = $this->stringValue($payload['operator_requested_provider'] ?? null)
            ?? $this->stringValue($payload['requested_provider'] ?? null);

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
        if ($this->stringValue($payload['workspace'] ?? null) === $workspace) {
            return 'payload.workspace';
        }

        if ($this->stringValue(data_get($payload, 'tool_permissions.workspace')) === $workspace) {
            return 'payload.tool_permissions.workspace';
        }

        if ($this->stringValue(data_get($payload, 'forge_workspace.workspace_path')) === $workspace) {
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
        if ($this->stringValue($packet['workspace_id'] ?? null) !== $workspace) {
            $blockers[] = 'artifact_agent_packet_workspace_mismatch';
        }
        if (! in_array($this->stringValue($packet['route_target'] ?? null), ['dev', 'repair'], true)) {
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
            'workspace_id' => $this->stringValue($packet['workspace_id'] ?? null),
            'consumer' => $this->stringValue($packet['consumer'] ?? null),
            'route_target' => $this->stringValue($packet['route_target'] ?? null),
            'artifact_type' => $this->stringValue($packet['artifact_type'] ?? null),
            'artifact_hash' => $this->stringValue($packet['artifact_hash'] ?? null),
            'allowed_paths' => $this->arrayOfStrings($packet['allowed_paths'] ?? []),
            'forbidden_paths' => $this->arrayOfStrings($packet['forbidden_paths'] ?? []),
            'must_keep' => $this->arrayOfStrings($packet['must_keep'] ?? []),
            'context_refs' => $this->arrayOfStrings($packet['context_refs'] ?? []),
            'test_plan' => $this->arrayOfStrings($packet['test_plan'] ?? []),
            'done_when' => $this->arrayOfStrings($packet['done_when'] ?? []),
            'redaction' => $this->stringValue($packet['redaction'] ?? null) ?? 'provider_safe',
            'raw_conversation_included' => false,
            'artifact_body_included' => false,
        ];
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * @return list<string>
     */
    private function arrayOfStrings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $item): ?string => $this->stringValue($item),
            $value,
        ))));
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
