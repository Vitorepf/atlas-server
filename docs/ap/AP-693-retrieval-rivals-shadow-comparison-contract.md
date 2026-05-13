---
id: AP-693
title: Retrieval Rivals Shadow Comparison Contract
status: proposed
owner: atlas-ai-cognitive-runtime
area: memory-open-brain
summary: Formaliza o escopo revisavel para comparar estrategias de retrieval em shadow mode sem executar rival, provider, Python runtime ou policy patch antes de review humano.
related_paths:
  - docs/engineering-knowledge-base/cognitive-runtime/retrieval-benchmark.md
  - docs/engineering-knowledge-base/memory/retrieval-and-context.md
  - app/Console/Commands/AtlasAiLocalRagBenchmarkCommand.php
  - app/Services/Ai/Context/LocalRagBenchmarkService.php
---

# AP-693 Retrieval Rivals Shadow Comparison Contract

## Objective

Permitir que o Atlas desenhe uma comparacao futura entre estrategias de
retrieval sem confundir plano com execucao. O contrato cria um gate humano para
shadow mode antes de qualquer rival lexical, Graph RAG, Python runtime, provider
call, policy patch ou promocao de ranking.

## Scope

Inclui:

- plano machine-readable `atlas.local_rag_benchmark.rivals_shadow_plan.v1`;
- contrato deterministico `atlas.memory_retrieval_rivals_shadow_case_contract.v1`;
- review packet `atlas.memory_retrieval_rivals_shadow_plan_review_packet.v1`;
- lista de candidatos: current governed hybrid recall, lexical fallback e future
  Graph RAG/Python candidate;
- evidence minima para permitir uma execucao shadow futura;
- proibicoes explicitas ate review humano.

Nao inclui:

- executar estrategia rival;
- criar Graph RAG, reranker Python ou memoria paralela;
- chamar provider;
- persistir raw query, raw context ou capture bruto;
- escolher provider/model;
- aplicar policy patch automaticamente.

## Authority

O Kernel continua autoridade de provider, domain, flow, policy e memory. AP-693
nao supersede AP-683. AP-683 segue sendo o gate de promocao Local RAG -> Graph
RAG/Python; AP-693 cobre apenas o escopo de comparacao shadow anterior a qualquer
promocao.

## Required Gate

Antes de qualquer shadow run real, todos os itens abaixo precisam existir:

- human review;
- `atlas.memory_retrieval_shadow_scope_decision_receipt.v1` with
  deterministic receipt hash and `shadow_execution_allowed_now=false`; the
  receipt may carry audit timestamps, but timestamps are not part of the
  semantic decision hash;
- shadow case contract;
- Decision Receipt hash;
- Evidence Ledger event contract;
- privacy/provider safety review;
- rollback plan;
- candidate strategy contracts.

Enquanto qualquer item estiver ausente, o comando deve reportar
`status=blocked`, `provider_call_allowed=false`, `runtime_execution_allowed=false`
e `policy_auto_apply_allowed=false`.

## Evidence Contract

Uma execucao shadow futura deve produzir apenas evidencia provider-safe:

| Evidence | Required |
|---|---|
| latest local RAG benchmark snapshot | yes |
| Memory Recall golden-set hashes | yes |
| per-strategy precision and contamination deltas | yes |
| provider safety checklist | yes |
| evidence ledger event ids or payload hashes | yes |
| raw query/context/capture text | never |

## Forbidden Until Review

- `execute_python_graph_rag`;
- `run_unreviewed_lexical_rival`;
- `persist_raw_query`;
- `persist_raw_context`;
- `send_raw_capture_to_provider`;
- `auto_apply_policy_patch`;
- `choose_provider_or_model`;
- `promote_rival_strategy`.

## Definition Of Done

- `atlas:ai:local-rag-benchmark --rivals-shadow-plan --json` references AP-693;
- `atlas:ai:local-rag-benchmark --rivals-shadow-case-contract --json`
  declares case, strategy, metric, ledger-event, rollback and runtime-invocation
  contracts without runtime execution;
- Architecture Operations lists the shadow plan as a governance gate;
- tests prove the plan is blocked, hash-only and no-runtime;
- Inbox scope review records a dry-run decision receipt and
  privacy/provider safety review plus `INBOX_ACTION_RECORDED` evidence without
  enabling runtime;
- retrieval benchmark docs explain the AP-693 gate;
- `php artisan atlas:ai:architecture-validate --json` passes;
- `atlas engineering knowledge docs-health --json` passes;
- `git diff --check` passes.
