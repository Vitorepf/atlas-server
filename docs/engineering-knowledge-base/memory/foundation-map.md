---
id: atlas-ai-memory-open-brain-foundation-map
type: engineering_knowledge
title: Atlas AI Memory Open Brain Foundation Map
status: active
category: architecture
priority: 98
summary: Current implementation map for Memory/Context Engine and Knowledge Base/Open Brain foundation.
tags:
  - atlas
  - memory
  - open-brain
  - implementation-map
capabilities:
  - memory_foundation_map
  - context_pack_foundation
  - engineering_knowledge_foundation
  - open_brain_foundation_map
decisions:
  - Memory/Open Brain is the active structure-mother priority; Voice remains a later surface.
  - Recall items must carry lineage, freshness and audit metadata before provider export.
maintenance:
  - Update after each Memory/Open Brain foundation cycle.
  - Keep this as a compact state map, not a changelog.
related_paths:
  - app/Console/Commands/AtlasMemory
  - app/Models/AtlasMemory
  - app/Models/AtlasMemoryEntry.php
  - app/Services/Ai/AtlasMemoryRegistryService.php
  - app/Services/Ai/AtlasHybridMemoryRetrievalService.php
  - app/Services/Ai/AtlasMemoryContextComposer.php
  - app/Services/Ai/AiContextPackBuilder.php
  - app/Services/Ai/AtlasOpenBrainContextInjectionService.php
  - app/Services/Engineering/EngineeringKnowledgeBaseService.php
  - tests/Feature/AtlasMemoryRegistryTest.php
  - docs/engineering-knowledge-base/memory/contracts.md
  - docs/engineering-knowledge-base/memory/retrieval-and-context.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-memory-open-brain-foundation-map

graph_title: Atlas AI Memory Open Brain Foundation Map

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Memory Open Brain Foundation Map
canonical_name: Atlas AI Memory Open Brain Foundation Map
technical_name: atlas-ai-memory-open-brain-foundation-map
cartography_type: module
canonical_source: docs/engineering-knowledge-base/memory/foundation-map.md

owner: memory

repo_paths:
  - docs/engineering-knowledge-base/memory/foundation-map.md

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-ai-documentation-operating-system

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - ai-safe-implementation-context

governs:
  - memory

evidence:
  - docs/engineering-knowledge-base/memory/foundation-map.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - module
  - memory

ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de implementar.

ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Contexto desatualizado entre doc, codigo, teste e evidencia.

observability_signals:
  - docs-health status ok

next_actions:
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
---
# Atlas AI Memory Open Brain Foundation Map

## Scope

This map covers the backend foundation for Memory/Context Engine and Knowledge
Base/Open Brain. It excludes Voice/LiveKit experience work.

## Current State Matrix

