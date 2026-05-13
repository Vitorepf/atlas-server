---
id: legacy-documentation-cleanup-report
type: engineering_knowledge
title: Legacy Documentation Cleanup Report
status: active
category: documentation-governance
priority: 90
summary: Compact index for the Atlas legacy documentation cleanup audit, with authority, current status, child specs and archived source material.
tags:
  - atlas
  - documentation
  - cleanup
  - legacy
capabilities:
  - legacy_documentation_inventory
  - documentation_archive_governance
  - source_material_promotion
decisions:
  - This active report is an index; detailed matrices live in focused child docs.
  - Legacy source material must not compete with README, START_HERE, Canonical Architecture Index or Documentation OS.
  - Full historical content is preserved under archive/source-material and is not operational authority.
maintenance:
  - Update this index when a cleanup wave changes status or adds a child spec.
  - Do not add detailed matrices here; create or update a focused child doc instead.
related_paths:
  - docs/engineering-knowledge-base/legacy-cleanup/README.md
  - docs/engineering-knowledge-base/legacy-cleanup/inventory-summary.md
  - docs/engineering-knowledge-base/legacy-cleanup/executed-promotions.md
  - docs/engineering-knowledge-base/legacy-documentation-cleanup-plan.md
  - docs/engineering-knowledge-base/archive/source-material/legacy-documentation-cleanup-report-full-2026-05-08.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: legacy-documentation-cleanup-report

graph_title: Legacy Documentation Cleanup Report

graph_world: atlas

graph_layer: system

graph_kind: policy

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

owner: documentation-governance

repo_paths:
  - docs/engineering-knowledge-base/legacy-documentation-cleanup-report.md

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
  - documentation-governance

evidence:
  - docs/engineering-knowledge-base/legacy-documentation-cleanup-report.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - policy
  - documentation-governance

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
# Legacy Documentation Cleanup Report

This document is the active report index for Atlas legacy documentation cleanup.
It exists to answer, quickly and safely:

- what the cleanup audit found;
- which document families are canonical;
- which legacy materials are archived, promoted, redirected or human-vault-only;
- where an AI should look before changing old docs.

The full original audit remains preserved at
`docs/engineering-knowledge-base/archive/source-material/legacy-documentation-cleanup-report-full-2026-05-08.md`.
Do not use the archived full file as operational authority unless this index or a
child doc asks for historical verification.

## Read Order

| Need | Read |
|---|---|
| Fast orientation | `legacy-cleanup/README.md` |
| Inventory status and cleanup classes | `legacy-cleanup/inventory-summary.md` |
| What was already promoted or archived | `legacy-cleanup/executed-promotions.md` |
| How to continue cleanup work | `legacy-documentation-cleanup-plan.md` |
| Original long audit | `archive/source-material/legacy-documentation-cleanup-report-full-2026-05-08.md` |

## Current Executive State

The Atlas canonical documentation system is mature enough to govern legacy
material. The risk is no longer "missing documentation"; the risk is old,
valuable documents competing with canonical docs and causing duplicate flows.

Current cleanup policy:

1. Keep `docs/engineering-knowledge-base` as the authoring truth.
2. Keep legacy source material preserved, but never let it outrank canonical
   index, Documentation OS, Kernel, Master Architecture or domain specs.
3. Promote only stable decisions, not full chat transcripts, prompts or plans.
4. Treat Obsidian/AtlasVault as Human Knowledge Surface, not raw runtime source.
5. Use quarantine before delete; deletion requires explicit human approval.

## Canonical Authority

| Authority | Purpose |
|---|---|
| `README.md` and `START_HERE.md` | Human and AI onboarding entry points. |
| `atlas-ai-canonical-architecture-index.md` | Decides authority in conflicts. |
| `atlas-ai-documentation-operating-system.md` | Defines documentation governance, line limits and required validation. |
| `atlas-ai-kernel-architecture.md` | Runtime/kernel contracts. |
| `atlas-ai-master-architecture.md` | Product and enterprise architecture. |

Legacy cleanup docs never override these files.

## Cleanup Classes

| Class | Meaning | Active location |
|---|---|---|
| `keep_canonical` | Document remains current authority or official support doc. | `legacy-cleanup/inventory-summary.md` |
| `promote_to_kb` | Source contains stable decision that should become KB. | `legacy-documentation-cleanup-plan.md` |
| `merge_into_existing` | Source is valuable but belongs inside an existing owner doc. | `legacy-documentation-cleanup-plan.md` |
| `archive_with_redirect` | Historical source preserved with canonical replacement. | `legacy-cleanup/executed-promotions.md` |
| `archived_quarantine` | Possible delete candidate, but delete is blocked until review. | `legacy-cleanup/inventory-summary.md` |
| `human_vault_only` | Human/personal/research material; not raw provider context. | `obsidian-atlas-vault.md` |

## Non-Negotiables

- Never delete a legacy document in the same wave that first archives it.
- Never promote personal or vault content without privacy review.
- Never copy a long legacy file into a new canonical doc.
- Never use prompt files, `AGENTS.md`, `CLAUDE.md` or provider bootstrap docs as
  source of truth.
- Never create a second master architecture because a legacy doc sounds better.

## Validation

After any cleanup wave:

```bash
atlas engineering knowledge docs-health --json
php artisan atlas:ai:architecture-validate --json
atlas engineering knowledge sync --prune --json
atlas engineering knowledge index-code --prune --workspace=/Users/vitorepf/develop/Atlas/atlas-server --json
```

## Maintenance Rule

If a future session needs to add more detail to this report, it must either:

1. update a focused child doc in `legacy-cleanup/`; or
2. create a new child doc and link it here.

This index should stay small enough for an AI session to read first.

## Resumo

Compact index for the Atlas legacy documentation cleanup audit, with authority, current status, child specs and archived source material.

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
