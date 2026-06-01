---
id: atlas-ai-resolver-corpus-readme
type: engineering_knowledge
title: Atlas AI Resolver Corpus README
status: source_material
category: architecture
priority: 86
summary: Entry point for focused resolver corpus audit docs.
tags:
  - atlas-ai
  - resolver-corpus
capabilities:
  - resolver_corpus_governance
decisions:
  - Resolver corpus docs are source material governed by KB promotion rules.
maintenance:
  - Update when child docs are added or resolver material changes class.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-resolver-corpus-audit.md
  - docs/engineering-knowledge-base/resolver-corpus/p0-promotions.md
  - docs/engineering-knowledge-base/resolver-corpus/policy-profile-model.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-resolver-corpus-readme

graph_title: Atlas AI Resolver Corpus README

graph_world: atlas

graph_layer: system

graph_kind: index

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Resolver Corpus README
canonical_name: Atlas AI Resolver Corpus README
technical_name: atlas-ai-resolver-corpus-readme
cartography_type: index
canonical_source: docs/engineering-knowledge-base/resolver-corpus/README.md

owner: resolver-corpus

repo_paths:
  - docs/engineering-knowledge-base/resolver-corpus/README.md

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
  - docs/engineering-knowledge-base/resolver-corpus/README.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - index
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
# Atlas AI Resolver Corpus README

## Purpose

This folder prevents old resolver material from being forgotten or accidentally
treated as current authority.

## Docs

| Doc | Use |
|---|---|
| `p0-promotions.md` | Stable P0 decisions already promoted into canonical docs. |
| `policy-profile-model.md` | Domain/Profile/Policy model extracted from resolver source material. |

## Source Rule

Resolver files are historical source material. The current source of truth is
the KB owner doc referenced by each promotion.

## Resumo

Entry point for focused resolver corpus audit docs.

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
