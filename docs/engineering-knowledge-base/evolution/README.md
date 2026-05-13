---
id: atlas-ai-evolution-roadmap-index
type: engineering_knowledge
title: Atlas AI Evolution Roadmap Index
status: active
category: roadmap
priority: 95
summary: Bootstrap for implementing Atlas evolution without creating parallel architecture or provider-wrapper fragility.
tags:
  - atlas-ai
  - roadmap
  - evolution
capabilities:
  - atlas_ai_evolution_roadmap
  - documentation_governance
  - roadmap_handoff
decisions:
  - This folder is the execution split for atlas-ai-evolution-roadmap.md.
  - Child docs own details; the parent roadmap stays compact.
  - Any new evolution feature must declare Core, Domain, Surface, Runtime, Evidence and Curator impact.
maintenance:
  - Keep each child doc focused and under documentation line limits.
  - Link new APs back here and to the canonical architecture index.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-evolution-roadmap.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-ai-session-bootstrap.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-evolution-roadmap-index

graph_title: Atlas AI Evolution Roadmap Index

graph_world: atlas

graph_layer: flow

graph_kind: index

graph_parent: atlas-ai-pipeline

graph_status: active

graph_source: repo

owner: evolution

repo_paths:
  - docs/engineering-knowledge-base/evolution/README.md

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
  - docs/engineering-knowledge-base/evolution/README.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: low

visual_tags:
  - flow
  - index
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
# Evolution Roadmap Index

Use this folder when implementing long-term Atlas evolution. It converts the
large source roadmap into focused, high-performance docs for AI sessions.

## Child Docs

| Doc | Owns |
|---|---|
| `context-builder-roadmap.md` | Vector RAG, Graph RAG, Evidence Replay, Context Pack Cache and retrieval routing |
| `provider-performance-roadmap.md` | AP-99, Dynamic Compute Market, provider telemetry and model routing |
| `advanced-capabilities-backlog.md` | Frontier ideas such as swarms, tool synthesis, simulations and zero-click operations |
| `personal-longitudinal-roadmap.md` | privacy vault, body-cognition memory, life timeline and long-horizon signals |
| `implementation-handoff.md` | AP order, execution gates and agent handoff rules |

## Mandatory Placement Questions

Before coding any item from this folder, answer:

1. Is this Core, Domain, Surface, Runtime, Evidence, Learning or Curator?
2. Which existing contract must be extended?
3. Which events prove it worked?
4. Which policy prevents overreach?
5. Which doc owns future maintenance?

If the answer is unclear, run the feature placement command before writing code.

## Anti-Drift Rules

- Atlas does not compete with providers; it uses and replaces direct provider
  usage through a governed upper layer.
- Provider-specific launches become provider drivers, skills, benchmarks or
  domain recipes.
- No feature may bypass Decision Receipt, Policy/Profile, Evidence Ledger or
  documentation governance.

## Resumo

Bootstrap for implementing Atlas evolution without creating parallel architecture or provider-wrapper fragility.

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
