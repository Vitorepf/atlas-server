---
id: atlas-ai-self-construction-failure-modes
type: engineering_knowledge
title: Atlas Self-Construction Failure Modes
status: active
category: architecture
priority: 99
summary: Known ways Atlas self-construction can fail and required countermeasures.
tags:
  - atlas-ai
  - self-construction
  - failure-modes
capabilities:
  - self_construction_failure_modes
  - failure_governance
decisions:
  - Self-construction must treat failure modes as first-class design constraints.
  - The most dangerous failures are silent drift, false completeness and unsafe learning.
maintenance:
  - Update after incidents, failed autonomous runs or drift detections.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/self-construction/self-programming-safety-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-failure-modes

graph_title: Atlas Self-Construction Failure Modes

graph_world: atlas

graph_layer: gear

graph_kind: module

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo
human_name: Atlas Self-Construction Failure Modes
canonical_name: Atlas Self-Construction Failure Modes
technical_name: atlas-ai-self-construction-failure-modes
cartography_type: module
canonical_source: docs/engineering-knowledge-base/self-construction/failure-modes.md

repo_paths:
  - docs/engineering-knowledge-base/self-construction/failure-modes.md

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
  - docs/engineering-knowledge-base/self-construction/failure-modes.md

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
# Atlas Self-Construction Failure Modes

Self-construction fails when Atlas becomes confident faster than it becomes
correct.

## Failure Table

| Failure | Signal | Countermeasure |
|---|---|---|
| Vibe self-coding | code without Meta-SDD | block; require spec and receipt |
| Parallel architecture | new flow bypasses Kernel/docs | architecture validate and AP review |
| Doc theatre | docs exist but code/gates absent | maturity ladder labels |
| False completeness | "done" without evidence | evidence closeout required |
| Scope creep | adjacent features added | receipt allowed files/actions |
| Memory contamination | weak facts promoted | Cognitive Immune gate |
| Research hallucination | claims without primary source | Evidence Lake and citation health |
| Drift | spec, code and tests diverge | drift detector |
| Unsafe learning | template/policy auto-mutated | proposal-first learning |
| Overengineering | large abstraction before need | small slice rule |
| Gate blindness | passing wrong tests | acceptance traceability |
| Long-session decay | repeated decisions, stale context | compaction and handoff metrics |

## Red Flags

Stop construction if:

- no one can name the target capability;
- the change improves no build graph dependency;
- test output does not prove acceptance criteria;
- docs and code disagree;
- implementation changes autonomy or security as a side effect;
- the agent says "complete" but cannot cite evidence.

## Required Incident Packet

When a self-construction failure occurs:

```yaml
incident:
  operation_id:
  failure_mode:
  root_cause:
  affected_docs:
  affected_files:
  failed_gates:
  rollback:
  prevention_proposal:
```

## Recovery Order

1. Stop writes.
2. Preserve evidence.
3. Identify drift/failure mode.
4. Revert only owned unsafe changes or propose rollback.
5. Update docs/spec if law was incomplete.
6. Add test/gate if failure was not detected.
7. Resume with smaller receipt.

## Resumo

Known ways Atlas self-construction can fail and required countermeasures.

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
