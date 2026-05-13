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
  - memory_registry
  - context_pack_recall
  - engineering_knowledge_base
  - open_brain_context_injection
decisions:
  - Memory/Open Brain is the active structure-mother priority; Voice remains a later surface.
  - Recall items must carry lineage, freshness and audit metadata before provider export.
maintenance:
  - Update after each Memory/Open Brain foundation cycle.
  - Keep this as a compact state map, not a changelog.
related_paths:
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
| Open Brain context export | implemented | `AtlasOpenBrainContextInjectionService`, `AtlasOpenBrainService`, API/CLI/MCP and audits |
| Provider projections | implemented | provider-safe projection status/apply/audit contracts and tests |
| AtlasVault as human surface | partial | local contracts/runbook exist; arbitrary vault notes are not operational primary source |
| Memory quality scorecard | implemented | `AtlasMemoryQualityService`, snapshots, history, API and CLI |
| Raw capture quarantine | implemented | `CaptureService` stamps `metadata.cognitive_quarantine` with memory/context/embedding disabled, provider/Open Brain export closed by default and idempotent replay receipts without raw content |
| Curation proposal quarantine | implemented | `CurationProposalService` inherits capture quarantine, keeps proposal non-memory/non-context and redacts raw source in audit |
| Memory delta review API | implemented | `/ai/memory/deltas`, `/ai/memory/deltas/{delta}` and review expose delta safety flags and accept/reject with `atlas.memory_delta.review_receipt.v1` |
| Raw capture to Memory Registry promotion | implemented | accepting a curation proposal with `promote_to_memory=true` promotes the capture-backed delta with `atlas.memory.promotion_receipt.v1` |
| Raw capture to Verbatim Store promotion | implemented | accepting a curation proposal with `promote_to_verbatim=true` writes exact local text to Verbatim Store with `atlas.verbatim_memory.promotion_receipt.v1` and external AI closed by default |
| Inbox/Capture review workflow | implemented | `CaptureService` writes `metadata.semantic_curation` and `CaptureResource.review_workflow` exposes proposal, delta, promotion actions and quarantine safety flags without raw evidence text |
| Retrieval usage evals | implemented | `AtlasMemoryQualityService` reports `counts.retrieval_eval`, recall coverage and recall-specific negative feedback from `atlas_memory_entry_usages` |
| Promoted memory retrieval benchmark | implemented | `atlas:ai:local-rag-benchmark --json` includes `memory_recall_corpus` plus a hashed golden-set packet for promoted Registry/Verbatim memories |
| Longitudinal retrieval snapshots | implemented | `atlas:ai:local-rag-benchmark --record-memory-quality --json` persists benchmark metrics into `atlas_memory_quality_snapshots` with hashes only and returns filtered benchmark history |
| Scheduled retrieval evaluation | implemented | `atlas:ai:local-rag-benchmark --schedule-plan --json` exposes the opt-in schedule and `bootstrap/app.php` registers it only when schedulable |
| Retrieval Rivals packet | implemented | `atlas:ai:local-rag-benchmark --json` returns `retrieval_rivals_packet` comparing current governed recall to proposal-only alternatives without executing them |
| Retrieval Rivals longitudinal comparison | implemented | `atlas:ai:local-rag-benchmark --rivals-report --json` compares latest/previous benchmark snapshots, flags regression and emits proposal-only human review packet |
| Retrieval Rivals safety contract | implemented | Rivals shadow plans, reports and Inbox projections expose `atlas.retrieval_rivals.safety.v1` with provider/runtime/policy/memory writes and raw content exposure closed |
| Retrieval Rivals shadow plan | implemented | `atlas:ai:local-rag-benchmark --rivals-shadow-plan --json` publishes AP-693 blocked scope for future shadow comparison without provider/runtime/policy execution |
| Retrieval Rivals shadow Inbox review | implemented | `--emit-rivals-shadow-inbox` emits AP-693 scope review and `review_retrieval_shadow_scope` records `atlas.inbox_action.memory_retrieval_shadow_scope_review.v1` plus deterministic dry decision receipt without runtime, provider or policy mutation |
| Retrieval Rivals shadow action replay | implemented | Inbox Action CLI/API/MCP replay reports project AP-693 scope decision, reviewed count, receipt count, plan hash and fail-closed runtime/provider/policy markers |
| Retrieval regression Inbox projection | implemented | `atlas:ai:local-rag-benchmark --rivals-report --emit-rivals-inbox --json` emits operator-review proposal only when comparison status is `attention` |
| Retrieval regression Inbox action | implemented | `review_retrieval_regression` records `atlas.inbox_action.memory_retrieval_regression_review.v1` and `INBOX_ACTION_RECORDED` without runtime, policy or memory mutation |
| Retrieval regression action replay | implemented | Inbox Action CLI/API/MCP replay reports project review decision, reviewed count, snapshot hashes and no-runtime/no-policy flags without raw retrieval context |
| Longitudinal retrieval benchmarks | partial | snapshot persistence/history/schedule/comparison/shadow plan/inbox review actions exist; cross-strategy A/B execution is still pending behind AP-693 |
| External vector/RAG | missing by design | blocked until dedicated AP/spec and promotion gate |

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
promoted capture memory now has recall receipts, a deterministic golden-set
benchmark slice, filtered benchmark history, opt-in scheduling, Rivals-style
comparison packets and Inbox projection for regressions, but executing alternate
retrieval strategies still requires AP/runtime contracts and human review.
