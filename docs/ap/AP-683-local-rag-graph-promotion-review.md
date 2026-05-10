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
eventos `LOCAL_RAG_*` sanitizados no Evidence Ledger e finding do
`self_improvement.docs_drift_review` ja existem. O benchmark agora emite
`promotion_review_contract` com schema `atlas.local_rag_graph_promotion_review.v1`
e `future_runtime_invocation_contract` do AP-201; Architecture Operations publica
`local_rag_graph_promotion_review`. O Curator falha fechado: se
`evidence_ledger.promotion_evidence_satisfied=false`, ele emite somente
`atlas.self_improvement.local_rag_graph_promotion_evidence_block.v1`, sem proposta
de promocao. O que continua bloqueado e a promocao real de Graph RAG/Python: ela
exige review humano/Curator, corpus real, Decision Receipt, policy patch revisavel
e AP futuro.

## 1. Proposito

Formalizar a revisao que decide se o sucesso do Local RAG controlado merece um AP futuro de Graph RAG/Python runtime, sem promover runtime automaticamente.

## 2. Escopo Implementado

- contrato documental para a decisao de promocao Local RAG -> Graph RAG;
- comandos `atlas:ai:local-rag-readiness` e `atlas:ai:local-rag-benchmark`;
- contrato e eventos `LOCAL_RAG_*` no Evidence Ledger sem query/contexto bruto;
- fonte canonica para o finding `Revisar promocao de Graph RAG/Python`;
- finding `proposal_only` em `self_improvement.docs_drift_review`;
- finding de bloqueio `local_rag_graph_promotion_evidence_block` quando o Ledger
  nao persistir os eventos `LOCAL_RAG_*`;
- `promotion_review_contract` machine-readable no benchmark;
- `review_packet` machine-readable com decisao humana, evidencias, rollback e
  proibicoes antes de Graph RAG/Python;
- operacao `local_rag_graph_promotion_review` no catalogo Architecture Operations;
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
3. Exigir `status=passed`, `quality_corpus.status=passed` e
   `evidence_ledger.status=persisted`; eventos `LOCAL_RAG_*` sem persistencia
   nao contam como evidencia de promocao.
4. Manter `graph_retrieval.available=false` enquanto nao houver review.
5. Rodar `php artisan atlas:ai:self-improve --flow=docs_drift_review --json`.
6. O Curator emite proposta `proposal_only`, nunca policy patch aplicado.
7. Humano/Curator escolhe: manter bloqueado, ampliar corpus ou criar AP futuro.
8. Se aprovado, criar novo AP para Graph RAG/Python com Decision Receipt, policy patch, corpus real e rollback.

## 5. Regras

- benchmark verde nao autoriza Graph RAG;
- `promotion_gate.promotion_allowed` deve ser sempre `false`;
- `promotion_review_contract.auto_promotion_allowed` deve ser sempre `false`;
- `promotion_review_contract.architecture_operation_id` deve ser `local_rag_graph_promotion_review`;
- `promotion_review_contract.future_runtime_invocation_contract` deve exigir Kernel first, `DecisionReceipt`, `evidence_sink` e `python_ai_data`;
- `promotion_review_contract.review_packet.schema_version` deve ser
  `atlas.local_rag_graph_promotion_review_packet.v1`;
- `promotion_review_contract.rollback_plan_required=true` e
  `promotion_review_contract.policy_patch_review_required=true`;
- `evidence_ledger.promotion_evidence_satisfied` deve ser `true` antes de
  qualquer review de promocao; se a tabela `atlas_ledger_events` estiver
  ausente, o benchmark pode passar como corpus controlado, mas a promocao
  continua sem evidencia operacional suficiente;
- Python pode reranquear, indexar e calcular, mas Laravel/Kernel decide;
- Graph RAG nao pode criar Memory Core, Ledger ou Context Builder paralelo;
- nenhuma surface pode chamar Graph RAG diretamente;
- todo candidato de promocao precisa declarar provider-safety, privacy, SLO, custo, freshness e replay;
- `LOCAL_RAG_GRAPH_PROMOTION_BLOCKED` so pode ser superseded por proposta revisada com evidencia.
- `review_packet.forbidden_until_review` deve manter proibido
  `enable_python_graph_rag_runtime`, `auto_apply_policy_patch` e
  `surface_direct_graph_rag_call`.
- proposta `proposal_only` so pode existir com
  `evidence_ledger.promotion_evidence_satisfied=true`; se o Ledger falhar, a acao
  recomendada e `restore_local_rag_evidence_ledger_before_graph_rag_review`.
- o payload deve manter `python_runtime_promotion_allowed=false`,
  `supersedes_event_required=LOCAL_RAG_GRAPH_PROMOTION_BLOCKED` e
  `supersede_authority=human_reviewed_curator_proposal_and_future_ap`.

## 6. Evidencia Minima

| Evidencia | Obrigatorio |
|---|---|
| `LOCAL_RAG_PLAN_CREATED` | sim |
| `LOCAL_RAG_QUALITY_CORPUS_EVALUATED` | sim |
| `LOCAL_RAG_GRAPH_PROMOTION_BLOCKED` | sim |
| `evidence_ledger.status=persisted` | sim |
| corpus real alem do sintetico | antes do AP futuro |
| Decision Receipt | antes de qualquer runtime |
| policy patch revisavel com rollback | antes de qualquer runtime |
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
- testes provam eventos sanitizados e proposta `proposal_only`;
- testes provam `review_packet`, rollback e proibicoes ate review humano;
- testes provam que Self-Improvement bloqueia a proposta quando o Evidence Ledger
  esta indisponivel;
- Architecture Operations lista readiness, benchmark e promotion review como operacoes canonicas;
- `php artisan atlas:ai:architecture-validate --json` retorna `ok`;
- `atlas engineering knowledge docs-health --json` retorna `ok`;
- `git diff --check` passa.
