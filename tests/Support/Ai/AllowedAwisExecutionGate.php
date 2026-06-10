<?php

namespace Tests\Support\Ai;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ForgeAuthority\AwisExecutionGatePort;

final class AllowedAwisExecutionGate implements AwisExecutionGatePort
{
    /**
     * @param  array<int,string>  $conversationTexts
     * @return array<string,mixed>
     */
    public function gate(?string $workspace = null, string $mode = 'conversation', string $task = '', array $conversationTexts = []): array
    {
        $workspaceId = $workspace !== null && trim($workspace) !== '' ? trim($workspace) : 'atlas';

        return [
            'schema_version' => 'atlas.workspace_intelligence.execution_gate.v1',
            'status' => 'ready',
            'allowed' => true,
            'mode' => $mode,
            'execution_class' => 'mutative',
            'workspace_id' => $workspaceId,
            'runtime_status' => 'ready',
            'required_contracts' => [
                'awis_workspace_binding' => true,
                'awaf_artifacts_generated' => true,
                'awco_execution_readiness' => true,
                'awair_shadow_execution' => true,
                'awnsb_next_session_brain' => true,
                'awnsb_context_loading_plan' => true,
                'raw_conversation_excluded' => true,
            ],
            'execution_context' => [
                'schema_version' => 'atlas.workspace_intelligence.execution_context.v1',
                'provider_safe' => true,
                'focused_repositories' => [[
                    'repo_key' => 'atlas-server',
                    'score' => 8,
                    'reasons' => ['test_fixture'],
                    'stack' => ['laravel', 'php'],
                ]],
                'execution_priority' => [],
                'context_loading_plan' => [
                    'schema_version' => 'atlas.awis.context_loading_plan.v1',
                    'stack_tags' => ['laravel', 'php'],
                    'focused_manifest_refs' => [[
                        'repo_key' => 'atlas-server',
                        'manifest_files' => ['composer.json'],
                        'stack' => ['laravel', 'php'],
                    ]],
                    'provider_policy' => [
                        'raw_manifest_returned' => false,
                        'script_bodies_returned' => false,
                        'absolute_workspace_path_returned' => false,
                    ],
                ],
                'raw_content_returned' => false,
            ],
            'blockers' => [],
            'warnings' => [],
        ];
    }
}
