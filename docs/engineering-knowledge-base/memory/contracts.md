---
id: atlas-ai-memory-contracts-focused
type: engineering_knowledge
title: Atlas AI Memory Contracts
status: active
category: architecture
priority: 98
summary: Focused contract for Atlas Memory Registry, Verbatim Store, Engineering Knowledge Base, Code Intelligence and Provider Projection.
tags:
  - atlas
  - memory
  - contracts
capabilities:
  - cognitive_immune_gate
  - memory_registry
  - verbatim_store
  - engineering_knowledge_base
  - code_intelligence_index
  - provider_projection
decisions:
  - Raw capture, evidence, learning signal, memory, context and decision are separate layers.
  - Atlas memory is canonical only after governed promotion.
  - Provider projections are generated from Atlas memory and may be regenerated.
  - Privacy and redaction apply before provider context or projection.
maintenance:
  - Keep schema and API changes here, not in the compact parent index.
  - Link implementation APs or tests instead of pasting long histories.
related_paths:
  - docs/engineering-knowledge-base/memory/cognitive-immune-learning-kernel.md
  - docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md
  - docs/engineering-knowledge-base/memory-core-contracts.md
  - docs/engineering-knowledge-base/memory-core-security-privacy.md
  - docs/engineering-knowledge-base/memory-core-maturity-dod.md
  - docs/engineering-knowledge-base/open-brain-context-injection.md
  - docs/engineering-knowledge-base/obsidian-atlas-vault.md
  - docs/engineering-knowledge-base/archive/source-material/memory-core-contracts-full-2026-05-08.md
---

# Atlas AI Memory Contracts

## Scope

This spec owns active memory contracts. It does not own retrieval ranking or Open
Brain transport; those live in sibling specs.

## Cognitive Immune Law

The Memory Core must preserve this separation:

```text
Raw Capture != Evidence != Learning Signal != Memory != Context != Decision
```

Every raw note, chat, voice transcript, CLI prompt, file, capture or vault item
starts with:

```text
memory_eligible=false
context_eligible=false
constellation_eligible=false
embedding_allowed=false
promotion_status=unclassified
```

For API/app captures, this is persisted as `captures.metadata.cognitive_quarantine`
with source lineage and a content hash. The capture can feed review and curation,
but cannot enter provider context, memory registry, embeddings or constellation
surfaces until an explicit promotion path changes those flags.
The same block includes `immune_audit` (`atlas.capture.cognitive_immune_audit.v1`)
with invariant, noise gate, promotion gates and audit hash; it proves why raw
capture is not memory/context/decision and never authorizes learning, embedding
or promotion. Capture/proposal safety resources may expose only schema/status/hash,
invariant when present, noise-gate status and closed learning/memory/context/
constellation flags, never raw capture text, raw evidence or provider payloads.
Duplicate capture ingest by `client_id` must remain idempotent and emit
`atlas.capture.ingest_replay_receipt.v1` audit evidence with content/client
hashes, quarantine flags and `raw_content_exposed=false`.

Semantic curation proposals inherit that quarantine as
`atlas.capture.curation_proposal_quarantine.v1`. A proposal may preserve the raw
source for local human ratification, but audit evidence must store only redacted
source fingerprints and the quarantine receipt.

Accepting a curation proposal can explicitly promote memory only when the caller
sets `promote_to_memory=true`. That path must use `ai_memory_deltas`,
`AtlasMemoryDeltaPromotionService` and a `atlas.memory.promotion_receipt.v1`
receipt linking proposal, capture, semantic note, delta and memory entry.
Capture-backed `ai_memory_deltas` must carry quarantine evidence with content
hash, proposal link and immune audit hash/status; legacy captures use deterministic
`legacy_missing` hash-only fallback with learning/memory/context flags closed.
`atlas.memory_delta.safety.v1` may project those fields by hash/status only.
Exact capture evidence can be promoted only when the caller sets
`promote_to_verbatim=true`. That path must use `AtlasVerbatimMemoryService`,
store provider-safe redaction metadata and emit
`atlas.verbatim_memory.promotion_receipt.v1`.

Pending `ai_memory_deltas` are first-class review items. The API must expose
list/show/review surfaces and review writes must produce
`atlas.memory_delta.review_receipt.v1` with previous status, target status,
reviewer, timestamp and hash-only reason/claim evidence. Review can accept or
reject a delta; promotion into Memory Registry remains a separate operation and
must fail closed unless the delta is accepted or an explicit force override is
used by a governed operator path.

