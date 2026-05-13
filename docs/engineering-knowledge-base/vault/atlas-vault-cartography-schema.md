---
id: atlas-vault-cartography-schema
type: engineering_knowledge
title: Atlas Vault Cartography Schema
status: active
category: architecture
priority: 98
summary: Index for the Atlas Vault Cartography contract, split into source-authority/frontmatter contracts and implementation runbook.
tags:
  - atlas
  - obsidian
  - atlas-vault
  - cartography
  - schema
  - source-authority
capabilities:
  - atlas_vault_cartography
  - vault_visualization
  - architecture_navigation
  - live_documentation
decisions:
  - The official documentation is the truth; cartography is the interface that makes truth navigable and verifiable.
  - Technical and architectural content lives in repo docs; human reflective content lives in AtlasVault.
  - The cartography reads real source files and never creates a synced projection between repo and vault.
  - Semantic frontmatter remains canonical; visual fields are additive and live under the `graph_*` namespace.
maintenance:
  - Keep this file as an index only.
  - Put schema rules in the contracts child spec.
  - Put migration, readers, watchers and rollout in the runbook child spec.
related_paths:
  - docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema-contracts.md
  - docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema-runbook.md
  - docs/engineering-knowledge-base/system-graph/living-architecture-graph-contract.md
  - docs/engineering-knowledge-base/system-graph/node-catalog-and-build-contract.md
  - docs/engineering-knowledge-base/vault/contracts.md
  - docs/engineering-knowledge-base/vault/README.md
owner: atlas-ai
layer: 0.5-documentation
schema_version: 1
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-vault-cartography-schema

graph_title: Atlas Vault Cartography Schema

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

repo_paths:
  - docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema.md

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
  - docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - module
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
# Atlas Vault Cartography Schema

This index points to the focused specs for the Atlas Vault Cartography.
Read it before changing cartography, AtlasVault graph parsing, repo/vault
source authority, or `graph_*` visual frontmatter.

## Canon

The cartography is a reader, navigator and auditor over two independent
canonical sources:

| Source | Authority |
|---|---|
| Repo docs | Architecture, contracts, operations, Kernel, Runtime, policies and auditable blockers |
| AtlasVault | Human memory, books, philosophy, marginalia, stories, hypotheses and reflective writing |

The Atlas App Cartography is never a source of truth. It renders real files,
shows their origin and path, and fails closed when a file is missing.

## Split Specs

| File | Purpose |
|---|---|
| `atlas-vault-cartography-schema-contracts.md` | Source authority, semantic fields, `graph_*` visual fields, examples, compatibility and anti-canon rules |
| `atlas-vault-cartography-schema-runbook.md` | Reader/watcher model, live documentation flow, migration phases, validation tooling and deferred questions |

## Non-Negotiables

- Do not mirror repo docs into AtlasVault.
- Do not treat AI narration as source truth; the human audits the real file.
- Do not use `graph_source: vault` for canonical architectural pipelines,
  lanes, steps or components.
- Do not introduce fields outside `graph_*` for visual concerns.
- Do not render missing files as truth; use `missing_source`.

## Validation

After changing cartography specs:

```bash
atlas engineering knowledge docs-health --json
php artisan atlas:ai:architecture-validate --json
atlas engineering knowledge sync --prune --json
atlas engineering knowledge index-code --prune --json
```

## Resumo

Index for the Atlas Vault Cartography contract, split into source-authority/frontmatter contracts and implementation runbook.

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
