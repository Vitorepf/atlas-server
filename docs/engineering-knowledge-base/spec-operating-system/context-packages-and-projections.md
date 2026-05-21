---
id: atlas-ai-spec-operating-system-context-packages-and-projections
type: engineering_knowledge
title: Atlas Spec Operating System Context Packages And Projections
status: active
category: architecture
priority: 99
summary: Versioned context packages and local projection rules for Atlas SDD.
tags:
  - atlas-ai
  - sdd
  - context-engineering
  - projections
capabilities:
  - spec_context_packages_and_projections
  - context_discovery
decisions:
  - Agents must use versioned context packages instead of reinventing project rules.
  - Local `.atlas` trees may project canonical docs into project form, but cannot outrank them.
  - Context packages must include good examples, bad examples, commands, gates and evaluation criteria.
maintenance:
  - Update when adding project adapters, generated `.atlas` trees or SDD context package versions.
  - Keep aligned with Documentation Operating System and canonical architecture index.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
  - docs/engineering-knowledge-base/spec-operating-system/context-discovery-and-business-context.md
  - docs/engineering-knowledge-base/spec-operating-system/templates-and-schemas.md
  - docs/engineering-knowledge-base/START_HERE.md
owner: atlas-ai
layer: 0.7-and-programming
line_limit: 220
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-spec-operating-system-context-packages-and-projections

graph_title: Atlas Spec Operating System Context Packages And Projections

graph_world: atlas

graph_layer: gear

graph_kind: module

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo
human_name: Atlas Spec Operating System Context Packages And Projections
canonical_name: Atlas Spec Operating System Context Packages And Projections
technical_name: atlas-ai-spec-operating-system-context-packages-and-projections
cartography_type: module
canonical_source: docs/engineering-knowledge-base/spec-operating-system/context-packages-and-projections.md

repo_paths:
  - docs/engineering-knowledge-base/spec-operating-system/context-packages-and-projections.md

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
  - docs/engineering-knowledge-base/spec-operating-system/context-packages-and-projections.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

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
# Atlas Spec Operating System Context Packages And Projections

Atlas SDD needs stable project understanding across agents, tools and sessions.
That understanding must be packaged, versioned and evaluated.

## Context Packages

Example packages:

```text
laravel-api-v1
react-typescript-v1
atlas-design-system-v2
security-policy-v1
testing-standard-v3
product-rules-v1
decision-receipt-v1
evidence-ledger-v1
```

Each package must define:

- purpose and scope;
- authority source;
- instructions;
- good examples;
- bad examples;
- commands and gates;
- common mistakes;
- evaluation criteria;
- compatible domains/harnesses;
- version and supersession rules.

## Package Selection

Context Builder selects packages from:

- Operation Envelope;
- project stack;
- active domain;
- changed file types;
- risk level;
- current spec/receipt;
- canonical docs and Knowledge DB.

For a React + Laravel UI save action, likely packages:

```text
react-typescript-v1
atlas-design-system-v2
laravel-api-v1
testing-standard-v3
security-policy-v1
decision-receipt-v1
```

## Local Projection Tree

A local `.atlas` tree can make Atlas rules easier for external agents:

```text
.atlas/
  constitution.md
  product/
    overview.md
    personas.md
    business-rules.md
    glossary.md
    workflows.md
  architecture/
    backend.md
    frontend.md
    database.md
    security.md
    testing.md
    api-standards.md
    design-system.md
  sdd/
    policy.yaml
    templates/
      spec-template.yaml
      plan-template.yaml
      task-template.yaml
      decision-receipt-template.yaml
    agents/
      spec-agent.md
      product-critic.md
      architecture-agent.md
      task-agent.md
      qa-agent.md
      security-agent.md
      drift-detector.md
  specs/
    SPEC-YYYY-NNNN-example/
      spec.yaml
      spec.md
      plan.yaml
      tasks.yaml
      traceability.yaml
      evidence.md
      assumptions.yaml
  evidence/
    OP-YYYY-NNNN/
      decision-receipt.yaml
      diff.patch
      test-output.log
      quality-gates.json
      final-report.md
  memory/
    decisions.md
    learned-patterns.md
    rejected-patterns.md
    recurring-failures.md
```

## Projection Law

- Canonical docs, APs, Kernel and receipts outrank `.atlas` projection files.
- Projection files must declare generation source and timestamp.
- Projection drift must be detected by docs-health or dedicated projection audit.
- External agents may read projections, but Atlas must verify against canonical
  docs before risky execution.
- Projection files must not silently add permissions, tools or autonomy.

## AGENTS.md Bridge

Project root `AGENTS.md` should summarize rules for any code agent:

```text
- stack and project purpose;
- mandatory docs to read;
- forbidden areas;
- required gates;
- design system rules;
- backend/frontend conventions;
- receipt and evidence expectations.
```

`AGENTS.md` is a bridge for agents. It is not a replacement for the canonical
Atlas docs.

## Resumo

Versioned context packages and local projection rules for Atlas SDD.

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
