---
id: atlas-hybrid-retrieval-infrastructure
type: engineering_knowledge
title: Atlas Hybrid Retrieval Infrastructure
status: building
implementation_state: building_read_only_retrieval_report_runtime
blocker: AHRI possui runtime read-only para source plan, candidate normalization, ASEF semantic candidates e report; ainda falta executar adapters profundos reais de docs/code/evidence e alimentar ACRS.
category: intelligence-runtime
priority: 99
summary: Doc filha AUCRI para retrieval hibrido: lexical, vector, memory, docs, evidence, code refs, attachments e source adapters sob um contrato unico.
tags: [atlas-ai, aucri, ahri, hybrid-retrieval, rag, context]
capabilities: [hybrid_retrieval, source_adapters, context_refs, retrieval_report]
decisions:
  - AHRI coleta candidatos de multiplas fontes; nao ranqueia sozinho como decisor final.
  - Retrieval deve ser provider-safe e auditavel por report.
maintenance:
  - Atualizar quando adapters, source types ou retrieval report mudarem.
related_paths:
  - docs/engineering-knowledge-base/atlas-unified-context-retrieval-intelligence.md
  - docs/engineering-knowledge-base/atlas-context-intelligence-engine.md
  - docs/engineering-knowledge-base/atlas-persistent-context-runtime.md
  - app/Services/Ai/Context/ContextRetrievalRouter.php
  - app/Services/Ai/AtlasHybridMemoryRetrievalService.php
  - app/Services/Ai/Context/AtlasHybridRetrievalInfrastructureService.php
  - app/Console/Commands/AtlasHybridRetrievalInfrastructureCommand.php
  - tests/Feature/Ai/Context/HybridRetrievalInfrastructureTest.php
doc_schema: atlas_canonical_module_doc.v1
macro_layer: true
product_name: Atlas Hybrid Retrieval Infrastructure
runtime_acronym: AHRI
internal_product_name: Atlas Retrieval Core
technical_runtime: AtlasHybridRetrievalInfrastructureService
graph_id: atlas-hybrid-retrieval-infrastructure
graph_title: Atlas Hybrid Retrieval Infrastructure
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-unified-context-retrieval-intelligence
graph_status: building
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-hybrid-retrieval-infrastructure.md
allowed_changes:
  - Adicionar adapters e reports de retrieval.
forbidden_changes:
  - Bypassar APCR/ACIE.
  - Misturar fonte raw sem privacy classification.
depends_on: [atlas-semantic-embedding-foundation, atlas-persistent-context-runtime, atlas-context-intelligence-engine]
flows_to: [atlas-agentic-rag-framework, atlas-context-ranking-system]
unlocks: [cross_domain_retrieval, unified_source_plan]
governs: [retrieval_sources, source_adapters]
evidence:
  - docs/engineering-knowledge-base/atlas-hybrid-retrieval-infrastructure.md
  - app/Services/Ai/Context/AtlasHybridRetrievalInfrastructureService.php
  - app/Console/Commands/AtlasHybridRetrievalInfrastructureCommand.php
  - tests/Feature/Ai/Context/HybridRetrievalInfrastructureTest.php
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:context:hybrid-retrieval --json"
  - "php artisan test tests/Feature/Ai/Context/HybridRetrievalInfrastructureTest.php"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Conectar AHRI ao ACRS como entrada de ranking.
  - Trocar candidatos shallow por adapters profundos de docs/code/evidence sem duplicar stores.
---

# Atlas Hybrid Retrieval Infrastructure

## Resumo

AHRI e o bloco 2 da AUCRI. Ele unifica a coleta de candidatos de contexto:
lexical, embeddings, memoria, docs canonicos, evidence, code refs, anexos,
receipts e future graph candidates.

## Papel no Atlas

Ser o motor de coleta. Ele alimenta AARF, ACRS e ACIE com candidatos
normalizados e auditaveis.

Estado atual: `AtlasHybridRetrievalInfrastructureService` emite
`atlas.aucri.retrieval_report.v1` read-only com source plan do
`ContextRetrievalRouter`, candidatos ASEF, context refs explicitos, dedupe,
misses, excluded refs e policy sem provider externo. O comando canonico e
`php artisan atlas:context:hybrid-retrieval --json`.

## Onde Se Encaixa

```text
source plan -> adapters -> candidates -> retrieval report -> ACRS
```

## Contratos

- `atlas.aucri.source_plan.v1`
- `atlas.aucri.retrieval_candidate.v1`
- `atlas.aucri.retrieval_report.v1`
- `atlas.aucri.source_adapter_receipt.v1`

## Fluxo

1. Receber objective/domain/flow/risk.
2. Acionar adapters permitidos.
3. Normalizar candidatos.
4. Deduplicar por source/ref/hash.
5. Anexar privacy, authority, freshness.
6. Emitir retrieval report.

## Regras para IA

- Nao adicionar source sem owner doc.
- Nao retornar candidato sem `reason`.
- Nao consultar provider externo por default.

## Escopo de Implementacao

Service unificado read-only, normalizador de candidatos, adapters shallow para
source plan/context refs/ASEF, tests por source e command readiness. Proxima
fase troca adapters shallow por adapters profundos reais de memory/docs/code e
evidence.

## Dependencias

ASEF, APCR, ACIE, Memory/Open Brain, Evidence Ledger.

## Evidencias

Retrieval report com fontes, candidates, misses, excluded refs, policy e hash.

## Riscos

Muitos candidatos, duplicacao de contexto, fonte privada entrando no pack.

## Exemplos

Prompt de debug busca arquivos, tests, receipts, known failures e docs.

## Proximas Acoes

1. Conectar report AHRI ao ACRS.
2. Criar adapters profundos reuse-first para docs/code/evidence.
3. Cobrir cross-domain sem quebrar Programming RAG.
