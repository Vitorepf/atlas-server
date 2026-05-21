---
id: atlas-ai-self-construction-implementation-priority-engine
type: engineering_knowledge
title: Atlas Self-Construction Implementation Priority Engine
status: active
category: architecture
priority: 100
summary: Priority law for choosing the highest-leverage Atlas construction work.
tags:
  - atlas-ai
  - self-construction
  - prioritization
capabilities:
  - implementation_priority_engine
  - self_construction_implementation_priority_engine
decisions:
  - Atlas should prioritize compounding foundations over isolated feature excitement.
  - Priority must be scored by leverage, dependency unlock, risk and evidence.
maintenance:
  - Update before changing roadmap priorities or autonomous work selection.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/self-construction/build-graph.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-implementation-priority-engine

graph_title: Atlas Self-Construction Implementation Priority Engine

graph_world: atlas

graph_layer: gear

graph_kind: module

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo
human_name: Atlas Self-Construction Implementation Priority Engine
canonical_name: Atlas Self-Construction Implementation Priority Engine
technical_name: atlas-ai-self-construction-implementation-priority-engine
cartography_type: module
canonical_source: docs/engineering-knowledge-base/self-construction/implementation-priority-engine.md

repo_paths:
  - docs/engineering-knowledge-base/self-construction/implementation-priority-engine.md

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
  - docs/engineering-knowledge-base/self-construction/implementation-priority-engine.md

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
# Atlas Self-Construction Implementation Priority Engine

Atlas must not choose work because it is interesting. It chooses work because it
maximizes durable capability.

## Priority Formula

```text
priority_score =
  strategic_leverage
+ dependency_unlocks
+ quality_improvement
+ autonomy_enablement
+ user_value
+ evidence_confidence
- risk
- implementation_size
- uncertainty
- maintenance_burden
```

## P0 Foundations

P0 construction areas:

1. governed memory;
2. context retrieval quality;
3. long session quality and compaction;
4. SDD runtime;
5. evidence, gates and drift detection;
6. research self-improvement runtime;
7. self-construction loop;
8. voice/mobile surfaces only after core contracts stay intact.

## Selection Questions

Before choosing work, Atlas asks:

- Does this unlock multiple downstream capabilities?
- Does it reduce future implementation error?
- Does it improve memory, context, SDD, evidence or gates?
- Does it make autonomous execution safer?
- Is there enough source truth and code context?
- Can it be implemented in a small reversible slice?
- Are gates available?

## Deprioritize

Deprioritize work that is:

- visually impressive but low leverage;
- provider-wrapper driven;
- not tied to a build graph dependency;
- missing source-backed research;
- missing tests/gates;
- likely to expand scope;
- likely to create parallel architecture.

## Priority Packet

Each self-construction task should carry:

```yaml
priority:
  score:
  rationale:
  p_level: P0 | P1 | P2 | P3
  unlocks:
  blocked_by:
  risk:
  smallest_safe_slice:
```

## Current Strategic Bias

Until Atlas reaches reliable autonomous runtime, the priority engine favors:

```text
memory + retrieval + SDD runtime + evidence + drift + research verification
```

over broad product expansion.

## Resumo

Priority law for choosing the highest-leverage Atlas construction work.

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
