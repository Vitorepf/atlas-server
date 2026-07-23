<?php

namespace App\Services\Ai;

use App\Models\AiRagFeedbackEvent;
use App\Models\AiTelemetryEvent;
use App\Models\AtlasAurgNode;
use App\Models\AtlasMemoryEntry;
use App\Models\AtlasMemoryEntryRelation;
use App\Models\AtlasOpenBrainAccessLog;
use App\Models\AtlasVerbatimMemory;
use App\Services\Ai\Context\AtlasRetrievalFeedbackLoopService;
use App\Services\Ai\Instrumentation\AtlasProviderProjectionService;
use App\Services\Ai\Kernel\Architecture\AtlasAiArchitectureValidationService;
use App\Services\Ai\Kernel\Architecture\AtlasArchitectureOperationsCatalog;
use App\Services\Ai\Kernel\Architecture\AtlasArchitectureReadinessService;
use App\Services\Ai\Kernel\Architecture\AtlasDocumentationSplitPlanService;
use App\Services\Ai\Kernel\Architecture\AtlasFeaturePlacementService;
use App\Services\Ai\Kernel\Architecture\AtlasGovernanceGateService;
use App\Services\Ai\Kernel\Architecture\AtlasProviderReleaseIntelligenceService;
use App\Services\Ai\Kernel\Architecture\AtlasProviderReleaseSourceRegistry;
use App\Services\Ai\Kernel\Architecture\AtlasRuntimeLanguageBoundaryReportService;
use App\Services\Ai\Kernel\Architecture\AtlasSessionBootstrapService;
use App\Services\Ai\Kernel\Decision\DynamicComputeMarketReportService;
use App\Services\Ai\Kernel\Domain\AtlasAiDomainCatalogService;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\AtlasLedgerReplayService;
use App\Services\Ai\Kernel\Evidence\KernelReplayReportInput;
use App\Services\Ai\Kernel\Evidence\LedgerProjectionRegistry;
use App\Services\Ai\Kernel\Evidence\ProviderPerformanceProjection;
use App\Services\Ai\Kernel\Mcp\OpenBrainMcpInput;
use App\Services\Ai\Mcp\AtlasMcpTierService;
use App\Services\Ai\OpenBrainMcp\CodeGraphTools;
use App\Services\Ai\OpenBrainMcp\TaskTools;
use App\Services\Ai\OpenBrainMcp\MemoryEntryTools;
use App\Services\Ai\OpenBrainMcp\GraphRagTools;
use App\Services\Ai\Reality\AtlasRealityGraphIngestionService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementScheduleService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Ai\Telemetry\AiTelemetryCollector;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use App\Services\Engineering\EngineeringKnowledgeBaseService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
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
        private readonly AtlasHybridMemoryRetrievalService $recall,
        private readonly AtlasOpenBrainContextPackService $contextPack,
        private readonly AtlasOpenBrainContextExpansionService $contextExpansion,
        private readonly AtlasRetrievalFeedbackLoopService $retrievalFeedback,
        private readonly AtlasOpenBrainService $openBrain,
        private readonly AtlasProviderProjectionService $projection,
        private readonly AtlasMemoryPrivacyService $privacy,
        private readonly AtlasMemoryQualityService $quality,
        private readonly EngineeringKnowledgeBaseService $knowledge,
        private readonly EngineeringCodeIntelligenceService $code,
        private readonly AtlasAiDomainCatalogService $domainCatalog,
        private readonly AtlasAiArchitectureValidationService $architectureValidation,
        private readonly AtlasArchitectureReadinessService $architectureReadiness,
        private readonly AtlasArchitectureOperationsCatalog $architectureOperations,
        private readonly AtlasRuntimeLanguageBoundaryReportService $runtimeBoundary,
        private readonly AtlasSessionBootstrapService $sessionBootstrap,
        private readonly AtlasFeaturePlacementService $featurePlacement,
        private readonly AtlasGovernanceGateService $governanceGate,
        private readonly AtlasDocumentationSplitPlanService $documentationSplitPlan,
        private readonly AtlasProviderReleaseIntelligenceService $providerReleaseIntelligence,
        private readonly AtlasProviderReleaseSourceRegistry $providerReleaseSources,
        private readonly AtlasSelfImprovementScheduleService $selfImprovementSchedule,
        private readonly AtlasLedgerReplayService $ledgerReplay,
        private readonly ProviderPerformanceProjection $providerPerformance,
        private readonly DynamicComputeMarketReportService $dynamicComputeMarketReports,
        private readonly LedgerProjectionRegistry $ledgerProjectionRegistry,
        private readonly KernelReplayReportInput $replayInput,
        private readonly OpenBrainMcpInput $mcpInput,
        private readonly AtlasEvidenceLedger $ledger,
        private readonly AtlasOpenBrainWriteBackService $writeBack,
        private readonly AtlasAobgWorkspaceOnboardingService $workspaceOnboarding,
        private readonly AtlasAobgBlackboardService $blackboard,
        private readonly AtlasMemoryRegistryService $registry,
        private readonly CodeGraphTools $codeGraph,
        private readonly TaskTools $taskTools,
        private readonly MemoryEntryTools $memoryEntry,
        private readonly GraphRagTools $graphRag,
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
        $tools = [
            [
                'name' => 'atlas_memory_recall',
                'title' => 'Atlas Memory Recall',
                'description' => 'Busca memoria provider-safe no Atlas usando registry, verbatim aprovado e notas semanticas locais. Nao executa escrita.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Pergunta ou objetivo de recall.'],
                        'context' => ['type' => 'object', 'description' => 'Contexto Atlas: workspace, project_id, task_id, run_id, session_id ou user_id.'],
                        'filters' => ['type' => 'object', 'description' => 'Filtros opcionais de memoria, verbatim ou semantic.'],
                        'options' => ['type' => 'object', 'description' => 'Opcoes como limit, budget_chars, include_semantic.'],
                    ],
                    'required' => ['query'],
                ],
                'annotations' => [
                    'readOnlyHint' => true,
                    'destructiveHint' => false,
                    'openWorldHint' => false,
                ],
            ],
            [
                'name' => 'atlas_open_brain_context_pack',
                'title' => 'Atlas Open Brain Context Pack',
                'description' => 'Exporta um context pack provider-safe e auditado para uma tarefa de programacao, review ou decisao.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'objective' => ['type' => 'string', 'description' => 'Objetivo que o provider deve executar.'],
                        'workspace' => ['type' => 'string', 'description' => 'Workspace local permitido.'],
                        'task_type' => ['type' => 'string', 'description' => 'Tipo da tarefa: dev, debug, review, research, decision ou memory.'],
                        'desired_mode' => ['type' => 'string', 'description' => 'Modo Atlas desejado.'],
                        'agent' => ['type' => 'string', 'description' => 'Agente/domino desejado.'],
                        'intent' => ['type' => 'string', 'description' => 'Intent auditavel.'],
                        'requester' => ['type' => 'string', 'description' => 'Nome do cliente/provider MCP.'],
                        'include_prompt' => ['type' => 'boolean', 'description' => 'Inclui secao renderizada de prompt.'],
                        'prompt_mode' => ['type' => 'string', 'description' => 'Modo do prompt quando include_prompt=true: compact (default, menor primeiro pacote) ou full (auditoria completa).'],
                        'payload' => ['type' => 'object', 'description' => 'Payload Atlas adicional, como project_id e task_id.'],
                        'options' => ['type' => 'object', 'description' => 'Opcoes do context pack.'],
                    ],
                    'required' => ['objective'],
                ],
                'annotations' => [
                    'readOnlyHint' => true,
                    'destructiveHint' => false,
                    'openWorldHint' => false,
                ],
            ],
            [
                'name' => 'atlas_context_expand',
                'title' => 'Atlas Context Expand',
                'description' => 'Expande sob demanda um handle do Open Brain, como expand:evidence_replay ou recheck:canonical_doc. Read-only, provider-safe, local-only, sem provider spend, sem raw docs/tests dump.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'handle' => ['type' => 'string', 'description' => 'Handle de expansao: expand:<source_type>, recheck:<source_type> ou source_type direto.'],
                        'objective' => ['type' => 'string', 'description' => 'Objetivo/tarefa que guia a expansao.'],
                        'workspace' => ['type' => 'string', 'description' => 'Workspace local permitido.'],
                        'task_type' => ['type' => 'string', 'description' => 'Tipo da tarefa: dev, debug, review, research, decision ou memory.'],
                        'domain' => ['type' => 'string', 'description' => 'Dominio/logical area, por exemplo developer.'],
                        'risk_level' => ['type' => 'string', 'description' => 'Risco da tarefa: low, medium, high ou irreversible.'],
                        'max_refs' => ['type' => 'integer', 'description' => 'Max refs para expansao via ranking. Default 6, max 20.'],
                        'budget' => ['type' => 'integer', 'description' => 'Budget para expansao via compact AOBG pack. Default 3200.'],
                    ],
                    'required' => ['handle', 'objective'],
                ],
                'annotations' => [
                    'readOnlyHint' => true,
                    'destructiveHint' => false,
                    'openWorldHint' => false,
                ],
            ],
            [
                'name' => 'atlas_context_feedback',
                'title' => 'Atlas Context Feedback',
                'description' => 'Registra feedback provider-safe sobre utilidade do contexto entregue: refs usadas, refs ruidosas, fontes ausentes, outcome e ROI. Nao aceita texto bruto; aprendizado e proposal-only e so persiste quando record=true.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'objective' => ['type' => 'string', 'description' => 'Objetivo ou label redigido da tarefa. Evite texto bruto sensivel.'],
                        'workspace' => ['type' => 'string', 'description' => 'Workspace local permitido.'],
                        'task_type' => ['type' => 'string', 'description' => 'Tipo da tarefa: dev, debug, review, research, decision ou memory.'],
                        'domain' => ['type' => 'string', 'description' => 'Dominio/logical area, por exemplo developer.'],
                        'flow_id' => ['type' => 'string', 'description' => 'Flow id explicito emitido por context_feedback_request, por exemplo developer.debug.'],
                        'risk_level' => ['type' => 'string', 'description' => 'Risco da tarefa: low, medium, high ou irreversible.'],
                        'outcome_status' => ['type' => 'string', 'description' => 'Resultado: passed, partial, failed, blocked, error ou unknown.'],
                        'context_pack_hash' => ['type' => 'string', 'description' => 'Hash do context pack usado, quando conhecido.'],
                        'retrieval_receipt_id' => ['type' => 'string', 'description' => 'Receipt/hash de retrieval, quando conhecido.'],
                        'delivered_context_refs' => ['type' => 'array', 'description' => 'Refs provider-safe entregues no contexto inicial.'],
                        'used_context_refs' => ['type' => 'array', 'description' => 'Refs provider-safe realmente usadas.'],
                        'noise_context_refs' => ['type' => 'array', 'description' => 'Refs provider-safe julgadas ruidosas ou desnecessarias.'],
                        'missed_required_sources' => ['type' => 'array', 'description' => 'Tipos de fonte ausentes, como migration, test, route, doc ou graph.'],
                        'post_execution_utility' => ['type' => 'integer', 'description' => 'Nota 0-100 de utilidade do contexto apos execucao.'],
                        'run_outcome_id' => ['type' => 'string', 'description' => 'Id de ai_run_outcomes para join MULTX-01 (delivered context).'],
                        'memory_candidate_id' => ['type' => 'string', 'description' => 'Id de ai_learning_candidates para subsequent measured recall.'],
                        'max_refs' => ['type' => 'integer', 'description' => 'Max refs para avaliacao auxiliar. Default 8.'],
                        'record' => ['type' => 'boolean', 'description' => 'Quando true, persiste evento em ai_rag_feedback_events se a tabela existir.'],
                    ],
                    'required' => ['objective'],
                ],
                'annotations' => [
                    'readOnlyHint' => false,
                    'destructiveHint' => false,
                    'openWorldHint' => false,
                ],
            ],
            [
                'name' => 'atlas_memory_maintenance_status',
                'title' => 'Atlas Memory Maintenance Status',
                'description' => 'Mostra health check read-only da memoria: docs sync, code index, provider projection, prompt metric aggregates e tabelas principais.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'workspace' => ['type' => 'string', 'description' => 'Workspace local para status de projection/code.'],
                        'include_drift_audit' => ['type' => 'boolean', 'description' => 'Quando true, roda auditoria read-only do Code Intelligence. Pode ser mais lento.'],
                    ],
                ],
                'annotations' => [
                    'readOnlyHint' => true,
                    'destructiveHint' => false,
                    'openWorldHint' => false,
                ],
            ],
            [
                'name' => 'atlas_memory_record',
                'title' => 'Atlas Memory Record',
                'description' => 'Persiste uma decisão, learning ou contexto técnico no registry Atlas. Provider-safe por default. Fecha o loop entre engine e Atlas memory.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'memory_type' => ['type' => 'string', 'description' => 'Tipo: decision, technical_context, harness_learning, preference, feedback, issue, resolution, benchmark_observation, anti_memory, strategic_insight.'],
                        'scope_type' => ['type' => 'string', 'description' => 'Scope: global, project, task, engineering_run, workspace, user, session.'],
                        'scope_id' => ['type' => 'string', 'description' => 'ID do scope (ex: project slug, task UUID). Omit para scope global.'],
                        'title' => ['type' => 'string', 'description' => 'Título curto da entry (até 200 chars).'],
                        'body' => ['type' => 'string', 'description' => 'Corpo completo da decisão/learning.'],
                        'summary' => ['type' => 'string', 'description' => 'Resumo opcional (até 500 chars).'],
                        'tags' => ['type' => 'array', 'description' => 'Tags livres para classificação.'],
                        'evidence' => ['type' => 'array', 'description' => 'Referências (file paths, URLs, commit SHAs).'],
                        'context' => ['type' => 'object', 'description' => 'Contexto Atlas: workspace, project_id, task_id, run_id.'],
                    ],
                    'required' => ['memory_type', 'scope_type', 'title', 'body'],
                ],
                'annotations' => [
                    'readOnlyHint' => false,
                    'destructiveHint' => false,
                    'openWorldHint' => false,
                ],
            ],
            [
                'name' => 'atlas_code_find_relevant',
                'title' => 'Atlas Code Find Relevant',
                'description' => 'Busca símbolos no índice de código (classes, métodos, funções, rotas, migrations, tests) por nome, layer, type ou language. Não faz semântica vetorial — usa metadados estruturados do índice. O parâmetro query faz match por substring em symbol_name, file_path e signature.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Termo de busca (matches por substring em symbol_name, file_path e signature).'],
                        'symbol_type' => ['type' => 'string', 'description' => 'Filtra por tipo: class, method, function, route, migration, test, command.'],
                        'language' => ['type' => 'string', 'description' => 'Filtra por linguagem: php, ts, tsx, js, jsx, md.'],
                        'workspace' => ['type' => 'string', 'description' => 'Workspace local.'],
                        'limit' => ['type' => 'integer', 'description' => 'Max símbolos retornados (default 20, max 100).'],
                    ],
                    'required' => ['query'],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_docs_lookup',
                'title' => 'Atlas Docs Lookup',
                'description' => 'Busca em itens da knowledge base (docs/engineering-knowledge-base/*.md indexados). Filtra por categoria, status, slug. Retorna metadata + path do arquivo.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Termo de busca em title, slug, summary e canonical_path.'],
                        'category' => ['type' => 'string', 'description' => 'Filtra por categoria: engineering, architecture, runbook, decision, etc.'],
                        'status' => ['type' => 'string', 'description' => 'Filtra por status: active, archived, draft.'],
                        'limit' => ['type' => 'integer', 'description' => 'Max docs retornados (default 10, max 50).'],
                    ],
                    'required' => ['query'],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_capabilities',
                'title' => 'Atlas Capabilities',
                'description' => 'Retorna inventário completo de tools MCP do Atlas, com schemas, annotations, protocol version e server info. Use para capability negotiation.',
                'inputSchema' => ['type' => 'object', 'properties' => [], 'required' => []],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_mcp_self_check',
                'title' => 'Atlas MCP Self Check',
                'description' => 'Diagnostica se o processo MCP nativo carregado pelo provider está alinhado com o runtime Atlas esperado: versão, fingerprint, feature flags e tools essenciais. Read-only; se faltar feature ou fingerprint divergir, reinicie o cliente/provider MCP ou use o CLI fallback fresh.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'expected_fingerprint' => ['type' => 'string', 'description' => 'Fingerprint esperado obtido de um CLI fresh ou projeção. Opcional.'],
                        'expected_feature_flags' => ['type' => 'array', 'description' => 'Feature flags esperadas pelo provider/projeção. Opcional.'],
                        'expected_tool_names' => ['type' => 'array', 'description' => 'Tools que devem estar registradas nesta sessão MCP. Opcional.'],
                    ],
                    'required' => [],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_domain_catalog',
                'title' => 'Atlas Domain Catalog',
                'description' => 'Retorna o catalogo canonico de dominios e flows do Atlas AI, incluindo maturity, onboarding, executor preference, autonomia e safety read-only.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'domain' => ['type' => 'string', 'description' => 'Filtra por domain id, por exemplo programming ou marketing.'],
                        'flow' => ['type' => 'string', 'description' => 'Filtra por flow id, por exemplo programming.repair.'],
                        'maturity' => ['type' => 'string', 'description' => 'Filtra orchestrators por maturity: implemented, scaffold ou planned.'],
                        'onboarding_status' => ['type' => 'string', 'description' => 'Filtra dominios por onboarding status: ready, executable_incomplete ou scaffold.'],
                    ],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_architecture_validate',
                'title' => 'Atlas Architecture Validate',
                'description' => 'Retorna health arquitetural canonico do Atlas AI a partir do mesmo contrato usado por CLI, API e Observability. Read-only e provider-safe.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'detail' => ['type' => 'string', 'description' => 'summary ou full. Default: summary.'],
                    ],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_architecture_operations',
                'title' => 'Atlas Architecture Operations',
                'description' => 'Retorna o catalogo compartilhado de comandos operacionais da arquitetura mae, o mesmo usado por CLI help e Observability.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string', 'description' => 'Filtra por stable operation id, por exemplo provider_performance_report.'],
                        'kind' => ['type' => 'string', 'description' => 'Filtra por kind: catalog, validation ou evidence_report.'],
                        'section' => ['type' => 'string', 'description' => 'Filtra pela secao canonica, por exemplo arquitetura_mae.'],
                        'surface' => ['type' => 'string', 'description' => 'Filtra por surface operacional: cli, runtime, api, mobile ou mcp.'],
                        'owner_layer' => ['type' => 'string', 'description' => 'Filtra pela camada dona da responsabilidade arquitetural, por exemplo runtime.'],
                    ],
                    'required' => [],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_architecture_readiness',
                'title' => 'Atlas Architecture Readiness',
                'description' => 'Retorna snapshot de prontidao da arquitetura mae antes de implementar: architecture validate, docs health, split plan, provider projection e comandos obrigatorios.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'workspace' => ['type' => 'string', 'description' => 'Workspace local para provider projection e paths. Default: base_path().'],
                        'owner' => ['type' => 'string', 'description' => 'Owner documental opcional, por exemplo kernel_architecture, programming_domain ou memory_open_brain.'],
                    ],
                    'required' => [],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_runtime_boundary',
                'title' => 'Atlas Runtime Boundary',
                'description' => 'Audita a fronteira Laravel/Python/Go/Swift antes de trabalho com RAG, ML, voz, runtime nativo ou microservicos. Read-only e provider-safe.',
                'inputSchema' => ['type' => 'object', 'properties' => [], 'required' => []],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_session_bootstrap',
                'title' => 'Atlas Session Bootstrap',
                'description' => 'Retorna pacote canonico de inicio de sessao: docs obrigatorios, placement, KB status, provider projection, validacoes e riscos. Read-only.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task' => ['type' => 'string', 'description' => 'Tarefa, bug, feature ou pergunta desta sessao.'],
                        'workspace' => ['type' => 'string', 'description' => 'Workspace usado para status de provider projection.'],
                        'strict' => ['type' => 'boolean', 'description' => 'Quando true, retorna ok=false se gate_status=blocked.'],
                    ],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_feature_placement',
                'title' => 'Atlas Feature Placement',
                'description' => 'Localiza feature em Core/Domain/Surface/Runtime/AP antes de implementar, apontando owner docs, duplicacoes e riscos.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'feature' => ['type' => 'string', 'description' => 'Feature, bug, pergunta ou capability a posicionar na arquitetura.'],
                        'hints' => ['type' => 'object', 'description' => 'Hints opcionais key=value, por exemplo domain=programming.'],
                        'strict' => ['type' => 'boolean', 'description' => 'Quando true, retorna ok=false se gate_status=blocked.'],
                    ],
                    'required' => ['feature'],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_docs_split_plan',
                'title' => 'Atlas Docs Split Plan',
                'description' => 'Transforma docs split_required em backlog operacional priorizado para manter alta performance documental.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'owner' => ['type' => 'string', 'description' => 'Filtra por owner_area, por exemplo kernel_architecture ou memory_open_brain.'],
                        'severity' => ['type' => 'string', 'description' => 'Filtra por severity, por exemplo critical, high, medium ou legacy_critical.'],
                        'status' => ['type' => 'string', 'description' => 'Filtra por status, por exemplo split_required ou split_required_grandfathered.'],
                    ],
                    'required' => [],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_self_improvement_schedule',
                'title' => 'Atlas Self-Improvement Schedule',
                'description' => 'Retorna o plano recorrente e health do Self-Improvement/Curator, incluindo cadencia, next_run_at por comando, plan_hash e scheduler_registration. Read-only.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'detail' => ['type' => 'string', 'description' => 'health, plan ou commands. Default: health.'],
                    ],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_self_improvement_schedule_report',
                'title' => 'Atlas Self-Improvement Schedule Report',
                'description' => 'Retorna replay/read model das observacoes SELF_IMPROVEMENT_SCHEDULE_OBSERVED no Evidence Ledger. Use para auditar historico da agenda do Curator.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'hours' => ['type' => 'integer', 'description' => 'Janela de replay em horas. Default 24, max 720.'],
                    ],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_kernel_slo_report',
                'title' => 'Atlas Kernel SLO Report',
                'description' => 'Retorna replay/read model das observacoes SLO_OBSERVED no Evidence Ledger, incluindo review_signal canonico. Use para auditar drift operacional sem depender de HTTP/CLI.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'hours' => ['type' => 'integer', 'description' => 'Janela de replay em horas. Default 24, max 720.'],
                        'domain' => ['type' => 'string', 'description' => 'Filtra por dimensao domain.'],
                        'flow' => ['type' => 'string', 'description' => 'Filtra por dimensao flow.'],
                        'surface_id' => ['type' => 'string', 'description' => 'Filtra por dimensao surface_id.'],
                        'provider' => ['type' => 'string', 'description' => 'Filtra por dimensao provider.'],
                        'model' => ['type' => 'string', 'description' => 'Filtra por dimensao model.'],
                        'runtime' => ['type' => 'string', 'description' => 'Filtra por dimensao runtime.'],
                        'tool_id' => ['type' => 'string', 'description' => 'Filtra por dimensao tool_id.'],
                    ],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_kernel_pipeline_report',
                'title' => 'Atlas Kernel Pipeline Report',
                'description' => 'Retorna replay/read model dos eventos KERNEL_PIPELINE_ACCEPTED/REJECTED no Evidence Ledger, incluindo health e review_signal canonicos.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'hours' => ['type' => 'integer', 'description' => 'Janela de replay em horas. Default 24, max 720.'],
                        'status' => ['type' => 'string', 'description' => 'Filtra por status accepted/rejected.'],
                        'surface_id' => ['type' => 'string', 'description' => 'Filtra por surface id.'],
                        'flow' => ['type' => 'string', 'description' => 'Filtra por flow.'],
                        'input_mode' => ['type' => 'string', 'description' => 'Filtra por input mode.'],
                        'surface_contract_source' => ['type' => 'string', 'description' => 'Filtra por fonte do surface contract.'],
                        'emitter_stage' => ['type' => 'string', 'description' => 'Filtra por emitter stage.'],
                    ],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_repair_loop_report',
                'title' => 'Atlas Repair Loop Report',
                'description' => 'Retorna replay/read model dos eventos REPAIR_INITIATED/COMPLETED no Evidence Ledger, incluindo review_signal canonico.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'hours' => ['type' => 'integer', 'description' => 'Janela de replay em horas. Default 24, max 720.'],
                        'status' => ['type' => 'string', 'description' => 'Filtra por status da decisao de repair.'],
                        'strategy' => ['type' => 'string', 'description' => 'Filtra por estrategia de repair.'],
                        'failure_domain' => ['type' => 'string', 'description' => 'Filtra por failure domain.'],
                        'emitter_stage' => ['type' => 'string', 'description' => 'Filtra por emitter stage.'],
                    ],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_inbox_action_report',
                'title' => 'Atlas Inbox Action Report',
                'description' => 'Retorna replay/read model dos eventos INBOX_ACTION_RECORDED no Evidence Ledger, incluindo acoes humanas como review_patch.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'hours' => ['type' => 'integer', 'description' => 'Janela de replay em horas. Default 24, max 720.'],
                        'action' => ['type' => 'string', 'description' => 'Filtra por action do Inbox, por exemplo review_patch.'],
                        'actor_type' => ['type' => 'string', 'description' => 'Filtra por actor type, como operator_cli ou mobile_device.'],
                        'inbox_item_category' => ['type' => 'string', 'description' => 'Filtra por categoria do item.'],
                        'inbox_item_severity' => ['type' => 'string', 'description' => 'Filtra por severidade do item.'],
                        'recommended_action' => ['type' => 'string', 'description' => 'Filtra por recommended_action preservada no review_signal.'],
                        'source_type' => ['type' => 'string', 'description' => 'Filtra por source_type do item.'],
                    ],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_agent_behavior_report',
                'title' => 'Atlas Agent Behavior Report',
                'description' => 'Retorna replay/read model dos eventos GATE_EVALUATED do agent behavior contract, incluindo findings recorrentes, providers afetados e review_signal.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'hours' => ['type' => 'integer', 'description' => 'Janela de replay em horas. Default 24, max 720.'],
                        'status' => ['type' => 'string', 'description' => 'Filtra por status da quality evaluation.'],
                        'provider' => ['type' => 'string', 'description' => 'Filtra por provider.'],
                        'model' => ['type' => 'string', 'description' => 'Filtra por model.'],
                        'agent_slug' => ['type' => 'string', 'description' => 'Filtra por agente/especialista.'],
                        'finding_code' => ['type' => 'string', 'description' => 'Filtra por finding code, ex: agent.verification_missing.'],
                        'contract_id' => ['type' => 'string', 'description' => 'Filtra por contract id.'],
                    ],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_provider_performance_report',
                'title' => 'Atlas Provider Performance Report',
                'description' => 'Retorna projection read-only dos eventos PROVIDER_RETURNED/PROVIDER_FALLBACK para auditar performance empirica por provider, dominio, flow, task_type e specialist_profile.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'hours' => ['type' => 'integer', 'description' => 'Janela de replay em horas. Default 24, max 720.'],
                        'provider' => ['type' => 'string', 'description' => 'Filtra por provider CLI.'],
                        'provider_cli' => ['type' => 'string', 'description' => 'Alias canonico para provider CLI.'],
                        'domain' => ['type' => 'string', 'description' => 'Filtra por domain.'],
                        'flow' => ['type' => 'string', 'description' => 'Filtra por flow.'],
                        'task_type' => ['type' => 'string', 'description' => 'Filtra por task_type.'],
                        'specialist_profile' => ['type' => 'string', 'description' => 'Filtra por specialist_profile.'],
                        'risk' => ['type' => 'string', 'description' => 'Filtra por risk.'],
                        'selection_mode' => ['type' => 'string', 'description' => 'Filtra por auto/manual_override.'],
                    ],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_dynamic_compute_market_report',
                'title' => 'Atlas Dynamic Compute Market Report',
                'description' => 'Explica recomendacao shadow de provider/modelo usando AP-99 sem executar tarefa, trocar provider ou bypassar DecisionReceipt.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'provider' => ['type' => 'string', 'description' => 'Provider selecionado para avaliar, por exemplo codex_cli.'],
                        'model' => ['type' => 'string', 'description' => 'Modelo selecionado, quando conhecido.'],
                        'domain' => ['type' => 'string', 'description' => 'Domain da rota avaliada.'],
                        'flow' => ['type' => 'string', 'description' => 'Flow da rota avaliada.'],
                        'task_type' => ['type' => 'string', 'description' => 'Tipo de tarefa.'],
                        'specialist_profile' => ['type' => 'string', 'description' => 'Specialist profile, por exemplo programming.frontend.'],
                    ],
                    'required' => ['provider'],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_provider_release_review',
                'title' => 'Atlas Provider Release Review',
                'description' => 'Classifica lancamentos de Claude, OpenAI, Gemini, Codex e labs em Provider Release Envelope com source gate, docs donos, APs, Rivals e sinal seguro para Decide. Nao altera policy.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'provider' => ['type' => 'string', 'description' => 'Provider ou lab, por exemplo anthropic, openai, google, codex.'],
                        'title' => ['type' => 'string', 'description' => 'Titulo do lancamento.'],
                        'url' => ['type' => 'string', 'description' => 'URL fonte para evidencia humana. O tool nao busca a URL.'],
                        'published_at' => ['type' => 'string', 'description' => 'Timestamp de publicacao quando conhecido.'],
                        'content_hash' => ['type' => 'string', 'description' => 'Hash de conteudo quando ja calculado por watcher externo.'],
                        'type' => ['type' => 'string', 'description' => 'Tipo opcional: vertical_agents, model, connector, tool_use, realtime, memory, coding, design, marketing, finance, capability_update.'],
                        'domain' => ['type' => 'array', 'description' => 'Dominios afetados sugeridos, ex: finance, programming, marketing.'],
                        'capability' => ['type' => 'array', 'description' => 'Capabilities mencionadas pelo lancamento.'],
                        'connector' => ['type' => 'array', 'description' => 'Connectors mencionados pelo lancamento.'],
                    ],
                    'required' => ['title'],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_provider_release_sources',
                'title' => 'Atlas Provider Release Sources',
                'description' => 'Lista watchlist oficial/tecnica/fraca de releases de providers ou gera candidate preview read-only. Nao faz fetch, nao escreve envelope e nao altera Decide.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'provider' => ['type' => 'string', 'description' => 'Filtra por provider, ex: anthropic, openai, google.'],
                        'tier' => ['type' => 'string', 'description' => 'Filtra por tier: official, technical, weak_signal.'],
                        'cadence' => ['type' => 'string', 'description' => 'Filtra por cadence, ex: daily, weekly, optional.'],
                        'track' => ['type' => 'string', 'description' => 'Filtra por track/capability family, ex: managed_agents, models.'],
                        'url' => ['type' => 'string', 'description' => 'URL detectada para candidate preview. O tool nao busca a URL.'],
                        'title' => ['type' => 'string', 'description' => 'Titulo do candidate quando url for enviada.'],
                        'published_at' => ['type' => 'string', 'description' => 'Timestamp de publicacao quando conhecido.'],
                        'content_hash' => ['type' => 'string', 'description' => 'Hash de conteudo quando watcher externo ja calculou.'],
                    ],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_ledger_projection_health',
                'title' => 'Atlas Ledger Projection Health',
                'description' => 'Retorna health read-only das projections derivadas do Evidence Ledger para ai_traces, atlas_engineering_runs e atlas_tool_runs, incluindo scheduler, lag e review_signal.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'max_lag_seconds' => ['type' => 'integer', 'description' => 'Limite de lag tolerado antes de warning. Default vem de atlas_ai.ledger_projection.max_lag_seconds.'],
                    ],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_decision_receipt_report',
                'title' => 'Atlas DecisionReceipt Report',
                'description' => 'Retorna replay read-only de DECISION_ISSUED para um envelope, verificando receipt_hash, chain_hash e review_signal.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'envelope' => ['type' => 'string', 'description' => 'Envelope id a auditar.'],
                        'envelope_id' => ['type' => 'string', 'description' => 'Alias de envelope id.'],
                    ],
                    'required' => ['envelope'],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_workspace_info',
                'title' => 'Atlas Workspace Info',
                'description' => 'Retorna metadata do workspace: Atlas o reconhece? quantas entries de memória? quando o code intelligence foi indexado? Usar para decidir profundidade de consulta antes de outros tools.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'workspace' => ['type' => 'string', 'description' => 'Caminho absoluto do workspace.'],
                    ],
                    'required' => ['workspace'],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_recent_changes',
                'title' => 'Atlas Recent Changes',
                'description' => 'Lista arquivos mudados no workspace recentemente (via git log) e cross-referencia com timestamp do code intelligence index. Retorna `index_fresh: false` se filesystem está à frente do índice.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'workspace' => ['type' => 'string', 'description' => 'Workspace local (deve ser repo git).'],
                        'since' => ['type' => 'string', 'description' => 'Período (git --since): "7 days ago", "2 weeks ago", "yesterday". Default: "7 days ago".'],
                        'limit' => ['type' => 'integer', 'description' => 'Max arquivos retornados (default 50, max 200).'],
                    ],
                    'required' => ['workspace'],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_decision_query',
                'title' => 'Atlas Decision Query',
                'description' => 'Recall filtrado para apenas decisões canônicas (memory_type=decision). Use quando precisar de "o que foi decidido sobre X" sem misturar com learnings ou preferences.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Pergunta ou tópico de decisão.'],
                        'scope' => ['type' => 'string', 'description' => 'Filtra por scope: global, project, etc.'],
                        'workspace' => ['type' => 'string', 'description' => 'Workspace local.'],
                        'limit' => ['type' => 'integer', 'description' => 'Max decisões retornadas (default 5).'],
                    ],
                    'required' => ['query'],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_task_start',
                'title' => 'Atlas Task Start',
                'description' => 'Cria uma task Atlas (status=open) e retorna task_id. Use quando o engine inicia trabalho — permite Atlas observar o ciclo de vida e amarrar memórias gravadas a uma task específica.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string', 'description' => 'Título curto da task.'],
                        'workspace' => ['type' => 'string', 'description' => 'Workspace local onde a task acontece.'],
                        'objective' => ['type' => 'string', 'description' => 'Objetivo declarado pelo engine (vai pra description).'],
                        'domain' => ['type' => 'string', 'description' => 'Domínio: dev, ops, research, etc. Default: dev.'],
                        'project_id' => ['type' => 'string', 'description' => 'UUID do projeto Atlas (opcional).'],
                        'metadata' => ['type' => 'object', 'description' => 'Metadata adicional (engine, session_id, etc).'],
                    ],
                    'required' => ['title'],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_task_progress',
                'title' => 'Atlas Task Progress',
                'description' => 'Registra um milestone/progresso numa task em andamento. Cria AtlasTaskEvent com event_type=milestone e payload customizado.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'string', 'description' => 'UUID da task.'],
                        'milestone' => ['type' => 'string', 'description' => 'Nome do milestone (tests-passing, design-approved, etc).'],
                        'details' => ['type' => 'string', 'description' => 'Detalhes opcionais.'],
                        'progress_pct' => ['type' => 'integer', 'description' => 'Progresso 0-100 (opcional).'],
                    ],
                    'required' => ['task_id', 'milestone'],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_task_complete',
                'title' => 'Atlas Task Complete',
                'description' => 'Fecha uma task: status=done, completed_at=now, registra AtlasTaskEvent(event_type=completed) com summary e files_changed. Use quando engine termina trabalho.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'string', 'description' => 'UUID da task.'],
                        'summary' => ['type' => 'string', 'description' => 'Resumo do que foi feito.'],
                        'files_changed' => ['type' => 'array', 'description' => 'Lista de paths de arquivos modificados.'],
                        'outcome' => ['type' => 'string', 'description' => 'Status semântico do outcome: success, partial, blocked.'],
                        'memory_entry_ids' => ['type' => 'array', 'description' => 'IDs de memory entries gravados durante a task (cross-link).'],
                    ],
                    'required' => ['task_id'],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_memory_archive',
                'title' => 'Atlas Memory Archive',
                'description' => 'Marca uma memory entry como archived (status=archived, archived_at=now). Não deleta — preserva histórico. Use quando uma decisão fica obsoleta.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'memory_entry_id' => ['type' => 'string', 'description' => 'UUID da entry a arquivar.'],
                        'reason' => ['type' => 'string', 'description' => 'Motivo do archive (vai pra metadata).'],
                    ],
                    'required' => ['memory_entry_id'],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_memory_link',
                'title' => 'Atlas Memory Link',
                'description' => 'Cria uma relação entre 2 memory entries (duplicate, conflict). Use para sinalizar duplicação ou conflito entre decisões. Tipos suportados hoje: duplicate, conflict (limitação atual — expandir requer migration).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'source_id' => ['type' => 'string', 'description' => 'UUID da entry origem da relação.'],
                        'target_id' => ['type' => 'string', 'description' => 'UUID da entry alvo.'],
                        'relation_type' => ['type' => 'string', 'description' => 'Tipo: duplicate ou conflict.'],
                        'reason' => ['type' => 'string', 'description' => 'Motivo da relação.'],
                        'confidence' => ['type' => 'number', 'description' => 'Confiança 0-1 (default 0.8).'],
                    ],
                    'required' => ['source_id', 'target_id', 'relation_type'],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_memory_supersede',
                'title' => 'Atlas Memory Supersede',
                'description' => 'Marca uma memory entry como substituída por outra mais nova. Define superseded_by_id, arquiva a entry velha (status=archived), preserva histórico. Use quando uma decisão é re-tomada — evita poluição do registry com entries duplicadas em vez de chained.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'old_entry_id' => ['type' => 'string', 'description' => 'UUID da entry antiga (será arquivada).'],
                        'new_entry_id' => ['type' => 'string', 'description' => 'UUID da entry nova (substitui a antiga).'],
                        'reason' => ['type' => 'string', 'description' => 'Motivo da substituição.'],
                    ],
                    'required' => ['old_entry_id', 'new_entry_id'],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_memory_get',
                'title' => 'Atlas Memory Get',
                'description' => 'Retorna corpo completo + metadata + relações de uma memory entry específica. Use após recall pra drill-down. Bloqueia entries não-provider-safe (privacy_class secret/sensitive sem external_ai_allowed=true).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'memory_entry_id' => ['type' => 'string', 'description' => 'UUID da entry.'],
                        'include_relations' => ['type' => 'boolean', 'description' => 'Incluir outgoing/incoming relations (default false).'],
                    ],
                    'required' => ['memory_entry_id'],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_module_info',
                'title' => 'Atlas Module Info',
                'description' => 'Retorna info detalhada de um módulo do code intelligence index: símbolos, paths, doc links. Use para drill-down após code_find_relevant.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'slug' => ['type' => 'string', 'description' => 'Slug ou ID do módulo.'],
                        'include_symbols' => ['type' => 'boolean', 'description' => 'Incluir lista de símbolos do módulo (default true).'],
                        'symbols_limit' => ['type' => 'integer', 'description' => 'Max símbolos retornados (default 50).'],
                    ],
                    'required' => ['slug'],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_route_info',
                'title' => 'Atlas Route Info',
                'description' => 'Busca rotas HTTP do projeto (filtrando símbolos type=route por path/name). Retorna lista de rotas que casam com o filtro.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => 'Path ou parte do path da rota a buscar (ex: "memory", "/api/atlas").'],
                        'workspace' => ['type' => 'string', 'description' => 'Workspace local.'],
                        'limit' => ['type' => 'integer', 'description' => 'Max rotas retornadas (default 20).'],
                    ],
                    'required' => ['path'],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_test_for',
                'title' => 'Atlas Test For',
                'description' => 'Busca testes (símbolos type=test_method) cujo nome contém o target. Heurística — matching por substring de nome, não análise de coverage real.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'target' => ['type' => 'string', 'description' => 'Nome do símbolo, classe, ou conceito a procurar nos testes.'],
                        'workspace' => ['type' => 'string', 'description' => 'Workspace local.'],
                        'limit' => ['type' => 'integer', 'description' => 'Max testes retornados (default 20).'],
                    ],
                    'required' => ['target'],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_context_for',
                'title' => 'Atlas Context For',
                'description' => 'Meta-tool que monta context pack ad-hoc a partir de descrição de tarefa. Internamente roda atlas_memory_recall + atlas_code_find_relevant + atlas_docs_lookup com a mesma query e retorna resultados unificados. Mais rápido que 3 chamadas separadas.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_description' => ['type' => 'string', 'description' => 'Descrição livre da tarefa/contexto (ex: "implementar cobrança recorrente").'],
                        'workspace' => ['type' => 'string', 'description' => 'Workspace local (opcional).'],
                        'memory_limit' => ['type' => 'integer', 'description' => 'Max memory entries (default 5).'],
                        'code_limit' => ['type' => 'integer', 'description' => 'Max code symbols (default 10).'],
                        'docs_limit' => ['type' => 'integer', 'description' => 'Max docs (default 5).'],
                    ],
                    'required' => ['task_description'],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_code_neighbors',
                'title' => 'Atlas Code Neighbors',
                'description' => 'Lista vizinhos diretos de um nó no world-model graph do código (edges depends_on/tests/documents/invokes/etc). Identifique o nó por node_id ("node:app/Services/...") ou por query textual. Read-only sobre o grafo construído; nunca executa provider.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'node_id' => ['type' => 'string', 'description' => 'Node id exato no grafo, ex: "node:app/Services/Ai/Router". Se omitido, use query.'],
                        'query' => ['type' => 'string', 'description' => 'Termo textual para localizar o nó de partida via ranker (usado quando node_id não é informado).'],
                        'direction' => ['type' => 'string', 'description' => 'Direção das edges: in, out ou both (default both).'],
                        'limit' => ['type' => 'integer', 'description' => 'Max vizinhos retornados (default = traversal_max_nodes, com teto na config).'],
                        'world_model_id' => ['type' => 'string', 'description' => 'World model específico (default = mais recente construído).'],
                        'workspace' => ['type' => 'string', 'description' => 'Workspace específico (ex: "blackink"): resolve o world-model SYMBOL daquele workspace. Ignorado se world_model_id for informado; ausente = comportamento global/latest.'],
                    ],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_code_path',
                'title' => 'Atlas Code Path',
                'description' => 'Encontra o caminho mais curto (BFS) entre dois nós no world-model graph do código, seguindo edges em ambas as direções. Read-only; respeita traversal_max_depth e traversal_max_nodes. Identifique cada ponta por node_id ou query textual.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'from' => ['type' => 'string', 'description' => 'Node id de origem ("node:...") ou query textual.'],
                        'to' => ['type' => 'string', 'description' => 'Node id de destino ("node:...") ou query textual.'],
                        'world_model_id' => ['type' => 'string', 'description' => 'World model específico (default = mais recente construído).'],
                        'workspace' => ['type' => 'string', 'description' => 'Workspace específico (ex: "blackink"): resolve o world-model SYMBOL daquele workspace. Ignorado se world_model_id for informado; ausente = comportamento global/latest.'],
                    ],
                    'required' => ['from', 'to'],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_code_explain',
                'title' => 'Atlas Code Explain',
                'description' => 'Explica um nó do world-model graph do código: tipo, path, flow, capabilities/risks e suas edges de entrada/saída (vizinhança imediata). Read-only; identifique o nó por node_id ou query textual.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'node_id' => ['type' => 'string', 'description' => 'Node id exato ("node:..."). Se omitido, use query.'],
                        'query' => ['type' => 'string', 'description' => 'Termo textual para localizar o nó via ranker (usado quando node_id não é informado).'],
                        'world_model_id' => ['type' => 'string', 'description' => 'World model específico (default = mais recente construído).'],
                        'workspace' => ['type' => 'string', 'description' => 'Workspace específico (ex: "blackink"): resolve o world-model SYMBOL daquele workspace. Ignorado se world_model_id for informado; ausente = comportamento global/latest.'],
                    ],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_ccr_retrieve',
                'title' => 'Atlas CCR Retrieve',
                'description' => 'Recupera o ORIGINAL completo de um bloco que a camada de compressão (AP-813) substituiu por uma forma comprimida + marcador. Informe o hash que aparece no marcador "[atlas:ccr ... hash=<hash>]". Read-only e lossless-by-governance (o original é durável no Evidence Ledger, nunca expira); conteúdo secret/sensitive nunca é exposto por este caminho provider-safe.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'hash' => ['type' => 'string', 'description' => 'Hash sha256 do original, como aparece no marcador atlas:ccr (hash=...).'],
                        'correlation_id' => ['type' => 'string', 'description' => 'Correlation id opcional para auditoria.'],
                        'trace_id' => ['type' => 'string', 'description' => 'Trace id opcional para auditoria.'],
                    ],
                    'required' => ['hash'],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_cross_domain_query',
                'title' => 'Atlas Cross-Domain Query',
                'description' => 'M-8 killer query (AP-814): a partir de um nó de domínio ("domain:finance" ou só "finance"), percorre o grafo cross-domain e retorna o que é alcançável ATRAVÉS de domínios sob o veto ARPTL — e o que foi BLOQUEADO. Read-only; cada travessia cross-domain é gated pelo mesh real (sensitive/secret/cyber nunca cruzam p/ audiências). Informe privacy_class (public/normal/sensitive/secret/cyber) p/ ver o que aquela classe pode atravessar.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'seed' => ['type' => 'string', 'description' => 'Nó de partida: "domain:<id>" ou só "<id>" (canônico, mesh ou registry — é resolvido).'],
                        'privacy_class' => ['type' => 'string', 'description' => 'Classe de privacidade do que se carrega: public|normal|sensitive|secret|cyber (default normal).'],
                    ],
                    'required' => ['seed'],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_aurg_query',
                'title' => 'Atlas AURG Brain Query',
                'description' => 'Salto-1 F2 (AURG vivo): consulta o cérebro — o grafo fundido dos 5 read-models reais (memória, código, domínios, evidência, estratégico) — e devolve a CADEIA cross-layer com proveniência completa: seeds híbridos (vetor semântico de memória + lexical por termo), travessia BFS bounded e paths nó→edge→nó (cada nó cita source_kind/source_id/content_hash; cada edge cita kind/source/confidence determinística). Read-only. PROVIDER-BOUND É FORÇADO neste surface: apenas nós provider_safe; domínios sensíveis e tudo alcançável SÓ através deles ficam estruturalmente fora (a visão local sem filtro é o CLI atlas:aurg:query). Ranking via runtime Python graph_rank (networkx) quando disponível; fallback honesto "unranked_*" — nunca scores fabricados.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Consulta natural multi-termo (ex: "memoria semantica embedding decisao").'],
                        'depth' => ['type' => 'integer', 'description' => 'Profundidade BFS a partir dos seeds (default 2, teto rígido 3).'],
                        'limit' => ['type' => 'integer', 'description' => 'Máximo de nós retornados (default 60, teto 200).'],
                        'expand' => ['type' => 'string', 'description' => 'MAXD-07 federated drill-down (comma list). Only "code" today: expande nós module do resultado com top-N símbolos lidos live de atlas_engineering_code_symbols; NÃO persiste no store (store node/edge count invariante).'],
                        'expand_per_module' => ['type' => 'integer', 'description' => 'MAXD-07 cap por módulo para expand=code (default 5, hard cap 20).'],
                    ],
                    'required' => ['query'],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_mission_history',
                'title' => 'Atlas Mission History',
                'description' => 'Salto-2 F3 (closed mission loop): lista as MISSÕES recentes que o Atlas entregou — lê os nós mission do AURG (o cérebro fundido), cada um com seu nó evidence (status passed/failed/delivered/blocked) e o ref do BRANCH (atlas/materialize/<id>; NUNCA um merge). Read-only. PROVIDER-BOUND É FORÇADO: só missões provider_safe/não-sensíveis (a saída pode cair num prompt). NÃO existe tool de deliver via MCP — entregar gasta + escreve e fica só no CLI (atlas:mission:deliver). Use depois de uma entrega p/ confirmar que o outcome foi gravado de volta no cérebro (compounding), ou p/ ver o que já foi feito.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'description' => 'Máximo de missões retornadas, mais recentes primeiro (default 20, teto 100).'],
                    ],
                    'required' => [],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_context_pack',
                'title' => 'Atlas Open Brain Context Pack (AOBG)',
                'description' => 'AOBG N1.F1: a PORTA DE ENTRADA única — o ÚNICO pack que qualquer IA externa (Claude Code / Codex / Cursor, em QUALQUER projeto) chama PRIMEIRO para "o que o cérebro já sabe sobre esta tarefa?". FUNDE os três cérebros já provados num único pack: code-graph (símbolos BM25+E-3 escopados ao workspace), reality graph AURG (paths cross-layer com proveniência) e memória semântica (recall pgvector, projeções REDIGIDAS provider-safe). Read-only, só DB local, ZERO gasto de provider. PROVIDER-BOUND É FORÇADO: domínios sensíveis/secret e tudo alcançável só por eles ficam estruturalmente fora; memória só redigida; evidência só ids/hashes. MULTI-PROJETO: workspace vem de `workspace` (path ou id) OU `cwd` do chamador — nunca vaza cross-workspace. HONESTO: cada seção degrada a vazio independente; o pack é "curated top-K (not exhaustive)", não onisciência. NÃO existe write-back via este tool (entrada externa é não-confiável; write passa pelo capture quality gate e nunca auto-promove).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task' => ['type' => 'string', 'description' => 'Tarefa/pergunta natural que guia o recall.'],
                        'workspace' => ['type' => 'string', 'description' => 'Path OU id do workspace a escopar (default: workspace configurado no processo MCP; atlas-server só quando nenhum workspace foi configurado).'],
                        'cwd' => ['type' => 'string', 'description' => 'Working directory do chamador; usado para resolver workspace quando workspace não foi informado.'],
                        'budget' => ['type' => 'integer', 'description' => 'Budget total de chars do pack (default config atlas.aobg.budget_chars).'],
                        'code_budget' => ['type' => 'integer', 'description' => 'Sub-budget de chars para code graph. Opcional.'],
                        'memory_budget' => ['type' => 'integer', 'description' => 'Sub-budget de chars para memória. Opcional.'],
                        'changed_files' => ['type' => 'array', 'description' => 'Arquivos alterados/tocados para enviesar code recall. Paths/refs provider-safe.'],
                        'session_id' => ['type' => 'string', 'description' => 'Id da sessão externa para working-set session-scoped.'],
                        'obra_id' => ['type' => 'string', 'description' => 'Id da obra ativa/composta para linhagem do working set.'],
                        'decision_id' => ['type' => 'string', 'description' => 'ASI-11 decision_id usado para resolver/stampar obra_id quando disponível.'],
                        'composed_arc' => ['type' => 'object', 'description' => 'Arco MULTN17-02 serializado; quando contém obra_id, alimenta a linhagem do working set sem copiar conteúdo.'],
                        'task_type' => ['type' => 'string', 'description' => 'Tipo da tarefa, usado com domain para flow-specific feedback policy. Ex: debug, dev, review.'],
                        'domain' => ['type' => 'string', 'description' => 'Domínio lógico, usado com task_type para flow_id. Ex: developer, programming, atlas.'],
                        'flow_id' => ['type' => 'string', 'description' => 'Flow explícito para feedback-aware context_delivery_policy. Ex: developer.debug.'],
                        'feedback_window_hours' => ['type' => 'integer', 'description' => 'Janela em horas para feedback-aware context_delivery_policy. Default 168, max 720.'],
                    ],
                    'required' => ['task'],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_record_outcome',
                'title' => 'Atlas Record Outcome (AOBG write-back)',
                'description' => 'AOBG N1.F2: uma sessão externa (Claude Code / Codex / Cursor) registra O QUE FEZ — arquivos tocados, ref de mission/task, resultado — de volta no cérebro como um nó provider-safe (mission + evidence) via o recorder gated existente. Entrada NÃO-CONFIÁVEL, tratada como hostil: só ids/hashes/labels redigidos cruzam, NUNCA um merge para main (grava um BRANCH), idempotente (re-registrar o mesmo outcome colapsa nos mesmos nós), fail-open (uma falha de store nunca quebra a sessão). Payload sensível/secret/oversized é REJEITADO honestamente. Custo: só DB local, zero gasto de provider. Toda escrita grava um receipt append-only.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string', 'description' => 'Ref externa da mission/task (a identidade do nó). Obrigatório.'],
                        'request' => ['type' => 'string', 'description' => 'O que a sessão foi pedida para fazer (label, redigido). Obrigatório.'],
                        'files' => ['type' => 'array', 'description' => 'Paths de arquivos que a sessão tocou (limitado).'],
                        'branch' => ['type' => 'string', 'description' => 'Ref de branch (nunca um merge).'],
                        'provider' => ['type' => 'string', 'description' => 'Label do provider/agente.'],
                        'receipt' => ['type' => 'string', 'description' => 'Id/hash de receipt opcional.'],
                        'delivered' => ['type' => 'boolean', 'description' => 'Marca o outcome como entregue.'],
                        'result' => ['type' => 'object', 'description' => 'Resultado do teste/medida: {status} ou {ok}.'],
                        'memory_refs' => ['type' => 'array', 'description' => 'Ids/source-ids de nós de memória existentes citados.'],
                        'privacy_class' => ['type' => 'string', 'description' => 'Classe declarada — DEVE ser normal ou omitida (qualquer outra é rejeitada).'],
                        'workspace' => ['type' => 'string', 'description' => 'Path OU id do workspace (default: primário atlas-server).'],
                    ],
                    'required' => ['id', 'request'],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_propose_learning',
                'title' => 'Atlas Propose Learning (AOBG write-back)',
                'description' => 'AOBG N1.F2: uma sessão externa PROPÕE um learning/decisão de volta ao cérebro. Entrada NÃO-CONFIÁVEL: passa pelo capture quality gate canônico + provider-safety e SEMPRE aterrissa como PROPOSAL status=pending_review aguardando revisão humana. NUNCA auto-promove, NUNCA muta memória canônica diretamente (apply/auto_apply são rejeitados pela pipeline). Ruído é rejeitado pelo gate; sensível/secret/oversized é rejeitado honestamente; conteúdo idêntico é deduplicado. Retorna proposal_id + status. Custo: só DB local. Toda escrita grava um receipt append-only.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'kind' => ['type' => 'string', 'description' => 'Um de: policy, routing, gate, benchmark, heuristic, retrieval_hint, memory, failure_pattern. Obrigatório.'],
                        'summary' => ['type' => 'string', 'description' => 'O learning proposto, uma frase. Obrigatório.'],
                        'evidence_refs' => ['type' => 'array', 'description' => 'Citações (file:line / id / hash). Pelo menos uma obrigatória.'],
                        'scope' => ['type' => 'string', 'description' => 'Scope (default global).'],
                        'current_state' => ['type' => 'object', 'description' => 'Estado atual estruturado (opcional).'],
                        'proposed_state' => ['type' => 'object', 'description' => 'Estado proposto estruturado (opcional).'],
                        'flow_id' => ['type' => 'string', 'description' => 'Id de flow de auditoria (opcional).'],
                        'provider' => ['type' => 'string', 'description' => 'Label do provider/agente.'],
                        'privacy_class' => ['type' => 'string', 'description' => 'Classe declarada — DEVE ser normal ou omitida (qualquer outra é rejeitada).'],
                        'workspace' => ['type' => 'string', 'description' => 'Path OU id do workspace (default: primário atlas-server).'],
                    ],
                    'required' => ['kind', 'summary', 'evidence_refs'],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_workspace_status',
                'title' => 'Atlas Workspace Status (AOBG multi-project)',
                'description' => 'AOBG N1.F3: o cérebro funciona em QUALQUER projeto, AUTO-ESCOPADO. Dado o `cwd` (a pasta do projeto da IA externa) OU um `workspace` (path ou id), resolve o workspace id via CodeGraphWorkspaceIdentity e responde HONESTAMENTE o que o cérebro sabe sobre ESTE projeto: {workspace_id, indexed:bool, symbols:int, last_index:?string, needs_onboarding:bool}. Escopa SÓ a este workspace (nunca mistura outro projeto). Se o repo ainda NÃO está indexado, retorna needs_onboarding:true e OFERECE o comando de index existente — mas NÃO roda um index pesado de um repo arbitrário implicitamente (auto-onboard é gated por config atlas.aobg.auto_onboard, default false). Read-only, só DB local, ZERO gasto de provider. Use ANTES dos outros tools para decidir se vale consultar o cérebro deste projeto ou fazer onboarding primeiro.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'cwd' => ['type' => 'string', 'description' => 'Working directory do chamador (a pasta do projeto da IA externa) — resolvido a um workspace id.'],
                        'workspace' => ['type' => 'string', 'description' => 'Path OU id do workspace a escopar (vence sobre cwd; default: primário atlas-server).'],
                    ],
                    'required' => [],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_workspace_map',
                'title' => 'Atlas Workspace Map (AOBG project inventory)',
                'description' => 'AOBG workspace map: leitura compacta e provider-safe do que o cérebro sabe sobre UM workspace. Resolve `cwd`/`workspace`, confirma index/provider projection, e retorna inventário de módulos, símbolos, linguagens, camadas, regiões de path e amostras de rotas, commands, migrations e testes. Read-only, bounded, só DB local, zero provider spend; não reindexa. Use no começo de uma sessão para entender a topologia do projeto antes do `atlas_context_pack` focado.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'cwd' => ['type' => 'string', 'description' => 'Working directory do chamador (a pasta do projeto da IA externa) — resolvido a um workspace id.'],
                        'workspace' => ['type' => 'string', 'description' => 'Path OU id do workspace a mapear (vence sobre cwd; default: primário atlas-server).'],
                        'limit' => ['type' => 'integer', 'description' => 'Máximo de amostras por seção (default 12, teto 50).'],
                        'detail' => ['type' => 'string', 'description' => 'summary (default, só inventário/readiness/handles) ou samples (inclui amostras de rotas, commands, migrations, testes e entrypoints).'],
                    ],
                    'required' => [],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_workspace_fleet_map',
                'title' => 'Atlas Workspace Fleet Map (AOBG configured workspaces)',
                'description' => 'AOBG workspace fleet map: leitura compacta e provider-safe de TODOS os workspaces configurados no Atlas Code registry. Retorna readiness agregado, contadores por workspace, blockers/warnings e handles de expansão; não retorna samples por padrão e nunca reindexa. Use quando o provider precisa escolher entre Atlas raiz, Atlas Server, Blackink, apps filhos ou verificar se a frota está pronta antes de implementar.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'description' => 'Limite usado nos handles de expansão por workspace (default 12, teto 50).'],
                        'detail' => ['type' => 'string', 'description' => 'summary (default). Fleet sempre defere samples para atlas_workspace_map de um workspace específico.'],
                    ],
                    'required' => [],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_workspace_activate',
                'title' => 'Atlas Workspace Activate (AOBG auto-bootstrap)',
                'description' => 'AOBG workspace activation: explicit local bootstrap for a folder the provider just opened. It registers/binds the workspace in AWIS, merges provider bootstrap files (.mcp.json, .claude/settings.json, AGENTS.md, CLAUDE.md), and runs the existing AWIS-gated CodeGraph index when the workspace is not indexed yet (or force=true). Local filesystem + DB only, zero provider spend. Use when atlas_workspace_status returns needs_onboarding=true or at session start for a new workspace.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'cwd' => ['type' => 'string', 'description' => 'Working directory do chamador (a pasta do projeto da IA externa) — resolvido a um workspace id.'],
                        'workspace' => ['type' => 'string', 'description' => 'Path OU id do workspace a ativar (vence sobre cwd; precisa resolver para uma pasta para bootstrap/index).'],
                        'force' => ['type' => 'boolean', 'description' => 'Reindexa mesmo se o workspace já tiver símbolos.'],
                    ],
                    'required' => [],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_claim_task',
                'title' => 'Atlas Claim Task (AOBG blackboard)',
                'description' => 'AOBG N2.F4 — o BLACKBOARD: múltiplos engines coordenam ATRAVÉS do cérebro. Reivindica um TARGET (um path de arquivo OU uma ref de task/mission) para o engine chamador (claude_code|codex|cursor|atlas) numa tabela compacta de claims que expira por TTL. Tanto Claude Code QUANTO Codex falam MCP, então ambos reivindicam + veem claims — assim um engine pode ver "codex já está editando fileX" e contornar em vez de pisar no mesmo target. IDEMPOTENTE (re-reivindicar o mesmo target colapsa no mesmo claim e renova o TTL); CONFLICT-AWARE (se OUTRO engine já tem um claim ativo no mesmo target, retorna status=conflict + o claim conflitante — NÃO rouba, NÃO bloqueia: coordenação, não gate); EXPIRA por TTL (um engine que crashou nunca segura para sempre); FAIL-OPEN (qualquer falha degrada para no-op seguro, nunca quebra a sessão). Workspace AUTO-ESCOPADO (path OU id, nunca vaza cross-workspace). Provider-safe (só labels/ids/timestamps), só DB local, ZERO gasto de provider. Use release via id quando terminar.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'engine' => ['type' => 'string', 'description' => 'O engine reivindicando (claude_code|codex|cursor|atlas|...). Obrigatório.'],
                        'target' => ['type' => 'string', 'description' => 'O target — um path de arquivo OU uma ref de task/mission (label, nunca conteúdo). Obrigatório.'],
                        'kind' => ['type' => 'string', 'description' => 'Um de: task, file, mission (default file).'],
                        'ttl' => ['type' => 'integer', 'description' => 'Tempo de vida do claim em segundos (default config; teto max_ttl_seconds).'],
                        'release' => ['type' => 'string', 'description' => 'Id de um claim a LIBERAR (ao invés de reivindicar). Quando presente, libera e ignora os demais campos.'],
                        'meta' => ['type' => 'object', 'description' => 'Nota/ref pequena (só labels/ids, nunca conteúdo).'],
                        'workspace' => ['type' => 'string', 'description' => 'Path OU id do workspace (default: primário atlas-server).'],
                        'cwd' => ['type' => 'string', 'description' => 'Working directory do chamador — resolvido a um workspace id (vence sobre default; workspace vence sobre cwd).'],
                    ],
                    'required' => [],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_blackboard_status',
                'title' => 'Atlas Blackboard Status (AOBG blackboard)',
                'description' => 'AOBG N2.F4 — lê o BLACKBOARD: os claims de trabalho ATIVOS no workspace ("codex está editando fileX", "claude_code segura a task T"). Escopado SÓ a este workspace (path OU id / cwd, nunca mistura outro projeto); claims expirados por TTL são marcados stale na leitura (um engine que crashou nunca aparece como ativo). Opcionalmente filtra por `target` para responder "quem mais está mexendo neste arquivo?" (a leitura de conflito cross-engine). Read-only, provider-safe (só labels/ids/timestamps), só DB local, ZERO gasto de provider. Use ANTES de editar um target compartilhado para coordenar com os outros engines.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'target' => ['type' => 'string', 'description' => 'Opcional — filtra os claims para este target (path/ref). Sem ele, lista todos os claims ativos do workspace.'],
                        'except_engine' => ['type' => 'string', 'description' => 'Opcional — exclui os claims deste engine (use o seu próprio engine para ver só os OUTROS).'],
                        'workspace' => ['type' => 'string', 'description' => 'Path OU id do workspace (default: primário atlas-server).'],
                        'cwd' => ['type' => 'string', 'description' => 'Working directory do chamador — resolvido a um workspace id.'],
                    ],
                    'required' => [],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_tool_search',
                'title' => 'Atlas Tool Search',
                'description' => 'Busca tools MCP por intenção e retorna o subconjunto compatível com contrato atlasContract por-tool. Read-only; use quando tools/list mostrar só a superfície primária.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Intenção, termo ou nome parcial da tool.'],
                        'limit' => ['type' => 'integer', 'description' => 'Máximo de tools retornadas (default 10, teto 25).'],
                    ],
                    'required' => ['query'],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_next_task',
                'title' => 'Atlas Next Task (PART 2 · the serving contract)',
                'description' => 'PART 2 — o Atlas OFERECE a próxima task. PULL de UM task packet auto-suficiente (id, lease, allowed_files/escopo, critério de aceite, required_evidence, régua) da fila canônica, via claim ATÔMICO conflict-free. `client_id` é OPACO (qualquer IA/harness passa o seu; o servidor nunca ramifica em plataforma). Gated no loop master switch (OFF => disabled). Fila seca => no_claimable_task honesto + escalation needs_brain_origination (NÃO é erro). Dois client_id distintos recebem packets DISJUNTOS. Pareie com atlas_task_report ao terminar.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'client_id' => ['type' => 'string', 'description' => 'Id OPACO do cliente (qualquer string; encaminhado verbatim e ecoado, nunca interpretado).'],
                        'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Opcional — filtra a fila por tags neutras (única dimensão de filtro honrada).'],
                    ],
                    'required' => ['client_id'],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_task_report',
                'title' => 'Atlas Task Report (PART 2 · the serving contract)',
                'description' => 'PART 2 — devolve o resultado de uma task servida e fecha/libera o lease. outcome=success roda o gate de completion dry-run (evidência validada); failed/give_back libera o lease pra task voltar a claimable. NÃO faz merge real (gated, obra à parte). Pareie com atlas_next_task.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'client_id' => ['type' => 'string', 'description' => 'Id OPACO do cliente (o mesmo que recebeu a task).'],
                        'task_packet_id' => ['type' => 'string', 'description' => 'O task_packet_id servido por atlas_next_task.'],
                        'lease_id' => ['type' => 'string', 'description' => 'O lease_id servido por atlas_next_task.'],
                        'outcome' => ['type' => 'string', 'description' => 'success | failed | give_back (default success).'],
                        'evidence' => ['type' => 'object', 'description' => 'Envelope de evidência de completion (só para outcome=success).'],
                    ],
                    'required' => ['client_id', 'task_packet_id', 'lease_id'],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'atlas_obra_status',
                'title' => 'Atlas Obra Status (AOBG N3.F4)',
                'description' => 'AOBG N3 (a INVERSÃO): lista as OBRAS recentes que o Atlas comissionou — o cérebro DIRIGE (o operador declara um intent e o Atlas decompõe num plano-DAG e executa governado numa ÚNICA branch pronta-pra-merge). Lê os nós obra do AURG (o cérebro fundido), cada um com seu nó evidence (status certified/needs_review) e o ref da BRANCH (atlas/obra/<id>; NUNCA um merge). Read-only. PROVIDER-BOUND É FORÇADO: só obras provider_safe/não-sensíveis (a saída pode cair num prompt). NÃO existe tool de deliver via MCP — comissionar uma obra GASTA + escreve e fica só no CLI (atlas:obra:deliver). Use depois de uma entrega p/ confirmar que a obra foi gravada de volta no cérebro (compounding), ou p/ ver o que já foi construído.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'description' => 'Máximo de obras retornadas, mais recentes primeiro (default 20, teto 100).'],
                    ],
                    'required' => [],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ],
        ];

        return array_map(fn (array $tool): array => $this->withSurfaceReviewAnnotation($tool), $tools);
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
                'atlas_memory_recall' => $this->toolResponse($id, $this->memoryRecall($arguments)),
                'atlas_open_brain_context_pack' => $this->toolResponse($id, $this->contextPack($arguments)),
                'atlas_context_expand' => $this->toolResponse($id, $this->contextExpand($arguments)),
                'atlas_context_feedback' => $this->toolResponse($id, $this->contextFeedback($arguments)),
                'atlas_memory_maintenance_status' => $this->toolResponse($id, $this->maintenanceStatus($arguments)),
                'atlas_memory_record' => $this->toolResponse($id, $this->memoryRecord($arguments)),
                'atlas_code_find_relevant' => $this->toolResponse($id, $this->codeFindRelevant($arguments)),
                'atlas_docs_lookup' => $this->toolResponse($id, $this->docsLookup($arguments)),
                'atlas_capabilities' => $this->toolResponse($id, $this->capabilities()),
                'atlas_mcp_self_check' => $this->toolResponse($id, $this->mcpSelfCheck($arguments)),
                'atlas_domain_catalog' => $this->toolResponse($id, $this->domainCatalog($arguments)),
                'atlas_architecture_validate' => $this->toolResponse($id, $this->architectureValidate($arguments)),
                'atlas_architecture_operations' => $this->toolResponse($id, $this->architectureOperations($arguments)),
                'atlas_architecture_readiness' => $this->toolResponse($id, $this->architectureReadiness($arguments)),
                'atlas_runtime_boundary' => $this->toolResponse($id, $this->runtimeBoundary()),
                'atlas_session_bootstrap' => $this->toolResponse($id, $this->sessionBootstrap($arguments)),
                'atlas_feature_placement' => $this->toolResponse($id, $this->featurePlacement($arguments)),
                'atlas_docs_split_plan' => $this->toolResponse($id, $this->docsSplitPlan($arguments)),
                'atlas_self_improvement_schedule' => $this->toolResponse($id, $this->selfImprovementSchedule($arguments)),
                'atlas_self_improvement_schedule_report' => $this->toolResponse($id, $this->selfImprovementScheduleReport($arguments)),
                'atlas_kernel_slo_report' => $this->toolResponse($id, $this->kernelSloReport($arguments)),
                'atlas_kernel_pipeline_report' => $this->toolResponse($id, $this->kernelPipelineReport($arguments)),
                'atlas_repair_loop_report' => $this->toolResponse($id, $this->repairLoopReport($arguments)),
                'atlas_inbox_action_report' => $this->toolResponse($id, $this->inboxActionReport($arguments)),
                'atlas_agent_behavior_report' => $this->toolResponse($id, $this->agentBehaviorReport($arguments)),
                'atlas_provider_performance_report' => $this->toolResponse($id, $this->providerPerformanceReport($arguments)),
                'atlas_dynamic_compute_market_report' => $this->toolResponse($id, $this->dynamicComputeMarketReport($arguments)),
                'atlas_provider_release_review' => $this->toolResponse($id, $this->providerReleaseReview($arguments)),
                'atlas_provider_release_sources' => $this->toolResponse($id, $this->providerReleaseSources($arguments)),
                'atlas_ledger_projection_health' => $this->toolResponse($id, $this->ledgerProjectionHealth($arguments)),
                'atlas_decision_receipt_report' => $this->toolResponse($id, $this->decisionReceiptReport($arguments)),
                'atlas_workspace_info' => $this->toolResponse($id, $this->workspaceInfo($arguments)),
                'atlas_recent_changes' => $this->toolResponse($id, $this->recentChanges($arguments)),
                'atlas_decision_query' => $this->toolResponse($id, $this->decisionQuery($arguments)),
                'atlas_task_start' => $this->toolResponse($id, $this->taskTools->taskStart($arguments)),
                'atlas_task_progress' => $this->toolResponse($id, $this->taskTools->taskProgress($arguments)),
                'atlas_task_complete' => $this->toolResponse($id, $this->taskTools->taskComplete($arguments)),
                'atlas_memory_archive' => $this->toolResponse($id, $this->memoryEntry->memoryArchive($arguments)),
                'atlas_memory_link' => $this->toolResponse($id, $this->memoryEntry->memoryLink($arguments)),
                'atlas_memory_supersede' => $this->toolResponse($id, $this->memoryEntry->memorySupersede($arguments)),
                'atlas_memory_get' => $this->toolResponse($id, $this->memoryEntry->memoryGet($arguments)),
                'atlas_module_info' => $this->toolResponse($id, $this->moduleInfo($arguments)),
                'atlas_route_info' => $this->toolResponse($id, $this->routeInfo($arguments)),
                'atlas_test_for' => $this->toolResponse($id, $this->testFor($arguments)),
                'atlas_context_for' => $this->toolResponse($id, $this->contextFor($arguments)),
                'atlas_code_neighbors' => $this->toolResponse($id, $this->codeGraph->codeNeighbors($arguments)),
                'atlas_code_path' => $this->toolResponse($id, $this->codeGraph->codePath($arguments)),
                'atlas_code_explain' => $this->toolResponse($id, $this->codeGraph->codeExplain($arguments)),
                'atlas_ccr_retrieve' => $this->toolResponse($id, $this->graphRag->ccrRetrieve($arguments)),
                'atlas_cross_domain_query' => $this->toolResponse($id, $this->graphRag->crossDomainQuery($arguments)),
                'atlas_aurg_query' => $this->toolResponse($id, $this->graphRag->aurgQuery($arguments)),
                'atlas_mission_history' => $this->toolResponse($id, $this->missionHistory($arguments)),
                'atlas_obra_status' => $this->toolResponse($id, $this->obraStatus($arguments)),
                'atlas_context_pack' => $this->toolResponse($id, $this->contextPackUnified($arguments)),
                'atlas_record_outcome' => $this->toolResponse($id, $this->recordOutcome($arguments)),
                'atlas_propose_learning' => $this->toolResponse($id, $this->proposeLearning($arguments)),
                'atlas_workspace_status' => $this->toolResponse($id, $this->workspaceStatus($arguments)),
                'atlas_workspace_map' => $this->toolResponse($id, $this->workspaceMap($arguments)),
                'atlas_workspace_fleet_map' => $this->toolResponse($id, $this->workspaceFleetMap($arguments)),
                'atlas_workspace_activate' => $this->toolResponse($id, $this->workspaceActivate($arguments)),
                'atlas_claim_task' => $this->toolResponse($id, $this->claimTask($arguments)),
                'atlas_blackboard_status' => $this->toolResponse($id, $this->blackboardStatus($arguments)),
                'atlas_tool_search' => $this->toolResponse($id, $this->toolSearch($arguments)),
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
    private function memoryRecall(array $arguments): array
    {
        $query = $this->string($arguments['query'] ?? '') ?? '';
        if ($query === '') {
            return [
                'ok' => false,
                'error' => 'query_required',
            ];
        }

        $context = $this->object($arguments['context'] ?? []);
        $workspace = $this->workspace($context['workspace'] ?? ($arguments['workspace'] ?? null));
        if ($workspace !== null) {
            $context['workspace'] = $workspace;
        }

        $recall = $this->recall->recall(
            $query,
            $context,
            $this->object($arguments['filters'] ?? []),
            $this->object($arguments['options'] ?? []),
        );

        return [
            'ok' => true,
            'tool' => 'atlas_memory_recall',
            'memory_recall' => $recall,
            // T4-S4 (Obra #17) — the recall's UNCERTAINTY MAP: how confident is this
            // retrieval? A weak/flat recall is flagged so the caller treats it as a weak
            // signal (and can ask for more), not as settled truth. Pure read over the
            // recall result; zero extra provider spend.
            'uncertainty' => (new AtlasRecallUncertaintyMap)->forRecall($recall),
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function contextPack(array $arguments): array
    {
        $objective = $this->string($arguments['objective'] ?? '') ?? '';
        if ($objective === '') {
            return [
                'ok' => false,
                'error' => 'objective_required',
            ];
        }

        $packArguments = $arguments;
        $packArguments['task'] = $objective;
        $pack = $this->contextPack->packFor($objective, $this->contextPackOptions($packArguments));
        $workspace = $this->workspace($arguments['workspace'] ?? data_get($arguments, 'payload.workspace'));
        $audit = $this->recordLegacyContextPackAudit($arguments, $objective, $workspace, $pack);
        $summary = [
            'context_refs_count' => (int) data_get($pack, 'context_feedback_request.delivered_ref_count', 0),
            'memory_refs_count' => count((array) ($pack['memory'] ?? [])),
            'provider_safe' => true,
            'runtime_version' => data_get($pack, 'provenance.aobg_runtime.runtime_version'),
            'deprecated_alias_of' => 'atlas_context_pack',
        ];

        return [
            'ok' => true,
            'tool' => 'atlas_open_brain_context_pack',
            'deprecated_alias_of' => 'atlas_context_pack',
            'provider_bound' => true,
            'pack' => $pack,
            'open_brain' => [
                'ok' => true,
                'schema_version' => 1,
                'context_pack_hash' => $pack['context_pack_hash'] ?? null,
                'context_pack' => $pack,
                'context_refs' => (array) data_get($pack, 'context_feedback_request.delivered_context_refs', []),
                'summary' => $summary,
                'safety' => [
                    'provider_safe' => true,
                    'audit_persisted' => $audit !== null,
                    'raw_text_exposed' => false,
                ],
                'audit' => $audit,
                'deprecated_alias_of' => 'atlas_context_pack',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @param  array<string,mixed>  $pack
     * @return array<string,mixed>|null
     */
    private function recordLegacyContextPackAudit(array $arguments, string $objective, ?string $workspace, array $pack): ?array
    {
        if (! Schema::hasTable('atlas_open_brain_access_logs')) {
            return null;
        }

        $log = AtlasOpenBrainAccessLog::query()->create([
            'surface' => 'mcp',
            'requester' => $this->string($arguments['requester'] ?? null) ?: 'mcp-client',
            'action' => 'context_pack_export',
            'status' => 'completed',
            'workspace_hash' => $workspace !== null ? hash('sha256', $workspace) : null,
            'workspace_label' => $workspace !== null ? basename($workspace) : null,
            'context_pack_hash' => $pack['context_pack_hash'] ?? null,
            'context_refs_count' => (int) data_get($pack, 'context_feedback_request.delivered_ref_count', 0),
            'memory_refs_count' => count((array) ($pack['memory'] ?? [])),
            'provider_safe' => true,
            'query_json' => [
                'objective_hash' => hash('sha256', $objective),
                'objective_excerpt_redacted' => true,
                'objective_length' => mb_strlen($objective),
                'workspace_hash' => $workspace !== null ? hash('sha256', $workspace) : null,
                'workspace_label' => $workspace !== null ? basename($workspace) : null,
            ],
            'result_summary_json' => [
                'schema_version' => 'atlas.aobg.legacy_context_pack_alias.v1',
                'deprecated_alias_of' => 'atlas_context_pack',
                'runtime_version' => data_get($pack, 'provenance.aobg_runtime.runtime_version'),
                'provider_safe' => true,
            ],
            'metadata' => [
                'schema_version' => 2,
                'source' => 'atlas_open_brain_mcp_service',
                'alias_of' => 'atlas_context_pack',
                'query_redaction' => 'hash_only_no_raw_objective_or_workspace_path',
            ],
            'accessed_at' => now(),
        ]);

        return [
            'id' => $log->id,
            'surface' => $log->surface,
            'requester' => $log->requester,
            'action' => $log->action,
            'status' => $log->status,
            'context_pack_hash' => $log->context_pack_hash,
            'context_refs_count' => $log->context_refs_count,
            'memory_refs_count' => $log->memory_refs_count,
            'provider_safe' => $log->provider_safe,
            'accessed_at' => $log->accessed_at?->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function contextExpand(array $arguments): array
    {
        $handle = $this->string($arguments['handle'] ?? '') ?? '';
        if ($handle === '') {
            return [
                'ok' => false,
                'error' => 'handle_required',
            ];
        }

        $objective = $this->string($arguments['objective'] ?? $arguments['task'] ?? $arguments['query'] ?? '') ?? '';
        if ($objective === '') {
            return [
                'ok' => false,
                'error' => 'objective_required',
            ];
        }

        $workspace = $this->workspace($arguments['workspace'] ?? null);

        return [
            'ok' => true,
            'tool' => 'atlas_context_expand',
            'context_expansion' => $this->contextExpansion->expand([
                'handle' => $handle,
                'objective' => $objective,
                'workspace' => $workspace,
                'task_type' => $this->string($arguments['task_type'] ?? null) ?: 'dev',
                'domain' => $this->string($arguments['domain'] ?? null) ?: 'atlas',
                'risk_level' => $this->string($arguments['risk_level'] ?? $arguments['risk'] ?? null) ?: 'low',
                'max_refs' => $this->positiveInt($arguments['max_refs'] ?? null) ?: 6,
                'budget' => $this->positiveInt($arguments['budget'] ?? null) ?: 3200,
            ]),
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function contextFeedback(array $arguments): array
    {
        $objective = $this->string($arguments['objective'] ?? $arguments['task'] ?? $arguments['query'] ?? '') ?? '';
        if ($objective === '') {
            return [
                'ok' => false,
                'error' => 'objective_required',
            ];
        }

        $contextPackHash = $this->string($arguments['context_pack_hash'] ?? null);
        $retrievalReceiptId = $this->string($arguments['retrieval_receipt_id'] ?? null) ?: $contextPackHash;
        $workspace = $this->workspace($arguments['workspace'] ?? null);
        $missedSources = $this->stringList($arguments['missed_required_sources'] ?? $arguments['missed_sources'] ?? []);
        $input = [
            'objective' => $objective,
            'workspace' => $workspace,
            'task_type' => $this->string($arguments['task_type'] ?? null) ?: 'dev',
            'domain' => $this->string($arguments['domain'] ?? null) ?: 'atlas',
            'risk_level' => $this->string($arguments['risk_level'] ?? $arguments['risk'] ?? null) ?: 'low',
            'outcome_status' => $this->string($arguments['outcome_status'] ?? $arguments['outcome'] ?? null) ?: 'unknown',
            'max_refs' => $this->positiveInt($arguments['max_refs'] ?? null) ?: 8,
            'delivered_context_refs' => $this->stringList($arguments['delivered_context_refs'] ?? $arguments['delivered_refs'] ?? []),
            'used_context_refs' => $this->stringList($arguments['used_context_refs'] ?? $arguments['used_refs'] ?? []),
            'noise_context_refs' => $this->stringList($arguments['noise_context_refs'] ?? $arguments['noise_refs'] ?? []),
            'missed_required_sources' => array_map(
                static fn (string $source): array => [
                    'source_type' => $source,
                    'reason' => 'mcp_reported_missing_source',
                ],
                $missedSources,
            ),
            'record' => (bool) ($arguments['record'] ?? false),
        ];

        if (($flowId = $this->string($arguments['flow_id'] ?? null)) !== null) {
            $input['flow_id'] = $flowId;
        }
        if ($retrievalReceiptId !== null) {
            $input['retrieval_receipt_id'] = $retrievalReceiptId;
        }
        if ($contextPackHash !== null) {
            $input['context_pack_hash'] = $contextPackHash;
        }
        if (is_numeric($arguments['post_execution_utility'] ?? $arguments['utility'] ?? null)) {
            $input['post_execution_utility'] = max(0, min(100, (int) ($arguments['post_execution_utility'] ?? $arguments['utility'])));
        }
        if (($runOutcomeId = $this->string($arguments['run_outcome_id'] ?? null)) !== null) {
            $input['run_outcome_id'] = $runOutcomeId;
        }
        if (($memoryCandidateId = $this->string($arguments['memory_candidate_id'] ?? null)) !== null) {
            $input['memory_candidate_id'] = $memoryCandidateId;
        }

        return [
            'ok' => true,
            'tool' => 'atlas_context_feedback',
            'context_feedback' => $this->retrievalFeedback->capture($input),
        ];
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
        $memory = $this->memorySummary($workspace);
        $memoryQuality = $this->quality->scorecard([
            'workspace' => $workspace,
        ]);
        $promptMetrics = $this->openBrainPromptMetrics();
        $contextFeedbackMetrics = $this->contextFeedbackMetrics();
        $runtimeSourceProbe = $this->runtimeSourceProbe();
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
            'overall_status' => $this->overallStatus($memory, $memoryQuality, $promptMetrics, $contextFeedbackMetrics, $runtimeSourceProbe, $knowledge, $code, $projection, $codeAudit),
            'next_actions' => $this->nextActions($workspace, $memory, $memoryQuality, $promptMetrics, $contextFeedbackMetrics, $runtimeSourceProbe, $knowledge, $code, $projection, $codeAudit),
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
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function domainCatalog(array $arguments): array
    {
        $maturity = $this->string($arguments['maturity'] ?? null);
        if ($maturity !== null && ! in_array($maturity, ['implemented', 'scaffold', 'planned'], true)) {
            return [
                'ok' => false,
                'tool' => 'atlas_domain_catalog',
                'error' => 'invalid_maturity',
                'allowed_maturity' => ['implemented', 'scaffold', 'planned'],
            ];
        }

        $onboardingStatus = $this->string($arguments['onboarding_status'] ?? null);
        if ($onboardingStatus !== null && ! in_array($onboardingStatus, ['ready', 'executable_incomplete', 'scaffold'], true)) {
            return [
                'ok' => false,
                'tool' => 'atlas_domain_catalog',
                'error' => 'invalid_onboarding_status',
                'allowed_onboarding_status' => ['ready', 'executable_incomplete', 'scaffold'],
            ];
        }

        $catalog = $this->domainCatalog->inspect([
            'domain' => $this->string($arguments['domain'] ?? null),
            'flow' => $this->string($arguments['flow'] ?? null),
            'maturity' => $maturity,
            'onboarding_status' => $onboardingStatus,
        ]);

        return [
            'ok' => ($catalog['status'] ?? null) === 'ok',
            'tool' => 'atlas_domain_catalog',
            ...$catalog,
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function architectureValidate(array $arguments): array
    {
        $detail = $this->string($arguments['detail'] ?? null) ?: 'summary';
        if (! in_array($detail, ['summary', 'full'], true)) {
            return [
                'ok' => false,
                'tool' => 'atlas_architecture_validate',
                'error' => 'invalid_detail',
                'allowed_detail' => ['summary', 'full'],
            ];
        }

        $payload = $this->architectureValidation->payload();
        $summary = [
            'status' => $payload['status'],
            'schema_version' => $payload['schema_version'],
            'validated_at' => $payload['validated_at'],
            'kernel' => [
                'valid' => data_get($payload, 'kernel.valid'),
                'static_scan' => [
                    'valid' => data_get($payload, 'kernel.static_scan.valid'),
                    'summary' => data_get($payload, 'kernel.static_scan.summary', []),
                ],
            ],
            'capabilities' => [
                'valid' => data_get($payload, 'capabilities.valid'),
                'count' => data_get($payload, 'capabilities.count'),
                'surface_count' => data_get($payload, 'capabilities.surface_count'),
            ],
            'domains' => [
                'valid' => data_get($payload, 'domains.valid'),
                'domain_count' => data_get($payload, 'domains.domain_count'),
                'flow_count' => data_get($payload, 'domains.flow_count'),
            ],
            'orchestrators' => [
                'valid' => data_get($payload, 'orchestrators.valid'),
                'count' => data_get($payload, 'orchestrators.count'),
            ],
            'onboarding' => $payload['onboarding'],
        ];

        return [
            'ok' => $payload['status'] === 'ok',
            'tool' => 'atlas_architecture_validate',
            'detail' => $detail,
            'architecture_validation' => $detail === 'full' ? $payload : $summary,
            'writes' => false,
        ];

    }

    /**
     * @return array<string,mixed>
     */
    private function architectureOperations(array $arguments): array
    {
        return [
            'ok' => true,
            'tool' => 'atlas_architecture_operations',
            'architecture_operations' => $this->architectureOperations->summary($this->onlyScalarFilters($arguments, ['id', 'kind', 'section', 'surface', 'owner_layer'])),
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function architectureReadiness(array $arguments): array
    {
        $payload = $this->architectureReadiness->snapshot($this->onlyScalarFilters($arguments, ['workspace', 'owner']));

        return [
            'ok' => ($payload['status'] ?? null) === 'ready',
            'tool' => 'atlas_architecture_readiness',
            'architecture_readiness' => $payload,
            'writes' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimeBoundary(): array
    {
        $payload = $this->runtimeBoundary->report();

        return [
            'ok' => ($payload['status'] ?? null) === 'ok',
            'tool' => 'atlas_runtime_boundary',
            'runtime_boundary' => $payload,
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function sessionBootstrap(array $arguments): array
    {
        $payload = $this->sessionBootstrap->bootstrap(
            $this->string($arguments['task'] ?? null) ?? '',
            ['workspace' => $this->string($arguments['workspace'] ?? null) ?? base_path()],
        );
        $strictBlocked = $this->governanceGate->strictBlocked($payload, ($arguments['strict'] ?? false) === true);

        return [
            'ok' => ($payload['status'] ?? null) === 'ok' && ! $strictBlocked,
            'tool' => 'atlas_session_bootstrap',
            'error' => $strictBlocked ? $this->governanceGate->mcpError('atlas_session_bootstrap') : null,
            ...$payload,
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function featurePlacement(array $arguments): array
    {
        $feature = $this->string($arguments['feature'] ?? null);
        if ($feature === null) {
            return [
                'ok' => false,
                'tool' => 'atlas_feature_placement',
                'error' => 'feature_required',
                'writes' => false,
            ];
        }

        $payload = $this->featurePlacement->place($feature, $this->onlyScalarFilters((array) ($arguments['hints'] ?? []), ['domain', 'surface', 'runtime', 'flow']));
        $strictBlocked = $this->governanceGate->strictBlocked($payload, ($arguments['strict'] ?? false) === true);

        return [
            'ok' => ($payload['status'] ?? null) === 'ok' && ! $strictBlocked,
            'tool' => 'atlas_feature_placement',
            'error' => $strictBlocked ? $this->governanceGate->mcpError('atlas_feature_placement') : null,
            ...$payload,
            'writes' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function docsSplitPlan(array $arguments): array
    {
        $payload = $this->documentationSplitPlan->plan($this->onlyScalarFilters($arguments, ['owner', 'severity', 'status']));

        return [
            'ok' => ($payload['status'] ?? null) === 'ok',
            'tool' => 'atlas_docs_split_plan',
            ...$payload,
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function selfImprovementSchedule(array $arguments): array
    {
        $detail = $this->string($arguments['detail'] ?? null) ?: 'health';
        if (! in_array($detail, ['health', 'plan', 'commands'], true)) {
            return [
                'ok' => false,
                'tool' => 'atlas_self_improvement_schedule',
                'error' => 'invalid_detail',
                'allowed_detail' => ['health', 'plan', 'commands'],
            ];
        }

        $plan = $this->selfImprovementSchedule->schedulePlan();
        $health = $this->selfImprovementSchedule->scheduleHealth();
        $scheduledCommands = $this->selfImprovementSchedule->scheduledCommands();

        return [
            'ok' => $health['health']['status'] !== 'warning',
            'tool' => 'atlas_self_improvement_schedule',
            'detail' => $detail,
            'schedule' => match ($detail) {
                'plan' => $plan,
                'commands' => [
                    'schema_version' => $plan['schema_version'],
                    'status' => $plan['status'],
                    'enabled' => $plan['enabled'],
                    'schedulable' => $plan['schedulable'],
                    'scheduler_registration' => $plan['scheduler_registration'],
                    'timezone' => $plan['timezone'],
                    'plan_hash' => $plan['plan_hash'],
                    'plan_hash_algorithm' => $plan['plan_hash_algorithm'],
                    'commands' => $scheduledCommands,
                    'count' => count($scheduledCommands),
                    'cadence_counts' => $plan['cadence_counts'],
                ],
                default => $health,
            },
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function selfImprovementScheduleReport(array $arguments): array
    {
        $hours = $this->reportWindowHours($arguments);
        $report = $this->ledgerReplay->selfImprovementScheduleReportForWindow(now()->subHours($hours));

        return [
            'ok' => (bool) ($report['available'] ?? false),
            'tool' => 'atlas_self_improvement_schedule_report',
            'hours' => $hours,
            'self_improvement_schedule_replay' => $report,
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function kernelSloReport(array $arguments): array
    {
        $hours = $this->reportWindowHours($arguments);
        $filters = $this->kernelSloFilters($arguments);
        $report = $this->ledgerReplay->sloReportForWindow(now()->subHours($hours), null, $filters);

        return [
            'ok' => (bool) ($report['available'] ?? false),
            'tool' => 'atlas_kernel_slo_report',
            'hours' => $hours,
            'filters' => $filters,
            'kernel_slo' => $report,
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function kernelPipelineReport(array $arguments): array
    {
        $hours = $this->reportWindowHours($arguments);
        $filters = $this->onlyScalarFilters($arguments, [
            'status',
            'surface_id',
            'flow',
            'input_mode',
            'surface_contract_source',
            'emitter_stage',
        ]);
        $report = $this->ledgerReplay->kernelPipelineReportForWindow(now()->subHours($hours), null, $filters);

        return [
            'ok' => (bool) ($report['available'] ?? false),
            'tool' => 'atlas_kernel_pipeline_report',
            'hours' => $hours,
            'filters' => $filters,
            'kernel_pipeline' => $report,
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function repairLoopReport(array $arguments): array
    {
        $hours = $this->reportWindowHours($arguments);
        $filters = $this->onlyScalarFilters($arguments, [
            'status',
            'strategy',
            'failure_domain',
            'emitter_stage',
        ]);
        $report = $this->ledgerReplay->repairReportForWindow(now()->subHours($hours), null, $filters);

        return [
            'ok' => (bool) ($report['available'] ?? false),
            'tool' => 'atlas_repair_loop_report',
            'hours' => $hours,
            'filters' => $filters,
            'kernel_repair' => $report,
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function inboxActionReport(array $arguments): array
    {
        $hours = $this->reportWindowHours($arguments);
        $filters = $this->onlyScalarFilters($arguments, [
            'action',
            'actor_type',
            'inbox_item_category',
            'inbox_item_severity',
            'recommended_action',
            'source_type',
        ]);
        $report = $this->ledgerReplay->inboxActionReportForWindow(now()->subHours($hours), null, $filters);

        return [
            'ok' => (bool) ($report['available'] ?? false),
            'tool' => 'atlas_inbox_action_report',
            'hours' => $hours,
            'filters' => $filters,
            'inbox_actions' => $report,
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function agentBehaviorReport(array $arguments): array
    {
        $hours = $this->reportWindowHours($arguments);
        $filters = $this->onlyScalarFilters($arguments, [
            'status',
            'provider',
            'model',
            'agent_slug',
            'finding_code',
            'contract_id',
        ]);
        $report = $this->ledgerReplay->agentBehaviorReportForWindow(now()->subHours($hours), null, $filters);

        return [
            'ok' => (bool) ($report['available'] ?? false),
            'tool' => 'atlas_agent_behavior_report',
            'hours' => $hours,
            'filters' => $filters,
            'agent_behavior' => $report,
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function ledgerProjectionHealth(array $arguments): array
    {
        $maxLagSeconds = $this->positiveInt($arguments['max_lag_seconds'] ?? null);
        $report = $this->ledgerProjectionRegistry->healthReport($maxLagSeconds);

        return [
            'ok' => (bool) ($report['available'] ?? false) && ($report['status'] ?? null) !== 'critical',
            'tool' => 'atlas_ledger_projection_health',
            'max_lag_seconds' => $report['max_lag_seconds'] ?? $maxLagSeconds,
            'ledger_projection_health' => $report,
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function providerPerformanceReport(array $arguments): array
    {
        $hours = $this->reportWindowHours($arguments);
        $filters = $this->onlyScalarFilters($arguments, [
            'provider_cli',
            'provider',
            'domain',
            'flow',
            'task_type',
            'specialist_profile',
            'risk',
            'selection_mode',
        ]);
        if (isset($filters['provider']) && ! isset($filters['provider_cli'])) {
            $filters['provider_cli'] = $filters['provider'];
        }
        unset($filters['provider']);

        $report = $this->providerPerformance->reportForWindow(now()->subHours($hours), null, $filters);

        return [
            'ok' => (bool) ($report['available'] ?? false),
            'tool' => 'atlas_provider_performance_report',
            'hours' => $hours,
            'filters' => $filters,
            'provider_performance' => $report,
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function dynamicComputeMarketReport(array $arguments): array
    {
        $payload = $this->dynamicComputeMarketReports->report($this->onlyScalarFilters($arguments, [
            'provider',
            'model',
            'domain',
            'flow',
            'task_type',
            'specialist_profile',
        ]));

        return [
            'ok' => ($payload['status'] ?? null) === 'ok',
            'tool' => 'atlas_dynamic_compute_market_report',
            ...$payload,
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function providerReleaseReview(array $arguments): array
    {
        $title = $this->string($arguments['title'] ?? null);
        if ($title === null) {
            return [
                'ok' => false,
                'tool' => 'atlas_provider_release_review',
                'error' => 'title_required',
                'writes' => false,
            ];
        }

        $payload = $this->providerReleaseIntelligence->review([
            'provider' => $this->string($arguments['provider'] ?? null),
            'title' => $title,
            'url' => $this->string($arguments['url'] ?? null),
            'published_at' => $this->string($arguments['published_at'] ?? null),
            'content_hash' => $this->string($arguments['content_hash'] ?? null),
            'type' => $this->string($arguments['type'] ?? null),
            'domains' => $this->stringList($arguments['domain'] ?? ($arguments['domains'] ?? [])),
            'capabilities' => $this->stringList($arguments['capability'] ?? ($arguments['capabilities'] ?? [])),
            'connectors' => $this->stringList($arguments['connector'] ?? ($arguments['connectors'] ?? [])),
        ]);

        return [
            'ok' => ($payload['status'] ?? null) === 'ok',
            'tool' => 'atlas_provider_release_review',
            ...$payload,
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function providerReleaseSources(array $arguments): array
    {
        $payload = $this->providerReleaseSources->summary([
            'provider' => $this->string($arguments['provider'] ?? null),
            'tier' => $this->string($arguments['tier'] ?? null),
            'cadence' => $this->string($arguments['cadence'] ?? null),
            'track' => $this->string($arguments['track'] ?? null),
        ]);

        $url = $this->string($arguments['url'] ?? null);
        if ($url !== null) {
            $payload = array_merge($payload, [
                'mode' => 'read_only_candidate_preview',
                'candidate' => $this->providerReleaseSources->candidateFromDetection(
                    url: $url,
                    title: $this->string($arguments['title'] ?? null) ?? 'untitled-provider-release-candidate',
                    contentHash: $this->string($arguments['content_hash'] ?? null),
                    publishedAt: $this->string($arguments['published_at'] ?? null),
                ),
            ]);
        }

        return [
            'ok' => ($payload['status'] ?? null) === 'ok',
            'tool' => 'atlas_provider_release_sources',
            ...$payload,
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function decisionReceiptReport(array $arguments): array
    {
        $envelopeId = $this->string($arguments['envelope'] ?? ($arguments['envelope_id'] ?? null));
        if ($envelopeId === null || $envelopeId === '') {
            return [
                'ok' => false,
                'tool' => 'atlas_decision_receipt_report',
                'error' => 'envelope_required',
                'writes' => false,
            ];
        }

        return [
            'ok' => true,
            'tool' => 'atlas_decision_receipt_report',
            'decision_receipt_replay' => $this->ledgerReplay->decisionReceiptReportForEnvelope($envelopeId),
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     */
    private function reportWindowHours(array $arguments): int
    {
        return $this->replayInput->hours($arguments['hours'] ?? null);
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,string>
     */
    private function kernelSloFilters(array $arguments): array
    {
        return $this->onlyScalarFilters($arguments, ['domain', 'flow', 'surface_id', 'provider', 'model', 'runtime', 'tool_id']);
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @param  array<int,string>  $allowed
     * @return array<string,string>
     */
    private function onlyScalarFilters(array $arguments, array $allowed): array
    {
        return $this->replayInput->scalarFilters($arguments, $allowed);
    }

    /**
     * @return array<string,mixed>
     */
    private function capabilities(): array
    {
        return [
            'ok' => true,
            'tool' => 'atlas_capabilities',
            'protocol_version' => self::PROTOCOL_VERSION,
            'server' => [
                'name' => 'atlas-open-brain',
                'version' => self::SERVER_VERSION,
            ],
            'runtime' => $this->runtimeProfile(),
            'surface_contract' => $this->surfaceContract(),
            'transport_contract' => $this->transportContract(),
            'progressive_disclosure' => $this->progressiveDisclosureCapabilities(),
            'tools' => $this->tools(),
            'transport' => 'stdio',
            'remote_capable' => false,
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function toolSearch(array $arguments): array
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        if ($query === '') {
            return ['ok' => false, 'tool' => 'atlas_tool_search', 'error' => 'query_required'];
        }

        $limit = min(25, max(1, (int) ($arguments['limit'] ?? 10)));
        $tokens = array_values(array_filter(
            preg_split('/[^a-z0-9_]+/i', mb_strtolower($query)) ?: [],
            static fn (string $token): bool => $token !== '',
        ));

        $ranked = [];
        foreach ($this->tools() as $tool) {
            $name = mb_strtolower((string) ($tool['name'] ?? ''));
            $title = mb_strtolower((string) ($tool['title'] ?? ''));
            $description = mb_strtolower((string) ($tool['description'] ?? ''));
            $score = str_contains($name, mb_strtolower($query)) ? 20 : 0;
            $score += str_contains($title, mb_strtolower($query)) ? 10 : 0;
            $score += str_contains($description, mb_strtolower($query)) ? 5 : 0;

            foreach ($tokens as $token) {
                if (str_contains($name, $token)) {
                    $score += 6;
                } elseif (str_contains($title, $token)) {
                    $score += 3;
                } elseif (str_contains($description, $token)) {
                    $score += 1;
                }
            }

            if ($score > 0) {
                $ranked[] = ['score' => $score, 'name' => (string) ($tool['name'] ?? ''), 'tool' => $tool];
            }
        }

        usort($ranked, static fn (array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strcmp($a['name'], $b['name']));
        $tools = array_map(static fn (array $row): array => $row['tool'], array_slice($ranked, 0, $limit));

        return [
            'ok' => true,
            'tool' => 'atlas_tool_search',
            'query' => $query,
            'count' => count($tools),
            'tools' => $tools,
            'compatibility' => [
                'all_legacy_tools_remain_callable_by_name' => true,
                'full_inventory_tool' => 'atlas_capabilities',
            ],
        ];
    }

    /**
     * Small stable interface advertised to new clients. The complete tool list
     * remains available as compatibility adapters and can only be removed after
     * an observed deprecation window.
     *
     * @return array<string,mixed>
     */
    private function surfaceContract(): array
    {
        $allNames = $this->toolNames();
        $primary = array_values(array_filter(
            self::PRIMARY_TOOLS,
            static fn (string $tool): bool => in_array($tool, $allNames, true),
        ));

        return [
            'schema_version' => 'atlas.open_brain.surface_contract.v1.1',
            'status' => 'stable',
            'primary_tool_count' => count($primary),
            'primary_tools' => $primary,
            'compatibility_tool_count' => max(0, count($allNames) - count($primary)),
            'compatibility_aliases' => self::COMPATIBILITY_ALIASES,
            'tool_contracts' => collect($this->tools())
                ->mapWithKeys(static fn (array $tool): array => [
                    (string) ($tool['name'] ?? '') => data_get($tool, 'annotations.atlasContract', []),
                ])
                ->all(),
            'deprecation_policy' => [
                'minimum_observation_days' => 90,
                'usage_evidence_required' => true,
                'breaking_removal_requires_major_version' => true,
                'current_action' => 'prefer_primary_keep_compatibility',
            ],
        ];
    }

    /**
     * Honest transport limits. PHP stdio dispatch is sequential, therefore an
     * in-flight tool cannot consume a later cancellation notification; clients
     * cancel by terminating/restarting the process. Claiming otherwise would be
     * a false capability.
     *
     * @return array<string,mixed>
     */
    private function transportContract(): array
    {
        return [
            'schema_version' => 'atlas.open_brain.transport_contract.v1',
            'schema_compatibility' => 'additive_minor_breaking_major',
            'quotas' => [
                'write_request_chars' => (int) config('atlas.aobg.write_back.max_request_chars', 2000),
                'write_files' => (int) config('atlas.aobg.write_back.max_files', 50),
                'write_memory_refs' => (int) config('atlas.aobg.write_back.max_memory_refs', 25),
                'context_budget_chars' => (int) config('atlas.aobg.budget_chars', 6000),
                'calls_per_window' => (int) config('atlas.aobg.mcp_quota.calls_per_window', 120),
                'window_seconds' => (int) config('atlas.aobg.mcp_quota.window_seconds', 60),
                'rate_limit_mode' => 'soft_fail_open',
            ],
            'timeouts' => [
                'file_context_soft_ms' => (int) config('atlas.aobg.file_context.soft_budget_ms', 1500),
                'client_hard_timeout_required' => true,
            ],
            'cancellation' => [
                'supported' => false,
                'reason' => 'sequential_stdio',
                'client_action' => 'terminate_and_restart_process',
            ],
            'diagnostics_tool' => 'atlas_mcp_self_check',
            'provider_safe' => true,
        ];
    }

    /**
     * Obra 7 / OB-03: Absorcao 4 phase-1 progressive disclosure manifest for MCP capabilities.
     *
     * @return array<string,mixed>
     */
    private function progressiveDisclosureCapabilities(): array
    {
        if (! (bool) config('atlas.aobg.progressive_disclosure_enabled', true)) {
            return [
                'enabled' => false,
                'phase' => 1,
            ];
        }

        try {
            $tier = app(AtlasMcpTierService::class);

            return [
                'enabled' => true,
                'phase' => 1,
                'schema_version' => 'atlas.mcp.tier.v1',
                'workflow' => 'search_brief → timeline → get_full',
                'manifest' => $tier->tierManifest(),
                'savings_estimate' => $tier->estimateSavings(5),
            ];
        } catch (Throwable) {
            return [
                'enabled' => true,
                'phase' => 1,
                'status' => 'unavailable',
            ];
        }
    }

    /**
     * Provider-safe runtime identity for stale-session detection.
     *
     * @return array<string,mixed>
     */
    public function runtimeProfile(): array
    {
        $toolNames = $this->toolNames();
        $features = self::RUNTIME_FEATURE_FLAGS;
        $sourceProbe = $this->runtimeSourceProbe();

        return [
            'schema_version' => self::RUNTIME_SCHEMA,
            'server_name' => 'atlas-open-brain',
            'server_version' => self::SERVER_VERSION,
            'protocol_version' => self::PROTOCOL_VERSION,
            'transport' => 'stdio',
            'process_started_at' => $this->processStartedAt,
            'feature_flags' => $features,
            'tool_count' => count($toolNames),
            'tool_names_hash' => $this->sha256($toolNames),
            'runtime_fingerprint' => $this->runtimeFingerprint($features, $toolNames),
            'source_probe' => $sourceProbe,
            'fresh_cli_probe' => [
                'command' => '/opt/homebrew/bin/php',
                'args' => [
                    'artisan',
                    'atlas:open-brain:mcp',
                    '--once={"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"atlas_mcp_self_check","arguments":{}}}',
                ],
            ],
            'restart_policy' => [
                'restart_required_when_feature_missing' => true,
                'restart_required_when_fingerprint_differs_from_fresh_cli' => true,
                'fallback_when_native_tool_unavailable' => 'run /opt/homebrew/bin/php artisan atlas:open-brain:mcp --once from atlas-server',
                'describe_command' => 'bin/atlas open-brain mcp --describe --json',
                'cli_context_fallback' => 'php artisan atlas:context-pack "<task>" --workspace="<path>" --json',
            ],
            'provider_safe' => true,
            'raw_prompt_exposed' => false,
            'raw_conversation_exposed' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function mcpSelfCheck(array $arguments): array
    {
        $runtime = $this->runtimeProfile();
        $sourceProbe = (array) ($runtime['source_probe'] ?? []);
        $toolNames = $this->toolNames();
        $featureFlags = (array) ($runtime['feature_flags'] ?? []);
        $expectedFingerprint = $this->string($arguments['expected_fingerprint'] ?? null);
        $expectedFeatures = $this->stringList($arguments['expected_feature_flags'] ?? []);
        $expectedTools = $this->stringList($arguments['expected_tool_names'] ?? []);

        $missingFeatures = array_values(array_diff($expectedFeatures, $featureFlags));
        $missingTools = array_values(array_diff($expectedTools, $toolNames));
        $fingerprintMatches = $expectedFingerprint === null
            || hash_equals((string) ($runtime['runtime_fingerprint'] ?? ''), $expectedFingerprint);
        $sourceMatches = ($sourceProbe['status'] ?? null) !== 'stale_source_mismatch';

        $status = ($missingFeatures === [] && $missingTools === [] && $fingerprintMatches && $sourceMatches)
            ? 'ready'
            : 'stale_or_incomplete';

        return [
            'ok' => true,
            'tool' => 'atlas_mcp_self_check',
            'status' => $status,
            'runtime' => $runtime,
            'checks' => [
                'expected_fingerprint_provided' => $expectedFingerprint !== null,
                'fingerprint_matches' => $fingerprintMatches,
                'expected_feature_count' => count($expectedFeatures),
                'missing_feature_flags' => $missingFeatures,
                'expected_tool_count' => count($expectedTools),
                'missing_tool_names' => $missingTools,
                'source_probe_status' => $sourceProbe['status'] ?? 'unknown',
                'source_matches_loaded_runtime' => $sourceMatches,
            ],
            'next_actions' => $status === 'ready'
                ? []
                : [
                    'Restart the provider MCP client/session so tools and payload schemas are re-registered.',
                    'Use the CLI fresh probe while the native MCP session is stale.',
                ],
            'writes' => false,
            'provider_safe' => true,
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * Compare the runtime constants loaded in this PHP process with the current
     * source file on disk. This catches long-lived MCP clients that keep serving
     * old schemas after the repo has already moved.
     *
     * @return array<string,mixed>
     */
    private function runtimeSourceProbe(): array
    {
        $components = [
            'mcp' => $this->runtimeSourceProbeFor(self::class, [
                'version_key' => 'server_version',
                'version_constant' => 'SERVER_VERSION',
                'loaded_version' => self::SERVER_VERSION,
                'loaded_feature_flags' => self::RUNTIME_FEATURE_FLAGS,
            ]),
            'context_pack' => $this->runtimeSourceProbeFor(AtlasOpenBrainContextPackService::class, [
                'version_key' => 'runtime_version',
                'version_constant' => 'RUNTIME_VERSION',
                'loaded_version' => AtlasOpenBrainContextPackService::RUNTIME_VERSION,
                'loaded_feature_flags' => AtlasOpenBrainContextPackService::RUNTIME_FEATURE_FLAGS,
            ]),
        ];

        $stale = collect($components)
            ->contains(fn (array $component): bool => ($component['status'] ?? null) === 'stale_source_mismatch');
        $missing = collect($components)
            ->flatMap(fn (array $component): array => (array) ($component['missing_loaded_feature_flags'] ?? []))
            ->values()
            ->all();

        return [
            'status' => $stale ? 'stale_source_mismatch' : 'current',
            'loaded_server_version' => self::SERVER_VERSION,
            'source_server_version' => data_get($components, 'mcp.source_server_version'),
            'missing_loaded_feature_flags' => $missing,
            'components' => $components,
            'action' => $stale ? 'restart_provider_mcp_client_or_use_cli_fallback' : null,
            'provider_safe' => true,
        ];
    }

    /**
     * @param  class-string  $class
     * @param  array{version_key:string,version_constant:string,loaded_version:string,loaded_feature_flags:array<int,string>}  $loaded
     * @return array<string,mixed>
     */
    private function runtimeSourceProbeFor(string $class, array $loaded): array
    {
        $versionKey = $loaded['version_key'];

        $file = (new \ReflectionClass($class))->getFileName();
        if (! is_string($file) || ! is_file($file) || ! is_readable($file)) {
            return [
                'status' => 'source_unavailable',
                'component' => $class,
                'loaded_'.$versionKey => $loaded['loaded_version'],
                'source_file_hash' => null,
                'provider_safe' => true,
            ];
        }

        $source = file_get_contents($file);
        if (! is_string($source) || $source === '') {
            return [
                'status' => 'source_unavailable',
                'component' => $class,
                'loaded_'.$versionKey => $loaded['loaded_version'],
                'source_file_hash' => null,
                'provider_safe' => true,
            ];
        }

        $sourceVersion = null;
        $constant = preg_quote($loaded['version_constant'], '/');
        if (preg_match("/public const {$constant} = '([^']+)'/", $source, $match)) {
            $sourceVersion = $match[1];
        }

        $missingLoadedFeatures = [];
        if (preg_match('/public const RUNTIME_FEATURE_FLAGS = \\[(.*?)\\];/s', $source, $match)) {
            preg_match_all("/'([^']+)'/", $match[1], $featureMatches);
            $sourceFeatures = array_values(array_unique($featureMatches[1] ?? []));
            $missingLoadedFeatures = array_values(array_diff($sourceFeatures, $loaded['loaded_feature_flags']));
        }

        $versionMatches = $sourceVersion === null || $sourceVersion === $loaded['loaded_version'];
        $status = ($versionMatches && $missingLoadedFeatures === [])
            ? 'current'
            : 'stale_source_mismatch';

        return [
            'status' => $status,
            'component' => $class,
            'loaded_'.$versionKey => $loaded['loaded_version'],
            'source_'.$versionKey => $sourceVersion,
            'missing_loaded_feature_flags' => $missingLoadedFeatures,
            'source_file_hash' => hash('sha256', $source),
            'action' => $status === 'current' ? null : 'restart_provider_mcp_client_or_use_cli_fallback',
            'provider_safe' => true,
        ];
    }

    /**
     * @return array{available:bool,window_started_at:?string,tools_by_name:array<string,array<string,mixed>>}
     */
    private function surfaceReviewTelemetry(): array
    {
        if (! DatabaseTableAvailability::has('ai_telemetry_events')) {
            return [
                'available' => false,
                'window_started_at' => null,
                'tools_by_name' => [],
            ];
        }

        $tools = [];
        $windowStartedAt = null;
        AiTelemetryEvent::query()
            ->where('event_name', self::MCP_TOOL_USAGE_EVENT_NAME)
            ->orderBy('received_at')
            ->get()
            ->each(function (AiTelemetryEvent $event) use (&$tools, &$windowStartedAt): void {
                $metadata = is_array($event->metadata) ? $event->metadata : [];
                $toolName = trim((string) ($metadata['tool_name'] ?? ''));
                if ($toolName === '') {
                    return;
                }

                $seenAt = $event->received_at?->toIso8601String()
                    ?? $event->created_at?->toIso8601String()
                    ?? Carbon::now()->toIso8601String();
                $windowStartedAt ??= $seenAt;

                if (! isset($tools[$toolName])) {
                    $tools[$toolName] = [
                        'tool_name' => $toolName,
                        'usage_count' => 0,
                        'first_seen_at' => $seenAt,
                        'last_seen_at' => $seenAt,
                    ];
                }

                $tools[$toolName]['usage_count']++;
                $tools[$toolName]['last_seen_at'] = $seenAt;
            });

        ksort($tools);

        return [
            'available' => true,
            'window_started_at' => $windowStartedAt,
            'tools_by_name' => $tools,
        ];
    }

    private function surfaceReviewObservationDays(mixed $windowStartedAt): ?int
    {
        if (! is_string($windowStartedAt) || trim($windowStartedAt) === '') {
            return null;
        }

        try {
            return max(0, (int) floor(Carbon::parse($windowStartedAt)->diffInDays(Carbon::now())));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<int,string>
     */
    private function toolNames(): array
    {
        return array_values(array_map(
            static fn (array $tool): string => (string) ($tool['name'] ?? ''),
            $this->tools(),
        ));
    }

    /**
     * @param  array<int,string>  $features
     * @param  array<int,string>  $toolNames
     */
    private function runtimeFingerprint(array $features, array $toolNames): string
    {
        sort($features);
        sort($toolNames);

        return $this->sha256([
            'schema_version' => self::RUNTIME_SCHEMA,
            'server_version' => self::SERVER_VERSION,
            'protocol_version' => self::PROTOCOL_VERSION,
            'feature_flags' => $features,
            'tool_names' => $toolNames,
        ]);
    }

    private function sha256(mixed $payload): string
    {
        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function workspaceInfo(array $arguments): array
    {
        $workspace = $this->workspace($arguments['workspace'] ?? null);

        // Derive project slug from basename of workspace path
        $slug = $workspace ? basename($workspace) : null;

        $memoryCount = 0;
        if ($slug && Schema::hasTable('atlas_memory_entries')) {
            $memoryCount = AtlasMemoryEntry::query()
                ->where('status', 'active')
                ->where(function ($q) use ($slug) {
                    $q->where(function ($inner) use ($slug) {
                        $inner->where('scope_type', 'project')->where('scope_id', $slug);
                    })->orWhere('scope_type', 'global');
                })
                ->count();
        }

        $codeSummary = $this->code->summary();
        $knowledgeSummary = $this->knowledge->summary();

        return [
            'ok' => true,
            'tool' => 'atlas_workspace_info',
            'workspace' => $workspace,
            'inferred_slug' => $slug,
            'atlas_tracked' => $memoryCount > 0,
            'memory_entry_count' => $memoryCount,
            'code_intelligence' => [
                'indexed' => ($codeSummary['module_count'] ?? 0) > 0,
                'last_indexed_at' => $codeSummary['last_indexed_at'] ?? null,
                'module_count' => $codeSummary['module_count'] ?? 0,
                'symbol_count' => $codeSummary['symbol_count'] ?? 0,
            ],
            'knowledge_base' => [
                'indexed' => ($knowledgeSummary['active'] ?? 0) > 0,
                'last_indexed_at' => $knowledgeSummary['last_indexed_at'] ?? null,
                'doc_count' => $knowledgeSummary['active'] ?? 0,
            ],
            'recommended_action' => $memoryCount > 0
                ? 'consult_atlas_first'
                : 'fallback_to_local_exploration',
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function recentChanges(array $arguments): array
    {
        $workspace = $this->workspace($arguments['workspace'] ?? null);
        if ($workspace === null || ! is_dir($workspace.'/.git')) {
            return ['ok' => false, 'tool' => 'atlas_recent_changes', 'error' => 'workspace_not_git_repo'];
        }

        $since = $this->string($arguments['since'] ?? null) ?: '7 days ago';
        $limit = $this->mcpInput->recentChangesLimit($arguments['limit'] ?? null);

        $process = new Process(
            ['git', 'log', '--name-only', '--pretty=format:', '--since='.$since],
            $workspace
        );
        $process->setTimeout(10);
        $process->run();

        if (! $process->isSuccessful()) {
            return ['ok' => false, 'tool' => 'atlas_recent_changes', 'error' => 'git_command_failed'];
        }

        $files = array_values(array_unique(array_filter(explode("\n", $process->getOutput()))));
        $files = array_slice($files, 0, $limit);

        $codeSummary = $this->code->summary();
        $lastIndexAt = $codeSummary['last_indexed_at'] ?? null;
        $indexFresh = false;
        if ($lastIndexAt !== null) {
            try {
                $indexFresh = Carbon::parse($lastIndexAt)->greaterThan(now()->subDay());
            } catch (Throwable $e) {
                $indexFresh = false;
            }
        }

        return [
            'ok' => true,
            'tool' => 'atlas_recent_changes',
            'workspace' => $workspace,
            'since' => $since,
            'changed_files' => $files,
            'count' => count($files),
            'index_fresh' => $indexFresh,
            'last_indexed_at' => $lastIndexAt,
            'recommended_action' => $indexFresh ? null : 'reindex_recommended',
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function decisionQuery(array $arguments): array
    {
        $query = $this->string($arguments['query'] ?? null);
        if ($query === null) {
            return ['ok' => false, 'tool' => 'atlas_decision_query', 'error' => 'query_required'];
        }

        $context = [];
        $workspace = $this->workspace($arguments['workspace'] ?? null);
        if ($workspace !== null) {
            $context['workspace'] = $workspace;
        }

        $filters = ['memory_type' => ['decision']];
        $scope = $this->string($arguments['scope'] ?? null);
        if ($scope !== null) {
            $filters['scope_type'] = $scope;
        }

        $options = ['limit' => $this->mcpInput->decisionLimit($arguments['limit'] ?? null)];

        $recall = $this->recall->recall($query, $context, $filters, $options);

        // Post-filter: ensure only decision-type items leak through
        // (registry items use 'type' key; verbatim/semantic items are not decision-typed)
        $decisions = array_values(array_filter(
            $recall['recall'] ?? [],
            fn (array $item): bool => ($item['type'] ?? null) === 'decision',
        ));

        return [
            'ok' => true,
            'tool' => 'atlas_decision_query',
            'query' => $query,
            'decisions' => $decisions,
            'count' => count($decisions),
            'summary' => $recall['summary'] ?? [],
            'generated_at' => now()->toJSON(),
        ];
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

        $opts = $this->contextPackOptions($arguments);

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
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function contextPackOptions(array $arguments): array
    {
        $opts = [];

        if ($this->string($arguments['workspace'] ?? null) !== null) {
            $opts['workspace'] = $this->workspace($arguments['workspace']);
        } elseif ($this->string($arguments['cwd'] ?? null) !== null) {
            $opts['cwd'] = $this->string($arguments['cwd']);
        } else {
            $opts['workspace'] = $this->workspace(null);
        }

        foreach (['budget', 'code_budget', 'memory_budget', 'feedback_window_hours'] as $key) {
            if (is_numeric($arguments[$key] ?? null)) {
                $opts[$key] = (int) $arguments[$key];
            }
        }

        foreach (['task_type', 'domain', 'flow_id', 'session_id', 'obra_id', 'decision_id'] as $key) {
            $value = $this->string($arguments[$key] ?? null);
            if ($value !== null) {
                $opts[$key] = $value;
            }
        }

        if (is_array($arguments['composed_arc'] ?? null)) {
            $opts['composed_arc'] = $arguments['composed_arc'];
        }

        $changedFiles = $this->stringList($arguments['changed_files'] ?? []);
        if ($changedFiles !== []) {
            $opts['changed_files'] = $changedFiles;
        }

        return $opts;
    }

    /**
     * AOBG N1.F2 — the record_outcome WRITE-BACK tool. An external session records WHAT
     * IT DID back into the brain (provider-safe mission/evidence node, never a merge,
     * idempotent, fail-open). Input is UNTRUSTED; the governed write-back service applies
     * the hostile-input floor (provider-safety + size caps) before delegating to the
     * existing recorder, and writes an audit receipt. This handler is a thin adapter.
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function recordOutcome(array $arguments): array
    {
        $tool = 'atlas_record_outcome';
        if ($this->string($arguments['id'] ?? null) === null || $this->string($arguments['request'] ?? null) === null) {
            return ['ok' => false, 'tool' => $tool, 'error' => 'id_and_request_required'];
        }

        return ['tool' => $tool] + $this->writeBack->recordOutcome($arguments);
    }

    /**
     * AOBG N1.F2 — the propose_learning WRITE-BACK tool. An external session proposes a
     * learning/decision back into the brain. Input is UNTRUSTED; the governed write-back
     * service runs the canonical capture quality gate + provider-safety and lands a
     * PROPOSAL status=pending_review — NEVER auto-promotes, NEVER mutates canonical
     * memory. Returns proposal_id + status. This handler is a thin adapter.
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function proposeLearning(array $arguments): array
    {
        $tool = 'atlas_propose_learning';
        if ($this->string($arguments['kind'] ?? null) === null || $this->string($arguments['summary'] ?? null) === null) {
            return ['ok' => false, 'tool' => $tool, 'error' => 'kind_and_summary_required'];
        }

        return ['tool' => $tool] + $this->writeBack->proposeLearning($arguments);
    }

    /**
     * AOBG N1.F3 — the atlas_workspace_status tool. Multi-project: given the caller's
     * `cwd` (the external tool's project dir) OR an explicit `workspace`, resolve the
     * workspace id and answer HONESTLY what the brain knows about THIS project
     * ({workspace_id, indexed, symbols, last_index, needs_onboarding}). Scoped to that
     * workspace ONLY (no cross-project leak). Read-only, local DB, zero provider spend.
     * Auto-onboarding stays GATED in the service — this read tool only ever reports +
     * offers; it never triggers a heavy index. Thin adapter.
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function workspaceStatus(array $arguments): array
    {
        $tool = 'atlas_workspace_status';

        $opts = [];
        $cwd = $this->string($arguments['cwd'] ?? null);
        if ($cwd !== null) {
            $opts['cwd'] = $cwd;
        }
        $workspace = $this->string($arguments['workspace'] ?? null);
        if ($workspace !== null) {
            $opts['workspace'] = $workspace;
        } elseif ($cwd === null) {
            $defaultWorkspace = $this->workspace(null);
            if ($defaultWorkspace !== null) {
                $opts['workspace'] = $defaultWorkspace;
            }
        }

        $status = $this->workspaceOnboarding->status($opts);

        return ['ok' => true, 'tool' => $tool] + $status;
    }

    /**
     * AOBG workspace map: bounded inventory of the already-indexed Code Intelligence
     * read-model for one workspace. Read-only; does not reindex.
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function workspaceMap(array $arguments): array
    {
        $tool = 'atlas_workspace_map';

        $opts = [];
        $cwd = $this->string($arguments['cwd'] ?? null);
        if ($cwd !== null) {
            $opts['cwd'] = $cwd;
        }
        $workspace = $this->string($arguments['workspace'] ?? null);
        if ($workspace !== null) {
            $opts['workspace'] = $workspace;
        } elseif ($cwd === null) {
            $defaultWorkspace = $this->workspace(null);
            if ($defaultWorkspace !== null) {
                $opts['workspace'] = $defaultWorkspace;
            }
        }
        $limit = $this->positiveInt($arguments['limit'] ?? null);
        if ($limit !== null) {
            $opts['limit'] = $limit;
        }
        $detail = $this->string($arguments['detail'] ?? null);
        if ($detail !== null) {
            $opts['detail'] = $detail;
        }

        return ['tool' => $tool] + $this->workspaceOnboarding->map($opts);
    }

    /**
     * AOBG workspace fleet map: compact readiness inventory for every configured
     * workspace. Read-only; does not reindex and defers samples to atlas_workspace_map.
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function workspaceFleetMap(array $arguments): array
    {
        $tool = 'atlas_workspace_fleet_map';

        $opts = [];
        $limit = $this->positiveInt($arguments['limit'] ?? null);
        if ($limit !== null) {
            $opts['limit'] = $limit;
        }
        $detail = $this->string($arguments['detail'] ?? null);
        if ($detail !== null) {
            $opts['detail'] = $detail;
        }

        return ['tool' => $tool] + $this->workspaceOnboarding->mapAll($opts);
    }

    /**
     * AOBG workspace activation: explicit local bootstrap + index for a newly opened
     * folder. This writes provider bootstrap files and may run the local CodeGraph
     * index, so it is not a read-only tool. It still spends zero provider tokens.
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function workspaceActivate(array $arguments): array
    {
        $tool = 'atlas_workspace_activate';

        $opts = [];
        $cwd = $this->string($arguments['cwd'] ?? null);
        if ($cwd !== null) {
            $opts['cwd'] = $cwd;
        }
        $workspace = $this->string($arguments['workspace'] ?? null);
        if ($workspace !== null) {
            $opts['workspace'] = $workspace;
        } elseif ($cwd === null) {
            $defaultWorkspace = $this->workspace(null);
            if ($defaultWorkspace !== null) {
                $opts['workspace'] = $defaultWorkspace;
            }
        }
        $force = $arguments['force'] ?? null;
        if (is_bool($force)) {
            $opts['force'] = $force;
        }

        return ['tool' => $tool] + $this->workspaceOnboarding->activate($opts);
    }

    /**
     * AOBG N2.F4 — the atlas_claim_task tool. The BLACKBOARD: an engine (Claude Code /
     * Codex / Cursor, all MCP) CLAIMS a target (file path or task ref) so a second
     * engine can see "codex is editing fileX" and step around it. Idempotent + conflict-
     * aware + TTL-expiring + fail-open. When `release` is present it RELEASES that claim
     * id instead. Input is untrusted; the service normalises + caps everything and never
     * throws. Provider-safe, local DB only, zero provider spend. Thin adapter.
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function claimTask(array $arguments): array
    {
        $tool = 'atlas_claim_task';

        // RELEASE path: an explicit claim id to free (the engine is done with it).
        $release = $this->string($arguments['release'] ?? null);
        if ($release !== null) {
            return ['tool' => $tool] + $this->blackboard->release($release);
        }

        $engine = $this->string($arguments['engine'] ?? null);
        $target = $this->string($arguments['target'] ?? null);
        if ($engine === null || $target === null) {
            return ['ok' => false, 'tool' => $tool, 'error' => 'engine_and_target_required'];
        }

        $kind = $this->string($arguments['kind'] ?? null) ?? 'file';

        $opts = [];
        if (is_numeric($arguments['ttl'] ?? null)) {
            $opts['ttl'] = (int) $arguments['ttl'];
        }
        $workspace = $this->string($arguments['workspace'] ?? null);
        if ($workspace !== null) {
            $opts['workspace'] = $workspace;
        }
        $cwd = $this->string($arguments['cwd'] ?? null);
        if ($cwd !== null) {
            $opts['cwd'] = $cwd;
        }
        if (is_array($arguments['meta'] ?? null)) {
            $opts['meta'] = $arguments['meta'];
        }

        return ['tool' => $tool] + $this->blackboard->claim($engine, $kind, $target, $opts);
    }

    /**
     * AOBG N2.F4 — the atlas_blackboard_status tool. Reads the BLACKBOARD: the ACTIVE
     * work claims for THIS workspace (stale ones expired by TTL on read). With `target`
     * it answers "who else is editing this?" (the cross-engine conflict read); with
     * `except_engine` it excludes the asker's own claim. Read-only, workspace-scoped,
     * provider-safe, local DB only, zero provider spend. Fail-open to an empty list.
     * Thin adapter.
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function blackboardStatus(array $arguments): array
    {
        $tool = 'atlas_blackboard_status';

        $opts = [];
        $workspace = $this->string($arguments['workspace'] ?? null);
        if ($workspace !== null) {
            $opts['workspace'] = $workspace;
        }
        $cwd = $this->string($arguments['cwd'] ?? null);
        if ($cwd !== null) {
            $opts['cwd'] = $cwd;
        }

        $target = $this->string($arguments['target'] ?? null);
        if ($target !== null) {
            $except = $this->string($arguments['except_engine'] ?? null);
            if ($except !== null) {
                $opts['except_engine'] = $except;
            }

            return ['ok' => true, 'tool' => $tool] + $this->blackboard->conflictsFor($target, $opts);
        }

        return ['ok' => true, 'tool' => $tool] + $this->blackboard->active($opts);
    }

    /**
     * Salto-2 F3 — the CLOSED MISSION LOOP read surface. Lists the most recent
     * missions Atlas delivered by reading the 'mission' source_kind nodes out of the
     * AURG fused store, each paired with its evidence node (status) and its branch
     * ref. Read-only.
     *
     * provider_bound is FORCED on this surface (MCP output can land in a provider
     * prompt): only provider_safe && !sensitive mission nodes are returned. The
     * recorded outcome is the BRANCH (atlas/materialize/<id>) — never a merge; only
     * ids/hashes/labels/branch/paths ride out (the recorder stored nothing else).
     *
     * No deliver tool is exposed via MCP — delivering spends + writes, so it stays on
     * the CLI (atlas:mission:deliver). This tool is the after-the-fact "what did the
     * loop do, and did it feed the brain back?" view.
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function missionHistory(array $arguments): array
    {
        $tool = 'atlas_mission_history';
        if (! (bool) config('atlas.aurg.enabled', true)) {
            return ['ok' => false, 'tool' => $tool, 'error' => 'aurg_disabled'];
        }
        if (! Schema::hasTable('atlas_aurg_nodes')) {
            return ['ok' => false, 'tool' => $tool, 'error' => 'store_missing'];
        }

        $limit = $this->positiveInt($arguments['limit'] ?? null) ?? 20;
        $limit = min($limit, 100);

        // Mission nodes — provider-bound (structural; never relaxable via MCP), most
        // recent first. Evidence nodes share the source_id, so we load them keyed by
        // mission id to pair the status without an N+1 per row.
        $missions = AtlasAurgNode::query()
            ->where('source_kind', 'mission')
            ->where('kind', 'mission')
            ->where('provider_safe', true)
            ->where('sensitive', false)
            ->orderByDesc('updated_at')
            ->orderByDesc('source_id')
            ->limit($limit)
            ->get();

        $missionIds = $missions->pluck('source_id')->all();

        $evidenceByMission = [];
        if ($missionIds !== []) {
            foreach (
                AtlasAurgNode::query()
                    ->where('source_kind', 'mission')
                    ->where('kind', 'evidence')
                    ->where('provider_safe', true)
                    ->where('sensitive', false)
                    ->whereIn('source_id', $missionIds)
                    ->get() as $evidence
            ) {
                $evidenceByMission[(string) $evidence->source_id] = $evidence;
            }
        }

        $rows = [];
        foreach ($missions as $mission) {
            $meta = (array) ($mission->meta ?? []);
            $missionSourceId = (string) $mission->source_id;
            $evidence = $evidenceByMission[$missionSourceId] ?? null;
            $evidenceMeta = $evidence !== null ? (array) ($evidence->meta ?? []) : [];

            $rows[] = [
                'mission_id' => $missionSourceId,
                'node_id' => (string) $mission->id,
                // Already-redacted label (the recorder redacts the request downstream).
                'request' => (string) $mission->label,
                'branch' => is_string($meta['branch'] ?? null) ? $meta['branch'] : null,
                'delivered' => (bool) ($meta['delivered'] ?? false),
                'provider' => is_string($meta['provider'] ?? null) ? $meta['provider'] : null,
                // The evidence node's status (passed/failed/delivered/blocked); honest
                // 'unrecorded' when no evidence node exists for this mission.
                'status' => is_string($evidenceMeta['status'] ?? null) ? $evidenceMeta['status'] : 'unrecorded',
                'never_merged' => (bool) ($meta['never_merged'] ?? true),
                'touched_paths' => array_values(array_filter((array) ($meta['touched_paths'] ?? []), 'is_string')),
                'recorded_at' => $mission->updated_at?->toJSON(),
            ];
        }

        return [
            'ok' => true,
            'tool' => $tool,
            'provider_bound' => true,
            'count' => count($rows),
            'missions' => $rows,
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * AOBG N3.F4 — the read surface for the OPERATOR SURFACE (atlas_obra_status MCP).
     *
     * THE INVERSION's after-the-fact view: lists the OBRAS the Atlas commissioned —
     * reads the AURG 'obra' nodes (the brain's first-class unit-of-work, recorded by
     * {@see AtlasRealityGraphIngestionService::recordObraOutcome()})
     * each paired with its 'evidence' node (the integrated-certification verdict) and
     * the BRANCH ref (atlas/obra/<id>) — never a merge.
     *
     * PROVIDER-BOUND is FORCED (structural, never relaxable via MCP; the output can land
     * in a provider prompt): only provider_safe && !sensitive obra nodes are returned.
     * Only ids/labels/branch/flags/counts/hashes ride out (the recorder stored nothing
     * else — never source, never diffs).
     *
     * No deliver tool is exposed via MCP — commissioning an obra SPENDS + writes, so it
     * stays on the CLI (atlas:obra:deliver). This tool is the "what obras did Atlas
     * build, and did the outcome feed the brain back?" view (compounding).
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function obraStatus(array $arguments): array
    {
        $tool = 'atlas_obra_status';
        if (! (bool) config('atlas.aurg.enabled', true)) {
            return ['ok' => false, 'tool' => $tool, 'error' => 'aurg_disabled'];
        }
        if (! Schema::hasTable('atlas_aurg_nodes')) {
            return ['ok' => false, 'tool' => $tool, 'error' => 'store_missing'];
        }

        $limit = $this->positiveInt($arguments['limit'] ?? null) ?? 20;
        $limit = min($limit, 100);

        // Obra nodes — provider-bound (structural; never relaxable via MCP), most recent
        // first. Evidence nodes share the source_id, so we load them keyed by obra id to
        // pair the certification verdict without an N+1 per row.
        $obras = AtlasAurgNode::query()
            ->where('source_kind', 'obra')
            ->where('kind', 'obra')
            ->where('provider_safe', true)
            ->where('sensitive', false)
            ->orderByDesc('updated_at')
            ->orderByDesc('source_id')
            ->limit($limit)
            ->get();

        $obraIds = $obras->pluck('source_id')->all();

        $evidenceByObra = [];
        if ($obraIds !== []) {
            foreach (
                AtlasAurgNode::query()
                    ->where('source_kind', 'obra')
                    ->where('kind', 'evidence')
                    ->where('provider_safe', true)
                    ->where('sensitive', false)
                    ->whereIn('source_id', $obraIds)
                    ->get() as $evidence
            ) {
                $evidenceByObra[(string) $evidence->source_id] = $evidence;
            }
        }

        $rows = [];
        foreach ($obras as $obra) {
            $meta = (array) ($obra->meta ?? []);
            $obraSourceId = (string) $obra->source_id;
            $evidence = $evidenceByObra[$obraSourceId] ?? null;
            $evidenceMeta = $evidence !== null ? (array) ($evidence->meta ?? []) : [];

            $rows[] = [
                'obra_id' => $obraSourceId,
                'node_id' => (string) $obra->id,
                // Already-redacted label (the recorder redacts the intent downstream).
                'intent' => (string) $obra->label,
                'branch' => is_string($meta['branch'] ?? null) ? $meta['branch'] : null,
                'certified' => (bool) ($meta['certified'] ?? false),
                // The obra's honest whole-status (certified/needs_review); the evidence
                // node's status is the integrated-check verdict (passed/failed/unrunnable/
                // absent). Honest 'unrecorded' when no evidence node exists.
                'status' => is_string($meta['status'] ?? null) ? $meta['status'] : 'unrecorded',
                'integrated_status' => is_string($evidenceMeta['status'] ?? null) ? $evidenceMeta['status'] : 'unrecorded',
                'delivered_steps' => (int) ($meta['delivered_steps'] ?? 0),
                'total_steps' => (int) ($meta['total_steps'] ?? 0),
                'never_merged' => (bool) ($meta['never_merged'] ?? true),
                'recorded_at' => $obra->updated_at?->toJSON(),
            ];
        }

        return [
            'ok' => true,
            'tool' => $tool,
            'provider_bound' => true,
            'count' => count($rows),
            'obras' => $rows,
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function moduleInfo(array $arguments): array
    {
        $slug = $this->string($arguments['slug'] ?? null);
        if ($slug === null) {
            return ['ok' => false, 'tool' => 'atlas_module_info', 'error' => 'slug_required'];
        }

        $data = $this->code->module($slug);
        if ($data === null) {
            return ['ok' => false, 'tool' => 'atlas_module_info', 'error' => 'module_not_found'];
        }

        // code->module() already returns ['module' => ..., 'symbols' => ..., 'doc_links' => ...]
        // Respect include_symbols and symbols_limit parameters
        $includeSymbols = (bool) ($arguments['include_symbols'] ?? true);
        $symbolsLimit = $this->mcpInput->symbolsLimit($arguments['symbols_limit'] ?? null);

        $payload = [
            'ok' => true,
            'tool' => 'atlas_module_info',
            'module' => $data['module'],
        ];

        if ($includeSymbols) {
            $payload['symbols'] = array_slice($data['symbols'] ?? [], 0, $symbolsLimit);
            $payload['doc_links'] = $data['doc_links'] ?? [];
        }

        $payload['generated_at'] = now()->toJSON();

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function routeInfo(array $arguments): array
    {
        $path = $this->string($arguments['path'] ?? null);
        if ($path === null) {
            return ['ok' => false, 'tool' => 'atlas_route_info', 'error' => 'path_required'];
        }

        $limit = $this->mcpInput->codeLimit($arguments['limit'] ?? null);
        // AP-815 W-3 — same default-safe scoping as codeFindRelevant(): resolve the workspace
        // to its stable id and thread it into symbols(); the filter only applies when the
        // W-1 workspace_id column exists, so a pre-W-1 read-model keeps global behaviour.
        $workspacePath = $this->workspace($arguments['workspace'] ?? null);
        $workspaceId = app(CodeGraphWorkspaceIdentity::class)->resolve($workspacePath);
        $result = $this->code->symbols(['q' => $path, 'symbol_type' => 'route', 'workspace_id' => $workspaceId], $limit);
        $routes = $result['symbols'] ?? [];

        return [
            'ok' => true,
            'tool' => 'atlas_route_info',
            'path' => $path,
            'workspace' => $workspacePath,
            'workspace_id' => $workspaceId,
            'routes' => $routes,
            'count' => count($routes),
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function testFor(array $arguments): array
    {
        $target = $this->string($arguments['target'] ?? null);
        if ($target === null) {
            return ['ok' => false, 'tool' => 'atlas_test_for', 'error' => 'target_required'];
        }

        $limit = $this->mcpInput->codeLimit($arguments['limit'] ?? null);
        // AP-815 W-3 — same default-safe scoping as codeFindRelevant() (see routeInfo()).
        $workspacePath = $this->workspace($arguments['workspace'] ?? null);
        $workspaceId = app(CodeGraphWorkspaceIdentity::class)->resolve($workspacePath);
        $result = $this->code->symbols(['q' => $target, 'symbol_type' => 'test_method', 'workspace_id' => $workspaceId], $limit);
        $tests = $result['symbols'] ?? [];

        return [
            'ok' => true,
            'tool' => 'atlas_test_for',
            'target' => $target,
            'workspace' => $workspacePath,
            'workspace_id' => $workspaceId,
            'tests' => $tests,
            'count' => count($tests),
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function contextFor(array $arguments): array
    {
        $task = $this->string($arguments['task_description'] ?? null);
        if ($task === null) {
            return ['ok' => false, 'tool' => 'atlas_context_for', 'error' => 'task_description_required'];
        }

        $workspace = $this->workspace($arguments['workspace'] ?? null);
        $context = $workspace !== null ? ['workspace' => $workspace] : [];
        // AP-815 W-3 — same default-safe scoping as codeFindRelevant() for the code leg
        // (memory recall is already workspace-scoped via $context above).
        $workspaceId = app(CodeGraphWorkspaceIdentity::class)->resolve($workspace);

        $memoryLimit = $this->mcpInput->contextMemoryLimit($arguments['memory_limit'] ?? null);
        $codeLimit = $this->mcpInput->contextCodeLimit($arguments['code_limit'] ?? null);
        $docsLimit = $this->mcpInput->contextDocsLimit($arguments['docs_limit'] ?? null);

        $memory = $this->recall->recall($task, $context, [], ['limit' => $memoryLimit]);
        $code = $this->code->symbols(['q' => $task, 'workspace_id' => $workspaceId], $codeLimit);
        $docs = $this->knowledge->catalog(['q' => $task, 'status' => 'active'], $docsLimit);

        $memoryEntries = $memory['recall'] ?? [];
        $codeSymbols = $code['symbols'] ?? [];
        $docsItems = $docs['items'] ?? [];

        return [
            'ok' => true,
            'tool' => 'atlas_context_for',
            'task_description' => $task,
            'workspace' => $workspace,
            'workspace_id' => $workspaceId,
            'memory' => [
                'entries' => $memoryEntries,
                'count' => count($memoryEntries),
            ],
            'code' => [
                'symbols' => $codeSymbols,
                'count' => count($codeSymbols),
            ],
            'docs' => [
                'items' => $docsItems,
                'count' => count($docsItems),
            ],
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function openBrainPromptMetrics(int $hours = 168): array
    {
        if (! Schema::hasTable('atlas_open_brain_access_logs')) {
            return [
                'schema_version' => 'atlas.open_brain.prompt_metric_aggregate.v1',
                'status' => 'not_migrated',
                'window_hours' => $hours,
                'observed_count' => 0,
                'review_signal' => [
                    'status' => 'unavailable',
                    'severity' => 'low',
                    'reasons' => ['open_brain_audit_table_missing'],
                    'recommended_action' => 'run_open_brain_audit_migrations_before_prompt_metric_review',
                    'recommended_command' => 'php artisan migrate --path=database/migrations/2026_05_03_130000_create_atlas_open_brain_access_logs_table.php',
                ],
            ];
        }

        $logs = AtlasOpenBrainAccessLog::query()
            ->where('accessed_at', '>=', now()->subHours($hours))
            ->where('action', 'context_pack_export')
            ->orderByDesc('accessed_at')
            ->limit(200)
            ->get();

        $promptRows = $logs
            ->map(function (AtlasOpenBrainAccessLog $log): ?array {
                $summary = (array) ($log->result_summary_json ?? []);
                $prompt = data_get($summary, 'prompt');
                if (! is_array($prompt)) {
                    return null;
                }

                return [
                    'id' => $log->id,
                    'mode' => (string) ($prompt['mode'] ?? 'unknown'),
                    'chars' => (int) ($prompt['chars'] ?? 0),
                    'lines' => (int) ($prompt['lines'] ?? 0),
                    'estimated_tokens' => (int) ($prompt['estimated_tokens'] ?? 0),
                    'full_chars' => (int) ($prompt['full_chars'] ?? 0),
                    'saved_chars' => (int) ($prompt['saved_chars'] ?? 0),
                    'estimated_tokens_saved' => (int) ($prompt['estimated_tokens_saved'] ?? 0),
                    'savings_ratio' => AiValueNormalizer::finiteFloatOrNull($prompt['savings_ratio'] ?? null) ?? 0.0,
                    'compact_to_full_ratio' => AiValueNormalizer::finiteFloatOrNull($prompt['compact_to_full_ratio'] ?? null) ?? 0.0,
                    'raw_prompt_persisted' => (bool) ($prompt['raw_prompt_persisted'] ?? false)
                        || (bool) data_get($summary, 'safety.prompt_raw_prompt_persisted', false)
                        || array_key_exists('prompt_section', $summary),
                    'accessed_at' => $log->accessed_at?->toJSON(),
                ];
            })
            ->filter()
            ->values();

        if ($promptRows->isEmpty()) {
            return [
                'schema_version' => 'atlas.open_brain.prompt_metric_aggregate.v1',
                'status' => 'no_data',
                'window_hours' => $hours,
                'observed_count' => 0,
                'total_context_pack_exports' => $logs->count(),
                'review_signal' => [
                    'status' => 'observe',
                    'severity' => 'low',
                    'reasons' => ['no_prompt_metric_exports_in_window'],
                    'recommended_action' => 'collect_include_prompt_exports_before_prompt_metric_review',
                    'recommended_command' => './bin/atlas open-brain context "AOBG prompt metric calibration" --include-prompt --prompt-mode=compact --json',
                ],
            ];
        }

        $compactRows = $promptRows->where('mode', 'compact')->values();
        $fullRows = $promptRows->where('mode', 'full')->values();
        $unknownRows = $promptRows
            ->reject(fn (array $row): bool => in_array($row['mode'], ['compact', 'full'], true))
            ->values();
        $rawPromptViolations = $promptRows
            ->filter(fn (array $row): bool => (bool) ($row['raw_prompt_persisted'] ?? false))
            ->values();
        $lowSavingsRows = $compactRows
            ->filter(fn (array $row): bool => (AiValueNormalizer::finiteFloatOrNull($row['savings_ratio'] ?? null) ?? 0.0) < 0.25)
            ->values();

        $observedCount = $promptRows->count();
        $fullModeRatio = $observedCount > 0 ? round($fullRows->count() / $observedCount, 4) : 0.0;
        $fullModeDominant = $observedCount >= 3 && $fullModeRatio > 0.5;
        $reasons = [];
        if ($rawPromptViolations->isNotEmpty()) {
            $reasons[] = 'raw_prompt_persistence_detected';
        }
        if ($lowSavingsRows->isNotEmpty()) {
            $reasons[] = 'compact_prompt_savings_below_threshold';
        }
        if ($fullModeDominant) {
            $reasons[] = 'full_prompt_mode_dominant';
        }
        if ($unknownRows->isNotEmpty()) {
            $reasons[] = 'unknown_prompt_mode_observed';
        }

        $status = 'ready';
        if ($rawPromptViolations->isNotEmpty()) {
            $status = 'critical';
        } elseif ($reasons !== []) {
            $status = 'warning';
        }

        return [
            'schema_version' => 'atlas.open_brain.prompt_metric_aggregate.v1',
            'status' => $status,
            'window_hours' => $hours,
            'observed_count' => $observedCount,
            'total_context_pack_exports' => $logs->count(),
            'compact_count' => $compactRows->count(),
            'full_count' => $fullRows->count(),
            'unknown_mode_count' => $unknownRows->count(),
            'full_mode_ratio' => $fullModeRatio,
            'raw_prompt_persistence_violation_count' => $rawPromptViolations->count(),
            'low_savings_count' => $lowSavingsRows->count(),
            'averages' => [
                'chars' => $this->averageMetric($promptRows, 'chars'),
                'estimated_tokens' => $this->averageMetric($promptRows, 'estimated_tokens'),
                'saved_chars' => $this->averageMetric($promptRows, 'saved_chars'),
                'estimated_tokens_saved' => $this->averageMetric($promptRows, 'estimated_tokens_saved'),
                'savings_ratio' => $this->averageMetric($promptRows, 'savings_ratio', 4),
            ],
            'compact' => [
                'count' => $compactRows->count(),
                'avg_chars' => $this->averageMetric($compactRows, 'chars'),
                'avg_full_chars' => $this->averageMetric($compactRows, 'full_chars'),
                'avg_saved_chars' => $this->averageMetric($compactRows, 'saved_chars'),
                'avg_estimated_tokens_saved' => $this->averageMetric($compactRows, 'estimated_tokens_saved'),
                'avg_savings_ratio' => $this->averageMetric($compactRows, 'savings_ratio', 4),
                'avg_compact_to_full_ratio' => $this->averageMetric($compactRows, 'compact_to_full_ratio', 4),
            ],
            'latest' => $promptRows->first(),
            'review_signal' => [
                'status' => $status === 'ready' ? 'ready' : ($status === 'critical' ? 'blocking' : 'review'),
                'severity' => $status === 'critical' ? 'high' : ($status === 'warning' ? 'medium' : 'low'),
                'reasons' => $reasons,
                'recommended_action' => $status === 'ready'
                    ? 'keep_compact_prompt_default_and_continue_measuring'
                    : 'review_open_brain_prompt_metric_regression_before_changing_prompt_delivery_policy',
            ],
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
        $toolNames = $this->toolNames();
        $contract = $this->surfaceContract();
        $policy = (array) ($contract['deprecation_policy'] ?? []);
        $minimumDays = max(1, (int) ($policy['minimum_observation_days'] ?? 90));
        $telemetry = $this->surfaceReviewTelemetry();
        $usageByTool = (array) ($telemetry['tools_by_name'] ?? []);
        $primarySet = array_fill_keys(self::PRIMARY_TOOLS, true);
        $tools = [];
        $toolsByName = [];

        foreach ($toolNames as $toolName) {
            $usage = (array) ($usageByTool[$toolName] ?? []);
            $usageCount = (int) ($usage['usage_count'] ?? 0);
            $windowStartedAt = $usage['first_seen_at'] ?? $telemetry['window_started_at'] ?? null;
            $observationDays = $this->surfaceReviewObservationDays($windowStartedAt);
            $isPrimary = isset($primarySet[$toolName]);
            $verdict = match (true) {
                $isPrimary => 'keep_primary',
                $usageCount > 0 => 'keep_used',
                $observationDays === null || $observationDays < $minimumDays => 'insufficient_window',
                default => 'deprecation_candidate',
            };

            $row = [
                'tool_name' => $toolName,
                'primary' => $isPrimary,
                'usage_count' => $usageCount,
                'window_started_at' => $windowStartedAt,
                'observation_days' => $observationDays,
                'verdict' => $verdict,
                'removal_planned' => false,
            ];
            $tools[] = $row;
            $toolsByName[$toolName] = $row;
        }

        return [
            'schema_version' => self::SURFACE_REVIEW_SCHEMA,
            'generated_at' => Carbon::now()->toIso8601String(),
            'surface_contract' => $contract,
            'primary_tools' => self::PRIMARY_TOOLS,
            'tool_count' => count($tools),
            'telemetry' => [
                'available' => (bool) ($telemetry['available'] ?? false),
                'event_name' => self::MCP_TOOL_USAGE_EVENT_NAME,
                'window_started_at' => $telemetry['window_started_at'] ?? null,
            ],
            'policy' => array_merge($policy, [
                'minimum_observation_days' => $minimumDays,
                'current_action' => 'zero_removals',
                'zero_removals' => true,
            ]),
            'tools' => $tools,
            'tools_by_name' => $toolsByName,
            'claims' => [
                'read_only' => true,
                'removed_tools' => 0,
                'coverage_total_tools' => count($toolNames),
                'coverage_reviewed_tools' => count($tools),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function contextFeedbackMetrics(int $hours = 168): array
    {
        if (! Schema::hasTable('ai_rag_feedback_events')) {
            return [
                'schema_version' => 'atlas.open_brain.context_feedback_metric_aggregate.v1',
                'status' => 'not_migrated',
                'window_hours' => $hours,
                'observed_count' => 0,
                'review_signal' => [
                    'status' => 'unavailable',
                    'severity' => 'low',
                    'reasons' => ['rag_feedback_table_missing'],
                    'recommended_action' => 'run_ai_rag_feedback_events_migration_before_context_feedback_review',
                    'recommended_command' => 'php artisan migrate --path=database/migrations/2026_05_17_180000_create_ai_compounding_engineering_intelligence_tables.php && php artisan migrate --path=database/migrations/2026_05_19_030000_strengthen_rag_feedback_and_create_learning_proposals.php',
                    'schema_repair_commands' => [
                        'php artisan migrate --path=database/migrations/2026_05_17_180000_create_ai_compounding_engineering_intelligence_tables.php',
                        'php artisan migrate --path=database/migrations/2026_05_19_030000_strengthen_rag_feedback_and_create_learning_proposals.php',
                    ],
                    'drift_hint' => 'If migrations are already recorded but ai_rag_feedback_events is missing, run the two migration up() methods idempotently or repair the migration ledger before collecting feedback.',
                ],
            ];
        }

        $events = AiRagFeedbackEvent::query()
            ->where('created_at', '>=', now()->subHours($hours))
            ->latest('created_at')
            ->limit(200)
            ->get();

        if ($events->isEmpty()) {
            return [
                'schema_version' => 'atlas.open_brain.context_feedback_metric_aggregate.v1',
                'status' => 'no_data',
                'window_hours' => $hours,
                'observed_count' => 0,
                'review_signal' => [
                    'status' => 'observe',
                    'severity' => 'low',
                    'reasons' => ['no_context_feedback_events_in_window'],
                    'recommended_action' => 'ask_external_providers_to_call_atlas_context_feedback_after_context_sensitive_runs',
                ],
            ];
        }

        $rows = $events
            ->map(function (AiRagFeedbackEvent $event): array {
                $roi = (array) (data_get($event->payload, 'context_roi') ?: data_get($event->payload, 'payload.context_roi', []));
                $attribution = (array) (data_get($event->payload, 'context_ref_attribution') ?: data_get($event->payload, 'payload.context_ref_attribution', []));
                $policy = (array) (data_get($event->payload, 'next_context_policy') ?: data_get($event->payload, 'payload.next_context_policy', []));
                $missedCount = count((array) $event->missed_required_sources);
                $hasRoiSignal = $roi !== [] || $attribution !== [];
                $actionableFeedback = $hasRoiSignal || $policy !== [] || $missedCount > 0 || (int) $event->noise_sources > 0;
                $measured = $this->feedbackEventMeasured($event);

                return [
                    'feedback_hash' => $event->feedback_hash,
                    'flow_id' => $event->flow_id,
                    'outcome_status' => (string) ($event->outcome_status ?: 'unknown'),
                    'included_sources' => (int) $event->included_sources,
                    'used_sources' => (int) $event->used_sources,
                    'noise_sources' => (int) $event->noise_sources,
                    'missed_required_source_count' => $missedCount,
                    'context_sufficiency' => (int) $event->context_sufficiency,
                    'post_execution_utility' => (int) $event->post_execution_utility,
                    'measured' => $measured,
                    'has_roi_signal' => $hasRoiSignal,
                    'actionable_feedback' => $actionableFeedback,
                    'roi_score' => array_key_exists('roi_score', $roi) ? AiValueNormalizer::finiteFloatOrNull($roi['roi_score']) : null,
                    'quality_band' => (string) ($roi['quality_band'] ?? 'unknown'),
                    'use_ratio' => array_key_exists('use_ratio', $attribution) ? AiValueNormalizer::finiteFloatOrNull($attribution['use_ratio']) : null,
                    'waste_ratio' => array_key_exists('waste_ratio', $attribution) ? AiValueNormalizer::finiteFloatOrNull($attribution['waste_ratio']) : null,
                    'policy_actions' => array_values(array_filter((array) ($policy['actions'] ?? []), 'is_string')),
                    'created_at' => $event->created_at?->toJSON(),
                ];
            })
            ->values();

        $totalEventCount = $rows->count();
        $rows = $rows->filter(fn (array $row): bool => (bool) ($row['measured'] ?? false))->values();
        if ($rows->isEmpty()) {
            return [
                'schema_version' => 'atlas.open_brain.context_feedback_metric_aggregate.v1',
                'status' => 'no_measured_data',
                'window_hours' => $hours,
                'observed_count' => 0,
                'measured_count' => 0,
                'total_event_count' => $totalEventCount,
                'quality_band_counts' => [
                    'strong' => 0,
                    'mixed' => 0,
                    'weak' => 0,
                    'unknown' => 0,
                ],
                'outcome_counts' => [],
                'low_roi_count' => 0,
                'waste_count' => 0,
                'noise_count' => 0,
                'missed_required_source_feedback_count' => 0,
                'non_passing_count' => 0,
                'roi_signal_count' => 0,
                'actionable_feedback_count' => 0,
                'non_actionable_feedback_count' => 0,
                'missing_roi_signal_count' => $totalEventCount,
                'weak_ratio' => 0.0,
                'non_passing_ratio' => 0.0,
                'averages' => [
                    'roi_score' => 0.0,
                    'use_ratio' => 0.0,
                    'waste_ratio' => 0.0,
                    'context_sufficiency' => 0.0,
                    'post_execution_utility' => 0.0,
                    'included_sources' => 0.0,
                    'used_sources' => 0.0,
                    'noise_sources' => 0.0,
                ],
                'latest' => null,
                'review_signal' => [
                    'status' => 'observe',
                    'severity' => 'low',
                    'reasons' => ['context_feedback_events_unmeasured'],
                    'recommended_action' => 'collect_explicit_used_refs_and_post_execution_utility_before_aggregating_context_feedback',
                    'auto_apply_threshold' => 0,
                    'auto_apply_ready' => false,
                    'remaining_feedback_events_before_auto_apply' => 0,
                ],
            ];
        }

        $observedCount = $rows->count();
        $weakRows = $rows->where('quality_band', 'weak')->values();
        $mixedRows = $rows->where('quality_band', 'mixed')->values();
        $strongRows = $rows->where('quality_band', 'strong')->values();
        $roiSignalRows = $rows->filter(fn (array $row): bool => (bool) $row['has_roi_signal'])->values();
        $actionableRows = $rows->filter(fn (array $row): bool => (bool) $row['actionable_feedback'])->values();
        $lowRoiRows = $roiSignalRows->filter(fn (array $row): bool => $row['roi_score'] !== null && (AiValueNormalizer::finiteFloatOrNull($row['roi_score']) ?? 0.0) < 0.50)->values();
        $wasteRows = $rows->filter(fn (array $row): bool => $row['waste_ratio'] !== null && (AiValueNormalizer::finiteFloatOrNull($row['waste_ratio']) ?? 0.0) >= 0.40)->values();
        $noiseRows = $rows->filter(fn (array $row): bool => (int) $row['noise_sources'] > 0)->values();
        $missedRows = $rows->filter(fn (array $row): bool => (int) $row['missed_required_source_count'] > 0)->values();
        $nonPassingRows = $rows
            ->filter(fn (array $row): bool => $this->isNonPassingContextOutcome((string) $row['outcome_status']))
            ->values();
        $missingRoiRows = $rows->reject(fn (array $row): bool => (bool) $row['has_roi_signal'])->values();

        $reasons = [];
        if ($lowRoiRows->isNotEmpty()) {
            $reasons[] = 'low_context_roi_observed';
        }
        if ($wasteRows->isNotEmpty()) {
            $reasons[] = 'context_waste_observed';
        }
        if ($noiseRows->isNotEmpty()) {
            $reasons[] = 'noise_context_observed';
        }
        if ($missedRows->isNotEmpty()) {
            $reasons[] = 'missed_required_sources_observed';
        }
        if ($nonPassingRows->isNotEmpty()) {
            $reasons[] = 'non_passing_context_outcome_observed';
        }
        if ($missingRoiRows->isNotEmpty()) {
            $reasons[] = 'context_feedback_missing_roi_signal';
        }

        $weakRatio = round($weakRows->count() / max(1, $observedCount), 4);
        $nonPassingRatio = round($nonPassingRows->count() / max(1, $observedCount), 4);
        $status = ($weakRatio >= 0.50 && $observedCount >= 3) || ($nonPassingRatio >= 0.75 && $observedCount >= 3)
            ? 'critical'
            : ($reasons !== [] ? 'warning' : 'ready');
        $latest = $rows->first();
        $latestPolicyAction = is_array($latest) ? (string) (($latest['policy_actions'][0] ?? '') ?: '') : '';
        $recommendedAction = $status === 'ready'
            ? 'keep_collecting_provider_safe_context_feedback'
            : ($reasons === ['context_waste_observed'] && $latestPolicyAction !== ''
                ? $latestPolicyAction
                : 'review_context_feedback_before_expanding_initial_context_or_demoting_sources');
        $autoApplyThreshold = $recommendedAction === 'shrink_initial_context' ? 2 : 0;
        $remainingBeforeAutoApply = $autoApplyThreshold > 0 ? max(0, $autoApplyThreshold - $observedCount) : 0;

        return [
            'schema_version' => 'atlas.open_brain.context_feedback_metric_aggregate.v1',
            'status' => $status,
            'window_hours' => $hours,
            'observed_count' => $observedCount,
            'measured_count' => $observedCount,
            'total_event_count' => $totalEventCount,
            'quality_band_counts' => [
                'strong' => $strongRows->count(),
                'mixed' => $mixedRows->count(),
                'weak' => $weakRows->count(),
                'unknown' => $observedCount - $strongRows->count() - $mixedRows->count() - $weakRows->count(),
            ],
            'outcome_counts' => $rows->map(fn (array $row): string => (string) $row['outcome_status'])->countBy()->all(),
            'low_roi_count' => $lowRoiRows->count(),
            'waste_count' => $wasteRows->count(),
            'noise_count' => $noiseRows->count(),
            'missed_required_source_feedback_count' => $missedRows->count(),
            'non_passing_count' => $nonPassingRows->count(),
            'roi_signal_count' => $roiSignalRows->count(),
            'actionable_feedback_count' => $actionableRows->count(),
            'non_actionable_feedback_count' => $observedCount - $actionableRows->count(),
            'missing_roi_signal_count' => $missingRoiRows->count(),
            'weak_ratio' => $weakRatio,
            'non_passing_ratio' => $nonPassingRatio,
            'averages' => [
                'roi_score' => $this->averageMetric($rows, 'roi_score', 4),
                'use_ratio' => $this->averageMetric($rows, 'use_ratio', 4),
                'waste_ratio' => $this->averageMetric($rows, 'waste_ratio', 4),
                'context_sufficiency' => $this->averageMetric($rows, 'context_sufficiency'),
                'post_execution_utility' => $this->averageMetric($rows, 'post_execution_utility'),
                'included_sources' => $this->averageMetric($rows, 'included_sources'),
                'used_sources' => $this->averageMetric($rows, 'used_sources'),
                'noise_sources' => $this->averageMetric($rows, 'noise_sources'),
            ],
            'latest' => $latest,
            'review_signal' => [
                'status' => $status === 'ready' ? 'ready' : ($status === 'critical' ? 'blocking' : 'review'),
                'severity' => $status === 'critical' ? 'high' : ($status === 'warning' ? 'medium' : 'low'),
                'reasons' => $reasons,
                'recommended_action' => $recommendedAction,
                'auto_apply_threshold' => $autoApplyThreshold,
                'auto_apply_ready' => $autoApplyThreshold > 0 && $remainingBeforeAutoApply === 0,
                'remaining_feedback_events_before_auto_apply' => $remainingBeforeAutoApply,
            ],
        ];
    }

    private function isNonPassingContextOutcome(string $status): bool
    {
        $status = strtolower(trim($status));
        if ($status === '' || in_array($status, ['passed', 'success', 'succeeded', 'ok', 'ready', 'completed'], true)) {
            return false;
        }

        if (in_array($status, ['ready_for_provider', 'unknown', 'observed', 'no_data'], true)) {
            return false;
        }

        return true;
    }

    private function feedbackEventMeasured(AiRagFeedbackEvent $event): bool
    {
        $payload = is_array($event->payload) ? $event->payload : [];

        return (bool) data_get(
            $payload,
            'measured',
            data_get(
                $payload,
                'payload.measured',
                data_get($payload, 'payload.context_roi.measured', data_get($payload, 'context_roi.measured', false)),
            ),
        );
    }

    private function averageMetric(Collection $rows, string $key, int $precision = 2): float
    {
        if ($rows->isEmpty()) {
            return 0.0;
        }

        return round(AiValueNormalizer::finiteFloatOrNull($rows->avg($key)) ?? 0.0, $precision);
    }

    /**
     * @return array<string,mixed>
     */
    private function memorySummary(?string $workspace): array
    {
        $memoryTable = Schema::hasTable('atlas_memory_entries');
        $verbatimTable = Schema::hasTable('atlas_verbatim_memories');
        $openBrainAuditTable = Schema::hasTable('atlas_open_brain_access_logs');
        $providerSafeCount = 0;
        if ($memoryTable) {
            $providerSafeCount = AtlasMemoryEntry::query()
                ->where('status', 'active')
                ->get()
                ->filter(fn (AtlasMemoryEntry $entry): bool => $this->privacy->providerAllowed($entry))
                ->count();
        }

        return [
            'status' => $memoryTable ? ($providerSafeCount > 0 ? 'ready' : 'empty_provider_safe_memory') : 'not_migrated',
            'tables' => [
                'atlas_memory_entries' => $memoryTable,
                'atlas_verbatim_memories' => $verbatimTable,
                'atlas_open_brain_access_logs' => $openBrainAuditTable,
            ],
            'active_memory_count' => $memoryTable ? AtlasMemoryEntry::query()->where('status', 'active')->count() : 0,
            'provider_safe_memory_count' => $providerSafeCount,
            'verbatim_active_count' => $verbatimTable ? AtlasVerbatimMemory::query()->where('status', 'active')->count() : 0,
            'open_brain_audit_count' => $openBrainAuditTable ? AtlasOpenBrainAccessLog::query()->count() : 0,
            'workspace' => $workspace,
        ];
    }

    /**
     * @param  array<string,mixed>|null  $codeAudit
     */
    private function overallStatus(array $memory, array $memoryQuality, array $promptMetrics, array $contextFeedbackMetrics, array $runtimeSourceProbe, array $knowledge, array $code, array $projection, ?array $codeAudit): string
    {
        if (($runtimeSourceProbe['status'] ?? null) === 'stale_source_mismatch') {
            return 'mcp_runtime_stale';
        }
        if (($memory['status'] ?? null) !== 'ready') {
            return 'needs_memory';
        }
        if (in_array($memoryQuality['status'] ?? null, ['critical'], true)) {
            return 'needs_memory_quality_review';
        }
        if (in_array($promptMetrics['status'] ?? null, ['critical'], true)) {
            return 'needs_prompt_metric_review';
        }
        if (in_array($contextFeedbackMetrics['status'] ?? null, ['critical'], true)) {
            return 'needs_context_feedback_review';
        }
        if (($knowledge['status'] ?? null) !== 'ready') {
            return 'needs_knowledge_sync';
        }
        if (($code['status'] ?? null) !== 'ready') {
            return 'needs_code_index';
        }
        if (($projection['status'] ?? null) !== 'passed') {
            return 'needs_projection_review';
        }
        if ($codeAudit !== null && ($codeAudit['status'] ?? null) !== 'fresh') {
            return 'needs_code_index_refresh';
        }

        return 'ready';
    }

    /**
     * @param  array<string,mixed>|null  $codeAudit
     * @return array<int,string>
     */
    private function nextActions(?string $workspace, array $memory, array $memoryQuality, array $promptMetrics, array $contextFeedbackMetrics, array $runtimeSourceProbe, array $knowledge, array $code, array $projection, ?array $codeAudit): array
    {
        $workspaceArg = $workspace ? ' --workspace="'.str_replace('"', '\"', $workspace).'"' : '';
        $actions = [];

        if (($runtimeSourceProbe['status'] ?? null) === 'stale_source_mismatch') {
            $actions[] = 'Restart the provider MCP client/session; until then use /opt/homebrew/bin/php artisan atlas:context-pack "<task>" --workspace="'.$workspace.'" --json as the fresh CLI fallback.';
        }
        if (($memory['provider_safe_memory_count'] ?? 0) < 1) {
            $actions[] = '/opt/homebrew/bin/php artisan atlas:memory:seed-core';
        }
        foreach ((array) ($memoryQuality['recommendations'] ?? []) as $action) {
            if (is_string($action) && $action !== '') {
                $actions[] = $action;
            }
        }
        if (in_array($promptMetrics['status'] ?? null, ['critical', 'warning'], true)) {
            $actions[] = 'Review open_brain_prompt_metrics before changing prompt delivery policy.';
        }
        if (($contextFeedbackMetrics['status'] ?? null) === 'no_data') {
            $actions[] = 'Ask external providers to call atlas_context_feedback after context-sensitive runs.';
        }
        if (in_array($contextFeedbackMetrics['status'] ?? null, ['critical', 'warning'], true)) {
            $flow = (string) data_get($contextFeedbackMetrics, 'latest.flow_id', '');
            if (data_get($contextFeedbackMetrics, 'review_signal.recommended_action') === 'shrink_initial_context') {
                $remaining = (int) data_get($contextFeedbackMetrics, 'review_signal.remaining_feedback_events_before_auto_apply', 0);
                $actions[] = $remaining > 0
                    ? 'Collect one more AOBG context feedback'.($flow !== '' ? ' for flow '.$flow : '').' before auto-shrinking initial context budget.'
                    : 'Shrink initial AOBG context budget'.($flow !== '' ? ' for flow '.$flow : '').' before expanding source coverage.';
            } else {
                $actions[] = 'Review context_feedback_metrics before expanding initial context or demoting sources.';
            }
        }
        if (($knowledge['status'] ?? null) !== 'ready') {
            $actions[] = './bin/atlas engineering knowledge sync --prune --json';
        }
        if (($code['status'] ?? null) !== 'ready' || ($codeAudit !== null && ($codeAudit['status'] ?? null) !== 'fresh')) {
            $actions[] = './bin/atlas engineering knowledge index-code --prune'.$workspaceArg.' --summary-only --json';
        }
        if (($projection['status'] ?? null) !== 'passed') {
            $actions[] = './bin/atlas memory projection review --target=all'.$workspaceArg.' --json';
            $actions[] = './bin/atlas memory projection apply --target=all'.$workspaceArg.' --yes --json';
        }

        return array_values(array_unique($actions));
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
