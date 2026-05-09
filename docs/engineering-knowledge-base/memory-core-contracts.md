---
id: atlas-memory-core-contracts
type: engineering_knowledge
title: Atlas Memory Core Contracts
status: active
category: architecture
priority: 98
summary: Compact parent contract for Memory Core, Knowledge Base, Code Intelligence, Open Brain and AtlasVault boundaries.
tags:
  - atlas
  - memory
  - contracts
  - api
capabilities:
  - memory_refs
  - knowledge_refs
  - code_refs
  - api_contracts
  - cli_contracts
  - open_brain_context_injection
  - obsidian_atlas_vault
decisions:
  - Context packs carry small traceable refs, not full dumps.
  - APIs and CLIs expose status, dry-run and stable errors for safe operations.
  - Memory contracts are public internal interfaces for Atlas runtimes and providers.
  - Automatic Open Brain injection is provider-safe, audited and centralized in atlas-server.
  - Obsidian/AtlasVault is Human Knowledge Surface, not operational primary source.
maintenance:
  - Keep this parent compact; edit focused child docs for schemas, refs, retrieval, MCP or Vault behavior.
  - Do not add implementation history here; archive source material or create an AP.
  - Full historical source is archived in archive/source-material/memory-core-contracts-full-2026-05-08.md.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md
  - docs/engineering-knowledge-base/memory/README.md
  - docs/engineering-knowledge-base/memory/contracts.md
  - docs/engineering-knowledge-base/memory/retrieval-and-context.md
  - docs/engineering-knowledge-base/memory/open-brain-mcp.md
  - docs/engineering-knowledge-base/obsidian-atlas-vault.md
  - docs/engineering-knowledge-base/open-brain-context-injection.md
  - docs/engineering-knowledge-base/archive/source-material/memory-core-contracts-full-2026-05-08.md
  - routes/api.php
  - app/Http/Controllers/AtlasMemoryController.php
  - app/Console/Commands/AtlasVaultCommand.php
  - app/Services/Ai/AiContextPackBuilder.php
  - app/Services/Ai/AtlasOpenBrainService.php
  - app/Services/Engineering/EngineeringContextPackService.php
---

# Atlas Memory Core Contracts

This parent doc is the fast entry point for Memory Core contracts. It delegates
detailed ownership to focused specs so AI sessions can load only what they need.

## Read Order

| Need | Read |
|---|---|
| Memory source of truth, refs, APIs, CLI, provider projection | `memory/contracts.md` |
| Context composition, ranking, budgets and provider-safe retrieval | `memory/retrieval-and-context.md` |
| Open Brain MCP/API/HTTP export boundary | `memory/open-brain-mcp.md` |
| Automatic injection in dev/continue/chat/app flows | `open-brain-context-injection.md` |
| Human Obsidian/Vault sync and managed notes | `obsidian-atlas-vault.md` |
| Historical implementation narrative | `archive/source-material/memory-core-contracts-full-2026-05-08.md` |

## Canonical Tables

| Area | Tables |
|---|---|
| Memory Registry | `atlas_memory_entries`, `atlas_memory_entry_usages`, `atlas_memory_entry_relations` |
| Verbatim Store | `atlas_verbatim_memories` |
| Memory proposals | `ai_memory_deltas`, review queues and curation proposals |
| Provider Projection | `atlas_memory_provider_projection_audits` |
| Engineering KB | `atlas_engineering_knowledge_items` |
| Code Intelligence | `atlas_engineering_code_modules`, `atlas_engineering_code_symbols`, `atlas_engineering_doc_links` |
| Open Brain | `atlas_open_brain_access_logs` |
| AtlasVault Sync | `atlas_vault_sync_items` |

## Active Contracts

Context refs are the interface between memory systems and providers:

- `memory_refs`: governed Atlas memory entries;
- `verbatim_refs`: exact redacted evidence;
- `knowledge_refs`: canonical engineering docs with content hash;
- `code_refs`: indexed modules/symbols/tests/routes with documentation status.

All refs must be small, explainable, provider-safe and budgeted. Full content is
exceptional. If a task needs more context, it should request files/docs through
the proper runtime instead of stuffing prompts.

## API And CLI

The active API/CLI contract lives in `memory/contracts.md`. The rule is stable:

- every dangerous operation has dry-run or explicit confirmation;
- predictable failures return stable JSON, not unhandled framework errors;
- provider projections are generated artifacts and can be regenerated;
- memory promotion requires governance, privacy and redaction checks;
- AtlasVault imports create reviewable candidates, not automatic memories.

## Open Brain Boundary

Open Brain exports context. It does not decide, execute, promote memory or pick
providers. MCP/HTTP consumers receive provider-safe context packets with audit
refs. Any consumer that wants execution must enter the Kernel pipeline and obtain
a Decision Receipt.

## AtlasVault Boundary

Obsidian/AtlasVault is a human workspace. It is powerful for reflection,
manual synthesis and personal knowledge, but it is not the raw operational
source for providers. The operational path is:

1. human note or managed projection enters review/sync;
2. Atlas classifies privacy and redaction;
3. approved candidates become memory/semantic artifacts;
4. Open Brain consumes indexed/promoted artifacts, not arbitrary vault files.

## Forbidden

- Provider-owned memory as source of truth.
- Silent promotion from chat, Obsidian or Open Brain usage.
- Raw secret/private text in provider prompts.
- ChromaDB/vector DB introduction without AP.
- Surface-specific memory prompt assembly.
- Vault sync overwriting human edits silently.

## Validation

After changing memory, context, Vault, Open Brain or provider projection:

```bash
php artisan test tests/Unit/Ai tests/Feature/Ai tests/Feature/AtlasEngineeringKnowledgeBaseTest.php
php artisan atlas:ai:architecture-validate --json
atlas engineering knowledge docs-health
atlas engineering knowledge sync --prune
atlas engineering knowledge index-code --prune
```