Memory Delta API payloads must expose `safety` as
`atlas.memory_delta.safety.v1`. Pending/rejected deltas are not memory eligible,
accepted deltas are promotion candidates only, and Open Brain/context eligibility
stays closed until a governed promotion creates a Memory Registry entry. Safety
metadata may include hashes/counts, not raw review reasons.

Memory Registry API payloads must expose `safety` as `atlas.memory_entry.safety.v1`,
derived from status, privacy, redaction and content hash; provider filtering
still stays in Memory Privacy before recall/projection. Promoted memories also
expose `atlas.memory_entry.lineage_safety.v1`: source type, hash-only source/
delta identity, origin, evidence count, confirmation, valid window and freshness
status, so Open Brain/Constelacao can reason without raw promotion evidence.

Verbatim Store API payloads must expose `safety` as
`atlas.verbatim_memory.safety.v1`, including whether exact local text was
requested via `include_verbatim`, provider/Open Brain eligibility derived from
redaction and `external_ai_allowed`, plus content/redacted hashes. This lets
review UIs distinguish local exact evidence from provider-safe redacted context.
The same safety contract is emitted by `atlas:memory:verbatim --json`.

Open Brain context pack exports must expose `safety` as
`atlas.open_brain.context_pack_safety.v1`, declaring provider-safe-only export,
raw-content exposure/persistence closed, audit query raw persistence closed,
audit persistence state and safe counts for refs/recall/registry/verbatim/
semantic inputs. Audit `query_json` must be hash-only for objective/workspace,
and audit listing APIs must sanitize legacy rows and expose
`atlas.open_brain.audit_query_safety.v1`.

Accepting a curation proposal can also promote exact reviewed local text to
Verbatim Store with `promote_to_verbatim=true`. That path must write
`atlas.verbatim_memory.promotion_receipt.v1`, link proposal, capture, semantic
note and verbatim memory, and keep external AI disabled by default unless an
operator explicitly overrides the provider-safe redaction policy.

Capture and Inbox responses expose `review_workflow` as the UI contract for this
stage. It lists only IDs, receipt summaries and action descriptors for proposal
ratification, Memory Registry promotion, Verbatim Store promotion and memory
delta review. It also exposes `review_workflow.safety` and
`SemanticCurationProposalResource.safety` from cognitive quarantine so UI/API
consumers can fail closed for provider export, Open Brain context and raw
content exposure. It must not include raw capture evidence.

Capture/Inbox pipeline integrity is checked by
`atlas:ai:capture-inbox-pipeline-report --json`. It is read-only and must report
recent capture quarantine, content intelligence, proposal lineage, memory delta,
capture link and operational inbox coverage before captures can be treated as
Memory/Open Brain inputs. Legacy capture repair is isolated in
`atlas:ai:capture-inbox-pipeline-backfill-contracts`; dry-run is default, and
`--write` may only add conservative metadata contracts/backlinks while keeping
provider export, Open Brain context, embeddings and memory eligibility closed.

Promotion is earned by evidence, scope, privacy, utility, outcome and review.
Delete/archive/manual cleanup are helpful hygiene, but they are not the primary
intelligence filter.

## Sources

| Source | Authority |
|---|---|
| Repo docs | Durable architecture, ADRs, domain contracts and playbooks |
| Postgres Memory Registry | Live operational memories and governed promotions |
| Verbatim Store | Exact text evidence with redacted provider-safe exposure |
| Engineering Knowledge Base | Canonical engineering docs indexed for context |
| Code Intelligence Index | Modules, symbols, routes, commands, tests and doc links |
| Provider Projection Audit | Projection target, checksum, drift, apply and purge audit |

## Required Memory Types

- `decision`
- `preference`
- `feedback`
- `technical_context`
- `issue`
- `resolution`
- `benchmark_observation`
- `harness_learning`
- `anti_memory`
- `strategic_insight`
- cognitive and domain-specific types only when declared by their domain specs

## Reference Contracts

Context packs carry refs, not full dumps.

| Ref | Source | Required fields |
|---|---|---|
| `memory_refs` | `AtlasMemoryRegistryService::relevantForContext()` | `type`, `id`, `memory_type`, scope, priority, source, `reason` |
| `verbatim_refs` | Verbatim Store | `type`, `id`, `verbatim_type`, scope, redacted `snippet`, `reason` |
| `knowledge_refs` | Engineering Knowledge Base | `type`, `id`, `slug`, `title`, `canonical_path`, `content_hash`, `summary`, `reason` |
| `code_refs` | Engineering Code Intelligence | `type`, `id`, `slug`, `name`, `layer`, `root_path`, counts, related docs/tests, `reason` |

Rules:

