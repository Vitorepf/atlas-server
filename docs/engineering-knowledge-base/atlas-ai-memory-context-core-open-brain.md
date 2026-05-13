---
id: atlas-ai-memory-context-core-open-brain
type: engineering_knowledge
title: Atlas AI Memory Context Core - Open Brain
status: active
category: architecture
priority: 99
summary: Compact entry point for Atlas memory, context, recall, Knowledge Base, Code Intelligence and Open Brain contracts. The full historical source was archived; active implementation detail now lives in focused child specs.
tags:
  - atlas
  - memory
  - context
  - open-brain
  - source-of-truth
capabilities:
  - cognitive_immune_gate
  - memory_registry
  - context_pack_recall
  - engineering_knowledge_base
  - code_intelligence_index
  - provider_projection
  - open_brain_context_injection
  - documentation_preservation
decisions:
  - Atlas memory belongs to Atlas, not providers.
  - Raw capture is not memory, evidence, context or decision.
  - Repo docs plus Postgres operational registries are the operational source of truth.
  - Provider files, Obsidian and chat are surfaces or projections, not primary memory.
  - Open Brain exports context with audit, policy and provider-safe redaction.
  - New memory responsibilities must be added to a focused child spec, not this index.
maintenance:
  - Keep this file under 300 lines.
  - Update child specs before or with code changes.
  - Preserve historical material in archive/source-material, never by expanding this index.
  - Run docs-health, architecture-validate, sync and index-code after changes.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-cognitive-runtime.md
  - docs/engineering-knowledge-base/cognitive-runtime/schemas-and-packets.md
  - docs/engineering-knowledge-base/cognitive-runtime/enterprise-excellence-checklist.md
  - docs/engineering-knowledge-base/cognitive-runtime/retrieval-benchmark.md
  - docs/engineering-knowledge-base/cognitive-runtime/state-of-art-research-map.md
  - docs/engineering-knowledge-base/cognitive-runtime/runbook.md
  - docs/engineering-knowledge-base/cognitive-runtime/failure-modes.md
  - docs/engineering-knowledge-base/memory/README.md
  - docs/engineering-knowledge-base/memory/cognitive-immune-learning-kernel.md
  - docs/engineering-knowledge-base/memory/contracts.md
  - docs/engineering-knowledge-base/memory/retrieval-and-context.md
  - docs/engineering-knowledge-base/memory/open-brain-mcp.md
  - docs/engineering-knowledge-base/memory-core-contracts.md
  - docs/engineering-knowledge-base/memory-core-runbook.md
  - docs/engineering-knowledge-base/open-brain-context-injection.md
  - docs/engineering-knowledge-base/obsidian-atlas-vault.md
  - docs/engineering-knowledge-base/archive/source-material/atlas-ai-memory-context-core-open-brain-full-2026-05-08.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-memory-context-core-open-brain

graph_title: Atlas AI Memory Context Core - Open Brain

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

owner: architecture

repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md

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
  - docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - module
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
# Atlas AI Memory Context Core - Open Brain

This is the compact canonical entry point for Atlas memory and Open Brain. It is
intentionally short so a new AI session can understand authority, boundaries and
where to edit without loading a 4000-line implementation diary.

The previous full document is preserved at
`archive/source-material/atlas-ai-memory-context-core-open-brain-full-2026-05-08.md`.
It is source material only. Active contracts live in the child specs below.

## Authority

| Question | Owner doc |
|---|---|
| What is Atlas memory? | `memory/contracts.md` |
| How does Atlas prevent noisy capture from becoming memory/context? | `memory/cognitive-immune-learning-kernel.md` |
| What is the integrated priority/DoD for memory, retrieval, 72h sessions and compaction? | `atlas-ai-cognitive-runtime.md` |
| How is context selected for prompts? | `memory/retrieval-and-context.md` |
| How does Open Brain expose context to tools/MCP/HTTP? | `memory/open-brain-mcp.md` |
| How do operators run, debug and maintain memory? | `memory-core-runbook.md` |
| What are privacy/security rules? | `memory-core-security-privacy.md` |
| What are failure modes? | `memory-core-failure-modes.md` |
| How mature is the implementation? | `memory-core-maturity-dod.md` |
| How does Obsidian relate to memory? | `obsidian-atlas-vault.md` + `vault/contracts.md` |

