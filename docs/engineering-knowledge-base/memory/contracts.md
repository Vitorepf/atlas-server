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
  - memory_registry
  - verbatim_store
  - engineering_knowledge_base
  - code_intelligence_index
  - provider_projection
decisions:
  - Atlas memory is canonical only after governed promotion.
  - Provider projections are generated from Atlas memory and may be regenerated.
  - Privacy and redaction apply before provider context or projection.
maintenance:
  - Keep schema and API changes here, not in the compact parent index.
  - Link implementation APs or tests instead of pasting long histories.
related_paths:
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
- No Obsidian-as-operational-primary behavior.
