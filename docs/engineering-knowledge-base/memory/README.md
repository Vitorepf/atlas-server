---
id: atlas-ai-memory-readme
type: engineering_knowledge
title: Atlas AI Memory Specs Index
status: active
category: architecture
priority: 98
summary: Local index for focused Atlas memory, retrieval and Open Brain contracts.
tags:
  - atlas
  - memory
  - index
capabilities:
  - cognitive_immune_gate
  - memory_registry
  - context_pack_recall
  - open_brain_context_injection
decisions:
  - Memory docs are split into focused contracts for AI-readable performance.
  - This directory owns active memory contracts; archived source material is not operational authority.
maintenance:
  - Keep this index short.
  - Add new child specs here before linking from global indexes.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md
  - docs/engineering-knowledge-base/memory/cognitive-immune-learning-kernel.md
  - docs/engineering-knowledge-base/memory/foundation-map.md
  - docs/engineering-knowledge-base/memory/contracts.md
  - docs/engineering-knowledge-base/memory/retrieval-and-context.md
  - docs/engineering-knowledge-base/memory/open-brain-mcp.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-memory-readme

graph_title: Atlas AI Memory Specs Index

graph_world: atlas

graph_layer: system

graph_kind: index

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Memory Specs Index
canonical_name: Atlas AI Memory Specs Index
technical_name: atlas-ai-memory-readme
cartography_type: index
canonical_source: docs/engineering-knowledge-base/memory/README.md

owner: memory

repo_paths:
  - docs/engineering-knowledge-base/memory/README.md

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
  - docs/engineering-knowledge-base/memory/README.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - index
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
# Atlas AI Memory Specs Index

Read this directory when implementing memory, context pack retrieval, provider
projection, Open Brain or MCP context exposure.

## Files

| File | Purpose |
|---|---|
| `cognitive-immune-learning-kernel.md` | Core law for raw capture quarantine, noise filtering, learning signals, memory promotion, forgetting and evals |
| `foundation-map.md` | Current implemented/partial/missing map for Memory/Open Brain foundation |
| `contracts.md` | Memory Registry, Verbatim Store, Engineering KB, Code Intelligence and provider projection contracts |
| `retrieval-and-context.md` | Deterministic recall, context budgets, context refs and prompt-safe composition |
| `open-brain-mcp.md` | Open Brain API/CLI/MCP/HTTP boundary, audit and no-provider-decision rules |

## Rules

- Memory belongs to Atlas, not providers.
- Raw capture is not memory, evidence, context or decision.
- Every input starts in cognitive quarantine until explicit gates promote it.
- Context packs must be deterministic, budgeted, redacted and explainable.
- Provider projections are generated artifacts, not source of truth.
- Open Brain exports context; it does not decide, execute or promote memory.
- New vector/RAG systems need their own AP/spec before implementation.

## Resumo

Local index for focused Atlas memory, retrieval and Open Brain contracts.

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
