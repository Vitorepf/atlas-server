<?php

declare(strict_types=1);

namespace App\Services\Ai\WorkspaceIntelligence;

use App\Services\Ai\Mission\MissionCanonicalHash;

final class AtlasWorkspaceExecutionBoundaryAuditService
{
    public const SCHEMA_VERSION = 'atlas.workspace_intelligence.execution_boundary_audit.v1';

    /**
     * @return array<string,mixed>
     */
    public function audit(): array
    {
        $boundaries = $this->boundaries();
        $items = [];

        foreach ($boundaries as $boundary) {
            $items[] = $this->inspect($boundary);
        }

        $processItems = [];
        foreach ($this->processInventory() as $boundary) {
            $processItems[] = $this->inspectProcessBoundary($boundary);
        }

        $boundaryFailed = array_values(array_filter(
            $items,
            static fn (array $item): bool => ($item['status'] ?? null) !== 'passed',
        ));
        $processFailed = array_values(array_filter(
            $processItems,
            static fn (array $item): bool => ($item['status'] ?? null) !== 'passed',
        ));
        $failed = array_values(array_merge($boundaryFailed, $processFailed));
        $unclassified = array_values(array_filter(
            $processItems,
            static fn (array $item): bool => ($item['classification'] ?? null) === 'unclassified',
        ));

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $failed === [] ? 'ready' : 'blocked',
            'summary' => [
                'total' => count($items),
                'passed' => count($items) - count($boundaryFailed),
                'failed' => count($boundaryFailed),
                'process_inventory_total' => count($processItems),
                'process_inventory_unclassified' => count($unclassified),
            ],
            'boundaries' => $items,
            'process_inventory' => [
                'schema_version' => 'atlas.workspace_intelligence.process_inventory.v1',
                'total' => count($processItems),
                'passed' => count($processItems) - count(array_filter(
                    $processItems,
                    static fn (array $item): bool => ($item['status'] ?? null) !== 'passed',
                )),
                'failed' => count(array_filter(
                    $processItems,
                    static fn (array $item): bool => ($item['status'] ?? null) !== 'passed',
                )),
                'unclassified' => count($unclassified),
                'by_classification' => $this->classificationCounts($processItems),
                'items' => $processItems,
            ],
            'claim_policy' => [
                'mutative_dev_forge_boundaries_require_awis' => true,
                'unclassified_workspace_process_boundaries_allowed' => false,
                'static_scan_only' => true,
                'executes_code' => false,
            ],
        ];
        $payload['audit_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function boundaries(): array
    {
        return [
            [
                'id' => 'atlas_dev_http_run',
                'path' => 'app/Http/Controllers/AtlasDev/RunController.php',
                'required_markers' => [
                    // Refactor hexagonal (05faa45f14/e62c0c3aea): o guard passou a
                    // entrar pelo port, que o AppServiceProvider amarra no
                    // AtlasWorkspaceIntelligenceExecutionGateService concreto.
                    'AwisExecutionGatePort',
                    'ATLAS_DEV_AWIS_EXECUTION_BLOCKED',
                    "mode: 'dev'",
                ],
            ],
            [
                'id' => 'atlas_dev_worker',
                'path' => 'app/Console/Commands/AtlasDevRunWorkerCommand.php',
                'required_markers' => [
                    'AwisExecutionGatePort',
                    'ATLAS_DEV_AWIS_EXECUTION_BLOCKED',
                    "mode: 'dev'",
                ],
            ],
            [
                'id' => 'engineering_runner_provider',
                'path' => 'app/Services/Engineering/EngineeringHarnessRunnerService.php',
                'required_markers' => [
                    'AtlasWorkspaceIntelligenceExecutionGateService',
                    'awis_workspace_required_for_engineering_run',
                    "mode: 'dev'",
                ],
            ],
            [
                'id' => 'engineering_replay_patch',
                'path' => 'app/Console/Commands/AtlasEngineeringReplayCommand.php',
                'required_markers' => [
                    'AtlasWorkspaceIntelligenceExecutionGateService',
                    'awis_workspace_required_for_replay_patch',
                    "mode: 'patch'",
                ],
            ],
            [
                'id' => 'atlas_code_diff_apply',
                'path' => 'app/Http/Controllers/AtlasCodeDiffController.php',
                'required_markers' => [
                    'AtlasWorkspaceIntelligenceExecutionGateService',
                    'awis_workspace_required_for_diff_apply',
                    "mode: 'patch'",
                ],
            ],
            [
                'id' => 'index_code_cli',
                'path' => 'app/Console/Commands/AtlasEngineeringKnowledgeCommand.php',
                'required_markers' => [
                    'AtlasWorkspaceIntelligenceExecutionGateService',
                    'atlas.engineering.code_index.awis_gate.v1',
                    "mode: 'index-code'",
                ],
            ],
            [
                'id' => 'index_code_api',
                'path' => 'app/Http/Controllers/EngineeringKnowledgeController.php',
                'required_markers' => [
                    'AtlasWorkspaceIntelligenceExecutionGateService',
                    'atlas.engineering.code_index.awis_gate.v1',
                    "mode: 'index-code'",
                ],
            ],
            [
                'id' => 'atlas_runtime_mutative_tools',
                'path' => 'app/Console/Commands/AtlasRuntimeCommand.php',
                'required_markers' => [
                    'AtlasWorkspaceIntelligenceExecutionGateService',
                    'awis_workspace_required_for_runtime_tool',
                    'awisMutationBlock',
                ],
            ],
            [
                'id' => 'atlas_super_tool_runtime',
                'path' => 'app/Services/Tools/AtlasToolExecutor.php',
                'required_markers' => [
                    'AtlasWorkspaceIntelligenceExecutionGateService',
                    'awis_workspace_required_for_tool_execution',
                    "mode: 'tool'",
                ],
            ],
            [
                'id' => 'engineering_quality_scan',
                'path' => 'app/Services/Engineering/EngineeringQualityScanService.php',
                'required_markers' => [
                    'AtlasWorkspaceIntelligenceExecutionGateService',
                    'awis_workspace_required_for_quality_scan',
                    "mode: 'tool'",
                ],
            ],
            [
                'id' => 'forge_intake',
                'path' => 'app/Services/Ai/Programming/Forge/ForgeIntakeService.php',
                'required_markers' => [
                    'AtlasWorkspaceIntelligenceExecutionGateService',
                    'awis_workspace_required_for_forge_intake',
                    "mode: 'forge'",
                ],
            ],
            [
                'id' => 'forge_fast_path',
                'path' => 'app/Services/Ai/Programming/AtlasCodeForgeFastPathService.php',
                'required_markers' => [
                    'AtlasWorkspaceIntelligenceExecutionGateService',
                    'awis_execution_gate_blocked',
                    "mode: 'forge'",
                ],
            ],
            [
                'id' => 'forge_runtime_dispatch',
                'path' => 'app/Services/Ai/Programming/AtlasForgeRuntimeDispatchService.php',
                'required_markers' => [
                    'AtlasWorkspaceIntelligenceExecutionGateService',
                    'workspace_execution_gate',
                    "mode: 'forge'",
                ],
            ],
            [
                'id' => 'forge_live_execution',
                'path' => 'app/Services/Ai/Programming/AtlasForgeLiveExecutionService.php',
                'required_markers' => [
                    'AtlasWorkspaceIntelligenceExecutionGateService',
                    'workspace_execution_gate',
                    'awis_execution_gate_blocked',
                ],
            ],
            [
                'id' => 'forge_governed_execution',
                'path' => 'app/Services/Ai/Programming/AtlasForgeGovernedExecutionService.php',
                'required_markers' => [
                    'AtlasWorkspaceIntelligenceExecutionGateService',
                    'workspace_execution_gate',
                    'awis_execution_gate_blocked',
                ],
            ],
            [
                'id' => 'forge_governed_promotion',
                'path' => 'app/Services/Ai/Programming/AtlasForgeGovernedPromotionService.php',
                'required_markers' => [
                    'AtlasWorkspaceIntelligenceExecutionGateService',
                    'workspace_execution_gate',
                    'awis_execution_gate_blocked',
                ],
            ],
            [
                'id' => 'forge_provider_invocation',
                'path' => 'app/Services/Ai/Programming/AtlasForgeProviderInvocationService.php',
                'required_markers' => [
                    'BLOCKER_AWIS_EXECUTION_GATE_REQUIRED',
                    'BLOCKER_AWIS_EXECUTION_GATE_BLOCKED',
                    'workspace_execution_gate',
                ],
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function processInventory(): array
    {
        return [
            [
                'id' => 'atlas_dev_provider_gateway',
                'path' => 'app/Services/Ai/Programming/AtlasDev/Provider/SymfonyClaudeCliGateway.php',
                'classification' => 'caller_guarded_by_awis',
                'reason' => 'Atlas Dev RunController and worker gate dev mode before provider dispatch.',
                'required_markers' => [
                    'Production Claude CLI transport for Atlas Dev',
                    'assertWorkspace',
                    'new Process($argv',
                ],
            ],
            [
                'id' => 'atlas_dev_patch_applier',
                'path' => 'app/Services/Ai/Programming/AtlasDev/Gate/PatchApplier.php',
                'classification' => 'caller_guarded_by_awis',
                'reason' => 'Patch application is downstream of Atlas Dev run gating and scope controls.',
                'required_markers' => [
                    'PatchApplier',
                    "'git', 'apply'",
                    "'patch', '-p0'",
                ],
            ],
            [
                'id' => 'atlas_dev_ripgrep_discovery',
                'path' => 'app/Services/Ai/Programming/AtlasDev/Discovery/RipgrepRunner.php',
                'classification' => 'read_only_workspace_probe',
                'reason' => 'Read-only fixed-string discovery; no mutation or provider execution.',
                'required_markers' => [
                    'Never used to author content',
                    '--fixed-strings',
                    '--max-filesize=2M',
                ],
            ],
            [
                'id' => 'atlas_dev_readiness_probe',
                'path' => 'app/Services/Ai/Programming/AtlasDev/Runtime/AtlasDevReadinessService.php',
                'classification' => 'read_only_workspace_probe',
                'reason' => 'Version/readiness checks only; no workspace mutation.',
                'required_markers' => [
                    'AtlasDevReadinessService',
                    '--version',
                    'new Process',
                ],
            ],
            [
                'id' => 'git_workspace_inspector',
                'path' => 'app/Services/AtlasCode/GitWorkspaceInspector.php',
                'classification' => 'read_only_workspace_probe',
                'reason' => 'Git status/diff/log inspection used for observed workflow context.',
                'required_markers' => [
                    'GitWorkspaceInspector',
                    'git',
                    'new Process',
                ],
            ],
            [
                'id' => 'verification_command_runner',
                'path' => 'app/Services/AtlasCode/VerificationCommandRunner.php',
                'classification' => 'operator_gated_verification',
                'reason' => 'Execution requires config enablement, allowlist and operator override token.',
                'required_markers' => [
                    'execute_enabled',
                    'operator_override_token_required',
                    'command_not_in_allowlist',
                ],
            ],
            [
                'id' => 'engineering_patch_artifact_capture',
                'path' => 'app/Services/Engineering/EngineeringPatchArtifactService.php',
                'classification' => 'caller_guarded_read_only_capture',
                'reason' => 'Captures git status/diff after a gated Engineering Harness run; it does not mutate workspace.',
                'required_markers' => [
                    "['git', 'status', '--short']",
                    "['git', 'diff']",
                    'AtlasSecurity::redactString',
                ],
            ],
            [
                'id' => 'engineering_provider_runtime',
                'path' => 'app/Services/Engineering/EngineeringProviderRuntimeService.php',
                'classification' => 'caller_guarded_by_awis',
                'reason' => 'Provider runtime is called from EngineeringHarnessRunnerService after AWIS gate.',
                'required_markers' => [
                    'EngineeringProviderRuntimeService',
                    'new Process',
                    'AtlasSecurity::processEnv',
                ],
            ],
            [
                'id' => 'engineering_workspace_service',
                'path' => 'app/Services/Engineering/EngineeringWorkspaceService.php',
                'classification' => 'caller_guarded_by_awis',
                'reason' => 'Workspace command execution is owned by engineering harness flows now gated by AWIS.',
                'required_markers' => [
                    'EngineeringWorkspaceService',
                    'new Process',
                    'AtlasSecurity::processEnv',
                ],
            ],
            [
                'id' => 'engineering_docker_harness',
                'path' => 'app/Services/Engineering/EngineeringDockerHarnessService.php',
                'classification' => 'caller_guarded_by_awis',
                'reason' => 'Docker harness execution is downstream of Engineering Harness run admission.',
                'required_markers' => [
                    'EngineeringDockerHarnessService',
                    'new Process',
                    'AtlasSecurity::processEnv',
                ],
            ],
            [
                'id' => 'atlas_runtime_ai_tool_runtime',
                // Process/shell execution was extracted from AiToolRuntime into
                // AiToolProcessRunner (cl2-split g1, 2026-06-26); the boundary
                // fingerprint follows the real Process call-site.
                'path' => 'app/Services/Ai/Runtime/AiToolProcessRunner.php',
                'classification' => 'entrypoint_guarded_by_awis',
                'reason' => 'Process runner extracted from AiToolRuntime; tool execution stays gated by AtlasRuntimeCommand before dispatch.',
                'required_markers' => [
                    'AiToolProcessRunner',
                    'Process::fromShellCommandline',
                    'new Process',
                ],
            ],
            [
                'id' => 'atlas_super_tool_runtime',
                'path' => 'app/Services/Tools/AtlasToolExecutor.php',
                'classification' => 'directly_guarded_by_awis',
                'reason' => 'Tool executor gates real tool execution directly before spawning Process.',
                'required_markers' => [
                    'AtlasWorkspaceIntelligenceExecutionGateService',
                    'awis_workspace_required_for_tool_execution',
                    "mode: 'tool'",
                ],
            ],
            [
                'id' => 'engineering_quality_scan',
                'path' => 'app/Services/Engineering/EngineeringQualityScanService.php',
                'classification' => 'directly_guarded_by_awis',
                'reason' => 'Quality scan gates workspace execution before git/process probes and command plans.',
                'required_markers' => [
                    'AtlasWorkspaceIntelligenceExecutionGateService',
                    'awis_workspace_required_for_quality_scan',
                    "mode: 'tool'",
                ],
            ],
            [
                'id' => 'forge_provider_process_runner',
                'path' => 'app/Services/Ai/Programming/AtlasForgeProviderProcessRunner.php',
                'classification' => 'caller_guarded_by_awis',
                'reason' => 'Provider process runner is admitted by Forge provider invocation AWIS boundary.',
                'required_markers' => [
                    'AtlasForgeProviderProcessRunner',
                    'new Process',
                    'ProcessTimedOutException',
                ],
            ],
            [
                'id' => 'forge_live_execution',
                'path' => 'app/Services/Ai/Programming/AtlasForgeLiveExecutionService.php',
                'classification' => 'directly_guarded_by_awis',
                'reason' => 'Forge live execution owns a direct workspace execution gate.',
                'required_markers' => [
                    'AtlasWorkspaceIntelligenceExecutionGateService',
                    'workspace_execution_gate',
                    'awis_execution_gate_blocked',
                ],
            ],
            [
                'id' => 'forge_governed_execution',
                'path' => 'app/Services/Ai/Programming/AtlasForgeGovernedExecutionService.php',
                'classification' => 'directly_guarded_by_awis',
                'reason' => 'Forge governed execution owns a direct workspace execution gate.',
                'required_markers' => [
                    'AtlasWorkspaceIntelligenceExecutionGateService',
                    'workspace_execution_gate',
                    'awis_execution_gate_blocked',
                ],
            ],
            [
                'id' => 'forge_governed_promotion',
                'path' => 'app/Services/Ai/Programming/AtlasForgeGovernedPromotionService.php',
                'classification' => 'directly_guarded_by_awis',
                'reason' => 'Forge governed promotion owns a direct workspace execution gate.',
                'required_markers' => [
                    'AtlasWorkspaceIntelligenceExecutionGateService',
                    'workspace_execution_gate',
                    'awis_execution_gate_blocked',
                ],
            ],
            [
                'id' => 'pipeline_run_executor',
                // Marker relocated under GOD-DEBULK D3 (2026-07-22): the pipeline's direct
                // Process spawns (git baseline/restore/diff) moved verbatim from the
                // PipelineRunExecutor godfile into the PipelineRun/WorkspaceGitSupport
                // family class; the boundary fingerprint follows the real call-site.
                'path' => 'app/Http/Controllers/AtlasDev/Support/PipelineRun/WorkspaceGitSupport.php',
                'classification' => 'legacy_pipeline_boundary',
                'reason' => 'Legacy pipeline helper remains inventoried; Atlas Dev standard run is AWIS-gated.',
                'required_markers' => [
                    'WorkspaceGitSupport',
                    'new Process',
                    'diff',
                ],
            ],
            [
                'id' => 'visual_smoke_runtime',
                'path' => 'app/Console/Commands/AtlasEngineeringVisualSmokeCommand.php',
                'classification' => 'operator_invoked_local_evidence',
                'reason' => 'Visual smoke is an explicit operator evidence command, not automatic provider execution.',
                'required_markers' => [
                    'AtlasEngineeringVisualSmokeCommand',
                    'Process::fromShellCommandline',
                    'visual-smoke',
                ],
            ],
            [
                'id' => 'voice_realtime_runtime',
                'path' => 'app/Console/Commands/AtlasAiVoiceRealtimeCommand.php',
                'classification' => 'external_system_boundary',
                'reason' => 'Voice realtime manages audio/runtime subprocesses and is outside workspace mutation policy.',
                'required_markers' => [
                    'AtlasAiVoiceRealtimeCommand',
                    'ProcessTimedOutException',
                    'voice',
                ],
            ],
            [
                'id' => 'mac_agent_runtime',
                'path' => 'app/Services/MacAgent/MacAgentService.php',
                'classification' => 'external_system_boundary',
                'reason' => 'Mac agent controls local OS facilities and is governed by MacAgent policy, not AWIS workspace policy.',
                'required_markers' => [
                    'MacAgentService',
                    'launchctl',
                    'pmset',
                ],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $boundary
     * @return array<string,mixed>
     */
    private function inspect(array $boundary): array
    {
        $path = (string) $boundary['path'];
        $absolute = $this->basePath($path);
        $markers = array_values(array_filter(
            (array) ($boundary['required_markers'] ?? []),
            'is_string',
        ));

        if (! is_file($absolute)) {
            return [
                'id' => (string) $boundary['id'],
                'status' => 'failed',
                'path' => $path,
                'missing_markers' => $markers,
                'reason' => 'file_missing',
            ];
        }

        $contents = (string) file_get_contents($absolute);
        $missing = array_values(array_filter(
            $markers,
            static fn (string $marker): bool => ! str_contains($contents, $marker),
        ));

        return [
            'id' => (string) $boundary['id'],
            'status' => $missing === [] ? 'passed' : 'failed',
            'path' => $path,
            'missing_markers' => $missing,
            'required_markers_count' => count($markers),
            'file_hash' => hash('sha256', $contents),
        ];
    }

    private function basePath(string $path): string
    {
        try {
            if (function_exists('app') && method_exists(app(), 'basePath')) {
                return base_path($path);
            }
        } catch (\Throwable) {
            // Fall back to repo root for pure PHPUnit tests without Laravel app bootstrap.
        }

        return dirname(__DIR__, 4).'/'.ltrim($path, '/');
    }

    /**
     * @param  array<string,mixed>  $boundary
     * @return array<string,mixed>
     */
    private function inspectProcessBoundary(array $boundary): array
    {
        $item = $this->inspect($boundary);
        $item['classification'] = (string) ($boundary['classification'] ?? 'unclassified');
        $item['reason'] = (string) ($boundary['reason'] ?? '');

        if ($item['classification'] === '') {
            $item['classification'] = 'unclassified';
            $item['status'] = 'failed';
            $item['reason'] = 'classification_missing';
        }

        return $item;
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @return array<string,int>
     */
    private function classificationCounts(array $items): array
    {
        $counts = [];
        foreach ($items as $item) {
            $classification = (string) ($item['classification'] ?? 'unclassified');
            $counts[$classification] = ($counts[$classification] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }
}