- every ref must explain why it entered context;
- refs must be provider-safe before prompt composition;
- full body inclusion is exceptional and budgeted;
- `content_hash` or equivalent identity is required for drift detection;
- undocumented code refs are maintenance gaps, not proof that code is absent.

## Recall Metadata Contract

Every composed `memory.recall[]` item must expose:

- `lineage`: selected source, source ref type/id, origin type/id/label and `content_hash`;
- `freshness`: `recorded_at`, optional `last_used_at`, age and stale-review status;
- `audit_trail`: schema version, provider-safe flag, privacy/redaction status, review timestamps and `content_hash`.

This metadata is part of the Open Brain foundation. It lets Kernel/surfaces
explain why context was included, detect stale or drifted memory, and audit
provider exports without logging raw private content.

## Privacy Contract

- Raw private text must not enter provider prompts.
- Verbatim evidence must expose redacted text by default.
- `external_ai_allowed=false` blocks provider context and projection.
- Sensitive/secret classes require review before any export.
- Provider-safe summaries may be used when raw evidence is blocked.

## Provider Projection Contract

`CLAUDE.md`, `AGENTS.md` and future provider bootstrap files are projections.
They must preserve manual blocks where configured, but canonical memory is rebuilt
from Atlas docs and registries.

A projection report must expose:

- target;
- workspace;
- checksum/drift status;
- provider-safe memory count;
- last generated/applied timestamp;
- next actions.

## API Contract

| Surface | Endpoints |
|---|---|
| Memory Registry | `/ai/memory`, `/tasks/{task}/memory`, `/projects/{project}/memory`, `/engineering/runs/{run}/memory` |
| Audit/governance | `/ai/memory/audit/traces/{trace}`, `/ai/memory/privacy/scan`, `/ai/memory/governance/scan`, `/ai/memory/review-queue` |
| Verbatim/projection | `/ai/memory/verbatim*`, `/ai/memory/provider-projection*` |
| Recall/quality | `/ai/memory/recall`, `/ai/memory/quality`, `/ai/memory/quality/history`, `/ai/memory/quality/snapshots` |
| Maintain | `/ai/memory/maintain` |
| Knowledge/code | `/engineering/knowledge*`, `/engineering/knowledge/code*` |
| AtlasVault | `/ai/vault/status`, `/ai/vault/import`, `/ai/vault/export-semantic`, `/ai/vault/sync`, `/ai/vault/conflicts*` |

Local HTTP APIs use the same services as CLI. Predictable input/path/mode
failures return stable JSON errors and must not become SQL/runtime exceptions.

## CLI Contract

| Command family | Role |
|---|---|
| `atlas:memory:*` | Registry, audit, privacy, governance, verbatim, projection, recall, quality and maintain |
| `atlas:vault *` | Human vault status, managed note dry-run/write, import/export/sync, conflict review and resolution |
| `atlas:open-brain:*` | Audited context export and local read-only MCP serving |
| `atlas:engineering:knowledge` | Docs sync, code intelligence index, drift and context preview |

CLI JSON failures should use exit code `1` with stable `{ok:false,error}`
payloads for missing files, unsafe paths, missing entities, invalid filters and
ambiguous dry-run/write modes.

CLI JSON success payloads for `atlas:memory:add`, `atlas:memory:list` and
`atlas:memory:privacy review` carry `atlas.memory_entry.safety.v1`.
`atlas:memory:seed-core --json` carries `atlas.memory_entry.safety.v1` for each
seeded core memory.
`atlas:memory:verbatim` carries `atlas.verbatim_memory.safety.v1`.
`/ai/memory/review-queue` and `atlas:memory:review-queue --json` carry safety
on memory/verbatim review items and relation memory summaries.
`/ai/memory/relations` and `atlas:memory:relations --json` carry safety on
source/target memory summaries so conflict/duplicate review never requires raw
content exposure.

## AtlasVault Boundary

Obsidian/AtlasVault is Human Knowledge Surface, not operational primary source.
It can import notes into reviewable candidates and export managed human notes,
but it cannot promote `atlas_memory_entries` without classification, privacy,
redaction and review.

Managed notes must:

- use frontmatter, `atlas://` links and managed/manual block boundaries;
- preserve `## Manual Notes`;
- block unsafe path traversal and symlink escape;
- refuse invalid privacy/provider-safe combinations;
- record conflicts instead of overwriting silently.

## Non-Goals

- No ChromaDB/vector search without AP.
- No provider-owned memory.
- No silent promotion from chat transcript.
- No raw capture, trivial query, operational reminder or prompt injection as canonical memory.
- No Obsidian-as-operational-primary behavior.
