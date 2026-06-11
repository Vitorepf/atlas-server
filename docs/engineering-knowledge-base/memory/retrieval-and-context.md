---
id: atlas-ai-memory-retrieval-and-context
type: engineering_knowledge
title: Atlas AI Memory Retrieval And Context
status: active
category: architecture
priority: 98
summary: Focused contract for deterministic recall, context pack memory refs, budgets, ranking and provider-safe composition.
tags:
  - atlas
  - memory
  - retrieval
  - context-pack
capabilities:
  - context_pack_recall
  - deterministic_recall
  - retrieval_code_context
  - retrieval_engineering_context
decisions:
  - Context is selected deterministically with explicit reasons and budgets.
  - Context packs carry refs, not unbounded raw dumps.
  - Raw capture, unclassified input and tombstoned data never enter context directly.
  - Retrieval must prefer provider-safe local sources unless an AP authorizes external/vector systems.
maintenance:
  - Keep ranking and context composition changes here.
  - Update tests when adding new context ref types.
related_paths:
  - docs/engineering-knowledge-base/memory/cognitive-immune-learning-kernel.md
  - docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md
  - docs/engineering-knowledge-base/context-pack.md
  - docs/engineering-knowledge-base/code-intelligence.md
  - docs/engineering-knowledge-base/atlas-unified-reality-graph.md
  - docs/engineering-knowledge-base/open-brain-context-injection.md
  - app/Services/Ai/AtlasOpenBrainContextPackService.php
  - app/Services/Ai/Reality/AtlasRealityGraphQueryService.php
  - tests/Feature/Ai/AtlasOpenBrainContextPackServiceTest.php
  - tests/Feature/Reality/AtlasAurgQueryTest.php
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-memory-retrieval-and-context

graph_title: Atlas AI Memory Retrieval And Context

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Memory Retrieval And Context
canonical_name: Atlas AI Memory Retrieval And Context
technical_name: atlas-ai-memory-retrieval-and-context
cartography_type: module
canonical_source: docs/engineering-knowledge-base/memory/retrieval-and-context.md

owner: memory

repo_paths:
  - docs/engineering-knowledge-base/memory/retrieval-and-context.md

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
  - docs/engineering-knowledge-base/memory/retrieval-and-context.md
  - app/Services/Ai/AtlasOpenBrainContextPackService.php
  - app/Services/Ai/Reality/AtlasRealityGraphQueryService.php

evidence_refs:
  - symbol: EngineeringContextPackService
  - symbol: AtlasOpenBrainContextPackService
  - symbol: AtlasRealityGraphQueryService
  - test: AtlasOpenBrainContextPackServiceTest
  - test: AtlasAurgQueryTest
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
# Atlas AI Memory Retrieval And Context

## Context Ref Types

| Ref | Source | Required fields |
|---|---|---|
| `memory_refs` | Memory Registry | id, type, scope, priority, reason, provider-safe summary |
| `verbatim_refs` | Verbatim Store | id, privacy class, redacted excerpt, reason |
| `knowledge_refs` | Engineering KB | doc id/path, category, priority, summary, reason |
| `code_refs` | Code Intelligence | module/symbol/path/test relation and reason |
| `provider_projection_refs` | Projection Audit | target, checksum, drift status, operation |
| `open_brain_audit_refs` | Open Brain Audit | requester, export hash, policy and counts |

Every returned recall item must also expose provider-safe provenance:

- `lineage`: local source, source ref type/id, origin type/id/label and content hash when available;
- `freshness`: recorded timestamp, last-used timestamp, age in days and review status;
- `audit`: provider-safe flag, privacy class, redaction status and governance/privacy review timestamps.

## Immune Retrieval Contract

Retrieval receives candidates, not authority. It must filter before ranking:

1. remove raw/unclassified captures;
2. remove tombstoned, expired or out-of-scope items;
3. remove private/sensitive items without provider-safe summary;
4. remove trivial or operational-only items unless the current task explicitly needs them;
5. mark untrusted content as data, never instruction;
6. return excluded refs with reason when useful for audit.

Vector search can propose candidates but cannot bypass scope, freshness, privacy,
authority ranking or contradiction checks.

## Ranking Rules

1. Prefer explicit task/project/session scope over global memory.
2. Prefer accepted decisions over observations.
3. Prefer recent unresolved issues only when relevant to the task.
4. Prefer docs with canonical status over archived/source material.
5. Deduplicate by semantic role and source path/id.
6. Include reason strings so an agent can explain why context was injected.

## Budget Rules

- Context packs should carry compact refs and summaries first.
- Raw code/doc excerpts require explicit need and bounded character budget.
- Verbatim snippets use redacted text and injection scanning.
- Code Intelligence refs point to paths/symbols/tests instead of dumping files.
- If budget is exceeded, drop lowest priority refs and report truncation.

## Initial Context Pack Source Rules

