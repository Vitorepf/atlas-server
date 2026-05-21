---
id: atlas-ai-self-construction-capability-maturity-ladder
type: engineering_knowledge
title: Atlas Self-Construction Capability Maturity Ladder
status: active
category: architecture
priority: 99
summary: Maturity levels for Atlas capabilities from documented idea to strategic self-programming.
tags:
  - atlas-ai
  - self-construction
  - maturity
capabilities:
  - capability_maturity_ladder
  - self_construction_capability_maturity_ladder
decisions:
  - Atlas capabilities must advance by evidence-backed maturity levels.
  - A capability is not complete because it is documented or scaffolded.
maintenance:
  - Update before changing readiness labels, roadmap scoring or implementation status language.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-capability-maturity-ladder

graph_title: Atlas Self-Construction Capability Maturity Ladder

graph_world: atlas

graph_layer: gear

graph_kind: module

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo
human_name: Atlas Self-Construction Capability Maturity Ladder
canonical_name: Atlas Self-Construction Capability Maturity Ladder
technical_name: atlas-ai-self-construction-capability-maturity-ladder
cartography_type: module
canonical_source: docs/engineering-knowledge-base/self-construction/capability-maturity-ladder.md

repo_paths:
  - docs/engineering-knowledge-base/self-construction/capability-maturity-ladder.md

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
  - docs/engineering-knowledge-base/self-construction/capability-maturity-ladder.md

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
# Atlas Self-Construction Capability Maturity Ladder

Atlas must distinguish idea, law, scaffold, runtime and autonomous competence.

## Levels

| Level | Name | Meaning |
|---|---|---|
| L0 | Named | Capability is identified but not governed. |
| L1 | Documented | Canonical docs define purpose, boundaries and owner. |
| L2 | Specified | SDD/AP/spec defines behavior, contracts and tests. |
| L3 | Scaffolded | Files/classes/routes may exist, but behavior is incomplete. |
| L4 | Executable Manual | Human can run the flow with commands/tests. |
| L5 | Agent Executable | Agent can execute scoped tasks through receipts and gates. |
| L6 | Autonomous Restricted | Atlas can run the loop for low-risk work with evidence and rollback. |
| L7 | Self-Improving Governed | Atlas can propose and validate improvements to its own construction system. |
| L8 | Strategic Self-Construction | Atlas can choose high-leverage next work, justify it, execute safely and improve future execution. |

## Promotion Requirements

| Promotion | Required Proof |
|---|---|
| L0 -> L1 | Canonical doc and owner. |
| L1 -> L2 | AP/spec, acceptance criteria, risk and non-goals. |
| L2 -> L3 | Scaffold with tests or explicit scaffold marker. |
| L3 -> L4 | Passing manual command or test proving behavior. |
| L4 -> L5 | Agent can execute with Decision Receipt and gates. |
| L5 -> L6 | Repeated successful runs, rollback and drift checks. |
| L6 -> L7 | Learning proposals improve future runs without unsafe mutation. |
| L7 -> L8 | Priority engine, build graph and metrics prove strategic selection quality. |

## Anti-Confusion Rule

Never describe a capability as "ready" without its maturity level.

Good:

```text
Spec Operating System is L1/L2 documented and specified; runtime is not yet L5.
```

Bad:

```text
Spec Operating System is complete.
```

## Current Target Framing

Self-Construction OS begins as:

```text
Documentation maturity: L1/L2
Runtime maturity: L0/L1
Autonomous maturity: L0
```

The next step is runtime implementation that promotes selected low-risk slices
from L2 to L4, then L5.

## Resumo

Maturity levels for Atlas capabilities from documented idea to strategic self-programming.

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
