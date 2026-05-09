---
title: Local RAG Graph Promotion Review
status: implemented_partial
owner: Atlas Context Runtime
line_limit: 160
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-local-performance-memory-strategy.md
  - docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md
  - app/Services/Ai/Context/ContextRetrievalRouter.php
  - app/Services/Ai/Context/LocalRagBenchmarkService.php
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php
---

# AP-683 - Local RAG Graph Promotion Review

## 0. Implementation Note

AP-683 esta implementado como review gate read-only/proposal-only:
`atlas:ai:local-rag-readiness`, `atlas:ai:local-rag-benchmark`,
contrato `LOCAL_RAG_*` no Evidence Ledger e finding do
`self_improvement.docs_drift_review` ja existem. O que continua bloqueado e a
promocao real de Graph RAG/Python: ela exige review humano/Curator, corpus real,
Decision Receipt, policy patch revisavel e AP futuro.

## 1. Proposito

Formalizar a revisao que decide se o sucesso do Local RAG controlado merece um AP futuro de Graph RAG/Python runtime, sem promover runtime automaticamente.

## 2. Escopo Implementado

- contrato documental para a decisao de promocao Local RAG -> Graph RAG;
- comandos `atlas:ai:local-rag-readiness` e `atlas:ai:local-rag-benchmark`;
- contrato `LOCAL_RAG_*` no Evidence Ledger sem persistir query/contexto bruto;
- fonte canonica para o finding `Revisar promocao de Graph RAG/Python`;
- finding `proposal_only` em `self_improvement.docs_drift_review`;
- guardrail para impedir que `LOCAL_RAG_GRAPH_PROMOTION_BLOCKED` seja tratado como permissao;
- criterio minimo para criar um AP futuro de Graph RAG/Python, se aprovado.

## 3. Autoridade

Schema: `atlas.local_rag_graph_promotion_review.v1`  
Modo: `proposal_gate_scaffold`  
Autoridade: `local_rag_graph_promotion_requires_human_review_and_future_ap`

Este AP e subordinado ao Kernel, ao Context Retrieval Router, ao Evidence Ledger e a estrategia local de memoria/performance.

## 4. Fluxo Canonico

1. Rodar `php artisan atlas:ai:local-rag-readiness --json`.
2. Rodar `php artisan atlas:ai:local-rag-benchmark --json`.
3. Exigir `status=passed`, `quality_corpus.status=passed` e eventos `LOCAL_RAG_*`.
4. Manter `graph_retrieval.available=false` enquanto nao houver review.
5. Rodar `php artisan atlas:ai:self-improve --flow=docs_drift_review --json`.
6. O Curator emite proposta `proposal_only`, nunca policy patch aplicado.
7. Humano/Curator escolhe: manter bloqueado, ampliar corpus ou criar AP futuro.
8. Se aprovado, criar novo AP para Graph RAG/Python com Decision Receipt, policy patch, corpus real e rollback.

## 5. Regras

- benchmark verde nao autoriza Graph RAG;
- Python pode reranquear, indexar e calcular, mas Laravel/Kernel decide;
- Graph RAG nao pode criar Memory Core, Ledger ou Context Builder paralelo;
- nenhuma surface pode chamar Graph RAG diretamente;
- todo candidato de promocao precisa declarar provider-safety, privacy, SLO, custo, freshness e replay;
- `LOCAL_RAG_GRAPH_PROMOTION_BLOCKED` so pode ser superseded por proposta revisada com evidencia.

## 6. Evidencia Minima

| Evidencia | Obrigatorio |
|---|---|
| `LOCAL_RAG_PLAN_CREATED` | sim |
| `LOCAL_RAG_QUALITY_CORPUS_EVALUATED` | sim |
| `LOCAL_RAG_GRAPH_PROMOTION_BLOCKED` | sim |
| corpus real alem do sintetico | antes do AP futuro |
| Decision Receipt | antes de qualquer runtime |
| human review | sempre |

## 7. Nao Escopo

- nao implementar Graph RAG;
- nao criar servico Python;
- nao alterar `ContextRetrievalRouter` para `available=true`;
- nao adicionar modelo local;
- nao aplicar policy patch automaticamente;
- nao persistir query, documento ou contexto bruto no Ledger.

## 8. Definition of Done

- finding de Self-Improvement referencia este AP;
- docs de Local Performance apontam este review gate;
- matriz implemented-vs-scaffold lista AP-683 como implementado parcial;
- testes provam proposta `proposal_only`;
- Architecture Operations lista readiness e benchmark como operacoes canônicas;
- `php artisan atlas:ai:architecture-validate --json` retorna `ok`;
- `atlas engineering knowledge docs-health --json` retorna `ok`;
- `git diff --check` passa.
