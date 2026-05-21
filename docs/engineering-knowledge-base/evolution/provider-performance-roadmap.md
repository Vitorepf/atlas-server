---
id: atlas-ai-evolution-provider-performance-roadmap
type: engineering_knowledge
title: Provider Performance Evolution Roadmap
status: active
category: roadmap
priority: 95
summary: Roadmap for AP-99, model selection, provider launches, Dynamic Compute Market and empirical provider routing.
tags:
  - atlas-ai
  - provider-performance
  - model-selection
  - ap-99
capabilities:
  - provider_performance_contract
  - model_selection_policy
  - dynamic_compute_market
decisions:
  - Atlas Decide owns model/provider choice in auto modes.
  - Manual model selection is an audited override, not the default path.
  - Provider launches are harvested into drivers, benchmarks, skills or domain recipes.
maintenance:
  - Keep provider-specific logic behind Provider Driver contracts.
  - Update benchmarks before changing default routing.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-provider-evolution-market-intelligence.md
  - docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md
  - docs/engineering-knowledge-base/atlas-ai-evolution-roadmap.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-evolution-provider-performance-roadmap

graph_title: Provider Performance Evolution Roadmap

graph_world: atlas

graph_layer: flow

graph_kind: module

graph_parent: atlas-ai-pipeline

graph_status: active

graph_source: repo
human_name: Provider Performance Evolution Roadmap
canonical_name: Provider Performance Evolution Roadmap
technical_name: atlas-ai-evolution-provider-performance-roadmap
cartography_type: module
canonical_source: docs/engineering-knowledge-base/evolution/provider-performance-roadmap.md

owner: evolution

repo_paths:
  - docs/engineering-knowledge-base/evolution/provider-performance-roadmap.md

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
  - docs/engineering-knowledge-base/evolution/provider-performance-roadmap.md

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
# Provider Performance Evolution Roadmap

## Modes

| Mode | Meaning |
|---|---|
| `auto_best_allowed` | choose the best model allowed by policy, budget and privacy |
| `auto_best_available` | choose the best known model when user explicitly permits higher cost/risk |
| `manual_override` | user picks provider/model; receipt records override and consequences |

## AP-99 Role

AP-99 turns provider use into empirical evidence:

1. task domain and flow;
2. provider/model;
3. latency and cost;
4. gate outcomes;
5. repair rate;
6. human acceptance;
7. final quality score.

This lets Atlas route by evidence instead of brand preference.

## Provider Launch Intake

When Claude, OpenAI, Gemini or another provider launches a specialized product:

1. summarize capability;
2. classify as provider driver, skill, benchmark, tool recipe or irrelevant;
3. run Rivals comparison when possible;
4. promote useful patterns into Atlas contracts;
5. keep direct provider usage behind Atlas.

## Dynamic Compute Market

Atlas Decide should eventually act as a compute broker: cheap local or fast
models for low-risk work, premium models for high-risk architecture, finance,
strategy and final review.

## Anti-Fragility Test

A provider release is good for Atlas if Atlas can absorb it without changing
user habit. If the user needs to leave Atlas to use the release, the surface or
driver layer is incomplete.

## Resumo

Roadmap for AP-99, model selection, provider launches, Dynamic Compute Market and empirical provider routing.

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
