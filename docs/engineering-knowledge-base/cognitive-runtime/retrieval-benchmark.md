---
id: atlas-ai-cognitive-runtime-retrieval-benchmark
type: engineering_knowledge
title: Atlas AI Cognitive Runtime Retrieval Benchmark
status: active
category: architecture
priority: 98
summary: Benchmark canonico para medir qualidade da busca de contexto em memoria, docs, APs, Code Intelligence e evidence.
tags:
  - atlas-ai
  - cognitive-runtime
  - retrieval
  - benchmark
  - context-quality
capabilities:
  - retrieval_quality
  - context_pack_recall_benchmark
  - benchmark
  - cognitive_audit
decisions:
  - Retrieval de alta qualidade precisa ser medido por acerto, omissao critica, contexto velho, contaminacao e uso real.
  - O benchmark deve favorecer contexto pequeno e correto, nao contexto grande.
  - Busca sem reason e falha de qualidade.
maintenance:
  - Atualizar quando novos ref types, ranking rules ou surfaces entrarem no Context Builder.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-cognitive-runtime.md
  - docs/engineering-knowledge-base/memory/retrieval-and-context.md
  - docs/engineering-knowledge-base/code-intelligence.md
  - docs/engineering-knowledge-base/context-pack.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-cognitive-runtime-retrieval-benchmark

graph_title: Atlas AI Cognitive Runtime Retrieval Benchmark

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Cognitive Runtime Retrieval Benchmark
canonical_name: Atlas AI Cognitive Runtime Retrieval Benchmark
technical_name: atlas-ai-cognitive-runtime-retrieval-benchmark
cartography_type: module
canonical_source: docs/engineering-knowledge-base/cognitive-runtime/retrieval-benchmark.md

owner: cognitive-runtime

repo_paths:
  - docs/engineering-knowledge-base/cognitive-runtime/retrieval-benchmark.md

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
  - cognitive-runtime

evidence:
  - docs/engineering-knowledge-base/cognitive-runtime/retrieval-benchmark.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - system
  - module
  - cognitive-runtime

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
# Atlas AI Cognitive Runtime Retrieval Benchmark

## Goal

Provar que o Atlas encontra o contexto certo para programacao, review, debug,
continuidade e pesquisa sem despejar ruido nem depender de chat bruto.

## Benchmark Cases

| Case | Required context | Failure if missing |
|---|---|---|
| Hot file review | git delta, ownership, tests touched | editar arquivo quente ou duplicar trabalho |
| AP implementation | AP owner, DoD, validation commands | implementar sem contrato |
| Long session resume | snapshot, decisions, evidence refs | repetir investigacao ou perder constraint |
| Voice runtime review | AP-687, runtime files, scanner rules | tocar runtime quente ou provider bypass |
| Memory change | Cognitive Immune, contracts, privacy | promover raw capture |
| Code task | code refs, tests, routes, commands | patch no modulo errado |
| Docs change | Documentation OS, owner doc, line limits | doc orfa ou oversized |

## Metrics

| Metric | Target |
|---|---|
| `precision_at_3` | >= 0.80 |
| `precision_at_5` | >= 0.80 |
| `missed_critical_context_count` | 0 |
| `context_contamination_count` | 0 |
| `stale_context_use_count` | 0 unless justified |
| `reason_coverage` | 100% of included refs |
| `provider_safe_violation_count` | 0 |
| `budget_truncation_count` | explained and non-critical |

## Implemented Slice

`atlas:ai:local-rag-benchmark --json` owns the first deterministic memory
slice: `memory_recall_corpus`.

It evaluates promoted provider-safe memory already inside the canonical stores:

- Memory Registry entries with `source_type=ai_memory_delta`;
- Verbatim Store entries with `source_type=capture` or
  `source_type=semantic_curation_proposal`;
- semantic notes disabled for the slice so Registry/Verbatim recall is measured
  directly.

The slice reports hashes instead of raw queries/context and checks
`precision_at_3`, `precision_at_5`, missed critical refs, contamination,
provider-safe violations, stale context use, budget truncation and reason
coverage. It also emits `golden_set` (`atlas.memory_recall_golden_set.v1`) with
hashed objectives, `must_include` source ref hashes, `must_exclude` safety
classes and critical invariants. When it passes, the local benchmark marks
`real_corpus_retrieval_answer_quality` complete, while Graph RAG/Python runtime
promotion remains blocked until human review, AP scope and decision receipts.

For longitudinal tracking, run:

```bash
php artisan atlas:ai:local-rag-benchmark --record-memory-quality --json
```

