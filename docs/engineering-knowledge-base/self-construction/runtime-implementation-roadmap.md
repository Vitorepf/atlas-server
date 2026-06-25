---
id: atlas-ai-self-construction-runtime-implementation-roadmap
type: engineering_knowledge
title: Atlas Self-Construction Runtime Implementation Roadmap
status: active
category: architecture
priority: 98
summary: Phased roadmap for implementing Self-Construction OS runtime safely.
tags:
  - atlas-ai
  - self-construction
  - roadmap
capabilities:
  - self_construction_runtime_implementation_roadmap
  - roadmap
decisions:
  - Runtime roadmap now targets Atlas Autonomous Engineering Government, with Self-Construction OS as its Atlas-building-Atlas operating system.
  - Task Fabric, Maestro, Verification Court, Merge Governor and Learning Transfer are required before credible 24/7 autonomy claims.
  - Multi-project stewardship must be isolated by project lane before external projects can run 24/7.
  - Runtime begins read-only and advisory before autonomous patching.
  - Each phase promotes one maturity slice with tests and evidence.
maintenance:
  - Update when a phase is implemented or a new runtime slice is approved.
related_paths:
  - docs/engineering-knowledge-base/atlas-autonomous-engineering-government.md
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-runtime-implementation-roadmap

graph_title: Atlas Self-Construction Runtime Implementation Roadmap

graph_world: atlas

graph_layer: gear

graph_kind: module

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo
human_name: Atlas Self-Construction Runtime Implementation Roadmap
canonical_name: Atlas Self-Construction Runtime Implementation Roadmap
technical_name: atlas-ai-self-construction-runtime-implementation-roadmap
cartography_type: module
canonical_source: docs/engineering-knowledge-base/self-construction/runtime-implementation-roadmap.md

repo_paths:
  - docs/engineering-knowledge-base/self-construction/runtime-implementation-roadmap.md

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
  - self-construction

evidence:
  - docs/engineering-knowledge-base/self-construction/runtime-implementation-roadmap.md
evidence_refs:
  - symbol: AtlasRuntimeImplementationRoadmapService
  - command: atlas:aaeos:runtime-implementation-roadmap
  - test: AtlasRuntimeImplementationRoadmapTest

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - gear
  - module
  - self-construction

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
# Atlas Self-Construction Runtime Implementation Roadmap

Self-Construction runtime must be phased. The target is no longer a monolithic
Loop. The target is `Atlas Autonomous Engineering Government` with
Self-Construction OS as the Atlas-building-Atlas operating system.

```text
Observe -> Decide -> Architect -> Decompose -> Schedule -> Execute
-> Verify -> Merge/Reject -> Learn -> Update Knowledge -> Reprioritize
```


## Phase Overview

The roadmap moves through ten phases from documentation through multi-project
24/7 stewardship. Each phase's full deliverables, evidence list and gates live in
the wave catalog companion doc:
[runtime-implementation-roadmap-waves.md](runtime-implementation-roadmap-waves.md).

Phase goals at a glance:

- Phase 1   - Documentation And Registry
- Phase 1.5 - Government Architecture Lock
- Phase 2   - Read-Only Gap Report
- Phase 3   - Meta-SDD Artifact Generator
- Phase 4   - Receipt-Scoped Task Planner
- Phase 4.2 - Task Fabric And Maestro Contract
- Phase 4.4 - Verification Court Contract
- Phase 4.6 - Merge / Release Governor Contract
- Phase 4.5 - Traceability Guardrail
- Phase 4.8 - Promotion Gate
- Phase 5   - Low-Risk Agent Execution
- Phase 6   - Restricted Runtime Patches
- Phase 6.5 - Learning Transfer Runtime
- Phase 7   - Strategic Self-Construction
- Phase 8   - Atlas 24/7 Stewardship Lane
- Phase 9   - External Project Stewardship
- Phase 10  - Multi-Project Engineering Company Runtime

## Phase Gate

The canonical gate reference lives in
[runtime-final-autonomy-gates.md](runtime-final-autonomy-gates.md). Each gate
declares its own ready / hold / blocked evidence classes; no scalar scoring.

Summary: every phase needs tests, docs, evidence, architecture validation and
explicit residual risk; every 24/7 phase additionally needs server-side
verification, rollback, kill switch, backlog depth, structured give-back
learning, no stale Code Intelligence or knowledge sync blockers and
multi-project isolation when the scope is external. See the gate reference for
machine-verifiable evidence requirements per gate.

## Resumo

Phased roadmap for implementing Self-Construction OS runtime safely.

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
