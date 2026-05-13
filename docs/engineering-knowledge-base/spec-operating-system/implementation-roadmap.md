---
id: atlas-ai-sdd-implementation-roadmap
type: engineering_knowledge
title: Atlas SDD Implementation Roadmap
status: active
category: roadmap
priority: 98
summary: Phased implementation path for Atlas Spec Operating System.
tags:
  - atlas-ai
  - sdd
  - roadmap
capabilities:
  - sdd_roadmap
decisions:
  - Build SDD in phases: foundation, auto-spec, controlled execution, state-of-art sync.
maintenance:
  - Update when APs implement any SDD subsystem.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
  - docs/ap/AP-690-atlas-spec-operating-system-contract.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-sdd-implementation-roadmap

graph_title: Atlas SDD Implementation Roadmap

graph_world: atlas

graph_layer: gear

graph_kind: module

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo

owner: spec-operating-system

repo_paths:
  - docs/engineering-knowledge-base/spec-operating-system/implementation-roadmap.md

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
  - spec-operating-system

evidence:
  - docs/engineering-knowledge-base/spec-operating-system/implementation-roadmap.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: low

visual_tags:
  - gear
  - module
  - spec-operating-system

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
# Atlas SDD Implementation Roadmap

## Phase 1 - Foundation

- confirm canonical docs/AP/Knowledge DB as source of truth;
- map Programming harness to SDD docs;
- add read-only SDD packet/schema tests;
- expose context discovery preview.

## Phase 2 - Auto-Spec

- Spec Compiler;
- Spec Critic;
- Assumption Ledger;
- clarification gate;
- spec registry/read model.

## Phase 3 - Controlled Execution

- Plan Compiler;
- Task Compiler;
- Decision Receipt extension;
- allowed-files enforcement;
- quality gate runner;
- evidence traceability.

## Phase 4 - State Of Art

- Spec Graph;
- Spec Drift Detector;
- Learning proposals;
- versioned context packages;
- low-risk auto-patch/PR flow.

## First Safe Block

Implement read-only SDD preview:

```text
raw intent -> context summary -> draft spec -> assumptions -> questions -> no code
```

This reduces future risk without giving runtime new autonomy.

## Resumo

Phased implementation path for Atlas Spec Operating System.

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