This persists an `atlas_memory_quality_snapshots` row with
`source_type=local_rag_benchmark`. Snapshot metadata stores benchmark status,
quality-corpus metrics, memory-recall metrics, golden-set counts and evidence
ledger payload hashes only. It must not store raw query text, raw context,
unredacted capture text or provider secrets. The command response includes
`retrieval_benchmark_history`, filtered to the same source type, for latest
score, score delta and recent snapshots.

Recurring execution is opt-in and inspectable:

```bash
php artisan atlas:ai:local-rag-benchmark --schedule-plan --json
```

Config:

- `ATLAS_AI_LOCAL_RAG_BENCHMARK_SCHEDULE_ENABLED=false` by default;
- `ATLAS_AI_LOCAL_RAG_BENCHMARK_SCHEDULE_TIME=02:30` by default;
- `ATLAS_AI_LOCAL_RAG_BENCHMARK_WORKSPACE` optionally scopes the Memory Quality
  snapshot.

When `schedulable=true`, `bootstrap/app.php` registers the schedule plan command
with Laravel Scheduler, daily, `withoutOverlapping`. Disabled or invalid schedule
config produces `scheduler_registration.status=skipped` and does not execute the
benchmark.

## Retrieval Rivals Packet

The benchmark emits `retrieval_rivals_packet`
(`atlas.retrieval_rivals.packet.v1`) as the first Rivals-style comparison
surface for retrieval. It is not an execution harness. It records the measured
current strategy, `current_governed_hybrid_memory_recall`, and lists alternatives
as blocked proposals until there is human review, AP scope, decision receipt,
runtime invocation contract and rollback plan.

The packet must report hashes/metrics only, set raw query/context persistence to
false, and forbid provider calls, Python runtime execution, Graph RAG auto-enable
and policy auto-apply. Its comparison winner is meaningful only for the current
measured path; `delta_measured=false` until an approved rival strategy is allowed
to run.
Retrieval Rivals reports, shadow plans and Inbox projections must expose
`safety` as `atlas.retrieval_rivals.safety.v1`: proposal-only, no provider call,
no runtime execution, no policy auto-apply, no memory write, no raw query/context
persistence and no raw capture exposure.

Before any rival strategy can run in shadow mode, inspect the blocked shadow
plan:

```bash
php artisan atlas:ai:local-rag-benchmark --rivals-shadow-plan --json
```

The plan emits `atlas.local_rag_benchmark.rivals_shadow_plan.v1`, review packet
`atlas.memory_retrieval_rivals_shadow_plan_review_packet.v1` and references
`docs/ap/AP-693-retrieval-rivals-shadow-comparison-contract.md`. It lists the
current governed hybrid recall baseline plus lexical and future Graph RAG/Python
candidates, but keeps every candidate blocked. It requires human review, a
shadow case contract, decision receipt hash, evidence ledger event contract,
privacy/provider safety review and rollback plan before any shadow execution. It
must keep `provider_call_allowed=false`, `runtime_execution_allowed=false`,
`policy_auto_apply_allowed=false`, `raw_query_persisted=false` and
`raw_context_persisted=false`; its `safety` block repeats those fail-closed
flags for Open Brain consumers.
The plan also exposes `atlas.rivals.evaluation_contract.v1`: required metrics,
baseline strategy, winner policy, minimum evidence hashes and forbidden claims
before evidence. This prevents a shadow plan from becoming a qualitative claim
or promotion signal without measured, reviewable results.

Use the case contract when AP-693 needs the next non-executing artifact before
any shadow run:

```bash
php artisan atlas:ai:local-rag-benchmark --rivals-shadow-case-contract --json
```

The contract emits `atlas.memory_retrieval_rivals_shadow_case_contract.v1` with
deterministic query hashes, strategy contracts, metric requirements and evidence
hashes. It also declares
`atlas.memory_retrieval_rivals_shadow_ledger_event_contract.v1` and
`atlas.memory_retrieval_rivals_shadow_rollback_plan.v1`, so a future reviewed
run has a hash-only evidence event shape and a baseline rollback target before
any rival can execute. `atlas.memory_retrieval_rivals_shadow_runtime_invocation_contracts.v1`
then separates the lexical kernel-internal candidate from the future
`python_ai_data` Graph RAG candidate and keeps both blocked. The contract keeps
`shadow_execution_allowed_now=false`, `provider_call_allowed=false`,
`runtime_execution_allowed=false`, `memory_write_allowed=false`,
`raw_query_persisted=false` and `raw_context_persisted=false`. It removes
ambiguity about what a future comparison must measure and how candidates would
be invoked, but it is not an approval to run lexical, Graph RAG or Python
candidates.

Add `--emit-rivals-shadow-inbox` only when an operator wants the AP-693 scope
projected into Inbox for human review:

