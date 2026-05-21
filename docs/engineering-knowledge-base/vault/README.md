---
id: atlas-vault-specs-index
type: engineering_knowledge
title: AtlasVault Specs Index
status: active
category: architecture
priority: 97
summary: Local index for Obsidian/AtlasVault as Human Knowledge Surface and Personal Knowledge Workspace.
tags:
  - atlas
  - obsidian
  - atlas-vault
  - index
capabilities:
  - obsidian_atlas_vault
  - managed_human_notes
  - vault_ingestion
decisions:
  - AtlasVault specs are split for AI-readable performance.
  - Obsidian is a human surface; operational memory lives in governed Atlas stores.
maintenance:
  - Keep this index short.
  - Add new Vault child specs here before linking from global indexes.
related_paths:
  - docs/engineering-knowledge-base/obsidian-atlas-vault.md
  - docs/engineering-knowledge-base/atlas-system-graph.md
  - docs/engineering-knowledge-base/vault/contracts.md
  - docs/engineering-knowledge-base/vault/runbook.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-vault-specs-index

graph_title: AtlasVault Specs Index

graph_world: atlas

graph_layer: system

graph_kind: index

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: AtlasVault Specs Index
canonical_name: AtlasVault Specs Index
technical_name: atlas-vault-specs-index
cartography_type: index
canonical_source: docs/engineering-knowledge-base/vault/README.md

owner: vault

repo_paths:
  - docs/engineering-knowledge-base/vault/README.md

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
  - vault

evidence:
  - docs/engineering-knowledge-base/vault/README.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - index
  - vault

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
# AtlasVault Specs Index

Read this directory before changing Obsidian, AtlasVault, semantic vault,
managed notes, markdown import/export or vault conflict handling.

## Files

| File | Purpose |
|---|---|
| `contracts.md` | Authority, frontmatter, links, note types, privacy and conflict rules |
| `runbook.md` | CLI/API operations, smoke commands, phase validation and safety gates |
| `atlas-vault-cartography-schema.md` | Index for the cartography schema split specs |
| `atlas-vault-cartography-schema-contracts.md` | Source authority, `graph_*` fields, compatibility and anti-canon rules |
| `atlas-vault-cartography-schema-runbook.md` | Reader/watcher model, migration phases and validation workflow |
| `../atlas-system-graph.md` | Contract for the Obsidian graph of Atlas systems, programs, modules and dependencies |

## Rules

- AtlasVault is Human Knowledge Surface, not operational primary source.
- Atlas System Graph notes are human navigation projections; repo docs remain canonical.
- Raw notes never enter providers without index, privacy, redaction and review.
- Atlas-generated notes are managed human projections.
- Conflicts are recorded; human edits are not overwritten silently.
- Open Brain consumes indexed/promoted artifacts, not arbitrary vault files.

## Resumo

Local index for Obsidian/AtlasVault as Human Knowledge Surface and Personal Knowledge Workspace.

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
