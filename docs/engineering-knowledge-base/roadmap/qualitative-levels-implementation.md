---
id: atlas-ai-qualitative-levels-implementation
type: engineering_knowledge
title: Atlas AI Qualitative Levels Implementation Queue
status: active
category: roadmap
priority: 86
summary: Focused implementation queue for Atlas qualitative levels QL-0 through QL-7.
tags:
  - atlas-ai
  - qualitative-levels
  - roadmap
capabilities:
  - qualitative_levels_implementation_plan
decisions:
  - Qualitative level implementation remains proposal/gated until Evidence Ledger and Rivals prove benefit.
maintenance:
  - Update when a QL item changes implementation status.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-qualitative-levels-roadmap.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-qualitative-levels-implementation

graph_title: Atlas AI Qualitative Levels Implementation Queue

graph_world: atlas

graph_layer: flow

graph_kind: module

graph_parent: atlas-ai-pipeline

graph_status: active

graph_source: repo
human_name: Atlas AI Qualitative Levels Implementation Queue
canonical_name: Atlas AI Qualitative Levels Implementation Queue
technical_name: atlas-ai-qualitative-levels-implementation
cartography_type: module
canonical_source: docs/engineering-knowledge-base/roadmap/qualitative-levels-implementation.md

owner: roadmap

repo_paths:
  - docs/engineering-knowledge-base/roadmap/qualitative-levels-implementation.md

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
  - roadmap

evidence:
  - docs/engineering-knowledge-base/roadmap/qualitative-levels-implementation.md
evidence_refs:
  - symbol: AtlasQualitativeLevelsImplementationService
  - command: atlas:aaeos:qualitative-levels-implementation
  - test: AtlasQualitativeLevelsImplementationTest

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: low

visual_tags:
  - flow
  - module
  - roadmap

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
# Atlas AI Qualitative Levels Implementation Queue

| Item | Status | Notes |
|---|---|---|
| QL-0 Documentation promotion | Done | Source roadmap preserved, compact KB spec active. |
| QL-1 Maturity read model | Implemented | `atlas ai qualitative-levels`, API read model. |
| QL-2 Rivals Strategy | Implemented | `atlas:ai:rivals-strategy report --json` exposes P4 promotion readiness, next review command, no-synthetic-score rule and blocked actions until a real scored revisit preserves agency. |
| QL-3 Strategic Decision scaffold | Implemented scaffold | No automatic external execution. |
| QL-4 Co-Strategist plan-only | Started | Counterargument, values alignment, cool-down, agency gate. |
| QL-5 Curator mutation classes | Future | Class-1/2/3 mutation governance. |
| QL-6 Presence and eclipse | Started | Mobile Inbox/Push has explicit proactive opt-out, manual eclipse for non-critical push, quiet-hours support and hash-only delivery receipts; broader environment presence remains future. |
| QL-7 Voice Realtime Surface | Scaffold active | Mobile-first voice, LiveKit Agents SDK, Swift native edge later. |

## Rule

No P4+ claim without Evidence Ledger, Rivals evidence and human agency gates.
`p4_promotion_readiness.status=ready` is required before any P4 claim; pending
scheduled reviews are evidence of discipline, not proof of outcome.

## Resumo

Focused implementation queue for Atlas qualitative levels QL-0 through QL-7.

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
