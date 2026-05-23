<?php

namespace App\Services\Ai\Programming;

use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevRuntimeIntelligenceService;
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
            'allowed_files' => $this->arrayOfStrings(data_get($payload, 'tool_permissions.allowed_files', [])),
            'forbidden_files' => $this->arrayOfStrings(data_get($payload, 'tool_permissions.forbidden_files', [])),
            'context_refs' => $this->arrayOfStrings($payload['context_refs'] ?? []),
            'expected_files' => $this->arrayOfStrings($payload['expected_files'] ?? []),
            'suggested_tests' => $this->arrayOfStrings($payload['suggested_tests'] ?? []),
            'acceptance_criteria' => $this->arrayOfStrings($payload['acceptance_criteria'] ?? []),
            'required_evidence' => self::EXPECTED_ARTIFACTS,
            'source' => 'AtlasDevRuntimeService',
        ]);
        $payload['atlas_dev_runtime_intelligence'] = $runtimeIntelligence;
        $workspaceGate = $this->workspaceExecutionGate?->gate(
            workspace: $workspace,
            mode: 'dev',
            task: $this->stringValue($payload['input_text'] ?? null)
                ?? $this->stringValue($payload['prompt'] ?? null)
                ?? $flowId,
        );
        if (is_array($workspaceGate)) {
            $payload['atlas_dev_runtime']['workspace_execution_gate'] = $workspaceGate;
        }

        $providerSafe = (bool) ($runtimeIntelligence['provider_safe'] ?? false);
        $workspaceAllowed = ! is_array($workspaceGate) || (bool) ($workspaceGate['allowed'] ?? false);
        $payload['atlas_dev_runtime']['provider_safe'] = $providerSafe;
        $payload['atlas_dev_runtime']['provider_execution_allowed'] = $providerSafe && $workspaceAllowed;
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
}
