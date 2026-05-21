---
id: atlas-retrieval-feedback-loop
type: engineering_knowledge
title: Atlas Retrieval Feedback Loop
status: building
implementation_state: building_aucri_feedback_wrapper
blocker: ARFL possui runtime AUCRI sobre ACFQ e AtlasRagFeedbackService; ainda falta ACOP/ACRS consumir feedback automaticamente.
category: intelligence-runtime
priority: 98
summary: Doc filha AUCRI para feedback de uso de contexto: refs uteis, ruido, misses, wrong-context, stale-context e outcome-aware retrieval learning.
tags: [atlas-ai, aucri, arfl, retrieval-feedback, compounding]
capabilities: [retrieval_feedback, missed_refs, noise_refs, context_roi, learning_candidate]
decisions:
  - Feedback de retrieval vira candidato governado, nao policy automatica.
maintenance:
  - Atualizar quando AEMOR/Compounding ou Memory feedback mudarem.
related_paths:
  - docs/engineering-knowledge-base/atlas-unified-context-retrieval-intelligence.md
  - docs/engineering-knowledge-base/atlas-execution-memory-outcome-runtime.md
  - app/Services/Ai/Context/AtlasRetrievalFeedbackLoopService.php
  - app/Console/Commands/AtlasRetrievalFeedbackLoopCommand.php
  - tests/Feature/Ai/Context/RetrievalFeedbackLoopTest.php
  - app/Services/Ai/Compounding/AtlasRagFeedbackService.php
doc_schema: atlas_canonical_module_doc.v1
macro_layer: true
product_name: Atlas Retrieval Feedback Loop
runtime_acronym: ARFL
internal_product_name: Atlas Context Learning
technical_runtime: AtlasRetrievalFeedbackLoopService
graph_id: atlas-retrieval-feedback-loop
graph_title: Atlas Retrieval Feedback Loop
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-unified-context-retrieval-intelligence
graph_status: building
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-retrieval-feedback-loop.md
allowed_changes:
  - Definir feedback events, candidates e promotion gates.
forbidden_changes:
  - Auto-promover aprendizado sem guard.
depends_on: [atlas-context-freshness-quality-gate, atlas-execution-memory-outcome-runtime]
flows_to: [atlas-context-ranking-system, atlas-semantic-embedding-foundation]
unlocks: [outcome_aware_retrieval, context_roi_learning]
governs: [retrieval_feedback, context_learning]
evidence:
  - docs/engineering-knowledge-base/atlas-retrieval-feedback-loop.md
  - app/Services/Ai/Context/AtlasRetrievalFeedbackLoopService.php
  - app/Console/Commands/AtlasRetrievalFeedbackLoopCommand.php
  - tests/Feature/Ai/Context/RetrievalFeedbackLoopTest.php
required_tests:
  - "php artisan test tests/Feature/Ai/Context/RetrievalFeedbackLoopTest.php"
  - "php artisan atlas:context:retrieval-feedback --query='debug repo with tests' --task-type=debug --domain=developer --json"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Integrar feedback ARFL ao ranking ACRS depois de ACOP/AREBA.
---

# Atlas Retrieval Feedback Loop

## Resumo

ARFL e o bloco 6 da AUCRI. Ele fecha o ciclo: quais contextos ajudaram, quais
atrapalharam, quais faltaram e quais devem influenciar ranking futuro.

## Papel no Atlas

Transformar outcome em melhoria de retrieval sem aprendizado falso.

## Onde Se Encaixa

```text
ACFQ -> execution outcome -> ARFL -> learning candidate -> ACRS/ASEF
```

## Contratos

- `atlas.aucri.feedback_event.v1`
- `atlas.aucri.context_roi.v1`
- `atlas.aucri.missed_ref_candidate.v1`
- `atlas.aucri.noise_ref_candidate.v1`
- `atlas.aucri.retrieval_learning_candidate.v1`

## Fluxo

1. Rodar ACFQ para capturar contexto aprovado ou bloqueado.
2. Comparar outcome com refs usadas, noise e misses.
3. Calcular context ROI.
4. Persistir `ai_rag_feedback_events` quando `record=true`.
5. Criar candidato review-only; nunca auto-promover.

## Regras para IA

- Nao aprender com falha sem causalidade.
- Nao promover feedback automatico para memoria.
- Nao esconder context noise.

## Escopo de Implementacao

`AtlasRetrievalFeedbackLoopService` e comando `atlas:context:retrieval-feedback`.
Ele reusa `AtlasRagFeedbackService`; score updates automaticos ficam proibidos
ate ACRS/AREBA/ACOP consumirem feedback com review.

## Dependencias

AEMOR, Compounding, ACFQ, ACRS, Evidence.

## Evidencias

Evidencias atuais:

- service: `AtlasRetrievalFeedbackLoopService`;
- command: `atlas:context:retrieval-feedback`;
- tests: `RetrievalFeedbackLoopTest`;
- persistence opcional: `ai_rag_feedback_events`.

## Riscos

Falso aprendizado, reinforcing bias, ranking piorar por feedback fraco,
promocao automatica indevida.

## Exemplos

Se um patch falha por doc ausente, ARFL registra missed_required_doc e gera
candidato `proposed` com `auto_apply=false`.

## Proximas Acoes

1. Fazer ACRS ler feedback aprovado.
2. Ligar ACOP para observabilidade de ROI/misses/noise.
