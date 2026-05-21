---
id: atlas-tool-economy
type: engineering_knowledge
title: Atlas Tool Economy
status: active
category: atlas-ai
priority: 100
summary: Politica e runtime para decidir quando usar ferramenta pronta, comprar, chamar API, clonar repositorio, adaptar, criar ferramenta propria, evoluir ou aposentar ferramentas.
tags:
  - atlas-ai
  - tools
  - tool-economy
  - tool-builder
capabilities:
  - tool_discovery
  - build_vs_buy
  - repo_evaluation
  - tool_creation
  - tool_evolution
decisions:
  - Ferramenta e ativo operacional do Atlas, nao improviso por prompt.
  - Usar pronto quando confiavel; criar proprio quando controle, seguranca ou eficiencia justificarem.
maintenance:
  - Atualize este doc antes de mudar regra de tool use ou tool builder.
related_paths:
  - docs/engineering-knowledge-base/atlas-autonomous-intelligence-operating-system.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-tool-economy
graph_title: Atlas Tool Economy
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-autonomous-intelligence-operating-system
graph_status: active
graph_source: repo
human_name: Atlas Tool Economy
canonical_name: Atlas Tool Economy
technical_name: atlas-tool-economy
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-tool-economy.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-tool-economy.md
allowed_changes:
  - Adicionar criterios de selecao, seguranca e custo.
forbidden_changes:
  - Clonar ou executar ferramenta sem avaliar risco.
depends_on:
  - atlas-permission-budget-safety-layer
flows_to:
  - atlas-world-model
  - atlas-experimentation-engine
unlocks:
  - atlas-tool-builder-runtime
governs:
  - atlas_ai.tool_selection
evidence:
  - docs/engineering-knowledge-base/atlas-tool-economy.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: critical
ai_entrypoints:
  - Leia Tool Decision Matrix antes de implementar tool use.
quality_gates:
  - tool-need-proven
  - alternatives-compared
  - safety-reviewed
  - validation-defined
failure_modes:
  - Criar ferramenta desnecessaria.
  - Usar repo inseguro.
  - Comprar ferramenta sem budget.
observability_signals:
  - tool_id
  - decision
  - tool_score
  - validation_status
next_actions:
  - Meta 5 entregue (2026-05-18): backend Tool Registry/Catalog/Planning/Invocation/Receipt/Health/Validation/Readiness/ControlPlane em ai_tool_* + comando atlas:ai:tool-runtime.
  - Ligar Tool Planning ao Atlas Router/Intent para selection automatica por mission/work_order.
  - Substituir mock executor por executors reais (filesystem, browser headless, gh CLI) atras do mesmo contrato, sob policy gate e evidence receipt.
  - Tool Builder/Evolution Loop: ingestao, evaluation, scoring, build-vs-buy decision e retirement (Meta 6+).
line_limit: 520
---
# Atlas Tool Economy

## Resumo

Tool Economy decide a melhor forma de obter capacidade: usar ferramenta pronta,
comprar, chamar API, clonar repo, adaptar codigo, criar ferramenta propria ou
aposentar ferramenta ruim.

## Papel no Atlas

Fica entre objetivo e execucao. Toda missao que exige ferramenta passa por essa
camada antes de browser, terminal, GitHub, API ou tool builder.

## Onde Se Encaixa

```text
Mission -> Tool Need -> Tool Economy -> Tool Runtime -> Evidence
```

## Contratos

- `atlas.ai.tool.need.v1`
- `atlas.ai.tool.decision.v1`
- `atlas.ai.tool.evaluation.v1`
- `atlas.ai.tool.receipt.v1`
- `atlas.ai.tool.evolution.v1`

## Fluxo

1. Identificar necessidade de ferramenta.
2. Buscar ferramenta existente.
3. Avaliar API, SaaS, CLI, repo, biblioteca e ferramenta interna.
4. Comparar custo, confiabilidade, licenca, seguranca e velocidade.
5. Decidir usar, comprar, clonar, adaptar, criar ou nao usar.
6. Definir validacao.
7. Executar com receipt.
8. Registrar aprendizado e evolucao.

## Tool Decision Matrix

- `use_existing`: confiavel, barato, rapido, seguro.
- `buy_or_subscribe`: melhor ROI, budget aprovado.
- `api_call`: API oficial cobre objetivo.
- `clone_repo`: repo confiavel, licenca ok, teste possivel.
- `adapt_open_source`: precisa ajuste local.
- `build_internal`: requisito unico, seguranca alta ou vantagem estrategica.
- `manual_fallback`: automacao arriscada ou login/ToS bloqueia.
- `do_not_use`: risco maior que beneficio.

## Regras para IA

- Nao usar ferramenta so porque existe.
- Nao criar ferramenta propria sem justificar.
- Nao clonar repo sem licenca, atividade, security e teste.
- Nao rodar CLI desconhecida sem sandbox.
- Nao pagar ferramenta sem budget aprovado.
- Nao usar API nao oficial quando API oficial resolve.

## Escopo de Implementacao

Tool Registry, evaluator, build-vs-buy scorer, repo auditor, execution receipt,
tool memory e evolution backlog.

Meta 5 entregue (2026-05-18):

- tabelas `ai_tool_definitions / ai_tool_capabilities / ai_tool_plans / ai_tool_invocations / ai_tool_receipts / ai_tool_health_checks / ai_tool_validation_runs`.
- services em `app/Services/Ai/ToolRuntime/`: Registry, CapabilityCatalog, Planning, PolicyBridge, Invocation, Receipt, Health, Validation, Readiness, ControlPlane.
- comando `atlas:ai:tool-runtime --action=readiness|seed-defaults|list|plan|doctor|smoke|validate|show|control-plane`.
- seeds: `filesystem.read`, `command.local_readonly`, `docs.search`, `github.readonly`, `browser.readonly`, `api.readonly`, `artifact.write_local`, `test.local_command`, `evidence.attach`, `policy.evaluate`.
- bridges tolerantes para Meta 3 (PermissionGateService) e Meta 4 (ReceiptService): se ausentes, Tool Runtime opera com fallback seguro e readiness denuncia.
- Tool Runtime nao executa acao externa real: high-risk `authority_group` (external_action, financial_action, security_sensitive) sem `allow` da policy fica `blocked` sem output.

## Dependencias

- Permission, Budget & Safety Layer.
- World Model.
- Evidence & Truth Layer.

## Evidencias

Lista de alternativas, score, decisao, justificativa, custo, riscos, licenca,
comandos/API usados, teste de validacao e receipt.

## Riscos

- Supply chain.
- Custo escondido.
- Licenca incompatível.
- Fragilidade de automacao.
- Dependencia de SaaS.

## Exemplos

Para Instagram: checar API oficial, browser automation, politicas, login,
rate limit e fallback manual antes de tentar scraping.

## Proximas Acoes

1. Criar `ai_tool_registry`.
2. Criar evaluator.
3. Criar tests de build-vs-buy.

## Definition of Done

Esta pronto quando toda decisao de ferramenta tem alternativas, score,
justificativa, safety, evidencia e validacao.

