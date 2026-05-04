<?php

namespace App\Services\Ai;

use App\Models\AtlasMemoryEntry;
use App\Models\AtlasOpenBrainAccessLog;
use App\Models\AtlasVerbatimMemory;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use App\Services\Engineering\EngineeringKnowledgeBaseService;
use Illuminate\Support\Facades\Schema;
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
                'atlas_workspace_info' => $this->toolResponse($id, $this->workspaceInfo($arguments)),
                'atlas_recent_changes' => $this->toolResponse($id, $this->recentChanges($arguments)),
                'atlas_decision_query' => $this->toolResponse($id, $this->decisionQuery($arguments)),
                'atlas_task_start' => $this->toolResponse($id, $this->taskStart($arguments)),
                'atlas_task_progress' => $this->toolResponse($id, $this->taskProgress($arguments)),
                'atlas_task_complete' => $this->toolResponse($id, $this->taskComplete($arguments)),
                'atlas_memory_archive' => $this->toolResponse($id, $this->memoryArchive($arguments)),
                'atlas_memory_link' => $this->toolResponse($id, $this->memoryLink($arguments)),
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

        $limit = min(100, max(1, (int) ($arguments['limit'] ?? 20)));
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

        $limit = min(50, max(1, (int) ($arguments['limit'] ?? 10)));
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
        if ($workspace === null || ! is_dir($workspace . '/.git')) {
            return ['ok' => false, 'tool' => 'atlas_recent_changes', 'error' => 'workspace_not_git_repo'];
        }

        $since = $this->string($arguments['since'] ?? null) ?: '7 days ago';
        $limit = min(200, max(1, (int) ($arguments['limit'] ?? 50)));

        $process = new \Symfony\Component\Process\Process(
            ['git', 'log', '--name-only', '--pretty=format:', '--since=' . $since],
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
            $indexFresh = \Illuminate\Support\Carbon::parse($lastIndexAt)
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

        $options = ['limit' => min(20, max(1, (int) ($arguments['limit'] ?? 5)))];

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

        $task = \App\Models\AtlasTask::create([
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

        $task = \App\Models\AtlasTask::find($taskId);
        if ($task === null) {
            return ['ok' => false, 'tool' => 'atlas_task_progress', 'error' => 'task_not_found'];
        }

        $event = \App\Models\AtlasTaskEvent::create([
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

        $task = \App\Models\AtlasTask::find($taskId);
        if ($task === null) {
            return ['ok' => false, 'tool' => 'atlas_task_complete', 'error' => 'task_not_found'];
        }

        $task->update([
            'status' => 'done',
            'completed_at' => now(),
        ]);

        $event = \App\Models\AtlasTaskEvent::create([
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

        if (! in_array($type, \App\Models\AtlasMemoryEntryRelation::TYPES, true)) {
            return ['ok' => false, 'tool' => 'atlas_memory_link', 'error' => 'invalid_relation_type'];
        }

        if ($sourceId === $targetId) {
            return ['ok' => false, 'tool' => 'atlas_memory_link', 'error' => 'cannot_link_to_self'];
        }

        $relation = \App\Models\AtlasMemoryEntryRelation::create([
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

    private function workspace(mixed $workspace): ?string
    {
        $workspace = $this->string($workspace) ?: (config('atlas.ai.workdir') ?: null);
        if ($workspace === null) {
            return base_path();
        }

        return realpath($workspace) ?: $workspace;
    }
}
