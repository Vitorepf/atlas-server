---
id: legacy-cleanup-readme
type: engineering_knowledge
title: Legacy Cleanup README
status: source_material
category: documentation-governance
priority: 88
summary: Entry point for Atlas legacy documentation cleanup, inventory, execution waves and handoff.
tags:
  - atlas
  - documentation
  - cleanup
capabilities:
  - legacy_documentation_cleanup
decisions:
  - Legacy cleanup is governed by focused child docs and compact owner indexes.
  - The archived full reports are source material, not day-to-day authority.
maintenance:
  - Update when a child cleanup doc is added or retired.
related_paths:
  - docs/engineering-knowledge-base/legacy-documentation-cleanup-report.md
  - docs/engineering-knowledge-base/legacy-documentation-cleanup-plan.md
  - docs/engineering-knowledge-base/legacy-cleanup/inventory-summary.md
  - docs/engineering-knowledge-base/legacy-cleanup/executed-promotions.md
  - docs/engineering-knowledge-base/legacy-cleanup/waves-and-gates.md
  - docs/engineering-knowledge-base/legacy-cleanup/handoff-checklist.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: legacy-cleanup-readme

graph_title: Legacy Cleanup README

graph_world: atlas

graph_layer: system

graph_kind: index

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Legacy Cleanup README
canonical_name: Legacy Cleanup README
technical_name: legacy-cleanup-readme
cartography_type: index
canonical_source: docs/engineering-knowledge-base/legacy-cleanup/README.md

owner: legacy-cleanup

repo_paths:
  - docs/engineering-knowledge-base/legacy-cleanup/README.md

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
  - docs/engineering-knowledge-base/legacy-cleanup/README.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - index
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
# Legacy Cleanup README

This folder holds focused documentation for cleaning, promoting, redirecting and
archiving legacy Atlas docs.

## Read Order

| Step | Doc | Use |
|---|---|---|
| 1 | `legacy-documentation-cleanup-report.md` | Active report index. |
| 2 | `inventory-summary.md` | Cleanup classes and current status. |
| 3 | `executed-promotions.md` | What was already promoted or archived. |
| 4 | `legacy-documentation-cleanup-plan.md` | Active plan index. |
| 5 | `waves-and-gates.md` | How to execute a cleanup wave. |
| 6 | `handoff-checklist.md` | Final report and validation checklist. |

## Ownership

Legacy cleanup is a documentation governance workflow. It can update canonical
docs only when the change is a stable decision promotion or a redirect fix.

## Source Material

Full historical audit files live in:

- `archive/source-material/legacy-documentation-cleanup-report-full-2026-05-08.md`
- `archive/source-material/legacy-documentation-cleanup-plan-full-2026-05-08.md`

Use them only when the compact docs do not contain enough historical detail.

## Rule For AI Sessions

An AI session must not create a new canonical architecture path from a legacy
doc. It must first ask: which current owner doc should receive this decision?

## Resumo

Entry point for Atlas legacy documentation cleanup, inventory, execution waves and handoff.

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