| Area | State | Evidence |
|---|---|---|
| Memory Registry | implemented | `AtlasMemoryEntry`, `AtlasMemoryRegistryService`, memory APIs/CLI, seed/review/relation safety flags and `AtlasMemoryRegistryTest` |
| Memory types | implemented | `decision`, `preference`, `feedback`, `technical_context`, `issue`, `resolution`, `benchmark_observation`, `harness_learning`, `anti_memory`, `strategic_insight` |
| Verbatim Store | implemented | `AtlasVerbatimMemoryService`, redaction, HTTP/CLI response safety flags, provider-safe recall and registry pointer sync |
| Privacy/redaction | implemented | `AtlasMemoryPrivacyService`, source privacy policy, provider-safe filters and tests |
| Hybrid recall | implemented | `AtlasHybridMemoryRetrievalService` combines registry, verbatim and semantic notes |
| Recall lineage/freshness/audit | implemented | `AtlasMemoryContextComposer` emits `lineage`, `freshness`, `audit` and `audit_trail` |
| Engineering Knowledge Base | implemented | `EngineeringKnowledgeBaseService`, `atlas engineering knowledge sync`, API and CLI |
| Code Intelligence | implemented | `atlas engineering knowledge index-code`, modules, symbols, routes, commands and tests index |
| Open Brain context export | implemented | `AtlasOpenBrainContextInjectionService`, `AtlasOpenBrainService`, API/CLI/MCP and hash-only audits with no raw objective/workspace query persistence |
| Provider projections | implemented | provider-safe projection status/apply/audit contracts and tests |
| AtlasVault as human surface | partial | local contracts/runbook exist; arbitrary vault notes are not operational primary source |
| Memory quality scorecard | implemented | `AtlasMemoryQualityService`, snapshots, history, API and CLI |
| Raw capture quarantine | implemented | `CaptureService` stamps `metadata.cognitive_quarantine` with memory/context/embedding disabled, provider/Open Brain export closed by default and idempotent replay receipts without raw content |
| Cognitive immune audit projection | implemented | `CaptureService` records `atlas.capture.cognitive_immune_audit.v1`; capture and curation proposal resources expose only safe audit summaries and closed learning/memory/context/constellation flags |
| Capture-backed delta immune lineage | implemented | `AiMemoryDeltaProposer` stores content hash, proposal link and immune audit hash/status in delta evidence, with deterministic `legacy_missing` fallback for older captures; `atlas.memory_delta.safety.v1` projects only hash/status gate summaries |
| Curation proposal quarantine | implemented | `CurationProposalService` inherits capture quarantine, keeps proposal non-memory/non-context and redacts raw source in audit |
| Memory delta review API | implemented | `/ai/memory/deltas`, `/ai/memory/deltas/{delta}` and review expose delta safety flags and accept/reject with `atlas.memory_delta.review_receipt.v1` |
| Promoted memory lineage safety | implemented | `AtlasMemoryEntryResource` exposes `atlas.memory_entry.lineage_safety.v1` with hash-only source/delta identity, promotion origin, evidence count, valid window and freshness status |
| Raw capture to Memory Registry promotion | implemented | accepting a curation proposal with `promote_to_memory=true` promotes the capture-backed delta with `atlas.memory.promotion_receipt.v1` |
| Raw capture to Verbatim Store promotion | implemented | accepting a curation proposal with `promote_to_verbatim=true` writes exact local text to Verbatim Store with `atlas.verbatim_memory.promotion_receipt.v1` and external AI closed by default |
| Inbox/Capture review workflow | implemented | `CaptureService` writes `metadata.semantic_curation` and `CaptureResource.review_workflow` exposes proposal, delta, promotion actions and quarantine safety flags without raw evidence text |
| Capture/Inbox pipeline report | implemented | `atlas:ai:capture-inbox-pipeline-report --json` verifies recent capture quarantine, content intelligence, proposal backlinks, memory deltas, capture links and operational inbox coverage, plus `atlas.capture_inbox_pipeline.promotion_gate.v1`, without mutating memory/context |
| Legacy capture contract backfill | implemented | `atlas:ai:capture-inbox-pipeline-backfill-contracts` dry-runs by default and can conservatively add quarantine/content-intelligence/proposal backlink metadata while keeping provider/Open Brain/embedding/memory gates closed |
| Retrieval usage evals | implemented | `AtlasMemoryQualityService` reports `counts.retrieval_eval`, recall coverage and recall-specific negative feedback from `atlas_memory_entry_usages` |
| Real memory retrieval benchmark | implemented | `atlas:ai:local-rag-benchmark --json` includes `memory_recall_corpus` plus a hashed golden-set packet; promoted Registry/Verbatim memories are preferred and active governed provider-safe memory is used as fallback when no promoted corpus exists |
| Longitudinal retrieval snapshots | implemented | `atlas:ai:local-rag-benchmark --record-memory-quality --json` persists benchmark metrics into `atlas_memory_quality_snapshots` with hashes only and returns filtered benchmark history |
| Scheduled retrieval evaluation | implemented | `atlas:ai:local-rag-benchmark --schedule-plan --json` exposes the opt-in schedule and `bootstrap/app.php` registers it only when schedulable |
| Retrieval Rivals packet | implemented | `atlas:ai:local-rag-benchmark --json` returns `retrieval_rivals_packet` comparing current governed recall to review-only alternatives without executing them |
| Retrieval Rivals longitudinal comparison | implemented | `atlas:ai:local-rag-benchmark --rivals-report --json` compares latest/previous benchmark snapshots, flags regression and emits review-only human packet |
| Retrieval Rivals safety contract | implemented | Rivals shadow plans, reports and Inbox projections expose `atlas.retrieval_rivals.safety.v1` with provider/runtime/policy/memory writes and raw content exposure closed |
| Retrieval Rivals shadow plan | implemented | `atlas:ai:local-rag-benchmark --rivals-shadow-plan --json` publishes AP-693 blocked scope for shadow comparison without provider/runtime/policy execution |
| Retrieval Rivals shadow case contract | implemented | `atlas:ai:local-rag-benchmark --rivals-shadow-case-contract --json` declares deterministic case, strategy, metric, ledger-event, rollback and runtime-invocation contracts for AP-693 without executing rival retrieval |
| Retrieval Rivals shadow Inbox review | implemented | `--emit-rivals-shadow-inbox` emits AP-693 scope/case review and `review_retrieval_shadow_scope` records scope review, privacy/provider safety review and deterministic dry decision receipt without runtime, provider or policy mutation |
| Retrieval Rivals shadow action replay | implemented | Inbox Action CLI/API/MCP replay reports project AP-693 scope decision, reviewed count, receipt count, plan hash and fail-closed runtime/provider/policy markers |
| Retrieval regression Inbox projection | implemented | `atlas:ai:local-rag-benchmark --rivals-report --emit-rivals-inbox --json` emits operator-review proposal only when comparison status is `attention` |
| Retrieval regression Inbox action | implemented | `review_retrieval_regression` records `atlas.inbox_action.memory_retrieval_regression_review.v1` and `INBOX_ACTION_RECORDED` without runtime, policy or memory mutation |
| Retrieval regression action replay | implemented | Inbox Action CLI/API/MCP replay reports project review decision, reviewed count, snapshot hashes and no-runtime/no-policy flags without raw retrieval context |
| Longitudinal retrieval benchmarks | partial | snapshot persistence/history/schedule/comparison/shadow plan/inbox review actions exist; cross-strategy A/B execution is still pending behind AP-693 |
| External vector/RAG | governed preflight | runtime remains blocked; `atlas:ai:local-rag-benchmark --json` publishes `atlas.external_vector_rag.promotion_preflight.v1` under the Local RAG promotion review contract with no embeddings, no external vector IO, no provider dispatch, no memory/context mutation and no Constelacao promotion |
| External vector/RAG Inbox review | implemented | `--emit-external-vector-rag-preflight-inbox` emits a proposal item and `review_external_vector_rag_preflight` records safety review plus dry-run Decision Receipt while keeping embeddings/runtime/provider/vector IO/Constelacao closed |
| External vector/RAG replay | implemented | Inbox Action report/replay projects `review_external_vector_rag_preflight`, counts reviewed/receipted actions and warns if any receipt allows embeddings, external vector writes or Constelacao promotion |

