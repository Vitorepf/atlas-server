---
id: atlas-retrieval-cost-latency-governor
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Retrieval Cost & Latency Governor
status: building
implementation_state: runtime_surface_budget_governor_ready
blocker: ARCLG possui service, command, budget policy, receipt, cache decision e testes; ainda falta historico longitudinal no ACOP e cache real persistido.
category: context_retrieval_intelligence
priority: 94
summary: "Governador de custo, latencia, cache e degradacao progressiva para retrieval sem perder fontes criticas."
tags: [atlas-ai, aucri, arclg, retrieval-budget, latency, cost]
capabilities: [retrieval_budgeting, latency_governance, cache_decision, degraded_mode]
decisions:
  - Custo e latencia nao podem remover fonte critica silenciosamente.
  - Degraded mode precisa de receipt e quality floor.
  - Cache so vale quando freshness e autoridade continuam validas.
maintenance:
  - Atualizar antes de mudar budgets, cache policy, degraded modes ou SLOs.
product_name: Atlas Retrieval Cost & Latency Governor
runtime_acronym: ARCLG
internal_product_name: Atlas Retrieval Budget Controller
technical_runtime: AtlasRetrievalCostLatencyGovernorService
macro_layer: true
graph_id: atlas-retrieval-cost-latency-governor
graph_title: Atlas Retrieval Cost & Latency Governor
graph_world: atlas
graph_parent: atlas-unified-context-retrieval-intelligence
graph_layer: module
graph_kind: module
graph_status: planned
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-unified-context-retrieval-intelligence.md
  - docs/engineering-knowledge-base/atlas-retrieval-evaluation-benchmark-arena.md
  - app/Services/Ai/Context/AtlasRetrievalCostLatencyGovernorService.php
  - app/Console/Commands/AtlasRetrievalCostLatencyGovernorCommand.php
  - tests/Feature/Ai/Context/RetrievalCostLatencyGovernorTest.php
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-runtime-efficiency-governor.md
allowed_changes:
  - Criar budgets por risco, dominio, flow e surface.
  - Adicionar cache e degraded modes auditaveis.
forbidden_changes:
  - Cortar fonte obrigatoria sem registrar blocker.
  - Otimizar custo acima de safety/evidence.
depends_on:
  - atlas-retrieval-evaluation-benchmark-arena
flows_to:
  - atlas-context-observability-plane
unlocks: [retrieval_budget_control, safe_degraded_retrieval]
governs: [retrieval_cost, retrieval_latency, context_budget]
evidence:
  - docs/engineering-knowledge-base/atlas-retrieval-cost-latency-governor.md
  - app/Services/Ai/Context/AtlasRetrievalCostLatencyGovernorService.php
  - app/Console/Commands/AtlasRetrievalCostLatencyGovernorCommand.php
  - tests/Feature/Ai/Context/RetrievalCostLatencyGovernorTest.php
evidence_refs:
  - symbol: AtlasRetrievalCostLatencyGovernorService
  - command: atlas:context:retrieval-budget
  - test: RetrievalCostLatencyGovernorTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:context:retrieval-budget --json"
  - "php artisan test tests/Feature/Ai/Context/RetrievalCostLatencyGovernorTest.php"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Integrar receipts ao ACOP e adicionar cache persistido com freshness.
---

# Atlas Retrieval Cost & Latency Governor

## Resumo

ARCLG controla quanto AUCRI pode gastar em contexto, busca, reranking,
embedding, graph traversal e leitura de fontes. O objetivo e manter velocidade e
custo sem sacrificar evidencias criticas.

## Papel no Atlas

Ele transforma retrieval em operacao com orcamento. O Atlas deve saber quando
buscar mais, quando usar cache, quando degradar e quando bloquear por contexto
insuficiente.

## Onde Se Encaixa

Fica depois de AREBA. Primeiro mede-se qualidade, depois governa-se custo e
latencia. Integra com AREG quando a sessao precisa controlar budget cognitivo.

## Contratos

- `atlas.aucri.retrieval_cost_latency_governor.v1`
- `atlas.aucri.retrieval_budget_policy.v1`
- `atlas.aucri.retrieval_cost_latency_receipt.v1`
- `atlas.aucri.retrieval_cache_decision.v1`
- `atlas.aucri.retrieval_degraded_mode.v1`

Campos minimos: `flow_id`, `risk_level`, `budget_ms`, `budget_cost_units`,
`required_sources`, `cache_hit`, `degraded_reason`, `quality_floor`,
`blocked_reason`, `receipt_hash`.

## Fluxo

1. Classificar risco da tarefa.
2. Definir budget por flow e surface.
3. Consultar cache valido e freshness.
4. Executar retrieval ate atingir sufficiency ou budget.
5. Se budget acabar sem contexto suficiente, bloquear ou pedir nova passagem.
6. Emitir receipt com custo, latencia e tradeoff.

## Regras para IA

- Nao usar degraded mode em tarefa high-risk sem explicar perda.
- Nao repetir busca cara se cache valido cobre required refs.
- Nao resumir fonte critica para economizar se a decisao depende dela.
- Nao confundir rapido com correto.

## Escopo de Implementacao

Implementado policy de budget, cache decision, degraded mode com receipt,
command `atlas:context:retrieval-budget --json` e tests que bloqueiam quando
budget removeria fonte obrigatoria. Metrics no Control Plane entram no ACOP.

## Dependencias

Depende de AREBA para saber quality floor e de ACFQ para freshness/sufficiency.

## Evidencias

Evidencia minima atual: receipt com budget, custo estimado, latencia observada,
fontes removidas, fontes mantidas, cache decision e motivo de degradacao.

## Riscos

- Cache obsoleto parecer barato.
- Degraded mode virar padrao silencioso.
- Budget fixo ignorar risco real.
- Custo baixo mascarar decisao errada.

## Exemplos

Pergunta simples pode usar lexical + memory cache. Obra Forge com risco alto
deve pagar retrieval amplo e bloquear se fonte obrigatoria faltar.

## Proximas Acoes

1. Integrar receipts no ACOP.
2. Adicionar historico de latencia/custo por flow.
3. Criar cache persistido depois de ARPTL.
4. Usar ATER para conectar token budget ao budget de retrieval.