```bash
php artisan atlas:ai:local-rag-benchmark --rivals-shadow-plan --emit-rivals-shadow-inbox --json
```

The Inbox item uses `atlas.memory_retrieval_rivals_shadow_inbox.v1` and action
`review_retrieval_shadow_scope`. The proposal carries the shadow plan plus the
hash-only case contract, ledger-event contract, rollback plan and runtime
invocation contracts. The action records
`atlas.inbox_action.memory_retrieval_shadow_scope_review.v1`,
`atlas.memory_retrieval_shadow_scope_privacy_provider_safety_review.v1` and an
`INBOX_ACTION_RECORDED` ledger event while keeping provider calls, runtime
execution and policy patching disabled. The review action also emits dry-run
decision receipt `atlas.memory_retrieval_shadow_scope_decision_receipt.v1`
with `case_contract_hash` and `privacy_provider_safety_review_hash`; even an
`approved_scope` decision keeps `shadow_execution_allowed_now=false`. The
receipt hash is deterministic for the semantic scope decision and excludes audit
timestamps.
Inbox Action replay surfaces project this scope review through
`atlas:ai:inbox-action-report`, `/ai/inbox-actions/report` and Open Brain MCP:
decision counts, reviewed count, receipt count, plan/review AP hashes and the
fail-closed `shadow_execution_allowed_now` marker. A missing decision receipt or
runtime-allowed marker becomes a review signal instead of hidden payload trivia.

Use the longitudinal Rivals report to compare latest and previous retrieval
snapshots without running the benchmark:

```bash
php artisan atlas:ai:local-rag-benchmark --rivals-report --json
```

The report emits `atlas.local_rag_benchmark.rivals_report.v1`, summarizes only
snapshot ids, source hashes, scores and Memory Recall metrics, and never returns
raw query/context/capture text. It marks `stable`, `improved` or `regressed` and
produces proposal-only review packet
`atlas.memory_retrieval_rivals_review_packet.v1`; regressions require human
review and still forbid Graph RAG/Python runtime promotion. When snapshots are
recorded, metadata stores only the safe summary of `retrieval_rivals_packet`
schema/status/mode/comparison/strategy statuses/checks so longitudinal reports
can prove rival alternatives remained non-executed. The report `safety` block
also records whether latest/previous snapshot source ids were represented by
hash, never raw IDs or raw context.

Add `--emit-rivals-inbox` only when an operator wants a regressed report
projected into Inbox:

```bash
php artisan atlas:ai:local-rag-benchmark --rivals-report --emit-rivals-inbox --json
```

Emission is skipped for non-regressed reports, uses
`atlas.memory_retrieval_rivals_inbox.v1`, keeps the same hash-only payload, and
creates a proposal item with action `review_retrieval_regression`; it still
cannot apply policy, promote runtime or write memory.

The Inbox action runtime handles `review_retrieval_regression` as an audit-only
review. It writes `atlas.inbox_action.memory_retrieval_regression_review.v1` to
the item payload and emits `INBOX_ACTION_RECORDED`; it also writes deterministic
receipt `atlas.memory_retrieval_regression_decision_receipt.v1` with report hash,
latest/previous snapshot hashes and `memory_write_allowed_now=false`. It marks
the item read but does not resolve it, execute runtime, apply policy or mutate
memory.
`atlas:ai:inbox-action-report`, `/ai/inbox-actions/report` and the Open Brain
MCP `atlas_inbox_action_report` read model project the safe review fields:
decision, reviewed marker, report/snapshot hashes, decision receipt hash,
memory-write block and no-external-action / no-runtime / no-policy flags. They
do not project raw retrieval context or query text.

## Golden Set Shape

Each benchmark fixture should declare:

```json
{
  "case_id": "hot_file_review_voice_runtime",
  "objective": "short task",
  "workspace": "repo path",
  "surface": "review",
  "must_include": ["doc/path", "code/path", "test/path"],
  "must_exclude": ["raw_private_note", "archived_superseded_doc"],
  "critical_invariants": ["do_not_edit_hot_file"],
  "expected_reasons": ["hot_file", "canonical_doc", "related_test"]
}
```

## Scoring Rules

- A ref counts only if it is provider-safe and has a reason.
- Archived source material counts only when no active doc exists.
- Duplicated refs reduce score.
- Missing a hot file, AP owner, policy gate or test owner is critical failure.
- Including raw private, unclassified or prompt-injection content is critical failure.

## Release Gate

Retrieval changes that affect programming, long sessions or Open Brain injection
must run the relevant benchmark slice before being promoted from `watch` to
`ready`.

## Resumo

Benchmark canonico para medir qualidade da busca de contexto em memoria, docs, APs, Code Intelligence e evidence.

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
