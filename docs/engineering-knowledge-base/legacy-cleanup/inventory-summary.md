---
id: legacy-cleanup-inventory-summary
type: engineering_knowledge
title: Legacy Cleanup Inventory Summary
status: active
category: documentation-governance
priority: 87
summary: Compact inventory model for legacy Atlas documentation classes, risks and current handling.
tags:
  - atlas
  - documentation
  - inventory
capabilities:
  - legacy_documentation_inventory
decisions:
  - Inventory classes describe handling, not automatic deletion.
  - Human vault material can be high-value while still being non-operational source.
maintenance:
  - Update when a cleanup class changes or a new legacy family is discovered.
related_paths:
  - docs/engineering-knowledge-base/legacy-documentation-cleanup-report.md
  - docs/engineering-knowledge-base/legacy-cleanup/executed-promotions.md
  - docs/engineering-knowledge-base/obsidian-atlas-vault.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: legacy-cleanup-inventory-summary

graph_title: Legacy Cleanup Inventory Summary

graph_world: atlas

graph_layer: system

graph_kind: policy

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Legacy Cleanup Inventory Summary
canonical_name: Legacy Cleanup Inventory Summary
technical_name: legacy-cleanup-inventory-summary
cartography_type: policy
canonical_source: docs/engineering-knowledge-base/legacy-cleanup/inventory-summary.md

owner: legacy-cleanup

repo_paths:
  - docs/engineering-knowledge-base/legacy-cleanup/inventory-summary.md

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
  - legacy-cleanup

evidence:
  - docs/engineering-knowledge-base/legacy-cleanup/inventory-summary.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - policy
  - legacy-cleanup

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
# Legacy Cleanup Inventory Summary

## Classes

| Class | Meaning | Default action |
|---|---|---|
| `keep_canonical` | Already active authority or official support doc. | Keep and link from index/README if missing. |
| `promote_to_kb` | Contains stable decision not yet canonical. | Create small KB doc or patch owner doc. |
| `merge_into_existing` | Valuable but duplicates an owner doc. | Merge only missing decisions, then redirect. |
| `archive_with_redirect` | Historical plan, prompt, report or old spec. | Preserve with canonical replacement. |
| `archived_quarantine` | Possible delete candidate. | Keep until explicit delete review. |
| `archived_projection` | Provider/agent projection. | Preserve as historical, never source of truth. |
| `human_vault_only` | Personal, constitutional, research or sensitive note. | Keep in human surface; promote only reviewed excerpts. |

## Current Families

| Family | Handling |
|---|---|
| Canonical KB docs | Keep under `docs/engineering-knowledge-base`. |
| Deprecated KB stubs | Keep with redirect; do not expand. |
| `docs/` operational runbooks | Keep if actively useful; point to KB owner. |
| `resolver-o-que-vale-a-pena` | Historical governed corpus; not direct authority. |
| Provider bootstrap files | Archived projections; useful for history only. |
| Superpower plans/specs | Preserve as source material or redirect to canonical AP/doc. |
| AtlasVault/Obsidian notes | Human Knowledge Surface, curated sync only. |

## Decision Criteria

| Question | If yes |
|---|---|
| Is it listed by README, START_HERE or canonical index? | `keep_canonical` |
| Does it contain a decision without canonical equivalent? | `promote_to_kb` |
| Does it duplicate an owner doc but add useful detail? | `merge_into_existing` |
| Is it a prompt, plan, old report or completed spec? | `archive_with_redirect` |
| Is it personal, sensitive or research-heavy? | `human_vault_only` |
| Does it appear obsolete and unreferenced? | `archived_quarantine`, never immediate delete |

## Risk Rules

- `human_vault_only` does not mean low value.
- `archived_quarantine` does not mean delete approved.
- `promote_to_kb` means synthesize the decision; do not paste the full source.
- Any class change must preserve enough traceability for future audits.

## Resumo

Compact inventory model for legacy Atlas documentation classes, risks and current handling.

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
