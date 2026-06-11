<?php

namespace App\Services\Ai;

use App\Models\AiCodebaseWorldModel;
use App\Models\AiCodebaseWorldModelEdge;
use App\Models\AiCodebaseWorldModelNode;
use App\Models\AtlasAurgNode;
use App\Models\AtlasMemoryEntry;
use App\Models\AtlasMemoryEntryRelation;
use App\Models\AtlasOpenBrainAccessLog;
use App\Models\AtlasTask;
use App\Models\AtlasTaskEvent;
use App\Models\AtlasVerbatimMemory;
use App\Services\Ai\AutonomousEngineering\WorldModel\WorldModelGraphRanker;
use App\Services\Ai\AutonomousEngineering\WorldModel\WorldModelRankingQuery;
use App\Services\Ai\Compression\AtlasCcrStore;
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
use App\Services\Ai\Reality\AtlasRealityGraphIngestionService;
use App\Services\Ai\Reality\AtlasRealityGraphQueryService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementScheduleService;
use App\Services\Engineering\CodeGraph\CodeGraphAdjacencyIndex;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceModelResolver;
use App\Services\Engineering\CodeGraph\CrossDomainGraphTraversalService;
use App\Services\Engineering\CodeGraph\CrossDomainTaxonomyMap;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use App\Services\Engineering\EngineeringKnowledgeBaseService;
use App\Support\AtlasSecurity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Throwable;

class AtlasOpenBrainMcpService
{
    public const PROTOCOL_VERSION = '2025-06-18';

