<?php

declare(strict_types=1);

namespace App\Services\Ai\WorkspaceIntelligence;

final class AtlasWorkspaceIntelligenceExecutionGateService
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
        'index-code',
        'provider-patch',
        'memory-write',
    ];

    public function __construct(
        private readonly AtlasWorkspaceIntelligenceRuntimeService $runtime,
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
        $blockers = [];
        $warnings = [];

        if ($mutative) {
            if (($report['status'] ?? null) !== 'ready') {
                $blockers[] = 'workspace_not_ready';
            }
            if (data_get($report, 'awco.execution_readiness_status') !== 'ready') {
                $blockers[] = 'workspace_contracts_not_certified';
            }
            if ((int) data_get($report, 'awaf.artifact_count', 0) < 10) {
                $blockers[] = 'workspace_artifacts_incomplete';
            }
        } elseif (($report['status'] ?? null) !== 'ready') {
            $warnings[] = 'workspace_not_ready_conversation_only';
        }

        $allowed = $mutative ? $blockers === [] : true;
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $allowed ? (($report['status'] ?? null) === 'ready' ? 'ready' : 'limited') : 'blocked',
            'allowed' => $allowed,
            'mode' => $normalizedMode,
            'execution_class' => $mutative ? 'mutative' : 'conversation',
            'workspace_id' => data_get($report, 'workspace.workspace_id'),
            'workspace_status' => data_get($report, 'workspace.status'),
            'runtime_status' => $report['status'] ?? 'unknown',
            'runtime_hash' => $report['runtime_hash'] ?? null,
            'required_contracts' => [
                'awis_workspace_binding' => data_get($report, 'workspace.status') === 'ready',
                'awaf_artifacts_generated' => (int) data_get($report, 'awaf.artifact_count', 0) >= 10,
                'awco_execution_readiness' => data_get($report, 'awco.execution_readiness_status') === 'ready',
                'raw_conversation_excluded' => data_get($report, 'claim_policy.raw_conversation_used_as_prompt') === false,
            ],
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'claim_policy' => [
                'conversation_allowed_without_workspace' => true,
                'mutative_execution_requires_workspace' => true,
                'mutative_execution_requires_certified_contracts' => true,
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
