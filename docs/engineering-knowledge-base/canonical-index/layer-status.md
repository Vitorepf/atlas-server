---
id: atlas-ai-canonical-layer-status
type: engineering_knowledge
title: Atlas AI Canonical Layer Status
status: active
category: architecture
priority: 95
summary: Compact status of Atlas AI architecture layers and next enterprise blocks.
tags:
  - atlas-ai
  - architecture-index
  - status
capabilities:
  - canonical_architecture_index
decisions:
  - Layer status is descriptive and must not override Kernel, Master or domain specs.
maintenance:
  - Update when major APs change readiness.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-canonical-layer-status

graph_title: Atlas AI Canonical Layer Status

graph_world: atlas

graph_layer: system

graph_kind: index

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Canonical Layer Status
canonical_name: Atlas AI Canonical Layer Status
technical_name: atlas-ai-canonical-layer-status
cartography_type: index
canonical_source: docs/engineering-knowledge-base/canonical-index/layer-status.md

owner: canonical-index

repo_paths:
  - docs/engineering-knowledge-base/canonical-index/layer-status.md

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
  - canonical-index

evidence:
  - docs/engineering-knowledge-base/canonical-index/layer-status.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - index
  - canonical-index

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
# Atlas AI Canonical Layer Status

| Layer | Current state |
|---|---|
| Layer 0 | Active in compact provider-safe form through glossary and human/vault boundaries. |
| Layer 0.5 | Documentation OS, Knowledge Governance and session bootstrap active. |
| Layer 0.6 | Research Self-Improvement Runtime documented as source-backed evolution law. |
| Layer 0.7 | Spec Operating System documented as SDD law; runtime implementation still phased. |
| Layer 0.8 | Self-Construction OS documented as governed self-programming law; runtime starts read-only/advisory. |
| Layer 1 | Implemented foundation: typed envelope/receipt, capability registry, domain catalog, ledger, surface/provider contracts, failure domains, SLOs and validation. |
| Layer 2 | Master Architecture active; large source doc remains grandfathered. |
| Layer 2.5 | Qualitative Levels active as roadmap/read model, not autonomy permission. |
| Layer 3 | Pipeline, core/domain rules, operating system and governance active. |
| Layer 4 | 15 domains registered ready, including Programming and Self-Improvement. |

## Current Enterprise Blocks

- AP-146/AP-147 for AP-99, provider cost/performance and dynamic compute market.
- Ledger projections and replay health.
- Self-Improvement recurring jobs and review signals.
- Documentation governance, session bootstrap and feature placement.
- Cognitive Immune Learning Kernel for memory quality and noise containment.
- Cognitive Development APs.
- AP-684 Graphify External Graph Harness as planned Code Intelligence source-material harvest, not runtime.
- AP-688 Cognitive Runtime for memory, retrieval, 72h sessions and compaction.
- AP-689 Research Self-Improvement Runtime for source-backed evolution.
- AP-690 Spec Operating System for SDD governance.
- AP-691 Self-Construction OS for governed self-programming.

Each block must ship code, tests and docs together.

## Resumo

Compact status of Atlas AI architecture layers and next enterprise blocks.

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