AOBG initial packs are optimized for the first provider turn: smallest useful
causal context, then expansion handles when more is needed.

- Code graph and memory may be present as compact top-K refs under their own
  budgets.
- Reality graph paths in the initial AOBG pack must be cross-layer. Same-layer
  mission/evidence paths are omitted from the first pack and counted in
  provenance as `same_layer_paths_omitted`.
- Provider-bound AURG lexical seeds expand separator terms (`code_graph`,
  `reality_graph`, `provider-bound`) but do not promote generic mission outcome
  evidence (`mission_outcome`, interrupted-request labels) from metadata-only
  matches.
- Initial AOBG packs expose `provenance.aobg_runtime` as structured metadata,
  not rendered prose. Providers use its feature flags and runtime fingerprint
  to detect stale MCP processes without adding extra prompt context.
- Initial AOBG code graph delivery is implementation-symbols-first. Symbols
  from `tests/` or `docs/`, plus auxiliary symbol types such as `test_method`
  and `doc_heading`, are deferred for normal `dev` tasks and reported under
  `provenance.code_graph.initial_delivery_policy`. They are not lost: providers
  must use `expand:test_symbols`, `recheck:canonical_doc` or focused file reads
  when the task actually needs test/doc context. Explicit test/doc tasks may opt
  into initial auxiliary symbols.
- Mission history remains available through local/AURG/mission-history audit
  surfaces; it is not dumped into initial implementation context unless the task
  explicitly asks for mission audit/history.

## Lineage, Freshness And Audit

Ranked recall items must be auditable objects, not anonymous snippets. The
composer attaches `lineage`, `freshness` and `audit_trail` to each included item.
Provider prompts may use title/summary/excerpt, while Kernel, CLI, MCP and app
surfaces can inspect metadata for drift, stale review, source explanation and
privacy proof.

Required behavior:

- preserve `content_hash` from Memory Registry, Verbatim Store and semantic notes;
- preserve `redacted_hash` for Verbatim recall and mark `raw_content_persisted=false`
  in recall audit trails;
- expose recall summary counts for `redacted_ref_count` and
  `raw_content_persisted_count` so API, CLI and MCP consumers can fail closed;
- mark missing dates as `freshness.status=unknown` instead of inventing dates;
- mark old dated memory as `stale_review_recommended`;
- keep audit metadata provider-safe and free of raw secrets;
- preserve old `audit` shape during transition, with `audit_trail` as the
  canonical field for new consumers.
- write standalone Registry recall usage rows with `source_type=memory_recall`
  when the usage table is available, updating `last_used_at` without logging raw
  private content.
- feed Memory Quality `counts.retrieval_eval` with recall usage count, recalled
  entry coverage, active entries never recalled and recall-specific
  wrong-context/stale feedback.

## Benchmark Slice

`php artisan atlas:ai:local-rag-benchmark --json` includes
`memory_recall_corpus` for real memory recall. The slice prefers provider-safe
Registry entries promoted from `ai_memory_delta` and provider-safe Verbatim
memories promoted from capture/curation. If no promoted corpus exists yet, it
falls back to active governed provider-safe Registry/Verbatim memory selected by
the same privacy boundary used by Open Brain retrieval, then reports:

- `precision_at_3` and `precision_at_5`;
- missed critical promoted refs;
- context contamination count;
- provider-safe violation count;
- stale context use count;
- budget truncation count;
- reason coverage.

The benchmark never persists raw queries or raw context in the report/ledger; it
uses hashes, source ref types and source ref hashes. The slice also emits a
`golden_set` packet (`atlas.memory_recall_golden_set.v1`) with hashed
objectives, `must_include` ref hashes, `must_exclude` safety classes and
critical invariants, so repeated runs can compare misses and contamination
without leaking capture text. Golden-set selection uses
`promoted_provider_safe_registry_or_verbatim_memory_latest_first` when promoted
items exist and
`promoted_provider_safe_latest_first_else_governed_provider_safe_active_latest_first`
for the fallback path. Passing this slice can satisfy
`real_corpus_retrieval_answer_quality`, but it does not authorize Graph RAG,
Python runtime promotion or provider bypass.

Use `--record-memory-quality` to persist the benchmark as a Memory Quality
snapshot:

```bash
php artisan atlas:ai:local-rag-benchmark --record-memory-quality --json
```

The snapshot uses `source_type=local_rag_benchmark` and stores metrics, checks,
golden-set counts and evidence payload hashes. It is opt-in and must remain free
of raw query text, raw context and unredacted capture evidence. Snapshot metadata
also stores the provider-safe `retrieval_rivals_packet` summary
schema/status/mode/comparison/strategy statuses/checks, so history can verify
that rival alternatives remained proposal-only and non-executed.

When recording is enabled, the command also returns
`retrieval_benchmark_history`, filtered to `source_type=local_rag_benchmark`,
so operators can see latest score, score delta and recent snapshots without
mixing generic Memory Quality snapshots with retrieval benchmark evidence.

