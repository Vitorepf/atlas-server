---
id: atlas-ai-resolver-corpus-p0-promotions
type: engineering_knowledge
title: Atlas AI Resolver Corpus P0 Promotions
status: active
category: architecture
priority: 86
summary: P0 resolver corpus decisions that were promoted into canonical Atlas AI architecture docs.
tags:
  - atlas-ai
  - resolver-corpus
  - promotions
capabilities:
  - resolver_corpus_governance
  - resolver_p0_domain_promotions
decisions:
  - P0 resolver material has been promoted as compact canonical decisions, not copied wholesale.
maintenance:
  - Update when a P0 source is promoted or superseded.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-resolver-corpus-audit.md
  - docs/engineering-knowledge-base/atlas-ai-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/super-tool-runtime-core.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-resolver-corpus-p0-promotions

graph_title: Atlas AI Resolver Corpus P0 Promotions

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Resolver Corpus P0 Promotions
canonical_name: Atlas AI Resolver Corpus P0 Promotions
technical_name: atlas-ai-resolver-corpus-p0-promotions
cartography_type: module
canonical_source: docs/engineering-knowledge-base/resolver-corpus/p0-promotions.md

owner: resolver-corpus

repo_paths:
  - docs/engineering-knowledge-base/resolver-corpus/p0-promotions.md

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
  - resolver-corpus

evidence:
  - docs/engineering-knowledge-base/resolver-corpus/p0-promotions.md
evidence_refs:
  - symbol: AtlasP0PromotionsService
  - command: atlas:aaeos:p0-promotions
  - test: AtlasP0PromotionsTest

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - module
  - resolver-corpus

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
# Atlas AI Resolver Corpus P0 Promotions

| Source theme | Canonical result |
|---|---|
| Domain Profile Orchestration | `Domain != flow`; `Profile != model preset`; surfaces enter domain/flow profiles. |
| Atlas Decide Final Architecture | Decide compiles intent, risk, context strategy, provider/model policy, gates, fallback and evidence into a receipt. |
| Atlas Programming Product Architecture | Programming is a domain with orchestrator and flows: dev, forge, fix, review, QA, security, refactor. |
| Super Tool Runtime Core | Tool Runtime is shared Core with registry, policy, executor, normalizer, evidence and gates. |
| Gaps/ideas source | Future backlog is governed by `atlas-ai-governed-backlog.md` and domain docs. |

## Promotion Rule

If a resolver source appears to contain missing value, first check the canonical
destination. Patch the owner doc only with the missing stable decision.

## Current Owner Docs

- `atlas-ai-operating-system.md`
- `atlas-ai-pipeline.md`
- `atlas-ai-core-vs-domain.md`
- `atlas-ai-kernel-architecture.md`
- `domains/programming.md`
- `super-tool-runtime-core.md`

## Resumo

P0 resolver corpus decisions that were promoted into canonical Atlas AI architecture docs.

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
