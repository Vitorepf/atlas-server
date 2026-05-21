---
id: atlas-ai-continuous-self-improvement-loop
type: engineering_knowledge
title: Atlas AI Continuous Self-Improvement Loop
status: active
category: learning
priority: 99
summary: Governed loop for turning evidence, research, validation and failures into self-improvement proposals.
tags:
  - atlas-ai
  - self-improvement
  - curator
  - learning
capabilities:
  - governed_self_improvement
  - research_self_improvement_proposal_generation
  - quality_learning
decisions:
  - Self-improvement remains proposal-first until promotion gates prove safety.
  - Improvement is measured by evidence, not by volume of changes.
maintenance:
  - Update when Curator gains stronger autonomy or proposal inbox changes.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-research-self-improvement-runtime.md
  - docs/engineering-knowledge-base/domains/self-improvement.md
  - docs/engineering-knowledge-base/memory/cognitive-immune-learning-kernel.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-continuous-self-improvement-loop

graph_title: Atlas AI Continuous Self-Improvement Loop

graph_world: atlas

graph_layer: module

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Continuous Self-Improvement Loop
canonical_name: Atlas AI Continuous Self-Improvement Loop
technical_name: atlas-ai-continuous-self-improvement-loop
cartography_type: module
canonical_source: docs/engineering-knowledge-base/research-self-improvement/continuous-self-improvement-loop.md

owner: research-self-improvement

repo_paths:
  - docs/engineering-knowledge-base/research-self-improvement/continuous-self-improvement-loop.md

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
  - research-self-improvement

evidence:
  - docs/engineering-knowledge-base/research-self-improvement/continuous-self-improvement-loop.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: low

visual_tags:
  - module
  - module
  - research-self-improvement

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
# Atlas AI Continuous Self-Improvement Loop

Self-Improvement converts evidence into safer future behavior.

## Loop

```text
Collect evidence
-> Detect pattern
-> Classify risk
-> Compare with docs/APs
-> Generate proposal
-> Human or gate review
-> Implement small block
-> Validate
-> Promote or rollback
```

## Inputs

- Evidence Ledger;
- failed tests;
- architecture validation findings;
- docs-health findings;
- provider performance;
- research packets;
- user corrections;
- memory quality metrics;
- retrieval benchmark results;
- long-session degradation metrics.

## Proposal Requirements

Every proposal must include:

- source evidence;
- affected docs/code;
- risk;
- expected gain;
- validation;
- rollback;
- autonomy level;
- reason it is not auto-applied.

## Promotion Levels

| Level | Meaning |
|---|---|
| `lead` | Interesting, not trusted yet. |
| `proposal` | Evidence-backed, needs review. |
| `approved_plan` | Ready for scoped implementation. |
| `validated_block` | Implemented and tested. |
| `promoted_law` | Reflected in canonical docs and runtime gates. |

## Autonomy Principle

Autonomy should increase only after repeated successful evidence cycles.
Self-Improvement earns power; it does not assume it.

## Resumo

Governed loop for turning evidence, research, validation and failures into self-improvement proposals.

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
