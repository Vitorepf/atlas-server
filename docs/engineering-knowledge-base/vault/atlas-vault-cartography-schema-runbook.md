---
id: atlas-vault-cartography-schema-runbook
type: engineering_knowledge
title: Atlas Vault Cartography Schema Runbook
status: active
category: maintenance
priority: 97
summary: Implementation, reader, watcher, migration and validation runbook for Atlas Vault Cartography.
tags:
  - atlas
  - atlas-vault
  - cartography
  - runbook
capabilities:
  - atlas_vault_cartography
  - live_documentation
decisions:
  - Cartography is a source-aware reader over repo docs and AtlasVault.
  - Missing source is a visible drift state, never an acceptable default.
  - Hardcoded demo data must be retired after the graph API exists.
maintenance:
  - Update when readers, watchers, graph API or validation tooling change.
  - Keep field contracts in the contracts child spec.
related_paths:
  - docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema.md
  - docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema-contracts.md
  - docs/engineering-knowledge-base/vault/runbook.md
  - docs/atlas-vault-cartografia.md
  - public/atlas-vault-cockpit-mockup.html
owner: atlas-ai
layer: 0.5-documentation
schema_version: 1
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-vault-cartography-schema-runbook

graph_title: Atlas Vault Cartography Schema Runbook

graph_world: atlas

graph_layer: module

graph_kind: runbook

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas Vault Cartography Schema Runbook
canonical_name: Atlas Vault Cartography Schema Runbook
technical_name: atlas-vault-cartography-schema-runbook
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema-runbook.md

repo_paths:
  - docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema-runbook.md

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
  - docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema-runbook.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: low

visual_tags:
  - module
  - runbook
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
# Atlas Vault Cartography Schema Runbook

## Reader Model

The Atlas App consumes cartography through two source-aware readers:

| Concern | Repo reader | Vault reader |
|---|---|---|
| Walk | `docs/engineering-knowledge-base/` | `~/AtlasVault/` |
| Parse | frontmatter + markdown body | same |
| Index | keyed by `graph_id` | keyed by `graph_id`, deduped against repo |
| Watch | filesystem watcher on repo path | watcher on iCloud-synced vault path |
| Open | code editor / OS handler | `obsidian://open` |
| Write | managed repo/doc services only | managed note service only |

The merged graph JSON may combine both sources, but each piece keeps
`source`, source-relative path and resolution status.

## Live Documentation Flow

When an AI agent edits a canonical repo doc:

1. Atlas AI edits `docs/engineering-knowledge-base/<file>.md`.
2. The watcher detects the filesystem change.
3. Cartography re-renders affected pieces.
4. The inspector shows the real updated file content and path.
5. The piece shows a recent-update badge.
6. The human reads the real file, not an agent narration.
7. Any gap becomes a revision request or Proposal Inbox item.

This keeps the AI as operator, not source truth.

## Missing Source

If a referenced file is missing or stale:

- render `missing_source` visibly;
- show the expected path and last known cached content if available;
- disable "open source" actions that would lie;
- emit documentation drift evidence;
- allow Atlas AI to propose repair through Inbox.

Never render a missing file as healthy truth.

## Migration Phases

### Phase 0: Live-doc Tooling

- Add filesystem watchers for repo docs and AtlasVault.
- Add websocket or SSE updates for the cartography frontend.
- Add "updated recently" visual cue.

### Phase 1: Visual Layer In Repo Kernel Docs

- Stamp `schema_version: 1` and `graph_*` fields onto kernel pipeline, lane
  and continent docs.
- Use `graph_source: repo`.
- Do not touch semantic fields.

### Phase 2: Backend Readers

- Implement repo reader.
- Implement vault reader.
- Expose merged graph API with source and path on every piece.

### Phase 3: Frontend JSON Consumption

- Replace hardcoded mockup arrays with graph API data.
- Inspector must show source, path, status and evidence presence.
- Open action is source-aware.

### Phase 4: Other Continents

- Add visual fields to human continents in AtlasVault.
- Add visual fields to remaining technical continents in repo docs.

### Phase 5: Validation Tooling

- Add `php artisan atlas:vault:validate`.
- Check schema, missing sources, enum validity and `graph_source` requirements.
- Report drift as Documentation Operating System and Self-Improvement signals.

### Phase 6: Deprecate Hardcoded Data

- Remove inline `pipeline`, `regions` and `otherWorlds` arrays from mockups
  after the API is source of graph data.
- Keep mockups as thin clients only.

## Deferred Questions

- Multiple views per note through `graph_views`.
- Edge metadata on `depends_on`.
- Group inheritance from parent pipelines.
- Subcomponent layout rules.
- Cross-source wiki-link resolution.

## Validation

After any cartography schema or runbook change:

```bash
atlas engineering knowledge docs-health --json
php artisan atlas:ai:architecture-validate --json
atlas engineering knowledge sync --prune --json
atlas engineering knowledge index-code --prune --json
git diff --check
```

## Resumo

Implementation, reader, watcher, migration and validation runbook for Atlas Vault Cartography.

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
