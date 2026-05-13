---
id: atlas-ai-resolver-corpus-audit
type: engineering_knowledge
title: Atlas AI Resolver Corpus Audit
status: active
category: architecture
priority: 99
summary: Compact index for the resolver-o-que-vale-a-pena corpus audit, preserving promotion decisions and source-material handling.
tags:
  - atlas-ai
  - resolver-corpus
  - architecture
  - policy-profile
  - super-tool-runtime
  - atlas-decide
capabilities:
  - resolver_corpus_governance
  - policy_profile_architecture
  - decision_receipt_governance
  - super_tool_runtime_governance
  - domain_profile_orchestration
decisions:
  - The resolver corpus contains valuable source material, but the KB is the operational authority.
  - Domain Profile / Flow Profile remains the canonical way to organize large Atlas domains.
  - Atlas Decide emits Decision Receipts and does not execute domain flows.
  - Super Tool Runtime is Core, not Forge-only or Harness-only.
maintenance:
  - Update when resolver source material is promoted, archived or rejected.
  - Keep this file as an index; use child docs for detail.
related_paths:
  - docs/engineering-knowledge-base/resolver-corpus/README.md
  - docs/engineering-knowledge-base/resolver-corpus/p0-promotions.md
  - docs/engineering-knowledge-base/resolver-corpus/policy-profile-model.md
  - docs/engineering-knowledge-base/archive/source-material/atlas-ai-resolver-corpus-audit-full-2026-05-08.md
  - docs/engineering-knowledge-base/atlas-ai-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
  - docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-resolver-corpus-audit

graph_title: Atlas AI Resolver Corpus Audit

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

owner: architecture

repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-resolver-corpus-audit.md

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
  - architecture

evidence:
  - docs/engineering-knowledge-base/atlas-ai-resolver-corpus-audit.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - module
  - architecture

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
# Atlas AI Resolver Corpus Audit

This is the active compact index for the `resolver-o-que-vale-a-pena` corpus.
The full original audit is preserved at
`archive/source-material/atlas-ai-resolver-corpus-audit-full-2026-05-08.md`.

## Rule

```text
Nothing important stays lost in resolver.
Nothing becomes canonical without classification.
Nothing competes with the KB after promotion or archive.
```

## Read Order

| Need | Read |
|---|---|
| Corpus orientation | `resolver-corpus/README.md` |
| P0 decisions already promoted | `resolver-corpus/p0-promotions.md` |
| Domain/Profile/Policy model | `resolver-corpus/policy-profile-model.md` |
| Historical full audit | archived full audit |

## Classification

| Level | Meaning |
|---|---|
| P0 | Must influence Mother Architecture now. |
| P1 | Implementation reference or near roadmap. |
| P2 | Future idea or immature domain. |
| Archive | Useful history, not active authority. |

## Canonical Result

The promoted resolver material now supports:

- Domain Profile / Flow Profile separation;
- Atlas Decide as operational compiler with Decision Receipt;
- Programming as a domain, not a CLI-only product;
- Super Tool Runtime as shared Core;
- Policy/Profile as layered governance above provider/model choice.

## Anti-Pattern

Do not reopen resolver files to create a new architecture path. Diff them against
the current owner docs, promote only missing decisions, and preserve source links.

## Resumo

Compact index for the resolver-o-que-vale-a-pena corpus audit, preserving promotion decisions and source-material handling.

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
