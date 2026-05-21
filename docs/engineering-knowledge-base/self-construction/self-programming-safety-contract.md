---
id: atlas-ai-self-construction-self-programming-safety-contract
type: engineering_knowledge
title: Atlas Self-Programming Safety Contract
status: active
category: architecture
priority: 100
summary: Safety contract for Atlas implementing or modifying itself.
tags:
  - atlas-ai
  - self-construction
  - safety
capabilities:
  - self_programming_safety
  - autonomous_loop_safety
decisions:
  - Self-programming requires stronger scope, rollback and evidence than normal implementation.
  - Autonomy must shrink, not expand, when context or gates are weak.
maintenance:
  - Update before enabling self-programming beyond documentation or low-risk patches.
related_paths:
  - docs/engineering-knowledge-base/atlas-cartography-nomenclature-contract.md
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-self-programming-safety-contract

graph_title: Atlas Self-Programming Safety Contract

graph_world: atlas

graph_layer: gear

graph_kind: contract

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo
human_name: Atlas Self-Programming Safety Contract
canonical_name: Atlas Self-Programming Safety Contract
technical_name: atlas-ai-self-construction-self-programming-safety-contract
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/self-construction/self-programming-safety-contract.md

version_note: Este contrato pertence a ponte para Self-Programming OS, mas contrato de safety nao e patamar por si so. O patamar precisa ser declarado nos campos patamar_* do doc dono.

repo_paths:
  - docs/engineering-knowledge-base/self-construction/self-programming-safety-contract.md

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
  - docs/engineering-knowledge-base/self-construction/self-programming-safety-contract.md

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
# Atlas Self-Programming Safety Contract

Self-programming is allowed only when safety conditions are explicit and
enforceable.

## Required Preconditions

- Canonical docs are current.
- Context pack is fresh.
- Target capability has a Meta-SDD spec.
- Allowed files and forbidden files are declared.
- Gates exist and are runnable.
- Rollback strategy exists.
- Evidence requirements are known.
- Drift detector can run or manual drift review is defined.

## Forbidden Mutations Without Human Gate

- provider/model selection policy;
- memory deletion, promotion or privacy policy;
- auth/security boundary;
- data exfiltration boundary;
- MCP/tool write access;
- production deployment behavior;
- self-improvement auto-apply;
- Kernel receipt or Evidence Ledger semantics;
- irreversible data changes.

## Autonomy Shrink Rule

When uncertainty rises, autonomy falls.

| Condition | Max Autonomy |
|---|---|
| docs stale | propose only |
| context missing | ask |
| high risk | human approval |
| no tests | docs/spec only |
| no rollback | no execution |
| forbidden files involved | block |
| validation fails | repair inside scope or escalate |

## Receipt Scope

The receipt must include:

```yaml
self_programming_scope:
  max_files_changed:
  max_runtime_surfaces:
  allowed_layers:
  forbidden_layers:
  allowed_commands:
  forbidden_commands:
  rollback_strategy:
  evidence_required:
```

## Patch Shape

Prefer patches that are:

- small;
- reversible;
- localized;
- covered by focused tests;
- linked to one spec;
- easy to review;
- validated by architecture/doc gates.

## Safety Closeout

Every self-programming closeout reports:

- what changed;
- why it was safe;
- gates run;
- evidence recorded;
- what was not touched;
- residual risk;
- next recommended maturity step.

## Resumo

Safety contract for Atlas implementing or modifying itself.

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
