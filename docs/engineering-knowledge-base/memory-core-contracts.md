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
  - memory_core_contracts
  - memory_refs
  - knowledge_refs
  - code_refs
  - api_contracts
  - cli_contracts
  - open_brain_context_contracts
  - obsidian_atlas_vault
decisions:
  - Raw capture, evidence, learning signal, memory, context and decision are separate layers.
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
  - docs/engineering-knowledge-base/memory/cognitive-immune-learning-kernel.md
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
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-memory-core-contracts

graph_title: Atlas Memory Core Contracts

graph_world: atlas

graph_layer: system

graph_kind: contract

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas Memory Core Contracts
canonical_name: Atlas Memory Core Contracts
technical_name: atlas-memory-core-contracts
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/memory-core-contracts.md

owner: architecture

repo_paths:
  - docs/engineering-knowledge-base/memory-core-contracts.md

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
  - architecture

evidence:
  - docs/engineering-knowledge-base/memory-core-contracts.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - contract
  - architecture

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
# Atlas Memory Core Contracts

This parent doc is the fast entry point for Memory Core contracts. It delegates
detailed ownership to focused specs so AI sessions can load only what they need.

## Read Order

| Need | Read |
|---|---|
| Noise filtering, raw capture quarantine, promotion gates, forgetting and learning evals | `memory/cognitive-immune-learning-kernel.md` |
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

Raw captures, trivial queries, operational reminders, untrusted content and
prompt-injection text are not refs until the Cognitive Immune Learning Kernel
classifies and promotes them.

## API And CLI

The active API/CLI contract lives in `memory/contracts.md`. The rule is stable:

- every dangerous operation has dry-run or explicit confirmation;
- predictable failures return stable JSON, not unhandled framework errors;
- provider projections are generated artifacts and can be regenerated;
- memory promotion requires governance, privacy and redaction checks;
- AtlasVault imports create reviewable candidates, not automatic memories.
- raw capture starts with memory/context/embedding/Constelacao eligibility set
  to false until explicit gates promote it.

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
- Raw capture, trivial queries or reminders as canonical memory.
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

## Resumo

Compact parent contract for Memory Core, Knowledge Base, Code Intelligence, Open Brain and AtlasVault boundaries.

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
