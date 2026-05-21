---
id: atlas-ai-self-construction-structural-contract-gate
type: engineering_knowledge
title: Atlas Self-Construction Structural Contract Gate
status: active
category: architecture
priority: 100
summary: Mandatory documentation-first gate for structural Atlas subsystems before any runtime implementation.
tags:
  - atlas-ai
  - self-construction
  - governance
  - structural-contract
capabilities:
  - self_construction_structural_contract_gate
  - structural_contract_gate
  - ai_implementation_packet
  - work_splitter
  - scope_validator
decisions:
  - Structural Atlas subsystems must be specified as documentation law before runtime code begins.
  - Incremental code-first work is allowed only for local low-risk slices, not for new core coordination systems.
  - AI Implementation Packet, Work Splitter and Scope Validator are structural core systems.
maintenance:
  - Update before implementing new orchestration, self-programming, multi-agent, memory, research, SDD or governance subsystems.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/self-construction/constitution.md
  - docs/engineering-knowledge-base/self-construction/runtime-implementation-roadmap.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-structural-contract-gate

graph_title: Atlas Self-Construction Structural Contract Gate

graph_world: atlas

graph_layer: gear

graph_kind: contract

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo
human_name: Atlas Self-Construction Structural Contract Gate
canonical_name: Atlas Self-Construction Structural Contract Gate
technical_name: atlas-ai-self-construction-structural-contract-gate
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/self-construction/structural-contract-gate.md

repo_paths:
  - docs/engineering-knowledge-base/self-construction/structural-contract-gate.md

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
  - docs/engineering-knowledge-base/self-construction/structural-contract-gate.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - gear
  - contract
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
# Atlas Self-Construction Structural Contract Gate

This gate exists because Atlas must not let any AI implement a central system
before the law of that system is explicit.

## Rule

If a change creates or materially alters a structural Atlas subsystem, runtime
implementation is forbidden until a complete contract document exists.

Structural subsystems include:

- AI Implementation Packet;
- Work Splitter;
- Scope Validator;
- Evidence Ledger;
- Spec Drift Detector;
- Memory OS;
- Research Operating System;
- SDD Core;
- Self-Construction Runtime;
- provider/model decision policy;
- tool/MCP write policy;
- autonomous repair or execution loop.

## Mandatory Order

```text
1. Define contract document.
2. Define schemas and packet fields.
3. Define invariants and hard laws.
4. Define ownership boundaries.
5. Define examples and failure modes.
6. Define gates and evidence.
7. Define rollout phases.
8. Only then implement read-only runtime.
9. Only after validation consider scoped execution.
```

## Minimum Contract Checklist

A structural contract must define:

- purpose and non-goals;
- consumers and producers;
- JSON schema shape;
- allowed files/actions;
- forbidden files/actions;
- ownership and disjoint write-set rules;
- acceptance criteria;
- required gates;
- evidence requirements;
- rollback policy;
- failure modes;
- completion criteria;
- examples for at least one safe and one rejected packet.

## AI Implementation Packet Gate

Before implementing `--implementation-packet`, docs must define:

- packet schema;
- task selection policy;
- allowed and forbidden scopes;
- acceptance criteria model;
- required gates and evidence;
- rollback and repair policy;
- how another AI consumes the packet from a one-line prompt.

## Work Splitter Gate

Before implementing `--work-splitter`, docs must define:

- how many packets can be emitted;
- how disjoint write sets are enforced;
- how dependencies are represented;
- how hot files are excluded;
- how packets are assigned or reserved;
- how collision risk is reported.

## Scope Validator Gate

Before implementing `--scope-validator`, docs must define:

- how `git diff --name-only` is classified;
- how untracked files are classified;
- how allowed, forbidden and unknown paths are reported;
- which violations are blocking;
- how migrations, routes, providers, daemons and runtime entrypoints are blocked;
- how validation evidence is emitted.

## One-Line Multi-Agent Goal

The target experience is:

```text
User says: "continue a implementação"
Each AI runs Atlas Self-Construction commands
Atlas emits a safe packet for that session
The AI implements only that packet
Atlas validates scope and evidence
```

This experience is forbidden until Implementation Packet, Work Splitter and
Scope Validator all have documented contracts and read-only validation.

## Runtime Unlock Criteria

A structural subsystem may receive read-only runtime only when:

- contract doc exists and is linked by Self-Construction OS;
- examples are present;
- failure modes are explicit;
- docs-health is clean;
- architecture validate is clean or the blocker is reported as external hot scope;
- focused tests are planned before code begins.

## Non-Negotiable Invariant

For structural core systems, speed comes from correct sequencing:

```text
document -> specify -> validate -> implement read-only -> test -> evidence
```

Any AI that starts with runtime code for a structural subsystem must stop,
create or complete the contract first, and only then continue.

## Resumo

Mandatory documentation-first gate for structural Atlas subsystems before any runtime implementation.

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