## Canonical Contract

Memory items must identify type, scope, source, confidence, privacy, retention
state, freshness and lineage. Context exports must include only provider-safe
title/summary/excerpt plus metadata that explains why each item was selected.

`memory.recall[]` is now the smallest complete Open Brain unit:

- ranked provider-safe excerpt;
- local source ref;
- `lineage` for origin and drift identity;
- `freshness` for stale review;
- `audit_trail` for privacy/governance proof.

## Next Edge

The next coherent block is approved cross-strategy retrieval comparison:
real memory now has recall receipts, a deterministic golden-set benchmark
slice, governed provider-safe fallback selection, filtered benchmark history,
opt-in scheduling, Rivals-style comparison packets, AP-693 shadow case contract,
ledger-event contract, rollback plan, runtime-invocation contracts, external
vector/RAG promotion preflight, privacy/provider safety review, decision receipt
and Inbox projection for regressions, but executing alternate retrieval
strategies or generating external embeddings still requires a separate
execution receipt, focused shadow tests and human approval for the actual run.

## Resumo

Current implementation map for Memory/Context Engine and Knowledge Base/Open
Brain foundation.

## Papel no Atlas

Mostra quais partes de memoria, recall, context export, capture quarantine,
retrieval evaluation e Open Brain estao implementadas, parciais ou bloqueadas.

## Onde Se Encaixa

Fica abaixo do ACOS e acima dos consumidores Forge, Mission, Programming e
providers. Docs filhos `memory/contracts.md` e `memory/retrieval-and-context.md`
mantem contratos detalhados.

## Contratos

- Memory/Open Brain nao exporta raw private content para provider.
- Recall precisa carregar lineage, freshness e audit metadata.
- AtlasVault/Obsidian e superficie humana, nao fonte operacional primaria.
- External vector/RAG segue bloqueado ate receipt, teste focado e aprovacao.

## Fluxo

```text
capture/source -> quarantine/review -> memory/verbatim promotion
-> recall/composer -> provider-safe context pack -> Open Brain export
```

## Regras para IA

- Nao tratar source material, Vault ou Obsidian como memoria operacional.
- Nao executar vector externo/RAG alternativo a partir deste map.
- Usar context pack provider-safe, com lineage/freshness/audit.
- Confirmar owner doc antes de criar novo runtime de memoria ou retrieval.

## Escopo de Implementacao

Mudancas ficam nos services de memoria/contexto listados em `related_paths`,
docs `memory/**` e comandos de benchmark/review associados.

## Dependencias

Depende de Memory Registry, Verbatim Store, Capture quarantine, Engineering KB,
Code Intelligence, Local RAG benchmark, Inbox review e Open Brain services.

## Evidencias

Evidencias aceitas: `AtlasMemoryRegistryTest`, Local RAG benchmark, memory
quality snapshots, promotion receipts, inbox action receipts e docs-health.

## Riscos

- Promover captura crua como memoria sem quarantine.
- Exportar conteudo privado para provider.
- Tratar benchmark review-only como execucao de estrategia alternativa.
- Criar segundo owner de recall/contexto fora do ACOS.

## Exemplos

AP-693 shadow plan e contrato de caso sao review-only: eles documentam escopo,
metricas e rollback, mas nao executam rival retrieval nem embeddings externos.

## Proximas Acoes

Manter o map sincronizado com scorecards de memoria, benchmark local, inbox
reviews e docs filhos de Memory/Open Brain.
