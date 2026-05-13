---
id: atlas-ai-evolution-context-builder-roadmap
type: engineering_knowledge
title: Context Builder Evolution Roadmap
status: active
category: roadmap
priority: 94
summary: Governed roadmap for hybrid retrieval, Graph RAG, Evidence Replay, Context Pack Cache and Context Builder routing.
tags:
  - atlas-ai
  - context-builder
  - graph-rag
  - vector-rag
capabilities:
  - hybrid_context_builder
  - retrieval_router
  - graph_rag
decisions:
  - Atlas should not choose between Vector RAG and Graph RAG; it routes by intent.
  - Evidence Ledger is the source for replayable operational memory.
  - Knowledge Graph is a projection, not the operational source of truth.
maintenance:
  - Keep retrieval changes tied to Context Builder and Kernel receipts.
  - Do not create parallel memory stores without a projection contract.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-evolution-roadmap.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-memory-core.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-evolution-context-builder-roadmap

graph_title: Context Builder Evolution Roadmap

graph_world: atlas

graph_layer: flow

graph_kind: module

graph_parent: atlas-ai-pipeline

graph_status: active

graph_source: repo

owner: evolution

repo_paths:
  - docs/engineering-knowledge-base/evolution/context-builder-roadmap.md

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
  - evolution

evidence:
  - docs/engineering-knowledge-base/evolution/context-builder-roadmap.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: low

visual_tags:
  - flow
  - module
  - evolution

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
# Context Builder Evolution Roadmap

## Target Shape

Context Builder chooses among:

1. Vector retrieval for semantic similarity;
2. Graph retrieval for relation traversal;
3. Evidence replay for prior decisions and outcomes;
4. Code intelligence for symbols, tests and modules;
5. Memory signals for preferences, lessons and quality trends.

## Routing Rules

| Question type | Primary source | Secondary source |
|---|---|---|
| Factual lookup | Vector retrieval | KB docs |
| Why did we decide X? | Evidence Replay | Decision Receipt chain |
| What breaks if this changes? | Graph RAG | Code Intelligence |
| What should Atlas improve? | Learning signals | Curator proposals |
| What does Vitor need now? | Personal memory projection | Policy/Profile |

## Graph Maturity

| Stage | Meaning |
|---|---|
| Explicit | human or service declares relationship |
| Observed | repeated evidence implies relationship |
| Inferred | model proposes relationship with confidence |
| Approved | proposal accepted and promoted |

Inferred relations never become default context without approval or strong
evidence policy.

## Next APs

| AP | Purpose |
|---|---|
| AP-101 | Retrieval Router: chooses vector/graph/evidence/code/memory per task |
| AP-102 | Retrieval Plan Summary: explain why each context source was used |
| AP-103 | Required Source Availability: block low-confidence runs missing required sources |
| AP-105 | Open Brain Retrieval Self-Improvement: proposes retrieval improvements |

## Gates

- Required-source gate for high-risk tasks.
- Provider-safe redaction before external model calls.
- Context budget accounting in the Decision Receipt.
- Evidence event for retrieval plan and source usage.

## Resumo

Governed roadmap for hybrid retrieval, Graph RAG, Evidence Replay, Context Pack Cache and Context Builder routing.

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
