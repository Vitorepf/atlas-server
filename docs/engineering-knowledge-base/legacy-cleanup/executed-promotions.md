---
id: legacy-cleanup-executed-promotions
type: engineering_knowledge
title: Legacy Cleanup Executed Promotions
status: active
category: documentation-governance
priority: 86
summary: Compact record of major Atlas legacy documentation promotions, redirects and preserved source materials.
tags:
  - atlas
  - documentation
  - promotions
capabilities:
  - source_material_promotion
  - documentation_archive_governance
decisions:
  - Executed promotions are historical record; active authority lives in their destination docs.
  - Future sessions should not re-promote the same source without diffing against the destination.
maintenance:
  - Add only wave-level summaries, not full matrices.
related_paths:
  - docs/engineering-knowledge-base/legacy-documentation-cleanup-report.md
  - docs/engineering-knowledge-base/atlas-ai-layer-0-glossary.md
  - docs/engineering-knowledge-base/atlas-ai-continuity-session-state.md
  - docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md
  - docs/engineering-knowledge-base/atlas-ai-cli-multimodal.md
  - docs/engineering-knowledge-base/atlas-ai-mobile-surface-gateway.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: legacy-cleanup-executed-promotions

graph_title: Legacy Cleanup Executed Promotions

graph_world: atlas

graph_layer: system

graph_kind: policy

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

owner: legacy-cleanup

repo_paths:
  - docs/engineering-knowledge-base/legacy-cleanup/executed-promotions.md

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
  - docs/engineering-knowledge-base/legacy-cleanup/executed-promotions.md

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
# Legacy Cleanup Executed Promotions

## 2026-05-05 Promotions

| Source theme | Destination | Result |
|---|---|---|
| Atlas identity, glossary and master prompt | `atlas-ai-layer-0-glossary.md` | Layer 0 identity promoted in compact form. |
| Sessions, compaction and continuity | `atlas-ai-continuity-session-state.md` | Session continuity contract promoted. |
| Telemetry, evidence and efficiency | `atlas-ai-telemetry-evidence-performance.md` | Evidence/performance authority consolidated. |
| Paste image and CLI multimodal | `atlas-ai-cli-multimodal.md` | Multimodal capability moved out of single surface thinking. |
| Mobile gateway, push and inbox | `atlas-ai-mobile-surface-gateway.md` | Mobile surface authority documented. |
| Runtime packets | `atlas-ai-runtime-packets.md` | Packet model promoted. |
| Skill system | `atlas-ai-skill-system.md` | Skill authority documented. |
| Gaps and backlog | `atlas-ai-governed-backlog.md` | Future gaps moved to governed backlog. |
| Mac local agent | `atlas-local-agent-surface.md` | Local surface promoted. |

## Already Canonical Or Preserved

| Family | Handling |
|---|---|
| Engineering Blueprint | Canonical docs under `engineering-blueprint*.md`. |
| Memory/Open Brain | Canonical docs under memory and Open Brain docs. |
| Super Tool Runtime | Canonical docs under `super-tool-runtime-core.md` and `tool-runtime/`. |
| Finance and Personal Development | Domain specs live under `domains/`; source material remains historical. |

## Redirect Principle

If a legacy source was promoted, the destination doc is the authority. The old
source can remain useful for historical nuance, but it must not be used by an AI
to create a parallel flow.

## Re-Promotion Rule

Before promoting a source again:

1. read the destination doc;
2. identify the exact missing decision;
3. patch the owner doc;
4. preserve source link;
5. run docs-health and architecture validation.

## Resumo

Compact record of major Atlas legacy documentation promotions, redirects and preserved source materials.

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
