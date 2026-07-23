---
id: atlas-agent-protocol-v0
type: engineering_knowledge
title: Atlas Agent Protocol (AAP) v0
status: active
category: programming-forge
priority: 102
summary: JSON-RPC protocol for Terminal Dev multi-client sessions (TUI, headless, Desktop, IDE).
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-agent-protocol-v0
graph_kind: contract
graph_parent: atlas-terminal-dev-product
graph_status: active
owner: programming
canonical_source: docs/engineering-knowledge-base/atlas-agent-protocol-v0.md
---

# Atlas Agent Protocol (AAP) v0

Transport: **JSON-RPC 2.0 over stdio** (ACP-shaped for multi-client reuse).

## Client → Server

| Method | Role |
|--------|------|
| `initialize` | Negotiate protocolVersion + capabilities |
| `session/new` | cwd, mode (normal\|plan), profile (dev\|forge), permission |
| `session/load` | resume sessionId |
| `session/prompt` | user text + optional attachments |
| `session/cancel` | cancel in-flight turn |
| `permission/respond` | allow \| deny \| always |
| `plan/respond` | approve \| revise \| quit |
| `slash` | name + args |

## Server → Client (notifications)

| Method | Kinds / payload |
|--------|-----------------|
| `session/update` | agent_message_chunk, agent_thought_chunk, tool_call, tool_call_update, plan, todo, status |
| `permission/request` | tool + args summary + risk |
| `atlas/context_pack` | hash + top-K provider-safe refs |
| `atlas/gate` | gate id + result summary |
| `atlas/evidence` | receipt ids/hashes only |

## Rules

1. Brain/memory authority stays in atlas-server — clients never invent memory.
2. Provider-bound: no raw secrets/prompts in AAP payloads.
3. Plan mode: only session `plan.md` is writable until plan approved.
4. Version field: `atlas.agent.protocol.v0`.

## CLI surfaces

- `php artisan atlas:terminal --stdio` — AAP server on stdio
- `php artisan atlas:terminal --json` — oneshot prompt as event stream
- Rust `atlas-term` — TUI client
