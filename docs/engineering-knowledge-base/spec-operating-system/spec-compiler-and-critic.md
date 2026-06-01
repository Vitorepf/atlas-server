---
id: atlas-ai-sdd-spec-compiler-critic
type: engineering_knowledge
title: Atlas SDD Spec Compiler And Critic
status: active
category: contracts
priority: 99
summary: Rules for compiling user intent into operational spec and critiquing ambiguity, risk and design conflicts.
tags:
  - atlas-ai
  - sdd
  - spec-compiler
  - spec-critic
capabilities:
  - spec_compiler
  - spec_critic
  - spec_assumption_ledger
decisions:
  - Spec Compiler converts intent into requirements and acceptance criteria.
  - Spec Critic must attack ambiguity, overreach, missing tests and policy/design conflicts before plan.
maintenance:
  - Update when spec compiler or ambiguity gates become executable.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
  - docs/engineering-knowledge-base/spec-operating-system/templates-and-schemas.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-sdd-spec-compiler-critic

graph_title: Atlas SDD Spec Compiler And Critic

graph_world: atlas

graph_layer: gear

graph_kind: contract

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo
human_name: Atlas SDD Spec Compiler And Critic
canonical_name: Atlas SDD Spec Compiler And Critic
technical_name: atlas-ai-sdd-spec-compiler-critic
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/spec-operating-system/spec-compiler-and-critic.md

owner: spec-operating-system

repo_paths:
  - docs/engineering-knowledge-base/spec-operating-system/spec-compiler-and-critic.md

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
  - docs/engineering-knowledge-base/spec-operating-system/spec-compiler-and-critic.md
evidence_refs:
  - symbol: AtlasSpecCompilerAndCriticService
  - command: atlas:aaeos:spec-compiler-and-critic
  - test: AtlasSpecCompilerAndCriticTest

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: low

visual_tags:
  - gear
  - contract
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
# Atlas SDD Spec Compiler And Critic

## Spec Compiler Output

Minimum fields:

- raw user request;
- interpreted goal;
- non-goals;
- product area;
- business actor/object/action;
- requirements;
- acceptance criteria;
- design-system constraints;
- security/privacy constraints;
- assumptions;
- blocking questions;
- test strategy.

## Assumption Ledger

Assumptions are never hidden.

```yaml
assumptions:
  - id: A1
    text: "The button saves the currently active profile form."
    confidence: 0.91
    evidence:
      - "active_file: ProfileForm.tsx"
      - "route: /profile/edit"
    blocking: false
```

## Spec Critic Checks

- missing target file/screen;
- missing business object;
- design-system conflict;
- hardcoded style when token exists;
- missing loading/disabled/error/success state;
- missing auth/permission rule;
- API/backend ambiguity;
- overengineering;
- scope creep;
- missing acceptance criteria;
- missing test strategy.

## Output States

| State | Meaning |
|---|---|
| `ready_for_plan` | Enough context and no blocking ambiguity. |
| `needs_clarification` | Short user question required. |
| `blocked_by_policy` | Request violates safety/design/architecture rule. |
| `spike_only` | Exploration allowed, implementation blocked. |

## Resumo

Rules for compiling user intent into operational spec and critiquing ambiguity, risk and design conflicts.

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
