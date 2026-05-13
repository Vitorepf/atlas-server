---
id: atlas-ai-spec-operating-system
type: engineering_knowledge
title: Atlas AI Spec Operating System
status: active
category: architecture
priority: 100
summary: Canonical SDD layer that compiles simple user intent into context-grounded specs, plans, tasks, receipts, execution, gates, evidence and learning.
tags:
  - atlas-ai
  - sdd
  - spec-operating-system
  - programming
capabilities:
  - spec_operating_system
  - sdd_core
  - spec_compiler
  - spec_graph
decisions:
  - Atlas SDD is an internal capability, not a manual ritual for the user.
  - Simple intent must become governed engineering artifacts before risky execution.
  - Specs are grounded in canonical docs, repo, Knowledge DB, Code Intelligence, design system and business context.
  - `.atlas` files may be projections or local project adapters, never a parallel source of truth above canonical docs and receipts.
  - One-shot execution is allowed only when context confidence is high and gates are available.
maintenance:
  - Update when Spec Compiler, Spec Graph, Drift Detector or SDD runtime become executable.
  - Read before changing Programming harness, task contracts, code agent prompts, design-system flows or AP execution automation.
related_paths:
  - docs/engineering-knowledge-base/spec-operating-system/context-discovery-and-business-context.md
  - docs/engineering-knowledge-base/spec-operating-system/spec-compiler-and-critic.md
  - docs/engineering-knowledge-base/spec-operating-system/plan-task-and-receipt-contract.md
  - docs/engineering-knowledge-base/spec-operating-system/spec-graph-and-traceability.md
  - docs/engineering-knowledge-base/spec-operating-system/autonomy-and-clarification-policy.md
  - docs/engineering-knowledge-base/spec-operating-system/drift-detector-and-learning.md
  - docs/engineering-knowledge-base/spec-operating-system/templates-and-schemas.md
  - docs/engineering-knowledge-base/spec-operating-system/data-model-and-services.md
  - docs/engineering-knowledge-base/spec-operating-system/agents-and-mcp-contract.md
  - docs/engineering-knowledge-base/spec-operating-system/context-packages-and-projections.md
  - docs/engineering-knowledge-base/spec-operating-system/implementation-roadmap.md
  - docs/engineering-knowledge-base/engineering-blueprint.md
  - docs/engineering-knowledge-base/engineering-blueprint-contracts.md
  - docs/engineering-knowledge-base/engineering-blueprint-quality-gates.md
  - docs/engineering-knowledge-base/domains/programming.md
  - docs/engineering-knowledge-base/domains/programming-frontend-superpower.md
  - docs/ap/AP-690-atlas-spec-operating-system-contract.md
owner: atlas-ai
layer: 0.7-and-programming
line_limit: 260
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-spec-operating-system

graph_title: Atlas AI Spec Operating System

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md

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
  - architecture

evidence:
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - module
  - architecture

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
# Atlas AI Spec Operating System

Atlas Spec Operating System is the SDD layer for turning ordinary intent into
enterprise execution.

User prompt:

```text
faz um botao verde salvar
```

Atlas target behavior:

```text
intent
-> context discovery
-> business/design/architecture grounding
-> operational spec
-> assumption ledger
-> spec critic
-> plan
-> tasks
-> Decision Receipt
-> scoped execution
-> quality gates
-> evidence
-> drift detection
-> learning proposal
```

The user does not need to manually operate SDD. Atlas operates SDD internally.
The user governs intent, risk and review.

## Hard Laws

- No risky implementation without operational spec.
- No operational spec without context.
- No execution without Decision Receipt.
- No result without evidence.
- No learning that changes critical behavior without proposal/review.
- No `.atlas` local tree can outrank canonical docs, APs, Kernel or receipts.
- No blind obedience to user wording when design system, security or business
  rules contradict it.

## SDD Vs Vibe Coding

Vibe coding:

```text
request -> code -> maybe tests
```

Atlas SDD:

```text
request -> context -> spec -> plan -> receipt -> execution -> gates -> evidence
```

The difference is not bureaucracy. The difference is traceable intent.

## Canonical Flow

```text
Surface receives intent
-> Operation Envelope
-> Intent / domain / risk routing
-> Context Discovery
-> Business Context
-> Spec Compiler
-> Spec Critic / Ambiguity Gate
-> Plan Compiler
-> Task Compiler
-> Atlas Decide
-> Decision Receipt
-> Execution Harness
-> Quality Gates
-> Evidence Ledger
-> Spec Drift Detector
-> Self-Improvement proposal
```

## Example: Green Save Button

Atlas must not blindly emit:

```tsx
<button className="bg-green-500">Salvar</button>
```

It must determine:

- which screen/form is active;
- what object is being saved;
- whether a submit handler exists;
- design system button token;
- validation/loading/disabled/error/success states;
- permission and backend endpoint;
- tests/gates required;
- whether "green" conflicts with the design system.

If design system says saves use `primary`, Atlas should use the design token and
record the divergence:

```text
User asked green; project policy uses primary for save actions. Atlas preserved
design-system consistency.
```

## Authority Map

| Area | Doc |
|---|---|
| Context discovery | `spec-operating-system/context-discovery-and-business-context.md` |
| Spec compiler/critic | `spec-operating-system/spec-compiler-and-critic.md` |
| Plan, tasks, receipt | `spec-operating-system/plan-task-and-receipt-contract.md` |
| Spec graph | `spec-operating-system/spec-graph-and-traceability.md` |
| Autonomy/clarification | `spec-operating-system/autonomy-and-clarification-policy.md` |
| Drift/learning | `spec-operating-system/drift-detector-and-learning.md` |
| Templates/schemas | `spec-operating-system/templates-and-schemas.md` |
| Data model/services | `spec-operating-system/data-model-and-services.md` |
| Agents/MCP | `spec-operating-system/agents-and-mcp-contract.md` |
| Context packages/projections | `spec-operating-system/context-packages-and-projections.md` |
| Roadmap | `spec-operating-system/implementation-roadmap.md` |

## Atlas Integration

Atlas SDD reuses existing Atlas primitives:

- canonical docs are law;
- APs govern risky evolution;
- Knowledge DB and Code Intelligence supply context;
- Operation Envelope describes the work;
- Atlas Decide selects runtime/model/autonomy;
- Decision Receipt authorizes execution;
- Evidence Ledger proves result;
- Self-Improvement proposes template/policy improvements;
- docs-health and architecture-validate protect the system.

## Completion Target

Atlas becomes elite SDD when it can reliably execute:

```text
simple intent
-> correct context
-> small spec
-> scoped patch
-> tests
-> evidence
```

without making the user manually write spec, plan and tasks.

## Resumo

Canonical SDD layer that compiles simple user intent into context-grounded specs, plans, tasks, receipts, execution, gates, evidence and learning.

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
