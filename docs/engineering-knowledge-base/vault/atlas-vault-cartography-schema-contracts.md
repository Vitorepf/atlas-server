---
id: atlas-vault-cartography-schema-contracts
type: engineering_knowledge
title: Atlas Vault Cartography Schema Contracts
status: active
category: architecture
priority: 98
summary: Source authority, frontmatter fields, compatibility and anti-canon rules for Atlas Vault Cartography.
tags:
  - atlas
  - atlas-vault
  - cartography
  - frontmatter
  - contracts
capabilities:
  - atlas_vault_cartography
  - graph_projection
  - vault_source_authority
decisions:
  - Repo docs and AtlasVault are independent canonical sources.
  - `graph_*` fields are visual-only and additive.
  - Semantic fields from the Living Architecture Graph remain authoritative.
maintenance:
  - Update when graph fields, source rules or compatibility rules change.
  - Keep rollout and implementation details in the runbook child spec.
related_paths:
  - docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema.md
  - docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema-runbook.md
  - docs/engineering-knowledge-base/system-graph/node-catalog-and-build-contract.md
  - docs/engineering-knowledge-base/system-graph/living-architecture-graph-contract.md
owner: atlas-ai
layer: 0.5-documentation
schema_version: 1
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-vault-cartography-schema-contracts

graph_title: Atlas Vault Cartography Schema Contracts

graph_world: atlas

graph_layer: system

graph_kind: contract

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas Vault Cartography Schema Contracts
canonical_name: Atlas Vault Cartography Schema Contracts
technical_name: atlas-vault-cartography-schema-contracts
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema-contracts.md

repo_paths:
  - docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema-contracts.md

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
  - docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema-contracts.md
evidence_refs:
  - symbol: AtlasVaultCartographySchemaContractsService
  - command: atlas:aaeos:atlas-vault-cartography-schema-contracts
  - test: AtlasVaultCartographySchemaContractsTest

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - contract
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
# Atlas Vault Cartography Schema Contracts

## Source Authority

Repo docs are canonical for architecture, contracts, operations, Kernel,
Runtime, policies, evidence, Forge and blockers:

```text
~/develop/Atlas/atlas-server/docs/engineering-knowledge-base/
```

AtlasVault is canonical for human memory, books, philosophy, marginalia,
stories, hypotheses and reflective writing:

```text
~/Library/Mobile Documents/iCloud~md~obsidian/Documents/AtlasVault/
```

The cartography reads both sources. It must never create a vault projection of
official repo docs, sync one source into the other, or hide the real path from
the inspector.

## Required Semantic Fields

These fields are inherited from the Living Architecture Graph and are not
changed by cartography:

```yaml
graph_id: stable-ascii-slug
type: system | module | program | note
status: active | building | planned | future | blocked | implemented | obsolete | archive
owner: atlas-ai | vitor | <person>
parent: parent-graph-id
contains: [child-graph-id]
depends_on: [graph-id]
unlocks: [graph-id]
governs: [graph-id]
implements: [graph-id]
canonical_doc: docs/engineering-knowledge-base/...
repo_paths: [path]
evidence: [link-or-command]
risks: [risk]
next_actions: [action]
tags: [atlas-system-graph]
```

Rules:

- `graph_id` is stable ASCII and should match the filename.
- `status: implemented` requires evidence.
- `next_actions` is required unless status is `implemented`, `obsolete` or
  `archive`.
- `canonical_doc` is required for repo-sourced non-note pieces.

## Visual Fields

All visual fields are optional unless noted. They are additive and must stay in
the `graph_*` namespace.

```yaml
schema_version: 1
graph_kind: continent | system | pipeline | lane | step | component | note
graph_view: atlas-ai-kernel | vault-universe | memory | obras | forge | filosofia | gargalos
graph_layer: universe | system | flow | component | subcomponent
graph_order: 10
graph_group: kernel-pipeline
graph_status: active | building | planned | future | blocked | implemented | obsolete | archive
graph_source: repo | vault | generated | sample
graph_pos: { x: 660, y: 952 }
```

Defaults:

- `graph_kind`: `note`.
- `graph_view`: `vault-universe`.
- `graph_layer`: derived from kind.
- `graph_status`: semantic `status`.
- `graph_source`: `vault` for `note`; explicit for all non-note kinds.
- `graph_pos`: omitted unless the operator needs a visual override.

## Source Rules

| `graph_source` | Meaning |
|---|---|
| `repo` | Canonical repo doc under `docs/engineering-knowledge-base/` |
| `vault` | Human-authored Obsidian note |
| `generated` | Atlas AI draft/proposal, never source truth until promoted |
| `sample` | Fixture/demo node |

`graph_source` is required for `continent`, `system`, `pipeline`, `lane`,
`step` and `component`. Architectural pieces with `graph_source: vault` are
invalid; move the canon to repo docs and link to it from the vault note.

## Compatibility

- Never rename or repurpose semantic fields.
- Never require a field that was previously optional.
- Never add visual fields outside `graph_*`.
- `status` and `graph_status` share the exact same enum.
- Wiki links remain first-class; explicit dependency fields win on conflict.
- Runtime decisions use semantic data, not cartography layout.

## Examples

Repo pipeline step:

```yaml
graph_id: atlas-decide
type: module
status: active
owner: atlas-ai
parent: atlas-ai-kernel-system
depends_on: [context-builder, policy-profile, evidence-ledger]
unlocks: [decision-receipt]
canonical_doc: docs/engineering-knowledge-base/atlas-ai-master-architecture.md
repo_paths: [app/Services/Ai/Decide]
evidence: [docs/engineering-knowledge-base/atlas-ai-pipeline.md]
next_actions: [Add mandatory dry-run above budget threshold X.]
schema_version: 1
graph_kind: step
graph_view: atlas-ai-kernel
graph_layer: flow
graph_order: 10
graph_group: kernel-pipeline
graph_source: repo
```

Vault note:

```yaml
graph_id: cartas-a-lucilio
type: note
status: active
owner: vitor
parent: memoria-livros
canonical_doc: ~/AtlasVault/01-acervo/livros/cartas-a-lucilio.md
tags: [estoicismo, leitura]
schema_version: 1
graph_kind: note
graph_view: memory
graph_layer: flow
graph_group: books
graph_source: vault
```

## Anti-Canon

- Do not create synced projections between repo and vault.
- Do not add `sync_status` or `last_verified` to paper over drift.
- Do not use `graph_source: vault` for canonical technical architecture.
- Do not trust an agent summary when the real file can be inspected.
- Do not render missing files as truth.

## Resumo

Source authority, frontmatter fields, compatibility and anti-canon rules for Atlas Vault Cartography.

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
