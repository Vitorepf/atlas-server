---
id: atlas-vault-runbook
type: engineering_knowledge
title: AtlasVault Runbook
status: active
category: maintenance
priority: 97
summary: Operational runbook for Obsidian/AtlasVault status, managed notes, import/export, sync, conflicts and validation.
tags:
  - atlas
  - obsidian
  - atlas-vault
  - runbook
capabilities:
  - obsidian_atlas_vault
  - vault_ingestion
  - managed_human_notes
decisions:
  - Vault operations must be local, explicit, auditable and dry-run friendly.
  - Sync must preserve human edits and never promote memory silently.
maintenance:
  - Update this runbook when AtlasVault CLI/API commands change.
  - Keep implementation history in archive/source-material.
related_paths:
  - docs/engineering-knowledge-base/vault/contracts.md
  - docs/engineering-knowledge-base/obsidian-atlas-vault.md
  - app/Console/Commands/AtlasVaultCommand.php
  - app/Http/Controllers/AtlasVaultController.php
  - app/Services/Semantic/AtlasVaultSyncService.php
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-vault-runbook

graph_title: AtlasVault Runbook

graph_world: atlas

graph_layer: module

graph_kind: runbook

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

owner: vault

repo_paths:
  - docs/engineering-knowledge-base/vault/runbook.md

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
  - docs/engineering-knowledge-base/vault/runbook.md

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
# AtlasVault Runbook

## Status

```bash
./bin/atlas vault status --json
```

Status is read-only. It must report missing vaults without creating directories
or files.

## Managed Notes

```bash
./bin/atlas vault note --type=memory_entry --id=<id> --title="Title" --dry-run --json
./bin/atlas vault note --type=memory_entry --id=<id> --title="Title" --write --json
./bin/atlas vault note --type=memory_entry --id=<id> --title="Title" --canonical-doc=docs/engineering-knowledge-base/open-brain-context-injection.md --link=Task=atlas://task/task_456 --dry-run --json
```

Rules:

- `--dry-run` has no filesystem side effect;
- `--write` creates or updates only safe managed notes;
- `--content` updates only the managed block;
- provider-safe/privacy/redaction combinations are validated before write.

## Import, Export And Sync

```bash
./bin/atlas vault import --path=<note.md> --dry-run --json
./bin/atlas vault import --path=<note.md> --write --json
./bin/atlas vault export-semantic --semantic-note=<id> --dry-run --json
./bin/atlas vault export-semantic --semantic-note=<id> --write --json
./bin/atlas vault sync --dry-run --limit=200 --json
./bin/atlas vault sync --write --limit=200 --json
```

Import writes `semantic_notes` and curation proposals. It must not promote
`atlas_memory_entries` automatically. Predictable per-file failures become
review items instead of aborting the whole scan.

## Conflicts

```bash
./bin/atlas vault conflicts --json
./bin/atlas vault conflicts --status=conflict --direction=atlas_to_vault --operation=export_semantic_note --json
./bin/atlas vault item --item=<sync-item-id> --json
./bin/atlas vault resolve --item=<sync-item-id> --resolution=archive --reason="Reviewed" --json
./bin/atlas vault resolve --item=<sync-item-id> --resolution=regenerate --json
```

Resolution records operator intent. It does not authorize silent destructive
overwrite. `regenerate` is allowed only for safe Atlas-to-vault semantic exports.

## Local API

Authenticated by `X-Atlas-Token`:

- `GET /ai/vault/status`;
- `POST /ai/vault/import`;
- `POST /ai/vault/export-semantic`;
- `POST /ai/vault/sync`;
- `GET /ai/vault/conflicts`;
- `GET /ai/vault/conflicts/{item}`;
- `POST /ai/vault/conflicts/{item}/resolve`.

Malformed IDs, invalid filters, missing files, unsafe paths, missing entities and
ambiguous dry-run/write modes return stable JSON errors.

## Phase Validation

```bash
/opt/homebrew/bin/php artisan migrate --force
/opt/homebrew/bin/php artisan test --filter=AtlasVault
/opt/homebrew/bin/php artisan test --filter=Semantic
./bin/atlas vault sync --dry-run --limit=5 --json
./bin/atlas vault conflicts --json
./bin/atlas memory maintain --workspace=/Users/vitorepf/develop/Atlas/atlas-server --json
git diff --check
```

## Forbidden

- Remote multiuser sync without a dedicated AP.
- MCP write tools in the current phase.
- Automatic memory promotion from vault notes.
- External embeddings/vector DB from vault content without AP.
- Conflict resolution without explicit operator review.

## Resumo

Operational runbook for Obsidian/AtlasVault status, managed notes, import/export, sync, conflicts and validation.

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