    public function __construct(
        private readonly AtlasHybridMemoryRetrievalService $recall,
        private readonly AtlasOpenBrainContextPackService $contextPack,
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
    ) {}

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
            'tools/list' => $this->response($id, ['tools' => $this->tools()]),
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
        return [
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
                'name' => 'atlas_memory_maintenance_status',
                'title' => 'Atlas Memory Maintenance Status',
                'description' => 'Mostra health check read-only da memoria: docs sync, code index, provider projection e tabelas principais.',
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
                        'budget' => ['type' => 'integer', 'description' => 'Budget total de chars do pack (default config atlas.aobg.budget_chars).'],
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
                'version' => '1.1.0',
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

        try {
            return match ($name) {
                'atlas_memory_recall' => $this->toolResponse($id, $this->memoryRecall($arguments)),
                'atlas_open_brain_context_pack' => $this->toolResponse($id, $this->contextPack($arguments)),
                'atlas_memory_maintenance_status' => $this->toolResponse($id, $this->maintenanceStatus($arguments)),
                'atlas_memory_record' => $this->toolResponse($id, $this->memoryRecord($arguments)),
                'atlas_code_find_relevant' => $this->toolResponse($id, $this->codeFindRelevant($arguments)),
                'atlas_docs_lookup' => $this->toolResponse($id, $this->docsLookup($arguments)),
                'atlas_capabilities' => $this->toolResponse($id, $this->capabilities()),
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
                'atlas_task_start' => $this->toolResponse($id, $this->taskStart($arguments)),
                'atlas_task_progress' => $this->toolResponse($id, $this->taskProgress($arguments)),
                'atlas_task_complete' => $this->toolResponse($id, $this->taskComplete($arguments)),
                'atlas_memory_archive' => $this->toolResponse($id, $this->memoryArchive($arguments)),
                'atlas_memory_link' => $this->toolResponse($id, $this->memoryLink($arguments)),
                'atlas_memory_supersede' => $this->toolResponse($id, $this->memorySupersede($arguments)),
                'atlas_memory_get' => $this->toolResponse($id, $this->memoryGet($arguments)),
                'atlas_module_info' => $this->toolResponse($id, $this->moduleInfo($arguments)),
                'atlas_route_info' => $this->toolResponse($id, $this->routeInfo($arguments)),
                'atlas_test_for' => $this->toolResponse($id, $this->testFor($arguments)),
                'atlas_context_for' => $this->toolResponse($id, $this->contextFor($arguments)),
                'atlas_code_neighbors' => $this->toolResponse($id, $this->codeNeighbors($arguments)),
                'atlas_code_path' => $this->toolResponse($id, $this->codePath($arguments)),
                'atlas_code_explain' => $this->toolResponse($id, $this->codeExplain($arguments)),
                'atlas_ccr_retrieve' => $this->toolResponse($id, $this->ccrRetrieve($arguments)),
                'atlas_cross_domain_query' => $this->toolResponse($id, $this->crossDomainQuery($arguments)),
                'atlas_aurg_query' => $this->toolResponse($id, $this->aurgQuery($arguments)),
                'atlas_mission_history' => $this->toolResponse($id, $this->missionHistory($arguments)),
                'atlas_obra_status' => $this->toolResponse($id, $this->obraStatus($arguments)),
                'atlas_context_pack' => $this->toolResponse($id, $this->contextPackUnified($arguments)),
                'atlas_record_outcome' => $this->toolResponse($id, $this->recordOutcome($arguments)),
                'atlas_propose_learning' => $this->toolResponse($id, $this->proposeLearning($arguments)),
                'atlas_workspace_status' => $this->toolResponse($id, $this->workspaceStatus($arguments)),
                'atlas_workspace_map' => $this->toolResponse($id, $this->workspaceMap($arguments)),
                'atlas_workspace_activate' => $this->toolResponse($id, $this->workspaceActivate($arguments)),
                'atlas_claim_task' => $this->toolResponse($id, $this->claimTask($arguments)),
                'atlas_blackboard_status' => $this->toolResponse($id, $this->blackboardStatus($arguments)),
                default => $this->error($id, -32602, "Unknown Atlas MCP tool [{$name}]."),
            };
        } catch (Throwable $exception) {
            return $this->toolError($id, $exception->getMessage(), [
                'tool' => $name,
                'exception' => class_basename($exception),
            ]);
        }
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

        return [
            'ok' => true,
            'tool' => 'atlas_memory_recall',
            'memory_recall' => $this->recall->recall(
                $query,
                $context,
                $this->object($arguments['filters'] ?? []),
                $this->object($arguments['options'] ?? []),
            ),
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

        $payload = $this->object($arguments['payload'] ?? []);
        $workspace = $this->workspace($arguments['workspace'] ?? data_get($payload, 'workspace'));
        if ($workspace !== null) {
            $payload['workspace'] = $workspace;
        }

        return [
            'ok' => true,
            'tool' => 'atlas_open_brain_context_pack',
            'open_brain' => $this->openBrain->contextPack([
                'objective' => $objective,
                'workspace' => $workspace,
                'task_type' => $this->string($arguments['task_type'] ?? null) ?: 'dev',
                'desired_mode' => $this->string($arguments['desired_mode'] ?? null) ?: 'direct',
                'agent' => $this->string($arguments['agent'] ?? null) ?: 'orquestrador',
                'intent' => $this->string($arguments['intent'] ?? null) ?: 'mcp_context_export',
                'requester' => $this->string($arguments['requester'] ?? null) ?: 'mcp-client',
                'include_prompt' => (bool) ($arguments['include_prompt'] ?? false),
                'payload' => $payload,
                'options' => $this->object($arguments['options'] ?? []),
            ], 'mcp'),
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
            'knowledge' => $knowledge,
            'code_intelligence' => $code,
            'code_audit' => $codeAudit,
            'provider_projection' => $projection,
            'overall_status' => $this->overallStatus($memory, $memoryQuality, $knowledge, $code, $projection, $codeAudit),
            'next_actions' => $this->nextActions($workspace, $memory, $memoryQuality, $knowledge, $code, $projection, $codeAudit),
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

        $entry = AtlasMemoryEntry::create([
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
                'version' => '1.1.0',
            ],
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
            $indexFresh = Carbon::parse($lastIndexAt)
                ->greaterThan(now()->subDay());
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
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function taskStart(array $arguments): array
    {
        if (! $this->taskOrchestrationAvailable()) {
            return $this->taskOrchestrationUnavailable('atlas_task_start');
        }

        $title = $this->string($arguments['title'] ?? null);
        if ($title === null) {
            return ['ok' => false, 'tool' => 'atlas_task_start', 'error' => 'title_required'];
        }

        $workspace = $this->workspace($arguments['workspace'] ?? null);
        $task = DB::transaction(function () use ($arguments, $title, $workspace): AtlasTask {
            $task = AtlasTask::create([
                'title' => $title,
                'description' => $this->string($arguments['objective'] ?? null),
                'status' => 'open',
                'domain' => $this->string($arguments['domain'] ?? null) ?: 'dev',
                'project_id' => $this->string($arguments['project_id'] ?? null),
                'metadata' => array_merge(
                    $this->object($arguments['metadata'] ?? []),
                    ['workspace' => $workspace, 'source' => 'mcp_tool'],
                ),
            ]);

            $this->recordTaskLifecycleEvent($task, 'started', [
                'title' => $task->title,
                'objective_present' => $task->description !== null && trim((string) $task->description) !== '',
                'workspace_hash' => $workspace ? hash('sha256', $workspace) : null,
                'project_id' => $task->project_id,
                'no_provider_execution' => true,
                'no_runtime_execution' => true,
            ]);

            return $task;
        });

        return [
            'ok' => true,
            'tool' => 'atlas_task_start',
            'task_id' => (string) $task->id,
            'status' => $task->status,
            'event_type' => 'started',
            'created_at' => $task->created_at?->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function taskProgress(array $arguments): array
    {
        if (! $this->taskOrchestrationAvailable()) {
            return $this->taskOrchestrationUnavailable('atlas_task_progress');
        }

        $taskId = $this->string($arguments['task_id'] ?? null);
        $milestone = $this->string($arguments['milestone'] ?? null);

        if ($taskId === null || $milestone === null) {
            return ['ok' => false, 'tool' => 'atlas_task_progress', 'error' => 'task_id_and_milestone_required'];
        }

        $task = AtlasTask::find($taskId);
        if ($task === null) {
            return ['ok' => false, 'tool' => 'atlas_task_progress', 'error' => 'task_not_found'];
        }

        $event = DB::transaction(function () use ($arguments, $task, $milestone): AtlasTaskEvent {
            return $this->recordTaskLifecycleEvent($task, 'milestone', [
                'milestone' => $milestone,
                'details' => $this->string($arguments['details'] ?? null),
                'progress_pct' => isset($arguments['progress_pct']) ? max(0, min(100, (int) $arguments['progress_pct'])) : null,
                'no_provider_execution' => true,
                'no_runtime_execution' => true,
            ]);
        });

        return [
            'ok' => true,
            'tool' => 'atlas_task_progress',
            'task_id' => $taskId,
            'event_id' => (string) $event->id,
            'milestone' => $milestone,
            'recorded_at' => $event->occurred_at?->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function taskComplete(array $arguments): array
    {
        if (! $this->taskOrchestrationAvailable()) {
            return $this->taskOrchestrationUnavailable('atlas_task_complete');
        }

        $taskId = $this->string($arguments['task_id'] ?? null);
        if ($taskId === null) {
            return ['ok' => false, 'tool' => 'atlas_task_complete', 'error' => 'task_id_required'];
        }

        $task = AtlasTask::find($taskId);
        if ($task === null) {
            return ['ok' => false, 'tool' => 'atlas_task_complete', 'error' => 'task_not_found'];
        }

        $event = DB::transaction(function () use ($arguments, $task): AtlasTaskEvent {
            $task->update([
                'status' => 'done',
                'completed_at' => now(),
            ]);

            return $this->recordTaskLifecycleEvent($task, 'completed', [
                'summary' => $this->string($arguments['summary'] ?? null),
                'files_changed' => is_array($arguments['files_changed'] ?? null) ? $this->stringList($arguments['files_changed']) : [],
                'outcome' => $this->string($arguments['outcome'] ?? null) ?: 'success',
                'memory_entry_ids' => is_array($arguments['memory_entry_ids'] ?? null) ? $this->stringList($arguments['memory_entry_ids']) : [],
                'no_provider_execution' => true,
                'no_runtime_execution' => true,
            ]);
        });

        $task->refresh();

        return [
            'ok' => true,
            'tool' => 'atlas_task_complete',
            'task_id' => $taskId,
            'status' => 'done',
            'event_id' => (string) $event->id,
            'completed_at' => $task->completed_at?->toJSON(),
        ];
    }

    private function taskOrchestrationAvailable(): bool
    {
        return Schema::hasTable('atlas_tasks') && Schema::hasTable('atlas_task_events');
    }

    /**
     * @return array<string,mixed>
     */
    private function taskOrchestrationUnavailable(string $tool): array
    {
        return [
            'ok' => false,
            'tool' => $tool,
            'error' => 'task_orchestration_unavailable',
            'missing_tables' => array_values(array_filter([
                Schema::hasTable('atlas_tasks') ? null : 'atlas_tasks',
                Schema::hasTable('atlas_task_events') ? null : 'atlas_task_events',
            ])),
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function recordTaskLifecycleEvent(AtlasTask $task, string $eventType, array $payload): AtlasTaskEvent
    {
        $previousEvent = $task->events()
            ->latest('occurred_at')
            ->latest('id')
            ->first();
        $eventSequence = (int) $task->events()->count() + 1;
        $eventPayload = [
            'schema_version' => 'atlas.task_orchestration.event.v1',
            'tool' => 'atlas_open_brain_mcp',
            'event_sequence' => $eventSequence,
            'previous_event_id' => $previousEvent?->id,
            'previous_event_hash' => data_get($previousEvent?->payload, 'event_hash'),
            ...$payload,
        ];
        $eventPayload['event_hash'] = $this->stableTaskEventHash($task, $eventType, $eventPayload);

        return AtlasTaskEvent::create([
            'task_id' => (string) $task->id,
            'event_type' => $eventType,
            'source' => 'mcp_tool',
            'payload' => $eventPayload,
            'occurred_at' => now(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function stableTaskEventHash(AtlasTask $task, string $eventType, array $payload): string
    {
        $hashPayload = $payload;
        unset($hashPayload['event_hash']);

        $encoded = json_encode($this->sortKeysRecursive([
            'task_id' => (string) $task->id,
            'event_type' => $eventType,
            'payload' => $hashPayload,
        ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $encoded === false ? '' : $encoded);
    }

    private function sortKeysRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortKeysRecursive($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->sortKeysRecursive($item), $value);
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function memoryArchive(array $arguments): array
    {
        $entryId = $this->string($arguments['memory_entry_id'] ?? null);
        if ($entryId === null) {
            return ['ok' => false, 'tool' => 'atlas_memory_archive', 'error' => 'memory_entry_id_required'];
        }

        $entry = AtlasMemoryEntry::find($entryId);
        if ($entry === null) {
            return ['ok' => false, 'tool' => 'atlas_memory_archive', 'error' => 'memory_entry_not_found'];
        }

        $reason = $this->string($arguments['reason'] ?? null);
        $metadata = $entry->metadata ?? [];
        if ($reason !== null) {
            $metadata['archive_reason'] = $reason;
            $metadata['archived_by'] = 'mcp_tool';
        }

        $entry->update([
            'status' => 'archived',
            'archived_at' => now(),
            'metadata' => $metadata,
        ]);

        return [
            'ok' => true,
            'tool' => 'atlas_memory_archive',
            'memory_entry_id' => (string) $entry->id,
            'status' => 'archived',
            'archived_at' => $entry->archived_at?->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function memoryLink(array $arguments): array
    {
        $sourceId = $this->string($arguments['source_id'] ?? null);
        $targetId = $this->string($arguments['target_id'] ?? null);
        $type = $this->string($arguments['relation_type'] ?? null);

        if ($sourceId === null || $targetId === null || $type === null) {
            return ['ok' => false, 'tool' => 'atlas_memory_link', 'error' => 'source_target_type_required'];
        }

        if (! in_array($type, AtlasMemoryEntryRelation::TYPES, true)) {
            return ['ok' => false, 'tool' => 'atlas_memory_link', 'error' => 'invalid_relation_type'];
        }

        if ($sourceId === $targetId) {
            return ['ok' => false, 'tool' => 'atlas_memory_link', 'error' => 'cannot_link_to_self'];
        }

        $relation = AtlasMemoryEntryRelation::create([
            'source_memory_entry_id' => $sourceId,
            'target_memory_entry_id' => $targetId,
            'relation_type' => $type,
            'status' => 'open',
            'confidence' => isset($arguments['confidence']) ? (float) $arguments['confidence'] : 0.8,
            'reason' => $this->string($arguments['reason'] ?? null),
            'metadata' => ['source' => 'mcp_tool'],
        ]);

        return [
            'ok' => true,
            'tool' => 'atlas_memory_link',
            'relation_id' => (string) $relation->id,
            'source_id' => $sourceId,
            'target_id' => $targetId,
            'relation_type' => $type,
            'status' => 'open',
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function memorySupersede(array $arguments): array
    {
        $oldId = $this->string($arguments['old_entry_id'] ?? null);
        $newId = $this->string($arguments['new_entry_id'] ?? null);

        if ($oldId === null || $newId === null) {
            return ['ok' => false, 'tool' => 'atlas_memory_supersede', 'error' => 'old_and_new_entry_id_required'];
        }

        if ($oldId === $newId) {
            return ['ok' => false, 'tool' => 'atlas_memory_supersede', 'error' => 'cannot_supersede_self'];
        }

        $old = AtlasMemoryEntry::find($oldId);
        $new = AtlasMemoryEntry::find($newId);

        if ($old === null) {
            return ['ok' => false, 'tool' => 'atlas_memory_supersede', 'error' => 'old_entry_not_found'];
        }
        if ($new === null) {
            return ['ok' => false, 'tool' => 'atlas_memory_supersede', 'error' => 'new_entry_not_found'];
        }

        $reason = $this->string($arguments['reason'] ?? null);
        $metadata = $old->metadata ?? [];
        if ($reason !== null) {
            $metadata['supersede_reason'] = $reason;
        }
        $metadata['superseded_by'] = (string) $new->id;
        $metadata['superseded_at'] = now()->toJSON();

        $old->update([
            'superseded_by_id' => $new->id,
            'status' => 'archived',
            'archived_at' => now(),
            'metadata' => $metadata,
        ]);

        return [
            'ok' => true,
            'tool' => 'atlas_memory_supersede',
            'old_entry_id' => (string) $old->id,
            'new_entry_id' => (string) $new->id,
            'old_status' => 'archived',
            'archived_at' => $old->archived_at?->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function memoryGet(array $arguments): array
    {
        $entryId = $this->string($arguments['memory_entry_id'] ?? null);
        if ($entryId === null) {
            return ['ok' => false, 'tool' => 'atlas_memory_get', 'error' => 'memory_entry_id_required'];
        }

        $entry = AtlasMemoryEntry::find($entryId);
        if ($entry === null) {
            return ['ok' => false, 'tool' => 'atlas_memory_get', 'error' => 'memory_entry_not_found'];
        }

        $privacyDecision = $this->privacy->providerDecision($entry);
        if (! (bool) $privacyDecision['allowed']) {
            $this->ledger->recordProviderMemoryBlocked($entry, $privacyDecision, 'open_brain_mcp', [
                'correlation_id' => $this->string($arguments['correlation_id'] ?? null) ?? $entry->trace_id,
                'trace_id' => $this->string($arguments['trace_id'] ?? null) ?? $entry->trace_id,
            ]);

            return ['ok' => false, 'tool' => 'atlas_memory_get', 'error' => 'not_provider_safe'];
        }

        $payload = [
            'id' => (string) $entry->id,
            'memory_type' => $entry->memory_type,
            'scope_type' => $entry->scope_type,
            'scope_id' => $entry->scope_id,
            'title' => $this->privacy->providerTitle($entry),
            'body' => $this->privacy->providerBody($entry),
            'summary' => $this->privacy->providerSummary($entry),
            'tags' => $entry->tags ?? [],
            'metadata' => $entry->metadata ?? [],
            'status' => $entry->status,
            'privacy_class' => $entry->privacy_class,
            'recorded_at' => $entry->recorded_at?->toJSON(),
            'archived_at' => $entry->archived_at?->toJSON(),
            'superseded_by_id' => $entry->superseded_by_id,
        ];

        if ((bool) ($arguments['include_relations'] ?? false)) {
            $payload['outgoing_relations'] = $entry->outgoingRelations()->get()->map(fn ($r) => [
                'id' => (string) $r->id,
                'target_id' => (string) $r->target_memory_entry_id,
                'relation_type' => $r->relation_type,
                'status' => $r->status,
            ])->toArray();
            $payload['incoming_relations'] = $entry->incomingRelations()->get()->map(fn ($r) => [
                'id' => (string) $r->id,
                'source_id' => (string) $r->source_memory_entry_id,
                'relation_type' => $r->relation_type,
                'status' => $r->status,
            ])->toArray();
        }

        return [
            'ok' => true,
            'tool' => 'atlas_memory_get',
            'entry' => $payload,
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * AP-813 · retrieve a CCR original by content hash. Read-only,
     * lossless-by-governance. Privacy gate mirrors the bridge-evidence secret-class
     * rule: a secret/sensitive original is NEVER surfaced over this provider-safe path.
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function ccrRetrieve(array $arguments): array
    {
        $hash = $this->string($arguments['hash'] ?? null);
        if ($hash === null || $hash === '') {
            return ['ok' => false, 'tool' => 'atlas_ccr_retrieve', 'error' => 'hash_required'];
        }

        $store = app(AtlasCcrStore::class);
        $result = $store->retrieve($hash, [
            'correlation_id' => $this->string($arguments['correlation_id'] ?? null),
            'trace_id' => $this->string($arguments['trace_id'] ?? null),
            'recorded_by' => 'open_brain_mcp',
        ]);

        if (($result['found'] ?? false) !== true) {
            return ['ok' => false, 'tool' => 'atlas_ccr_retrieve', 'error' => 'original_not_found'];
        }

        $privacyClass = (string) ($result['privacy_class'] ?? 'internal');
        if (in_array($privacyClass, ['secret', 'sensitive'], true)) {
            return ['ok' => false, 'tool' => 'atlas_ccr_retrieve', 'error' => 'not_provider_safe', 'privacy_class' => $privacyClass];
        }

        return [
            'ok' => true,
            'tool' => 'atlas_ccr_retrieve',
            'hash' => $hash,
            'content_type' => $result['content_type'],
            'original' => $result['original'],
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * M-8 Fase-2 (AP-814): the cross-domain killer query — what is reachable across
     * domains under the ARPTL veto. Read-only, flag-gated. Provider-safe: returns
     * graph topology (domain labels + vetoes), never domain content.
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function crossDomainQuery(array $arguments): array
    {
        $tool = 'atlas_cross_domain_query';
        $seed = $this->string($arguments['seed'] ?? null);
        if ($seed === null || $seed === '') {
            return ['ok' => false, 'tool' => $tool, 'error' => 'seed_required'];
        }
        if (! (bool) config('atlas.cross_domain_graph.enabled', false)) {
            return ['ok' => false, 'tool' => $tool, 'error' => 'cross_domain_graph_disabled'];
        }

        $taxonomy = app(CrossDomainTaxonomyMap::class);
        // Accept "domain:finance", "finance", a mesh id, or a registry id.
        if (! str_starts_with($seed, 'domain:')) {
            $canonical = $taxonomy->canonical($seed);
            $seed = $canonical !== null ? 'domain:'.$canonical : $seed;
        }

        $privacy = $this->string($arguments['privacy_class'] ?? null) ?? 'normal';
        $traversal = app(CrossDomainGraphTraversalService::class);
        $result = $traversal->killerQuery($seed, $privacy);

        return [
            'ok' => (bool) ($result['ok'] ?? false),
            'tool' => $tool,
            'seed' => $seed,
            'query' => $result,
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * AURG F2 (Salto 1): the fused reality-graph brain query with provenance.
     * Read-only. provider_bound is FORCED TRUE on this surface — MCP output can
     * land in provider prompts, and sensitive domains (plus anything reachable
     * only through them) are NEVER included in any provider prompt output. The
     * unbounded local view is the operator CLI (atlas:aurg:query).
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function aurgQuery(array $arguments): array
    {
        $tool = 'atlas_aurg_query';
        $query = $this->string($arguments['query'] ?? null);
        if ($query === null) {
            return ['ok' => false, 'tool' => $tool, 'error' => 'query_required'];
        }
        if (! (bool) config('atlas.aurg.enabled', true)) {
            return ['ok' => false, 'tool' => $tool, 'error' => 'aurg_disabled'];
        }

        $opts = ['provider_bound' => true]; // structural: never relaxable via MCP
        if (is_numeric($arguments['depth'] ?? null)) {
            $opts['depth'] = (int) $arguments['depth'];
        }
        if (is_numeric($arguments['limit'] ?? null)) {
            $opts['max_nodes'] = (int) $arguments['limit'];
        }

        $result = app(AtlasRealityGraphQueryService::class)->query($query, $opts);

        return [
            'ok' => true,
            'tool' => $tool,
            'provider_bound' => true,
            'result' => $result,
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

        $opts = [];
        $workspace = $this->workspace($arguments['workspace'] ?? null);
        if ($workspace !== null) {
            $opts['workspace'] = $workspace;
        }
        if (is_numeric($arguments['budget'] ?? null)) {
            $opts['budget'] = (int) $arguments['budget'];
        }

        $pack = $this->contextPack->packFor($task, $opts);

        return [
            'ok' => true,
            'tool' => $tool,
            'provider_bound' => true,
            'pack' => $pack,
            'generated_at' => now()->toJSON(),
        ];
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

        return ['tool' => $tool] + $this->workspaceOnboarding->map($opts);
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
        $result = $this->code->symbols(['q' => $path, 'symbol_type' => 'route'], $limit);
        $routes = $result['symbols'] ?? [];

        return [
            'ok' => true,
            'tool' => 'atlas_route_info',
            'path' => $path,
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
        $result = $this->code->symbols(['q' => $target, 'symbol_type' => 'test_method'], $limit);
        $tests = $result['symbols'] ?? [];

        return [
            'ok' => true,
            'tool' => 'atlas_test_for',
            'target' => $target,
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

        $memoryLimit = $this->mcpInput->contextMemoryLimit($arguments['memory_limit'] ?? null);
        $codeLimit = $this->mcpInput->contextCodeLimit($arguments['code_limit'] ?? null);
        $docsLimit = $this->mcpInput->contextDocsLimit($arguments['docs_limit'] ?? null);

        $memory = $this->recall->recall($task, $context, [], ['limit' => $memoryLimit]);
        $code = $this->code->symbols(['q' => $task], $codeLimit);
        $docs = $this->knowledge->catalog(['q' => $task, 'status' => 'active'], $docsLimit);

        $memoryEntries = $memory['recall'] ?? [];
        $codeSymbols = $code['symbols'] ?? [];
        $docsItems = $docs['items'] ?? [];

        return [
            'ok' => true,
            'tool' => 'atlas_context_for',
            'task_description' => $task,
            'workspace' => $workspace,
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
    private function overallStatus(array $memory, array $memoryQuality, array $knowledge, array $code, array $projection, ?array $codeAudit): string
    {
        if (($memory['status'] ?? null) !== 'ready') {
            return 'needs_memory';
        }
        if (in_array($memoryQuality['status'] ?? null, ['critical'], true)) {
            return 'needs_memory_quality_review';
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
    private function nextActions(?string $workspace, array $memory, array $memoryQuality, array $knowledge, array $code, array $projection, ?array $codeAudit): array
    {
        $workspaceArg = $workspace ? ' --workspace="'.str_replace('"', '\"', $workspace).'"' : '';
        $actions = [];

        if (($memory['provider_safe_memory_count'] ?? 0) < 1) {
            $actions[] = '/opt/homebrew/bin/php artisan atlas:memory:seed-core';
        }
        foreach ((array) ($memoryQuality['recommendations'] ?? []) as $action) {
            if (is_string($action) && $action !== '') {
                $actions[] = $action;
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

    /**
     * @return array<string,mixed>
     */
    // ---------------------------------------------------------------------
    // Code graph traversal tools (AP-811). Read-only over the world-model
    // edge/node tables and the read-only WorldModelGraphRanker. No writes,
    // no provider. Every graph-derived string is run through the same
    // provider-safe redaction the memory/code tools use before it leaves.
    // ---------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function codeNeighbors(array $arguments): array
    {
        $tool = 'atlas_code_neighbors';
        $model = $this->resolveGraphModel($arguments);
        if ($model === null) {
            return $this->noGraph($tool, $arguments);
        }

        $direction = strtolower($this->string($arguments['direction'] ?? null) ?? 'both');
        if (! in_array($direction, ['in', 'out', 'both'], true)) {
            $direction = 'both';
        }

        $start = $this->resolveStartNode($model, $arguments);
        if ($start === null) {
            return [
                'ok' => false,
                'tool' => $tool,
                'world_model_id' => $this->sanitizeGraphText($model->model_id),
                'error' => 'node_not_found',
                'generated_at' => now()->toJSON(),
            ];
        }

        $maxNodes = $this->traversalMaxNodes();
        $limit = $this->positiveInt($arguments['limit'] ?? null);
        $limit = $limit === null ? $maxNodes : min($limit, $maxNodes);

        $edges = $this->edgesTouching($model, $start->node_id);
        // AP-815 B3: load only the neighbour nodes this call presents, not the whole graph.
        $neighborIds = [];
        foreach ($edges as $edge) {
            $neighborIds[] = $edge->from_node_id === $start->node_id ? $edge->to_node_id : $edge->from_node_id;
        }
        $nodeIndex = $this->nodeIndex($model, $neighborIds);

        $neighbors = [];
        foreach ($edges as $edge) {
            $isOut = $edge->from_node_id === $start->node_id;
            $isIn = $edge->to_node_id === $start->node_id;
            if ($direction === 'out' && ! $isOut) {
                continue;
            }
            if ($direction === 'in' && ! $isIn) {
                continue;
            }
            $otherId = $isOut ? $edge->to_node_id : $edge->from_node_id;
            $otherNode = $nodeIndex[$otherId] ?? null;
            $neighbors[] = [
                'direction' => $isOut ? 'out' : 'in',
                'edge' => $this->presentEdge($edge),
                'node' => $otherNode instanceof AiCodebaseWorldModelNode
                    ? $this->presentNode($otherNode)
                    : ['node_id' => $this->sanitizeGraphText($otherId), 'resolved' => false],
            ];
            if (count($neighbors) >= $limit) {
                break;
            }
        }

        return [
            'ok' => true,
            'tool' => $tool,
            'world_model_id' => $this->sanitizeGraphText($model->model_id),
            'node' => $this->presentNode($start),
            'direction' => $direction,
            'limit' => $limit,
            'neighbors' => $neighbors,
            'count' => count($neighbors),
            'truncated' => count($neighbors) >= $limit && $edges->count() > count($neighbors),
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function codePath(array $arguments): array
    {
        $tool = 'atlas_code_path';
        $model = $this->resolveGraphModel($arguments);
        if ($model === null) {
            return $this->noGraph($tool, $arguments);
        }

        $from = $this->resolveStartNode($model, ['node_id' => $arguments['from'] ?? null, 'query' => $arguments['from'] ?? null]);
        $to = $this->resolveStartNode($model, ['node_id' => $arguments['to'] ?? null, 'query' => $arguments['to'] ?? null]);
        if ($from === null || $to === null) {
            return [
                'ok' => false,
                'tool' => $tool,
                'world_model_id' => $this->sanitizeGraphText($model->model_id),
                'error' => $from === null ? 'from_node_not_found' : 'to_node_not_found',
                'generated_at' => now()->toJSON(),
            ];
        }

        $maxDepth = $this->traversalMaxDepth();
        $maxNodes = $this->traversalMaxNodes();
        $adjacency = $this->adjacency($model);

        $pathNodeIds = $this->bfsShortestPath($from->node_id, $to->node_id, $adjacency, $maxDepth, $maxNodes);

        if ($pathNodeIds === null) {
            return [
                'ok' => true,
                'tool' => $tool,
                'world_model_id' => $this->sanitizeGraphText($model->model_id),
                'from' => $this->presentNode($from),
                'to' => $this->presentNode($to),
                'found' => false,
                'reason' => 'no_path_within_traversal_limits',
                'max_depth' => $maxDepth,
                'max_nodes' => $maxNodes,
                'path' => [],
                'hops' => 0,
                'generated_at' => now()->toJSON(),
            ];
        }

        // AP-815 B3: load only the path's nodes, not the whole graph.
        $nodeIndex = $this->nodeIndex($model, $pathNodeIds);
        $path = [];
        foreach ($pathNodeIds as $nodeId) {
            $node = $nodeIndex[$nodeId] ?? null;
            $path[] = $node instanceof AiCodebaseWorldModelNode
                ? $this->presentNode($node)
                : ['node_id' => $this->sanitizeGraphText($nodeId), 'resolved' => false];
        }

        return [
            'ok' => true,
            'tool' => $tool,
            'world_model_id' => $this->sanitizeGraphText($model->model_id),
            'from' => $this->presentNode($from),
            'to' => $this->presentNode($to),
            'found' => true,
            'max_depth' => $maxDepth,
            'max_nodes' => $maxNodes,
            'path' => $path,
            'hops' => max(0, count($path) - 1),
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function codeExplain(array $arguments): array
    {
        $tool = 'atlas_code_explain';
        $model = $this->resolveGraphModel($arguments);
        if ($model === null) {
            return $this->noGraph($tool, $arguments);
        }

        $node = $this->resolveStartNode($model, $arguments);
        if ($node === null) {
            return [
                'ok' => false,
                'tool' => $tool,
                'world_model_id' => $this->sanitizeGraphText($model->model_id),
                'error' => 'node_not_found',
                'generated_at' => now()->toJSON(),
            ];
        }

        $maxNodes = $this->traversalMaxNodes();
        $edges = $this->edgesTouching($model, $node->node_id);
        // AP-815 B3: load only the adjacent nodes this call presents, not the whole graph.
        $adjacentIds = [];
        foreach ($edges as $edge) {
            $adjacentIds[] = $edge->from_node_id === $node->node_id ? $edge->to_node_id : $edge->from_node_id;
        }
        $nodeIndex = $this->nodeIndex($model, $adjacentIds);

        $outgoing = [];
        $incoming = [];
        foreach ($edges as $edge) {
            if ($edge->from_node_id === $node->node_id) {
                $other = $nodeIndex[$edge->to_node_id] ?? null;
                if (count($outgoing) < $maxNodes) {
                    $outgoing[] = [
                        'edge' => $this->presentEdge($edge),
                        'node' => $other instanceof AiCodebaseWorldModelNode
                            ? $this->presentNode($other)
                            : ['node_id' => $this->sanitizeGraphText($edge->to_node_id), 'resolved' => false],
                    ];
                }
            }
            if ($edge->to_node_id === $node->node_id) {
                $other = $nodeIndex[$edge->from_node_id] ?? null;
                if (count($incoming) < $maxNodes) {
                    $incoming[] = [
                        'edge' => $this->presentEdge($edge),
                        'node' => $other instanceof AiCodebaseWorldModelNode
                            ? $this->presentNode($other)
                            : ['node_id' => $this->sanitizeGraphText($edge->from_node_id), 'resolved' => false],
                    ];
                }
            }
        }

        return [
            'ok' => true,
            'tool' => $tool,
            'world_model_id' => $this->sanitizeGraphText($model->model_id),
            'node' => $this->presentNode($node),
            'outgoing_edges' => $outgoing,
            'incoming_edges' => $incoming,
            'outgoing_count' => count($outgoing),
            'incoming_count' => count($incoming),
            'degree' => $edges->count(),
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * Pick the world model to traverse. Precedence (most → least specific):
     *
     *  1. explicit world_model_id → that exact model (unchanged);
     *  2. workspace (W-3, additive) → that workspace's SYMBOL-level world model
     *     via {@see CodeGraphWorkspaceModelResolver}, so a second workspace's
     *     graph no longer silently shadows atlas-server's latest;
     *  3. neither → most-recent built globally (byte-identical to pre-W-3).
     *
     * Returns null when the graph tables are missing or no model exists.
     *
     * @param  array<string,mixed>  $arguments
     */
    private function resolveGraphModel(array $arguments): ?AiCodebaseWorldModel
    {
        if (! $this->graphTablesReady()) {
            return null;
        }

        $worldModelId = $this->string($arguments['world_model_id'] ?? null);
        if ($worldModelId !== null) {
            return AiCodebaseWorldModel::query()
                ->where('model_id', $worldModelId)
                ->first();
        }

        $workspace = $this->string($arguments['workspace'] ?? null);
        if ($workspace !== null) {
            return (new CodeGraphWorkspaceModelResolver)->symbolModel($workspace);
        }

        return AiCodebaseWorldModel::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Resolve a starting node from an explicit node_id (exact match) or, when
     * absent, a textual query routed through the read-only ranker.
     *
     * @param  array<string,mixed>  $arguments
     */
    private function resolveStartNode(AiCodebaseWorldModel $model, array $arguments): ?AiCodebaseWorldModelNode
    {
        $nodeId = $this->string($arguments['node_id'] ?? null);
        if ($nodeId !== null) {
            $node = AiCodebaseWorldModelNode::query()
                ->where('world_model_id', $model->id)
                ->where('node_id', $nodeId)
                ->first();
            if ($node !== null) {
                return $node;
            }
        }

        $query = $this->string($arguments['query'] ?? null);
        if ($query === null) {
            return null;
        }

        // When the query already looks like an exact node id, prefer that.
        $byId = AiCodebaseWorldModelNode::query()
            ->where('world_model_id', $model->id)
            ->where('node_id', $query)
            ->first();
        if ($byId !== null) {
            return $byId;
        }

        $ranking = (new WorldModelGraphRanker)->rank(WorldModelRankingQuery::fromArray([
            'textual_seeds' => [$query],
            'world_model_id' => $model->model_id,
            'max_results' => 1,
        ]));
        $topNodeId = data_get($ranking, 'ranked_nodes.0.node_id');
        if (! is_string($topNodeId) || $topNodeId === '') {
            return null;
        }

        return AiCodebaseWorldModelNode::query()
            ->where('world_model_id', $model->id)
            ->where('node_id', $topNodeId)
            ->first();
    }

    /**
     * @return Collection<int,AiCodebaseWorldModelEdge>
     */
    private function edgesTouching(AiCodebaseWorldModel $model, string $nodeId): Collection
    {
        // AP-815 B1: two index-seekable queries UNION'd, instead of a (from=? OR to=?)
        // predicate that no single composite index can serve. Each side hits the
        // (world_model_id, from_node_id) / (…, to_node_id) composite index directly.
        // unionAll (NOT union): a UNION's implicit DISTINCT makes pgsql compare every
        // selected column for equality, and the `metadata` json column has no `=`
        // operator (SQLSTATE 42883) — so we union-ALL and dedup the self-loop in PHP.
        // Structural backstop: the 2026_06_09_130000 migration converts these json
        // columns to jsonb (which HAS `=`), so the whole 42883 class is closed too.
        $incoming = AiCodebaseWorldModelEdge::query()
            ->where('world_model_id', $model->id)
            ->where('to_node_id', $nodeId);

        return AiCodebaseWorldModelEdge::query()
            ->where('world_model_id', $model->id)
            ->where('from_node_id', $nodeId)
            ->unionAll($incoming)
            ->get()
            ->unique('id')
            ->sort(static fn (AiCodebaseWorldModelEdge $a, AiCodebaseWorldModelEdge $b): int => [$a->from_node_id, $a->to_node_id, $a->edge_type] <=> [$b->from_node_id, $b->to_node_id, $b->edge_type])
            ->values();
    }

    /** @var array<string,array<string,array<int,string>>> AP-815 B2: per-request adjacency memo, keyed by model id. */
    private array $adjacencyCache = [];

    /** @var array<string,array<string,AiCodebaseWorldModelNode>> AP-815 B3: per-request full node-index memo, keyed by model id. */
    private array $nodeIndexCache = [];

    /**
     * AP-815 B3: resolve graph nodes. With $nodeIds, fetch ONLY those (a traversal
     * visits ≤ max_nodes, not the whole graph); without, load all once and memoize so
     * repeated traversal calls in a request don't reload the entire node set.
     *
     * @param  array<int,string>|null  $nodeIds
     * @return array<string,AiCodebaseWorldModelNode>
     */
    private function nodeIndex(AiCodebaseWorldModel $model, ?array $nodeIds = null): array
    {
        if ($nodeIds !== null) {
            $nodeIds = array_values(array_unique(array_filter(
                $nodeIds,
                static fn ($id): bool => is_string($id) && $id !== '',
            )));
            if ($nodeIds === []) {
                return [];
            }

            return AiCodebaseWorldModelNode::query()
                ->where('world_model_id', $model->id)
                ->whereIn('node_id', $nodeIds)
                ->get()
                ->keyBy('node_id')
                ->all();
        }

        $key = (string) $model->id;
        if (array_key_exists($key, $this->nodeIndexCache)) {
            return $this->nodeIndexCache[$key];
        }

        return $this->nodeIndexCache[$key] = AiCodebaseWorldModelNode::query()
            ->where('world_model_id', $model->id)
            ->get()
            ->keyBy('node_id')
            ->all();
    }

    /**
     * Undirected adjacency map (both edge directions are walkable for path finding).
     *
     * AP-815 B2: built ONCE per request via the capped CodeGraphAdjacencyIndex (D-1)
     * and memoized — codeNeighbors/codePath/codeExplain on one model share a single
     * build instead of re-reading the full edge table every call.
     *
     * @return array<string,array<int,string>>
     */
    private function adjacency(AiCodebaseWorldModel $model): array
    {
        $key = (string) $model->id;
        if (array_key_exists($key, $this->adjacencyCache)) {
            return $this->adjacencyCache[$key];
        }

        $index = CodeGraphAdjacencyIndex::fromEdges(
            AiCodebaseWorldModelEdge::query()
                ->where('world_model_id', $model->id)
                ->get(['from_node_id', 'to_node_id'])
                ->map(static fn (AiCodebaseWorldModelEdge $edge): array => [
                    'from_node_id' => $edge->from_node_id,
                    'to_node_id' => $edge->to_node_id,
                ])
                ->all(),
        );

        $adjacency = [];
        foreach ($index->nodes() as $nodeId) {
            $adjacency[$nodeId] = array_values(array_unique(array_merge(
                $index->neighbors($nodeId),
                $index->incoming($nodeId),
            )));
        }

        return $this->adjacencyCache[$key] = $adjacency;
    }

    /**
     * Bounded BFS shortest path. Honours both the depth and node-budget caps.
     *
     * @param  array<string,array<int,string>>  $adjacency
     * @return array<int,string>|null
     */
    private function bfsShortestPath(
        string $from,
        string $to,
        array $adjacency,
        int $maxDepth,
        int $maxNodes,
    ): ?array {
        if ($from === $to) {
            return [$from];
        }

        $visited = [$from => true];
        $parents = [];
        $queue = [[$from, 0]];
        $expanded = 0;

        while ($queue !== []) {
            [$current, $depth] = array_shift($queue);
            if ($depth >= $maxDepth) {
                continue;
            }
            if (++$expanded > $maxNodes) {
                break;
            }
            foreach ($adjacency[$current] ?? [] as $next) {
                if (isset($visited[$next])) {
                    continue;
                }
                $visited[$next] = true;
                $parents[$next] = $current;
                if ($next === $to) {
                    return $this->reconstructPath($parents, $from, $to);
                }
                $queue[] = [$next, $depth + 1];
                if (count($visited) > $maxNodes) {
                    break 2;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string,string>  $parents
     * @return array<int,string>
     */
    private function reconstructPath(array $parents, string $from, string $to): array
    {
        $path = [$to];
        $cursor = $to;
        while ($cursor !== $from && isset($parents[$cursor])) {
            $cursor = $parents[$cursor];
            $path[] = $cursor;
        }

        return array_reverse($path);
    }

    /**
     * @return array<string,mixed>
     */
    private function presentNode(AiCodebaseWorldModelNode $node): array
    {
        return [
            'node_id' => $this->sanitizeGraphText($node->node_id),
            'node_type' => $this->sanitizeGraphText($node->node_type),
            'path' => $this->sanitizeGraphText($node->path),
            'flow_id' => $this->sanitizeGraphText($node->flow_id),
            'capabilities' => $this->sanitizeGraphList((array) ($node->capabilities ?? [])),
            'risks' => $this->sanitizeGraphList((array) ($node->risks ?? [])),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function presentEdge(AiCodebaseWorldModelEdge $edge): array
    {
        $metadata = is_array($edge->metadata) ? $edge->metadata : [];

        return [
            'from_node_id' => $this->sanitizeGraphText($edge->from_node_id),
            'to_node_id' => $this->sanitizeGraphText($edge->to_node_id),
            'edge_type' => $this->sanitizeGraphText($edge->edge_type),
            'confidence' => $this->sanitizeGraphText(
                is_scalar($metadata['confidence'] ?? null) ? (string) $metadata['confidence'] : null,
            ),
            'inferred' => (bool) ($metadata['inferred'] ?? false),
        ];
    }

    /**
     * Graceful "no graph" response — never throws when nothing is built.
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function noGraph(string $tool, array $arguments): array
    {
        return [
            'ok' => true,
            'tool' => $tool,
            'graph_available' => false,
            'reason' => $this->graphTablesReady() ? 'no_world_model_built' : 'world_model_tables_missing',
            'message' => 'No code world model has been built yet. Build one before traversing the code graph.',
            'world_model_id' => $this->sanitizeGraphText($this->string($arguments['world_model_id'] ?? null)),
            'generated_at' => now()->toJSON(),
        ];
    }

    private function graphTablesReady(): bool
    {
        try {
            return Schema::hasTable('ai_codebase_world_models')
                && Schema::hasTable('ai_codebase_world_model_nodes')
                && Schema::hasTable('ai_codebase_world_model_edges');
        } catch (Throwable) {
            return false;
        }
    }

    private function traversalMaxDepth(): int
    {
        return max(1, (int) config('atlas.code_graph.traversal_max_depth', 4));
    }

    private function traversalMaxNodes(): int
    {
        return max(1, (int) config('atlas.code_graph.traversal_max_nodes', 60));
    }

    /**
     * Provider-safe sanitization for every graph-derived string that leaves
     * the service: the same secret/credential redaction used elsewhere, plus
     * a control-char strip and a hard length cap so graph text cannot smuggle
     * payloads or blow up the response.
     */
    private function sanitizeGraphText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_scalar($value)) {
            return null;
        }
        $string = (string) $value;
        $string = AtlasSecurity::redactString($string);
        $string = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $string) ?? $string;
        $string = trim($string);
        if ($string === '') {
            return null;
        }
        if (mb_strlen($string) > 512) {
            $string = mb_substr($string, 0, 512).'…';
        }

        return $string;
    }

    /**
     * @param  array<int,mixed>  $values
     * @return array<int,string>
     */
    private function sanitizeGraphList(array $values): array
    {
        $clean = [];
        foreach ($values as $value) {
            $sanitized = $this->sanitizeGraphText($value);
            if ($sanitized !== null) {
                $clean[] = $sanitized;
            }
        }

        return array_values(array_unique($clean));
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
