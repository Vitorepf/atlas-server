<?php

namespace App\Services\Ai;

use App\Services\Ai\OpenBrainMcp\OpenBrainMcpToolCatalog;
use App\Models\AiTelemetryEvent;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Instrumentation\AtlasProviderProjectionService;
use App\Services\Ai\Kernel\Architecture\AtlasAiArchitectureValidationService;
use App\Services\Ai\Kernel\Architecture\AtlasArchitectureReadinessService;
use App\Services\Ai\Kernel\Architecture\AtlasGovernanceGateService;
use App\Services\Ai\Kernel\Evidence\KernelReplayReportInput;
use App\Services\Ai\Kernel\Mcp\OpenBrainMcpInput;
use App\Services\Ai\Memory\AtlasMemoryQualityService;
use App\Services\Ai\Memory\AtlasMemoryRegistryService;
use App\Services\Ai\OpenBrainMcp\CodeGraphTools;
use App\Services\Ai\OpenBrainMcp\TaskTools;
use App\Services\Ai\OpenBrainMcp\MemoryEntryTools;
use App\Services\Ai\OpenBrainMcp\GraphRagTools;
use App\Services\Ai\OpenBrainMcp\WorkspaceTools;
use App\Services\Ai\OpenBrainMcp\ContextTools;
use App\Services\Ai\OpenBrainMcp\HealthMetricsTools;
use App\Services\Ai\OpenBrainMcp\ProviderReleaseTools;
use App\Services\Ai\OpenBrainMcp\ReportTools;
use App\Services\Ai\OpenBrainMcp\ArchitectureTools;
use App\Services\Ai\OpenBrainMcp\NavigationTools;
use App\Services\Ai\OpenBrainMcp\RuntimeSurfaceTools;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\Telemetry\AiTelemetryCollector;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use App\Services\Engineering\EngineeringKnowledgeBaseService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class AtlasOpenBrainMcpService
{
    public const PROTOCOL_VERSION = '2025-06-18';

    public const SERVER_VERSION = '1.3.0';

    public const RUNTIME_SCHEMA = 'atlas.open_brain.mcp.runtime.v1';

    /**
     * Provider-visible features that prove a native MCP process is recent enough
     * for current AOBG behavior. If a provider sees these missing from
     * atlas_capabilities/atlas_mcp_self_check, it should restart the MCP client
     * or use the CLI fallback from this workspace.
     */
    public const RUNTIME_FEATURE_FLAGS = [
        'context_expand_tool',
        'context_feedback_tool',
        'context_feedback_metrics',
        'context_delivery_policy',
        'context_hygiene_summary',
        'feedback_demotes_initial_context_refs',
        'initial_code_file_symbol_deferral',
        'initial_code_path_noise_filter',
        'memory_relevance_floor',
        'initial_surface_symbol_deferral',
        'context_pack_runtime_fingerprint',
        'open_brain_prompt_metrics',
        'workspace_activation',
        'blackboard_coordination',
        'mcp_runtime_self_check',
        'initial_code_symbol_noise_filter',
        'reality_doc_mission_filter',
        'deep_surface_contract',
    ];

    public const PRIMARY_TOOLS = [
        'atlas_capabilities',
        'atlas_context_pack',
        'atlas_context_expand',
        'atlas_context_feedback',
        'atlas_record_outcome',
        'atlas_propose_learning',
        'atlas_memory_maintenance_status',
        'atlas_claim_task',
        'atlas_mcp_self_check',
    ];

    public const COMPATIBILITY_ALIASES = [
        'atlas_open_brain_context_pack' => 'atlas_context_pack',
    ];

    public const MCP_TOOL_USAGE_EVENT_NAME = 'open_brain.mcp_tool_call';

    public const SURFACE_REVIEW_SCHEMA = 'atlas.open_brain.surface_review.v1';

    /**
     * Tool business side-effects, independent from telemetry writes emitted by the transport.
     *
     * @var list<string>
     */
    private const WRITE_TOOLS = [
        'atlas_memory_record',
        'atlas_context_feedback',
        'atlas_record_outcome',
        'atlas_propose_learning',
        'atlas_workspace_activate',
        'atlas_claim_task',
        'atlas_task_start',
        'atlas_task_progress',
        'atlas_task_complete',
        'atlas_memory_archive',
        'atlas_memory_link',
        'atlas_memory_supersede',
        'atlas_next_task',
        'atlas_task_report',
    ];

    private string $processStartedAt;

    public function __construct(
        private readonly AtlasOpenBrainContextPackService $contextPack,
        private readonly AtlasProviderProjectionService $projection,
        private readonly AtlasMemoryQualityService $quality,
        private readonly EngineeringKnowledgeBaseService $knowledge,
        private readonly EngineeringCodeIntelligenceService $code,
        private readonly AtlasAiArchitectureValidationService $architectureValidation,
        private readonly AtlasArchitectureReadinessService $architectureReadiness,
        private readonly AtlasGovernanceGateService $governanceGate,
        private readonly KernelReplayReportInput $replayInput,
        private readonly OpenBrainMcpInput $mcpInput,
        private readonly AtlasMemoryRegistryService $registry,
        private readonly CodeGraphTools $codeGraph,
        private readonly TaskTools $taskTools,
        private readonly MemoryEntryTools $memoryEntry,
        private readonly GraphRagTools $graphRag,
        private readonly WorkspaceTools $workspaceTools,
        private readonly ContextTools $contextTools,
        private readonly HealthMetricsTools $healthMetrics,
        private readonly ProviderReleaseTools $providerReleaseTools,
        private readonly ReportTools $reportTools,
        private readonly ArchitectureTools $architectureTools,
        private readonly NavigationTools $navTools,
        private readonly RuntimeSurfaceTools $runtimeSurfaceTools,
    ) {
        $this->processStartedAt = Carbon::now()->toIso8601String();
    }

    /**
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>|null
     */
    public function handleJsonRpc(array $request): ?array
    {
        if ($this->isBatchRequest($request)) {
            $responses = [];
            foreach ($request as $item) {
                if (! is_array($item)) {
                    $responses[] = $this->error(null, -32600, 'Invalid JSON-RPC request.');

                    continue;
                }

                $response = $this->handleJsonRpc($item);
                if ($response !== null) {
                    $responses[] = $response;
                }
            }

            return $responses === [] ? null : $responses;
        }

        $id = $request['id'] ?? null;
        $method = is_string($request['method'] ?? null) ? (string) $request['method'] : null;

        if ($method === null || $method === '') {
            return $this->error($id, -32600, 'Invalid JSON-RPC request.');
        }

        if (! array_key_exists('id', $request)) {
            return null;
        }

        return match ($method) {
            'initialize' => $this->response($id, $this->initializeResult($request)),
            'ping' => $this->response($id, []),
            'tools/list' => $this->response($id, ['tools' => $this->listedTools($request)]),
            'tools/call' => $this->callTool($id, $request),
            default => $this->error($id, -32601, "Method [{$method}] not found."),
        };
    }

    /**
     * JSON-RPC batch requests are arrays of request objects. They are uncommon for MCP
     * stdio clients, but accepting them keeps the local server protocol-tolerant.
     *
     * @param  array<mixed>  $request
     */
    private function isBatchRequest(array $request): bool
    {
        return array_is_list($request);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function tools(): array
    {
        return OpenBrainMcpToolCatalog::definitions();
    }

    /**
     * @param  array<string,mixed>  $request
     * @return array<int,array<string,mixed>>
     */
    private function listedTools(array $request): array
    {
        if ((bool) data_get($request, 'params.include_compatibility', false)) {
            return $this->tools();
        }

        $primary = array_fill_keys([...self::PRIMARY_TOOLS, 'atlas_tool_search'], true);

        return array_values(array_filter(
            $this->tools(),
            static fn (array $tool): bool => isset($primary[(string) ($tool['name'] ?? '')]),
        ));
    }

    /**
     * @param  array<string,mixed>  $tool
     * @return array<string,mixed>
     */
    private function withSurfaceReviewAnnotation(array $tool): array
    {
        $name = (string) ($tool['name'] ?? '');
        $annotations = (array) ($tool['annotations'] ?? []);
        $annotations['atlasSurfaceReview'] = [
            'primary' => in_array($name, self::PRIMARY_TOOLS, true),
            'review_command' => 'atlas:open-brain:surface-review --json',
            'deprecation_policy' => [
                'minimum_observation_days' => 90,
                'usage_evidence_required' => true,
                'zero_removals_in_current_slice' => true,
            ],
        ];
        $annotations['atlasContract'] = $this->atlasToolContract($name, $annotations);
        $tool['annotations'] = $annotations;

        return $tool;
    }

    /**
     * @param  array<string,mixed>  $annotations
     * @return array<string,mixed>
     */
    private function atlasToolContract(string $name, array $annotations): array
    {
        $sideEffect = in_array($name, self::WRITE_TOOLS, true) ? 'write' : 'read';

        return [
            'stability' => 'stable',
            'since' => '2026-07-12',
            'provider_bound' => true,
            'side_effect' => $sideEffect,
            'cost_tier' => ((bool) ($annotations['openWorldHint'] ?? false)) ? 'external' : 'local_cpu',
        ];
    }

    /**
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>
     */
    private function initializeResult(array $request): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [
                'tools' => [
                    'listChanged' => false,
                ],
            ],
            'serverInfo' => [
                'name' => 'atlas-open-brain',
                'title' => 'Atlas Open Brain',
                'version' => self::SERVER_VERSION,
            ],
            'instructions' => 'Use Atlas tools as the provider-safe source of truth for Atlas memory, canonical docs, code intelligence and audited context packs. Read tools are provider-safe by default; the write tool atlas_memory_record persists provider-safe entries with hard-coded defaults.',
        ];
    }

    /**
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>
     */
    private function callTool(mixed $id, array $request): array
    {
        $name = data_get($request, 'params.name');
        if (! is_string($name) || $name === '') {
            return $this->error($id, -32602, 'tools/call requires params.name.');
        }

        $arguments = data_get($request, 'params.arguments', []);
        if (! is_array($arguments)) {
            return $this->toolError($id, 'Tool arguments must be an object.', ['tool' => $name]);
        }
        $quota = $this->mcpClientQuota($arguments);

        try {
            $response = match ($name) {
                'atlas_memory_recall' => $this->toolResponse($id, $this->contextTools->memoryRecall($arguments)),
                'atlas_open_brain_context_pack' => $this->toolResponse($id, $this->contextTools->contextPack($arguments)),
                'atlas_context_expand' => $this->toolResponse($id, $this->contextTools->contextExpand($arguments)),
                'atlas_context_feedback' => $this->toolResponse($id, $this->contextTools->contextFeedback($arguments)),
                'atlas_memory_maintenance_status' => $this->toolResponse($id, $this->maintenanceStatus($arguments)),
                'atlas_memory_record' => $this->toolResponse($id, $this->memoryRecord($arguments)),
                'atlas_code_find_relevant' => $this->toolResponse($id, $this->codeFindRelevant($arguments)),
                'atlas_docs_lookup' => $this->toolResponse($id, $this->docsLookup($arguments)),
                'atlas_capabilities' => $this->toolResponse($id, $this->runtimeSurfaceTools->capabilities($this)),
                'atlas_mcp_self_check' => $this->toolResponse($id, $this->runtimeSurfaceTools->mcpSelfCheck($arguments, $this)),
                'atlas_domain_catalog' => $this->toolResponse($id, $this->architectureTools->domainCatalog($arguments)),
                'atlas_architecture_validate' => $this->toolResponse($id, $this->architectureTools->architectureValidate($arguments)),
                'atlas_architecture_operations' => $this->toolResponse($id, $this->architectureTools->architectureOperations($arguments)),
                'atlas_architecture_readiness' => $this->toolResponse($id, $this->architectureTools->architectureReadiness($arguments)),
                'atlas_runtime_boundary' => $this->toolResponse($id, $this->architectureTools->runtimeBoundary()),
                'atlas_session_bootstrap' => $this->toolResponse($id, $this->architectureTools->sessionBootstrap($arguments)),
                'atlas_feature_placement' => $this->toolResponse($id, $this->architectureTools->featurePlacement($arguments)),
                'atlas_docs_split_plan' => $this->toolResponse($id, $this->architectureTools->docsSplitPlan($arguments)),
                'atlas_self_improvement_schedule' => $this->toolResponse($id, $this->reportTools->selfImprovementSchedule($arguments)),
                'atlas_self_improvement_schedule_report' => $this->toolResponse($id, $this->reportTools->selfImprovementScheduleReport($arguments)),
                'atlas_kernel_slo_report' => $this->toolResponse($id, $this->reportTools->kernelSloReport($arguments)),
                'atlas_kernel_pipeline_report' => $this->toolResponse($id, $this->reportTools->kernelPipelineReport($arguments)),
                'atlas_repair_loop_report' => $this->toolResponse($id, $this->reportTools->repairLoopReport($arguments)),
                'atlas_inbox_action_report' => $this->toolResponse($id, $this->reportTools->inboxActionReport($arguments)),
                'atlas_agent_behavior_report' => $this->toolResponse($id, $this->reportTools->agentBehaviorReport($arguments)),
                'atlas_provider_performance_report' => $this->toolResponse($id, $this->reportTools->providerPerformanceReport($arguments)),
                'atlas_dynamic_compute_market_report' => $this->toolResponse($id, $this->reportTools->dynamicComputeMarketReport($arguments)),
                'atlas_provider_release_review' => $this->toolResponse($id, $this->providerReleaseTools->providerReleaseReview($arguments)),
                'atlas_provider_release_sources' => $this->toolResponse($id, $this->providerReleaseTools->providerReleaseSources($arguments)),
                'atlas_ledger_projection_health' => $this->toolResponse($id, $this->reportTools->ledgerProjectionHealth($arguments)),
                'atlas_decision_receipt_report' => $this->toolResponse($id, $this->reportTools->decisionReceiptReport($arguments)),
                'atlas_workspace_info' => $this->toolResponse($id, $this->navTools->workspaceInfo($arguments)),
                'atlas_recent_changes' => $this->toolResponse($id, $this->navTools->recentChanges($arguments)),
                'atlas_decision_query' => $this->toolResponse($id, $this->navTools->decisionQuery($arguments)),
                'atlas_task_start' => $this->toolResponse($id, $this->taskTools->taskStart($arguments)),
                'atlas_task_progress' => $this->toolResponse($id, $this->taskTools->taskProgress($arguments)),
                'atlas_task_complete' => $this->toolResponse($id, $this->taskTools->taskComplete($arguments)),
                'atlas_memory_archive' => $this->toolResponse($id, $this->memoryEntry->memoryArchive($arguments)),
                'atlas_memory_link' => $this->toolResponse($id, $this->memoryEntry->memoryLink($arguments)),
                'atlas_memory_supersede' => $this->toolResponse($id, $this->memoryEntry->memorySupersede($arguments)),
                'atlas_memory_get' => $this->toolResponse($id, $this->memoryEntry->memoryGet($arguments)),
                'atlas_module_info' => $this->toolResponse($id, $this->navTools->moduleInfo($arguments)),
                'atlas_route_info' => $this->toolResponse($id, $this->navTools->routeInfo($arguments)),
                'atlas_test_for' => $this->toolResponse($id, $this->navTools->testFor($arguments)),
                'atlas_context_for' => $this->toolResponse($id, $this->navTools->contextFor($arguments)),
                'atlas_code_neighbors' => $this->toolResponse($id, $this->codeGraph->codeNeighbors($arguments)),
                'atlas_code_path' => $this->toolResponse($id, $this->codeGraph->codePath($arguments)),
                'atlas_code_explain' => $this->toolResponse($id, $this->codeGraph->codeExplain($arguments)),
                'atlas_ccr_retrieve' => $this->toolResponse($id, $this->graphRag->ccrRetrieve($arguments)),
                'atlas_cross_domain_query' => $this->toolResponse($id, $this->graphRag->crossDomainQuery($arguments)),
                'atlas_aurg_query' => $this->toolResponse($id, $this->graphRag->aurgQuery($arguments)),
                'atlas_mission_history' => $this->toolResponse($id, $this->workspaceTools->missionHistory($arguments)),
                'atlas_obra_status' => $this->toolResponse($id, $this->workspaceTools->obraStatus($arguments)),
                'atlas_context_pack' => $this->toolResponse($id, $this->contextPackUnified($arguments)),
                'atlas_record_outcome' => $this->toolResponse($id, $this->workspaceTools->recordOutcome($arguments)),
                'atlas_propose_learning' => $this->toolResponse($id, $this->workspaceTools->proposeLearning($arguments)),
                'atlas_workspace_status' => $this->toolResponse($id, $this->workspaceTools->workspaceStatus($arguments)),
                'atlas_workspace_map' => $this->toolResponse($id, $this->workspaceTools->workspaceMap($arguments)),
                'atlas_workspace_fleet_map' => $this->toolResponse($id, $this->workspaceTools->workspaceFleetMap($arguments)),
                'atlas_workspace_activate' => $this->toolResponse($id, $this->workspaceTools->workspaceActivate($arguments)),
                'atlas_claim_task' => $this->toolResponse($id, $this->workspaceTools->claimTask($arguments)),
                'atlas_blackboard_status' => $this->toolResponse($id, $this->workspaceTools->blackboardStatus($arguments)),
                'atlas_tool_search' => $this->toolResponse($id, $this->runtimeSurfaceTools->toolSearch($arguments, $this)),
                // PART 2 · A7 — the task-serving contract over MCP (same service as `atlas:task`, platform-free).
                'atlas_next_task' => $this->toolResponse($id, $this->taskTools->nextTask($arguments)),
                'atlas_task_report' => $this->toolResponse($id, $this->taskTools->taskReport($arguments)),
                default => $this->error($id, -32602, "Unknown Atlas MCP tool [{$name}]."),
            };
            $response = $this->withMcpQuotaEnvelope($response, $quota);
            $this->recordMcpToolUsageTelemetry($name, $this->mcpToolCallStatus($response), $quota);

            return $response;
        } catch (Throwable $exception) {
            $this->recordMcpToolUsageTelemetry($name, 'error', $quota);

            return $this->toolError($id, $exception->getMessage(), [
                'tool' => $name,
                'exception' => class_basename($exception),
            ]);
        }
    }

    /**
     * OPE-05 — append-only per-tool usage on ai_telemetry_events. Fail-open.
     *
     * @param  array<string,mixed>  $response
     */
    private function mcpToolCallStatus(array $response): string
    {
        if (array_key_exists('error', $response)) {
            return 'error';
        }

        if ((bool) data_get($response, 'result.isError', false)) {
            return 'error';
        }

        return 'ok';
    }

    /**
     * @param  array<string,mixed>  $quota
     */
    private function recordMcpToolUsageTelemetry(string $toolName, string $status, array $quota = []): void
    {
        if (! DatabaseTableAvailability::has('ai_telemetry_events')) {
            return;
        }

        try {
            app(AiTelemetryCollector::class)->record([
                'event_key' => 'open_brain:mcp_tool:'.Str::uuid(),
                'surface' => 'server',
                'runtime' => 'laravel',
                'event_name' => self::MCP_TOOL_USAGE_EVENT_NAME,
                'event_phase' => $status,
                'metadata' => [
                    'tool_name' => $toolName,
                    'called_at' => now()->toIso8601String(),
                    'status' => $status,
                    'client_id_hash' => (string) ($quota['client_id_hash'] ?? ''),
                    'quota_status' => (string) ($quota['status'] ?? 'unavailable'),
                    'rate_softcapped' => (bool) ($quota['rate_softcapped'] ?? false),
                ],
            ]);
        } catch (Throwable) {
            // fail-open: telemetry must never block tool execution
        }
    }

    /**
     * MAXM-07 — local, provider-safe soft cadence accounting by opaque client id.
     * Fail-open: missing/broken telemetry never blocks read tools.
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function mcpClientQuota(array $arguments): array
    {
        $windowSeconds = max(1, (int) config('atlas.aobg.mcp_quota.window_seconds', 60));
        $callsPerWindow = max(1, (int) config('atlas.aobg.mcp_quota.calls_per_window', 120));
        $clientId = $this->string($arguments['client_id'] ?? ($arguments['client'] ?? null)) ?? 'anonymous';
        $clientHash = substr(hash('sha256', $clientId), 0, 16);
        $base = [
            'schema' => 'atlas.open_brain.mcp_quota.v1',
            'client_id_hash' => $clientHash,
            'calls_per_window' => $callsPerWindow,
            'window_seconds' => $windowSeconds,
            'rate_softcapped' => false,
            'retry_after_seconds' => 0,
        ];

        if (! DatabaseTableAvailability::has('ai_telemetry_events')) {
            return $base + ['status' => 'unavailable', 'reason' => 'telemetry_table_missing'];
        }

        try {
            $windowStart = now()->subSeconds($windowSeconds);
            $recent = AiTelemetryEvent::query()
                ->where('event_name', self::MCP_TOOL_USAGE_EVENT_NAME)
                ->latest('received_at')
                ->limit(max(500, $callsPerWindow * 4))
                ->get()
                ->filter(fn (AiTelemetryEvent $event): bool => $event->received_at !== null && $event->received_at->greaterThanOrEqualTo($windowStart))
                ->filter(fn (AiTelemetryEvent $event): bool => data_get($event->metadata, 'client_id_hash') === $clientHash);
            $callsInWindow = $recent->count();
            $softcapped = $callsInWindow >= $callsPerWindow;

            return array_merge($base, [
                'status' => $softcapped ? 'rate_softcapped' : 'ok',
                'calls_in_window' => $callsInWindow,
                'rate_softcapped' => $softcapped,
                'retry_after_seconds' => $softcapped ? $windowSeconds : 0,
            ]);
        } catch (Throwable) {
            return $base + ['status' => 'unavailable', 'reason' => 'quota_accounting_failed'];
        }
    }

    /**
     * @param  array<string,mixed>  $response
     * @param  array<string,mixed>  $quota
     * @return array<string,mixed>
     */
    private function withMcpQuotaEnvelope(array $response, array $quota): array
    {
        if (! isset($response['result']) || ! is_array($response['result'])) {
            return $response;
        }

        $structured = data_get($response, 'result.structuredContent');
        if (! is_array($structured)) {
            return $response;
        }

        $structured['quota'] = $quota;
        if (($quota['rate_softcapped'] ?? false) === true) {
            $structured['rate_softcapped'] = true;
            $structured['retry_after_seconds'] = (int) ($quota['retry_after_seconds'] ?? 0);
        }
        data_set($response, 'result.structuredContent', $structured);
        data_set($response, 'result.content.0.text', json_encode($structured, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $response;
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function maintenanceStatus(array $arguments): array
    {
        $workspace = $this->workspace($arguments['workspace'] ?? null);
        $projection = $this->projection->status('all', [
            'workspace' => $workspace,
        ], [
            'workspace' => $workspace,
        ]);
        $knowledge = $this->knowledge->summary();
        $code = $this->code->summary();
        $memory = $this->healthMetrics->memorySummary($workspace);
        $memoryQuality = $this->quality->scorecard([
            'workspace' => $workspace,
        ]);
        $promptMetrics = $this->healthMetrics->openBrainPromptMetrics();
        $contextFeedbackMetrics = $this->healthMetrics->contextFeedbackMetrics();
        $runtimeSourceProbe = $this->runtimeSurfaceTools->runtimeSourceProbe();
        $includeDriftAudit = (bool) ($arguments['include_drift_audit'] ?? false);
        $codeAudit = $includeDriftAudit
            ? $this->code->audit([
                'workspace' => $workspace,
                'limit' => 25,
            ])
            : null;

        return [
            'ok' => true,
            'tool' => 'atlas_memory_maintenance_status',
            'workspace' => $workspace,
            'memory' => $memory,
            'memory_quality' => $memoryQuality,
            'open_brain_prompt_metrics' => $promptMetrics,
            'context_feedback_metrics' => $contextFeedbackMetrics,
            'mcp_runtime_source_probe' => $runtimeSourceProbe,
            'knowledge' => $knowledge,
            'code_intelligence' => $code,
            'code_audit' => $codeAudit,
            'provider_projection' => $projection,
            'overall_status' => $this->healthMetrics->overallStatus($memory, $memoryQuality, $promptMetrics, $contextFeedbackMetrics, $runtimeSourceProbe, $knowledge, $code, $projection, $codeAudit),
            'next_actions' => $this->healthMetrics->nextActions($workspace, $memory, $memoryQuality, $promptMetrics, $contextFeedbackMetrics, $runtimeSourceProbe, $knowledge, $code, $projection, $codeAudit),
            'writes' => false,
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function memoryRecord(array $arguments): array
    {
        $memoryType = $this->string($arguments['memory_type'] ?? null);
        $scopeType = $this->string($arguments['scope_type'] ?? null);
        $title = $this->string($arguments['title'] ?? null);
        $body = $this->string($arguments['body'] ?? null);

        if ($memoryType === null || ! in_array($memoryType, AtlasMemoryEntry::TYPES, true)) {
            return ['ok' => false, 'tool' => 'atlas_memory_record', 'error' => 'invalid_memory_type'];
        }
        if ($scopeType === null || ! in_array($scopeType, AtlasMemoryEntry::SCOPES, true)) {
            return ['ok' => false, 'tool' => 'atlas_memory_record', 'error' => 'invalid_scope_type'];
        }
        if ($title === null || $body === null) {
            return ['ok' => false, 'tool' => 'atlas_memory_record', 'error' => 'title_and_body_required'];
        }

        $context = $this->object($arguments['context'] ?? []);
        $tags = is_array($arguments['tags'] ?? null) ? $arguments['tags'] : [];
        $evidence = is_array($arguments['evidence'] ?? null) ? $arguments['evidence'] : [];

        $entry = $this->registry->record([
            'memory_type' => $memoryType,
            'scope_type' => $scopeType,
            'scope_id' => $this->string($arguments['scope_id'] ?? null),
            'project_id' => $this->string($context['project_id'] ?? null),
            'task_id' => $this->string($context['task_id'] ?? null),
            'engineering_run_id' => $this->string($context['run_id'] ?? null),
            'session_id' => $this->string($context['session_id'] ?? null),
            'user_id' => $this->string($context['user_id'] ?? null),
            'title' => $title,
            'body' => $body,
            'summary' => $this->string($arguments['summary'] ?? null),
            'importance' => 5,
            'priority' => 5,
            'confidence' => 0.8,
            'privacy_class' => 'normal',
            'external_ai_allowed' => true,
            'redaction_status' => 'clean',
            'source_type' => 'mcp_tool',
            'source_label' => 'atlas_memory_record',
            'status' => 'active',
            'tags' => $tags,
            'metadata' => ['evidence' => $evidence, 'context' => $context],
            'recorded_at' => now(),
        ]);

        return [
            'ok' => true,
            'tool' => 'atlas_memory_record',
            'memory_entry_id' => (string) $entry->id,
            'memory_type' => $entry->memory_type,
            'scope_type' => $entry->scope_type,
            'recorded_at' => $entry->recorded_at?->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function codeFindRelevant(array $arguments): array
    {
        $query = $this->string($arguments['query'] ?? null);
        if ($query === null) {
            return ['ok' => false, 'tool' => 'atlas_code_find_relevant', 'error' => 'query_required'];
        }

        $limit = $this->mcpInput->codeLimit($arguments['limit'] ?? null);
        $workspacePath = $this->workspace($arguments['workspace'] ?? null);
        // AP-815 W-3 — scope the read-model query to the RESOLVED workspace so a code-find
        // against atlas-server never returns symbols indexed from another workspace (the
        // 'workspace' field was previously cosmetic-only), and a query CAN now be pinned to
        // one workspace via the `workspace` arg. The resolver mirrors the proven W-1 path
        // ({@see CodeGraphContextRetriever}, EngineeringCodeIntelligenceService::index());
        // symbols() applies the filter only when the W-1 column exists, so a pre-W-1
        // read-model keeps its single-workspace behaviour byte-for-byte.
        $workspaceId = app(CodeGraphWorkspaceIdentity::class)->resolve($workspacePath);

        $filters = array_filter([
            'q' => $query,
            'symbol_type' => $this->string($arguments['symbol_type'] ?? null),
            'language' => $this->string($arguments['language'] ?? null),
        ]);

        $result = $this->code->symbols($filters + ['workspace_id' => $workspaceId], $limit);
        $symbols = $result['symbols'] ?? [];

        return [
            'ok' => true,
            'tool' => 'atlas_code_find_relevant',
            'workspace' => $workspacePath,
            'workspace_id' => $workspaceId,
            'query' => $query,
            'filters' => $filters,
            'symbols' => $symbols,
            'count' => count($symbols),
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function docsLookup(array $arguments): array
    {
        $query = $this->string($arguments['query'] ?? null);
        if ($query === null) {
            return ['ok' => false, 'tool' => 'atlas_docs_lookup', 'error' => 'query_required'];
        }

        $limit = $this->mcpInput->docsLimit($arguments['limit'] ?? null);
        $filters = array_filter([
            'q' => $query,
            'category' => $this->string($arguments['category'] ?? null),
            'status' => $this->string($arguments['status'] ?? null) ?: 'active',
        ]);

        $result = $this->knowledge->catalog($filters, $limit);

        return [
            'ok' => true,
            'tool' => 'atlas_docs_lookup',
            'query' => $query,
            'filters' => $filters,
            'docs' => $result['items'] ?? [],
            'count' => count($result['items'] ?? []),
            'generated_at' => now()->toJSON(),
        ];
    }







    /**
     * Provider-safe runtime identity for stale-session detection.
     *
     * @return array<string,mixed>
     */
    public function runtimeProfile(): array
    {
        return $this->runtimeSurfaceTools->runtimeProfile($this);
    }


    /**
     * AOBG N1.F1 — the unified context-pack front door. The ONE provider-bound
     * pack any external AI calls first: fuses code-graph + AURG reality graph +
     * semantic memory into one budgeted brief. Read-only, local DB only, zero
     * provider spend.
     *
     * provider_bound is STRUCTURAL on this surface (MCP output can land in a
     * provider prompt) — the service forces the AURG query provider_bound and the
     * memory recall returns only redacted provider-safe projections. The
     * workspace is resolved from the `workspace` arg (path or id), never leaking
     * cross-workspace. There is NO write-back via MCP: external input is
     * untrusted, so any learn-back goes through the capture quality gate + the
     * never-auto-promote CLI path, not this read tool.
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function contextPackUnified(array $arguments): array
    {
        $tool = 'atlas_context_pack';
        $task = $this->string($arguments['task'] ?? null);
        if ($task === null) {
            return ['ok' => false, 'tool' => $tool, 'error' => 'task_required'];
        }

        $opts = $this->contextTools->contextPackOptions($arguments);

        $pack = $this->contextPack->packFor($task, $opts);

        return [
            'ok' => true,
            'tool' => $tool,
            'provider_bound' => true,
            'mcp_runtime' => $this->runtimeProfile(),
            'pack' => $pack,
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * OPE-07 — read-only surface review. It never removes tools; it emits the
     * evidence-backed verdict a future deprecation slice may consume.
     *
     * @return array<string,mixed>
     */
    public function surfaceReview(): array
    {
        return $this->runtimeSurfaceTools->surfaceReview($this);
    }

    private function toolResponse(mixed $id, array $structured): array
    {
        return $this->response($id, [
            'content' => [[
                'type' => 'text',
                'text' => json_encode($structured, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]],
            'structuredContent' => $structured,
            'isError' => (bool) (($structured['ok'] ?? true) === false),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function toolError(mixed $id, string $message, array $metadata = []): array
    {
        return $this->response($id, [
            'content' => [[
                'type' => 'text',
                'text' => $message,
            ]],
            'structuredContent' => [
                'ok' => false,
                'error' => $message,
                'metadata' => $metadata,
            ],
            'isError' => true,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function response(mixed $id, array $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function error(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function object(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * @return array<int,string>
     */
    private function stringList(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $item): ?string => $this->string($item),
            $values,
        ))));
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    private function workspace(mixed $workspace): ?string
    {
        $workspace = $this->string($workspace) ?: (config('atlas.ai.workdir') ?: null);
        if ($workspace === null) {
            return base_path();
        }

        return realpath($workspace) ?: $workspace;
    }
}
