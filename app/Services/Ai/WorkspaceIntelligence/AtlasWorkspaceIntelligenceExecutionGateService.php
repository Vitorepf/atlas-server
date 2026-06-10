<?php

declare(strict_types=1);

namespace App\Services\Ai\WorkspaceIntelligence;

final class AtlasWorkspaceIntelligenceExecutionGateService implements \App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ForgeAuthority\AwisExecutionGatePort
{
    public const SCHEMA_VERSION = 'atlas.workspace_intelligence.execution_gate.v1';

    /**
     * @var array<int,string>
     */
    private const MUTATIVE_MODES = [
        'dev',
        'forge',
        'patch',
        'test',
        'tool',
        'index-code',
        'provider-patch',
        'memory-write',
    ];

    public function __construct(
        private readonly AtlasWorkspaceIntelligenceRuntimeService $runtime,
        private readonly AtlasWorkspaceArtifactShadowExecutionService $artifactShadowExecution,
        private readonly ExecutionGateBlockerCollector $blockerCollector = new ExecutionGateBlockerCollector,
        private readonly ExecutionGateVerdictResolver $verdictResolver = new ExecutionGateVerdictResolver,
        private readonly AtlasWorkspaceIntelligenceListNormalizer $listNormalizer = new AtlasWorkspaceIntelligenceListNormalizer,
    ) {}

    /**
     * @param  array<int,string>  $conversationTexts
     * @return array<string,mixed>
     */
    public function gate(
        ?string $workspace = null,
        string $mode = 'conversation',
        string $task = '',
        array $conversationTexts = [],
    ): array {
        $normalizedMode = $this->normalizeMode($mode);
        $report = $this->runtime->certify(
            workspace: $workspace,
            task: $task,
            conversationTexts: $conversationTexts,
        );

        $mutative = in_array($normalizedMode, self::MUTATIVE_MODES, true);
        $artifactShadow = $this->artifactShadowExecution->evaluate($report, $normalizedMode);
        $nextSessionBrain = (array) ($report['workspace_next_session_brain'] ?? []);
        $nextSessionBrainReady = ($nextSessionBrain['status'] ?? null) === 'ready'
            && data_get($nextSessionBrain, 'source_policy.raw_file_content_returned') === false
            && data_get($nextSessionBrain, 'source_policy.raw_conversation_returned') === false
            && data_get($nextSessionBrain, 'source_policy.absolute_workspace_path_returned') === false
            && data_get($nextSessionBrain, 'context_loading_plan.provider_policy.raw_manifest_returned') === false
            && data_get($nextSessionBrain, 'context_loading_plan.provider_policy.script_bodies_returned') === false
            && data_get($nextSessionBrain, 'context_loading_plan.provider_policy.absolute_workspace_path_returned') === false;
        $runtimeReady = ($report['status'] ?? null) === 'ready';
        $contractsCertified = data_get($report, 'awco.execution_readiness_status') === 'ready';
        $artifactCount = (int) data_get($report, 'awaf.artifact_count', 0);
        $artifactShadowReady = ($artifactShadow['status'] ?? null) === 'ready';
        $blockers = [];
        $warnings = [];

        if ($mutative) {
            $blockers = $this->blockerCollector->collect([
                'runtime_ready' => $runtimeReady,
                'contracts_certified' => $contractsCertified,
                'artifact_count' => $artifactCount,
                'shadow_ready' => $artifactShadowReady,
                'brain_ready' => $nextSessionBrainReady,
            ]);
        } elseif (! $runtimeReady) {
            $warnings[] = 'workspace_not_ready_conversation_only';
        }

        $verdict = $this->verdictResolver->resolve($mutative, $blockers, $runtimeReady);
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $verdict['status'],
            'allowed' => $verdict['allowed'],
            'mode' => $normalizedMode,
            'execution_class' => $mutative ? 'mutative' : 'conversation',
            'workspace_id' => data_get($report, 'workspace.workspace_id'),
            'workspace_status' => data_get($report, 'workspace.status'),
            'runtime_status' => $report['status'] ?? 'unknown',
            'runtime_hash' => $report['runtime_hash'] ?? null,
            'required_contracts' => [
                'awis_workspace_binding' => data_get($report, 'workspace.status') === 'ready',
                'awaf_artifacts_generated' => $artifactCount >= 10,
                'awco_execution_readiness' => $contractsCertified,
                'awair_shadow_execution' => $artifactShadowReady,
                'awnsb_next_session_brain' => $nextSessionBrainReady,
                'awnsb_context_loading_plan' => data_get($nextSessionBrain, 'context_loading_plan.schema_version') === 'atlas.awis.context_loading_plan.v1',
                'raw_conversation_excluded' => data_get($report, 'claim_policy.raw_conversation_used_as_prompt') === false,
            ],
            'execution_context' => [
                'schema_version' => 'atlas.workspace_intelligence.execution_context.v1',
                'workspace_next_session_brain_hash' => data_get($nextSessionBrain, 'brain_hash'),
                'load_order' => array_values((array) data_get($nextSessionBrain, 'resume_packet.load_order', [])),
                'focused_repositories' => array_values((array) data_get($nextSessionBrain, 'resume_packet.focused_repositories', [])),
                'focused_areas' => array_values((array) data_get($nextSessionBrain, 'resume_packet.focused_areas', [])),
                'execution_priority' => array_values((array) data_get($nextSessionBrain, 'execution_priority', [])),
                'context_loading_plan' => (array) data_get($nextSessionBrain, 'context_loading_plan', []),
                'memory_candidate_refs' => array_values((array) data_get($nextSessionBrain, 'memory_candidates.candidate_refs', [])),
                'provider_safe' => $nextSessionBrainReady,
                'raw_content_returned' => false,
            ],
            'artifact_shadow_execution' => $artifactShadow,
            'blockers' => $this->listNormalizer->uniqueStrings($blockers),
            'warnings' => $this->listNormalizer->uniqueStrings($warnings),
            'claim_policy' => [
                'conversation_allowed_without_workspace' => true,
                'mutative_execution_requires_workspace' => true,
                'mutative_execution_requires_certified_contracts' => true,
                'mutative_execution_requires_next_session_brain' => true,
                'raw_conversation_used_as_prompt' => false,
            ],
        ];
        $payload['gate_hash'] = $this->hashPayload($payload);

        return $payload;
    }

    private function normalizeMode(string $mode): string
    {
        $mode = trim(strtolower($mode));

        return $mode === '' ? 'conversation' : str_replace('_', '-', $mode);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hashPayload(array $payload): string
    {
        unset($payload['gate_hash']);

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
