---
id: atlas-ai-architecture-audit-capability-ownership-map
type: engineering_knowledge
title: Atlas AI Architecture Audit Capability Ownership Map
status: active
category: architecture
priority: 87
summary: Ownership map for capabilities identified by the architecture audit, preventing duplication across surfaces and domains.
tags:
  - atlas-ai
  - architecture
  - ownership
capabilities:
  - flow_consolidation
  - anti_duplication_governance
decisions:
  - Every repeated capability must have one owner and be consumed by surfaces/domains.
maintenance:
  - Update when a capability owner changes in the canonical architecture index.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-architecture-audit.md
  - docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-architecture-audit-capability-ownership-map

graph_title: Atlas AI Architecture Audit Capability Ownership Map

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

owner: architecture-audit

repo_paths:
  - docs/engineering-knowledge-base/architecture-audit/capability-ownership-map.md

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
  - architecture-audit

evidence:
  - docs/engineering-knowledge-base/architecture-audit/capability-ownership-map.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - module
  - architecture-audit

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
# Atlas AI Architecture Audit Capability Ownership Map

| Capability | Canonical owner | Notes |
|---|---|---|
| Domain/intent resolution | Atlas AI Core | Classifies domain, risk and task type. |
| Provider/model/policy choice | Atlas Decide | Emits receipt; does not execute. |
| Policy profiles | Policy/Profile layer | Merges global, domain, flow, surface, risk and session overrides. |
| Domain/flow profiles | Profile resolver | Separates vertical domain from executable flow. |
| Base context | Context Builder | Task, conversation, workspace and policy context. |
| Memory/Open Brain | Memory Context Core | Provider-safe knowledge, memory quality and code refs. |
| Engineering context | Programming domain | Deep repo/task context for programming flows. |
| Programming | Programming domain | Unifies `dev`, `forge`, `fix`, `continue`, app and workers. |
| Heavy harness | Engineering Harness | Runtime intensity, not separate product. |
| Tools | Super Tool Runtime | Registry, planner, policy, executor, normalizer and evidence. |
| Programming gates | Programming Quality Matrix | Composes basic, tool, blueprint and release gates. |
| Repair | Domain repair loop | Uses failure taxonomy and repair capsule. |
| Evidence packet | Evidence layer | Domain-specific final packet and ledger events. |
| Telemetry | Telemetry/read models | Quality, cost, latency, repair, provider performance. |
| Evolution | Self-Improvement/Curator | Detects gaps, drift and duplicate behavior. |

## Placement Rule

If more than one surface needs it, it is not surface-owned. If more than one
domain needs it, it is Core or Runtime. If it executes external commands, it is
Runtime or Tool Runtime.

## Resumo

Ownership map for capabilities identified by the architecture audit, preventing duplication across surfaces and domains.

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
