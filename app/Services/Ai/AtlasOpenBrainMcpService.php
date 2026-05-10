<?php

namespace App\Services\Ai;

use App\Models\AtlasMemoryEntry;
use App\Models\AtlasMemoryEntryRelation;
use App\Models\AtlasOpenBrainAccessLog;
use App\Models\AtlasTask;
use App\Models\AtlasTaskEvent;
use App\Models\AtlasVerbatimMemory;
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
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementScheduleService;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use App\Services\Engineering\EngineeringKnowledgeBaseService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Throwable;

class AtlasOpenBrainMcpService
{
    public const PROTOCOL_VERSION = '2025-06-18';

    public function __construct(
        private readonly AtlasHybridMemoryRetrievalService $recall,
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
    ) {}

    /**
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>|null
     */
    public function handleJsonRpc(array $request): ?array
    {
        $id = $request['id'] ?? null;
        $method = is_string($request['method'] ?? null) ? (string) $request['method'] : null;

        if ($method === null || $method === '') {
            return $this->error($id, -32600, 'Invalid JSON-RPC request.');
        }

        if (! array_key_exists('id', $request) && str_starts_with($method, 'notifications/')) {
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
                        'memory_type' => ['type' => 'string', 'description' => 'Tipo: decision, technical_context, harness_learning, preference, feedback, issue, resolution, benchmark_observation.'],
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
        $filters = array_filter([
            'q' => $query,
            'symbol_type' => $this->string($arguments['symbol_type'] ?? null),
            'language' => $this->string($arguments['language'] ?? null),
        ]);

        $result = $this->code->symbols($filters, $limit);
        $symbols = $result['symbols'] ?? [];

        return [
            'ok' => true,
            'tool' => 'atlas_code_find_relevant',
            'workspace' => $this->workspace($arguments['workspace'] ?? null),
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
        $title = $this->string($arguments['title'] ?? null);
        if ($title === null) {
            return ['ok' => false, 'tool' => 'atlas_task_start', 'error' => 'title_required'];
        }

        $task = AtlasTask::create([
            'title' => $title,
            'description' => $this->string($arguments['objective'] ?? null),
            'status' => 'open',
            'domain' => $this->string($arguments['domain'] ?? null) ?: 'dev',
            'project_id' => $this->string($arguments['project_id'] ?? null),
            'metadata' => array_merge(
                $this->object($arguments['metadata'] ?? []),
                ['workspace' => $this->workspace($arguments['workspace'] ?? null), 'source' => 'mcp_tool'],
            ),
        ]);

        return [
            'ok' => true,
            'tool' => 'atlas_task_start',
            'task_id' => (string) $task->id,
            'status' => $task->status,
            'created_at' => $task->created_at?->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function taskProgress(array $arguments): array
    {
        $taskId = $this->string($arguments['task_id'] ?? null);
        $milestone = $this->string($arguments['milestone'] ?? null);

        if ($taskId === null || $milestone === null) {
            return ['ok' => false, 'tool' => 'atlas_task_progress', 'error' => 'task_id_and_milestone_required'];
        }

        $task = AtlasTask::find($taskId);
        if ($task === null) {
            return ['ok' => false, 'tool' => 'atlas_task_progress', 'error' => 'task_not_found'];
        }

        $event = AtlasTaskEvent::create([
            'task_id' => $taskId,
            'event_type' => 'milestone',
            'source' => 'mcp_tool',
            'payload' => [
                'milestone' => $milestone,
                'details' => $this->string($arguments['details'] ?? null),
                'progress_pct' => isset($arguments['progress_pct']) ? (int) $arguments['progress_pct'] : null,
            ],
            'occurred_at' => now(),
        ]);

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
        $taskId = $this->string($arguments['task_id'] ?? null);
        if ($taskId === null) {
            return ['ok' => false, 'tool' => 'atlas_task_complete', 'error' => 'task_id_required'];
        }

        $task = AtlasTask::find($taskId);
        if ($task === null) {
            return ['ok' => false, 'tool' => 'atlas_task_complete', 'error' => 'task_not_found'];
        }

        $task->update([
            'status' => 'done',
            'completed_at' => now(),
        ]);

        $event = AtlasTaskEvent::create([
            'task_id' => $taskId,
            'event_type' => 'completed',
            'source' => 'mcp_tool',
            'payload' => [
                'summary' => $this->string($arguments['summary'] ?? null),
                'files_changed' => is_array($arguments['files_changed'] ?? null) ? $arguments['files_changed'] : [],
                'outcome' => $this->string($arguments['outcome'] ?? null) ?: 'success',
                'memory_entry_ids' => is_array($arguments['memory_entry_ids'] ?? null) ? $arguments['memory_entry_ids'] : [],
            ],
            'occurred_at' => now(),
        ]);

        return [
            'ok' => true,
            'tool' => 'atlas_task_complete',
            'task_id' => $taskId,
            'status' => 'done',
            'event_id' => (string) $event->id,
            'completed_at' => $task->completed_at?->toJSON(),
        ];
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
            $actions[] = './bin/atlas engineering knowledge index-code --prune'.$workspaceArg.' --json';
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