## Canonical Architecture

```text
Repo Canonical Docs + Code + Runs + Feedback
        |
        v
Cognitive Immune Gate
  - raw capture quarantine
  - promotion gates
  - learning signal filtering
        |
        v
Postgres Operational Registries
  - Memory Registry
  - Verbatim Store
  - Engineering Knowledge Base
  - Code Intelligence Index
  - Provider Projection Audit
        |
        v
Context / Retrieval Composer
  - memory_refs
  - verbatim_refs
  - knowledge_refs
  - code_refs
  - provider_projection_refs
        |
        v
Atlas Kernel / CLI / App / Harness / Open Brain
        |
        v
Provider-safe projections and exports
```

## Source Of Truth Policy

- Atlas owns memory. Providers never own Atlas memory.
- Repo docs own durable architecture, ADRs, playbooks and domain contracts.
- Postgres owns live state, audit, runs, indices and operational relationships.
- Evidence Ledger owns important runtime events and replayable decisions.
- `CLAUDE.md` and `AGENTS.md` are generated provider projections.
- Obsidian/AtlasVault is Human Knowledge Surface and managed sync surface.
- Chat history and notes are source material until promoted through governed
  memory flow.
- Raw capture, trivial queries, reminders and untrusted content never enter
  context, embeddings, Constelacao or provider projections by default.
- ChromaDB, vector stores, Streamable HTTP/SSE and multiuser sync require their
  own AP/spec and must not be smuggled into this file.

## Current Maturity

| Layer | Status | Active owner |
|---|---|---|
| Cognitive Immune Learning Kernel | contract active, implementation pending | `memory/cognitive-immune-learning-kernel.md` |
| Memory Registry | implemented | `memory/contracts.md` |
| Context Pack memory refs | implemented | `memory/retrieval-and-context.md` |
| Verbatim Store + privacy guard | implemented | `memory/contracts.md` |
| Deterministic recall composer | implemented | `memory/retrieval-and-context.md` |
| Provider projections | implemented | `memory/contracts.md` |
| Engineering KB | implemented | `memory/contracts.md` |
| Code Intelligence refs | implemented | `memory/retrieval-and-context.md` |
| Open Brain MCP/HTTP JSON-RPC | implemented local/remoto controlado | `memory/open-brain-mcp.md` |
| ChromaDB/vector search | future | requires AP |
| Streamable HTTP/SSE | future | requires AP |

## Edit Rules

1. Do not add new implementation diary sections here.
2. If changing capture quarantine, memory promotion, noise filtering, forgetting
   or learning evals, edit `memory/cognitive-immune-learning-kernel.md`.
3. If changing schema/API/contracts, edit `memory/contracts.md`.
4. If changing context selection, budget or ranking, edit `memory/retrieval-and-context.md`.
5. If changing MCP/Open Brain exposure, edit `memory/open-brain-mcp.md`.
6. If importing historical details, cite the archived source instead of copying bulk text.
7. After edits run:

```bash
atlas engineering knowledge docs-health --json
php artisan atlas:ai:architecture-validate --json
atlas engineering knowledge sync --prune --json
atlas engineering knowledge index-code --prune --json
```

## Implementation Evidence

The implementation history before this split is preserved in the archive source
material. Child specs summarize only current contracts and active responsibilities
so future agents can implement without context bloat.

## Resumo

Compact entry point for Atlas memory, context, recall, Knowledge Base, Code Intelligence and Open Brain contracts. The full historical source was archived; active implementation detail now lives in focused child specs.

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
