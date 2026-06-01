---
id: atlas-ai-kernel-roadmap-ap-index
type: engineering_knowledge
title: Atlas AI Kernel Roadmap AP Index
status: active
category: roadmap
priority: 98
summary: Focused AP and phase index for implementing the Atlas AI Kernel without expanding the mother spec.
tags:
  - atlas-ai
  - kernel
  - roadmap
  - ap
capabilities:
  - kernel_specification
  - architecture_validation
  - roadmap_handoff
decisions:
  - Kernel implementation should advance as APs, not new monolithic sections.
  - Each AP must declare contracts, services, migrations, tests, events and docs updated.
maintenance:
  - Move completed phase detail into AP docs or focused child specs.
  - Keep this as an implementation map, not a historical transcript.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/ap
  - docs/engineering-knowledge-base/evolution/implementation-handoff.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-kernel-roadmap-ap-index

graph_title: Atlas AI Kernel Roadmap AP Index

graph_world: atlas

graph_layer: flow

graph_kind: index

graph_parent: atlas-ai-pipeline

graph_status: active

graph_source: repo
human_name: Atlas AI Kernel Roadmap AP Index
canonical_name: Atlas AI Kernel Roadmap AP Index
technical_name: atlas-ai-kernel-roadmap-ap-index
cartography_type: index
canonical_source: docs/engineering-knowledge-base/kernel/roadmap-ap-index.md

owner: kernel

repo_paths:
  - docs/engineering-knowledge-base/kernel/roadmap-ap-index.md

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
  - kernel

evidence:
  - docs/engineering-knowledge-base/kernel/roadmap-ap-index.md
evidence_refs:
  - symbol: AtlasRoadmapApIndexService
  - command: atlas:aaeos:roadmap-ap-index
  - test: AtlasRoadmapApIndexTest

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: low

visual_tags:
  - flow
  - index
  - kernel

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
# Kernel Roadmap AP Index

## Phase Map

| Phase | Purpose |
|---|---|
| 0 | Promote docs and establish canonical governance |
| 1 | Operation Envelope and Kernel Pipeline scaffold |
| 2 | Deterministic Decision Receipt v2 and replay |
| 3 | Evidence Ledger append-only and projections |
| 4 | Capability Registry and surface parity |
| 5 | Domain Manifest and SDK |
| 6 | Surface Adapter Contract |
| 7 | Provider Driver Contract |
| 8 | Failure Domains and handlers |
| 9 | SLO telemetry and cost |
| 10 | Domain expansion |
| 11 | Multi-tenancy hardening |
| 12 | Self-Evolution Curator |

## Active AP Families

| Family | Examples |
|---|---|
| Retrieval and Open Brain | AP-100 to AP-105 |
| Learning proposals and inbox | AP-106 to AP-125 |
| Architecture operations | AP-126 to AP-133 |
| Decision receipt replay | AP-134 to AP-140 |
| Ledger projections | AP-141 to AP-145 |
| Provider performance | AP-146 to AP-147 and AP-99 family |
| Documentation governance | AP-173 to AP-177 |
| Agent workflow governance | AP-200 family |
| External graph candidates | AP-684 Graphify External Graph Harness |
| Voice runtime boundaries | AP-686 Python Runtime Boundary; AP-687 Production Promotion Gate |
| Recurring agent behavior schedule | AP-161 keeps `agent_behavior_review` in the default recorrente set across 13 flow profiles |

## AP Acceptance

An AP is not done until:

1. contract is documented;
2. migration/model/service exists when needed;
3. events and read models are declared;
4. CLI/API/MCP surfaces are aligned when applicable;
5. tests include happy path and guard path;
6. docs-health and architecture validation are run;
7. Knowledge sync/index is refreshed.

## Resumo

Focused AP and phase index for implementing the Atlas AI Kernel without expanding the mother spec.

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
