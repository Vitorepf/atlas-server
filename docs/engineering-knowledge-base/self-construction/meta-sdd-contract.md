---
id: atlas-ai-self-construction-meta-sdd-contract
type: engineering_knowledge
title: Atlas Self-Construction Meta-SDD Contract
status: active
category: architecture
priority: 100
summary: SDD rules for changing Atlas itself.
tags:
  - atlas-ai
  - self-construction
  - meta-sdd
capabilities:
  - meta_sdd
  - self_construction_spec_operating_system_bridge
decisions:
  - Atlas core changes require Meta-SDD, not ordinary feature specs.
  - Meta-SDD must include layer impact, maturity delta and system risk.
maintenance:
  - Update when SDD runtime or Atlas core construction workflow changes.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
  - docs/engineering-knowledge-base/self-construction/build-graph.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-meta-sdd-contract

graph_title: Atlas Self-Construction Meta-SDD Contract

graph_world: atlas

graph_layer: gear

graph_kind: contract

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo
human_name: Atlas Self-Construction Meta-SDD Contract
canonical_name: Atlas Self-Construction Meta-SDD Contract
technical_name: atlas-ai-self-construction-meta-sdd-contract
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/self-construction/meta-sdd-contract.md

repo_paths:
  - docs/engineering-knowledge-base/self-construction/meta-sdd-contract.md

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
  - docs/engineering-knowledge-base/self-construction/meta-sdd-contract.md

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
# Atlas Self-Construction Meta-SDD Contract

Meta-SDD is the SDD of Atlas itself. It is stricter than normal SDD because the
system being changed is the system that decides future changes.

## Required Meta-Spec Fields

```yaml
meta_spec:
  id:
  title:
  target_layer:
  target_capability:
  current_maturity:
  target_maturity:
  problem:
  goal:
  non_goals:
  dependencies:
  affected_authority_docs:
  affected_runtime_components:
  risk_level:
  autonomy_allowed:
  rollback_strategy:
  evidence_required:
```

## Layer Classification

Every Meta-SDD must classify the target:

```text
L0 documentation/governance
L1 Kernel/Decision/Evidence
L2 Memory/Cognitive Runtime
L3 Research/Self-Improvement
L4 SDD/Programming Harness
L5 Product Surface/UI/API/Mobile/Voice
L6 Tool Runtime/MCP/External Integrations
L7 Autonomy/Self-Programming
```

## Required Questions

Before implementation, answer:

- What exact Atlas capability improves?
- Which existing law already governs this?
- Which docs must change first?
- Which code areas are allowed?
- Which current behavior must remain unchanged?
- Which gates prove success?
- What evidence is enough?
- What failure would make the change unsafe?
- What rollback is possible?

## Meta-SDD Flow

```text
gap
-> layer/risk classification
-> research if knowledge is unstable
-> docs update
-> meta-spec
-> critic/security/architecture review
-> plan/tasks
-> receipt
-> small implementation
-> gates
-> evidence
-> drift check
-> maturity update proposal
```

## Prohibitions

- No Atlas core code change from plain user intent.
- No "quick fix" for self-construction without at least minimal Meta-SDD.
- No hidden maturity promotion.
- No spec that lacks rollback for risky changes.
- No self-programming expansion when documentation and context are stale.

## Minimal Meta-SDD Exception

Tiny documentation fixes may use a lightweight spec if:

- no runtime behavior changes;
- no authority order changes;
- no policy/autonomy/security changes;
- docs-health and diff check pass.

Even then, evidence must exist in the final report.

## Resumo

SDD rules for changing Atlas itself.

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
