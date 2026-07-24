<?php

declare(strict_types=1);

namespace App\Services\Ai\OpenBrainMcp;

/**
 * MCP tool definitions catalog (full-pass density extract).
 *
 * Behavior-preserving move of AtlasOpenBrainMcpService::tools() body.
 */
final class OpenBrainMcpToolCatalog
{
    public static function definitions(): array
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

        return $tools;
    }

}
