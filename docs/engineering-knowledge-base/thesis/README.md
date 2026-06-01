---
id: atlas-thesis-specs-index
type: engineering_knowledge
title: Atlas Thesis Specs Index
status: source_material
category: constitutional
priority: 100
summary: Local index for the Layer -1 thesis that makes Atlas a provider-agnostic multiplier and sovereign channel.
tags:
  - atlas-ai
  - thesis
  - constitutional
capabilities:
  - canonical_thesis
  - architectural_north_star
  - feature_decision_filter
decisions:
  - The thesis is split into focused specs for AI-readable performance.
  - The parent thesis remains the first authority in strategic conflicts.
maintenance:
  - Keep this index short.
  - Add new thesis child specs before expanding the parent.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-thesis-multiplier-channel.md
  - docs/engineering-knowledge-base/thesis/multiplier-channel.md
  - docs/engineering-knowledge-base/thesis/provider-antifragility.md
  - docs/engineering-knowledge-base/thesis/rivals-validation.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-thesis-specs-index

graph_title: Atlas Thesis Specs Index

graph_world: atlas

graph_layer: module

graph_kind: index

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas Thesis Specs Index
canonical_name: Atlas Thesis Specs Index
technical_name: atlas-thesis-specs-index
cartography_type: index
canonical_source: docs/engineering-knowledge-base/thesis/README.md

owner: thesis

repo_paths:
  - docs/engineering-knowledge-base/thesis/README.md

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
  - thesis

evidence:
  - docs/engineering-knowledge-base/thesis/README.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - module
  - index
  - thesis

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
# Atlas Thesis Specs Index

Read this directory when a feature touches provider strategy, channel ownership,
Rivals, anti-wrapper positioning, model selection, provider releases or the
question "does this make Atlas stronger when providers improve?"

## Files

| File | Purpose |
|---|---|
| `multiplier-channel.md` | Core formula, canal unico, gravity and feature filter |
| `provider-antifragility.md` | Provider release ingestion, structural moats and residual threats |
| `rivals-validation.md` | Empirical validation of the multiplier through Atlas Rivals |

## Rule

Atlas does not compete with Claude, ChatGPT, Gemini, Codex or future models at
model level. Atlas replaces direct provider use as the governed operational
channel that orchestrates, audits and multiplies them.

## Resumo

Local index for the Layer -1 thesis that makes Atlas a provider-agnostic multiplier and sovereign channel.

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
