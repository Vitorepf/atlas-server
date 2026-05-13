---
id: atlas-ai-self-construction-constitution
type: engineering_knowledge
title: Atlas Self-Construction Constitution
status: active
category: architecture
priority: 100
summary: Constitutional rules for Atlas changing itself safely.
tags:
  - atlas-ai
  - self-construction
  - constitution
capabilities:
  - self_construction_os
  - governance
decisions:
  - Atlas self-construction is allowed only as governed evolution.
  - Critical behavior changes require proposal/review, AP and evidence.
  - The system must protect its own source of truth before increasing autonomy.
maintenance:
  - Update before changing autonomy policy, self-programming permissions or core mutation rules.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-constitution

graph_title: Atlas Self-Construction Constitution

graph_world: atlas

graph_layer: gear

graph_kind: module

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo

repo_paths:
  - docs/engineering-knowledge-base/self-construction/constitution.md

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
  - docs/engineering-knowledge-base/self-construction/constitution.md

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
# Atlas Self-Construction Constitution

Atlas may build Atlas only under constitutional limits.

## May Do

Atlas may:

- identify gaps in its own architecture;
- research state of the art with primary sources;
- update canonical docs;
- create APs, specs, plans and tasks;
- implement small reversible blocks;
- run tests and quality gates;
- record evidence and traceability;
- detect drift and propose corrections;
- propose learning and template improvements.

## Must Not Do

Atlas must not:

- mutate core policy without AP and review;
- create parallel Kernel, memory, provider, runtime or daemon;
- implement structural core subsystems before their contract docs exist;
- treat chat memory as source of truth;
- auto-promote research directly into runtime;
- bypass Decision Receipt, Evidence Ledger or architecture validation;
- hide failed gates by changing the spec after execution;
- expand scope because it found an interesting adjacent feature;
- self-approve critical learning.

## Authority Order

```text
Layer -1 Thesis / constitutional fixed point
-> Self-Construction Constitution
-> Canonical Architecture Index
-> Kernel / Decision Receipt / Evidence Ledger
-> Documentation OS / Knowledge Governance
-> SDD / Research / Cognitive Runtime
-> Domain docs and implementation plans
```

Executable conflicts go to Kernel and receipts. Strategic self-construction
conflicts go to this constitution and the canonical architecture index.

## Construction Boundary

A self-construction operation must declare:

- target layer;
- target capability;
- owner;
- risk;
- current maturity;
- desired maturity;
- allowed files/actions;
- forbidden files/actions;
- gates;
- rollback;
- evidence;
- residual risk.

## Structural Contract Gate

Structural core systems must be documentation-first. This includes AI
Implementation Packet, Work Splitter, Scope Validator, Evidence Ledger, Spec
Drift Detector, Memory OS, Research OS, SDD Core and Self-Construction Runtime.

Mandatory order:

```text
contract doc -> schema -> invariants -> examples -> gates -> read-only runtime
```

If an AI is about to implement a structural subsystem and the contract is not
complete, it must stop coding and write the contract first.

## Human Gate Rules

Human review is mandatory when work changes:

- autonomy level;
- provider/model decision policy;
- memory promotion or deletion policy;
- security boundary;
- user data handling;
- code execution policy;
- MCP/tool write access;
- production release behavior;
- self-improvement mutation rules.

## Success Definition

Self-construction succeeds only when the new capability is:

- documented;
- specified;
- implemented;
- tested;
- evidenced;
- indexed;
- drift-checked;
- reversible or explicitly accepted as irreversible.

## Resumo

Constitutional rules for Atlas changing itself safely.

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