The benchmark also returns `retrieval_rivals_packet`
(`atlas.retrieval_rivals.packet.v1`). This packet is proposal-only: it measures
the current governed hybrid memory recall strategy and describes candidate
alternatives such as lexical fallback or future Graph RAG/Python without
executing them. It must keep `raw_query_persisted=false`,
`raw_context_persisted=false`, forbid provider calls/runtime execution/policy
auto-apply and require human review plus AP/runtime contracts before any rival
strategy can run.

External vector/RAG belongs to Memory/Context Engine and Knowledge Base/Open
Brain, with Constelacao as a likely future surface. It is absent from runtime by
design because embeddings, external vector-store IO and Graph RAG can change
recall semantics, privacy exposure, retention/delete behavior and costs. The
benchmark exposes this boundary through
`promotion_review_contract.external_vector_rag_preflight_contract`
(`atlas.external_vector_rag.promotion_preflight.v1`): proposal-only, hash-only,
no embedding generation, no external vector reads/writes, no provider dispatch,
no memory/context mutation and no Constelacao promotion. Any activation requires
AP-683/AP-684 or successor scope, human review, Decision Receipt, runtime
invocation contract, provider-safety review, retention/delete-cascade policy,
golden-set benchmark, Evidence Ledger and rollback plan.

`--emit-external-vector-rag-preflight-inbox` turns that contract into a
proposal Inbox item with `review_external_vector_rag_preflight`. The action
records a dry-run Decision Receipt and safety review while keeping runtime
execution, provider calls, embedding generation, external vector IO, memory
writes, context mutation, policy patching and Constelacao promotion closed.

Recurring snapshots are disabled by default. Use
`php artisan atlas:ai:local-rag-benchmark --schedule-plan --json` to inspect the
plan. `ATLAS_AI_LOCAL_RAG_BENCHMARK_SCHEDULE_ENABLED=true` makes
`bootstrap/app.php` register the daily Laravel Scheduler entry; invalid time or
timezone keeps `scheduler_registration.status=skipped`.

Use `php artisan atlas:ai:local-rag-benchmark --rivals-report --json` to compare
the latest hash-only retrieval snapshot against the previous one. The report is
proposal-only: it exposes snapshot ids, source hashes, scores, metric deltas and
a human review packet when retrieval regresses; it must not run benchmark cases,
promote Graph RAG/Python runtime or emit raw capture text.

`--emit-rivals-inbox` may be combined with `--rivals-report` to create a
proposal Inbox item only for regressed reports. The Inbox payload carries the
review packet, safe snapshot refs and `review_retrieval_regression`; it remains
operator-review-only and does not authorize automatic memory or runtime changes.
Once reviewed, the Inbox Action replay surfaces project only safe audit fields
for `review_retrieval_regression`: decision, reviewed marker, report/snapshot
hashes and explicit no-external-action / no-runtime / no-policy flags. This
closes the retrieval regression loop through Open Brain reporting without
persisting raw query/context text in the replay layer.

## Forbidden Paths

- No raw private notes from Obsidian by default.
- No raw capture or unclassified chat transcript by default.
- No trivial query, operational reminder or prompt injection as context.
- No tombstoned content, stale superseded memory or out-of-scope anti-memory.
- No provider projection content treated as source truth.
- No vector retrieval silently changing deterministic order.
- No context pack that cannot explain included sources.

## Validation

Context changes should run focused tests for:

- `AiContextPackBuilder`;
- `AtlasMemoryContextComposer`;
- `EngineeringContextPackService`;
- privacy/redaction filters;
- architecture validation.

## Resumo

Focused contract for deterministic recall, context pack memory refs, budgets, ranking and provider-safe composition.

## Papel no Atlas

Define a responsabilidade desta peca dentro da arquitetura Atlas.

## Onde Se Encaixa

Relaciona esta peca com seu sistema, camada, fluxo ou modulo pai.

## Contratos

Declara invariantes, entradas, saidas, limites e obrigacoes relevantes.

## Fluxo

Descreve o caminho operacional ou a sequencia de uso quando aplicavel.

## Regras para IA

Agentes devem respeitar escopo, evidencias, testes e proibicoes antes de alterar codigo.

## Escopo de Implementacao

Mudancas devem permanecer nos caminhos e limites declarados no frontmatter.

## Dependencias

Dependencias canonicas vivem em frontmatter e no corpo deste documento.

## Evidencias

Evidencias aceitas incluem docs, comandos, testes, receipts, reports e paths verificaveis.

## Riscos

Riscos principais devem ser tratados antes de promover status, runtime ou claims de prontidao.

## Exemplos

Exemplos concretos devem ser adicionados quando reduzirem ambiguidade para humanos ou IAs.

## Proximas Acoes

Proximas acoes devem ser concretas, verificaveis e ligadas a gates de qualidade.
