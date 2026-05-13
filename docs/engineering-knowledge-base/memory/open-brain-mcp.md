---
id: atlas-ai-open-brain-mcp
type: engineering_knowledge
title: Atlas AI Open Brain MCP And API Contract
status: active
category: architecture
priority: 98
summary: Focused contract for Open Brain context export through CLI, API, MCP and controlled HTTP JSON-RPC.
tags:
  - atlas
  - open-brain
  - mcp
  - context
capabilities:
  - open_brain_context_injection
  - context_pack_recall
  - provider_projection
decisions:
  - Open Brain exports governed context; it does not decide, execute or promote memory.
  - MCP/HTTP consumers receive provider-safe context packets with audit refs.
  - Streamable HTTP/SSE and multiuser sync remain future AP scope.
maintenance:
  - Keep transport/API/MCP contract changes here.
  - Do not duplicate memory schema or retrieval ranking details from sibling specs.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md
  - docs/engineering-knowledge-base/open-brain-context-injection.md
  - docs/engineering-knowledge-base/mcp-tools-contract.md
  - docs/engineering-knowledge-base/memory/contracts.md
  - docs/engineering-knowledge-base/memory/retrieval-and-context.md
---

# Atlas AI Open Brain MCP And API Contract

## Boundary

Open Brain is an access layer over Atlas context. It is not a second brain with
independent authority.

| It can | It cannot |
|---|---|
| Build/export context packs | Choose providers |
| Return docs/code/memory refs | Execute runtime tools |
| Expose MCP/API/CLI context | Promote memory silently |
| Audit requester/export/hash | Bypass privacy or policy |
| Serve local/remote controlled consumers | Become source of truth |

## Required Export Shape

Every export must include:

- schema version;
- requester/surface;
- workspace/task hints;
- policy result;
- context refs by type;
- provider-safe summaries;
- truncation/budget metadata;
- audit hash or trace id;
- generated timestamp.

Context pack exports from API, CLI and MCP must expose `safety` as
`atlas.open_brain.context_pack_safety.v1`. The safety summary declares
`provider_safe_only=true`, `raw_content_exposed=false`,
`raw_content_persisted=false`, `audit_query_raw_content_persisted=false`, audit
persistence state and safe ref counts. Open Brain audit `query_json` stores
hashes, lengths and labels only; raw objective excerpts and raw workspace paths
must not be persisted. Audit listing APIs must also sanitize legacy rows before
returning them to clients and expose `atlas.open_brain.audit_query_safety.v1`.

Memory recall surfaces must also expose safety summary counts:

- `redacted_ref_count` for refs backed by redacted/verbatim hashes;
- `raw_content_persisted_count`, which must stay `0` for provider-safe recall;
- audit trails that preserve `redacted_hash` while declaring
  `raw_content_persisted=false`.

## Supported Surfaces

- CLI context commands;
- local MCP tools;
- HTTP JSON-RPC authenticated endpoint;
- Atlas App panels that consume read-only context;
- future voice/mobile consumers through Surface Adapter, not direct bypass.

## Context Injection Contract

Automatic Open Brain injection uses this same access boundary. A surface does
not assemble memory into prompts; it sends an injection request to the backend
profile defined in `open-brain-context-injection.md`.

Required request hints:

- `surface`: `cli_dev`, `cli_continue`, `cli_chat`, `app_ai`, `mobile`,
  `voice_realtime` or `mcp_consumer`;
- `mode`: `dev`, `debug`, `review`, `programming`, `direct`, `plan` or another
  Kernel-approved intent;
- `workspace`, `objective`, optional `task_id` and optional `thread_id`;
- policy mode: `off`, `auto` or `required`;
- budget and provider-safe-only flag.

Required result fields:

- status: `injected`, `skipped`, `degraded`, `failed_open` or `failed_closed`;
- context pack hash and prompt section hash;
- audit id or trace id;
- ref counts by memory, docs, code and tool evidence;
- provider-safe and truncation metadata;
- warnings that the caller must surface or persist.

The injection result is a context export. It is not a Decision Receipt and does
not authorize provider calls by itself.

## Trace And Audit Semantics

Every Open Brain export should be explainable later from append-only metadata:

- who/what requested context;
- which workspace/objective hash was used;
- which policy decided the export;
- which refs were included or excluded;
- whether provider-safe filtering changed the packet;
- whether the output was injected, skipped, degraded or blocked.

Open Brain usage can create learning signals. It cannot silently promote memory,
apply projections or change Kernel policy without Proposal Inbox / human review
when the change affects critical behavior.

## Future Scope Requiring AP

- Streamable HTTP/SSE;
- multiuser Open Brain sync;
- external vector DB or ChromaDB;
- provider-owned memory merge;
- autonomous memory promotion from Open Brain usage.

## Operational Rule

If an external tool wants context, it asks Open Brain. If it wants to decide,
execute, repair, learn or persist, it must go through the Atlas Kernel pipeline
and Decision Receipt rules.
