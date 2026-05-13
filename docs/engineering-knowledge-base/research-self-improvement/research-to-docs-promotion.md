---
id: atlas-ai-research-to-docs-promotion
type: engineering_knowledge
title: Atlas AI Research To Documentation Promotion
status: active
category: documentation-governance
priority: 99
summary: Rules for promoting research into canonical documentation before Atlas implementation.
tags:
  - atlas-ai
  - research
  - documentation
  - promotion
capabilities:
  - research_to_docs_promotion
  - documentation_law
  - source_backed_docs
decisions:
  - Research becomes Atlas law only when promoted into the correct canonical doc.
  - Documentation must preserve source quality, uncertainty, forbidden actions and validation.
maintenance:
  - Update when Documentation OS gains automated source-backed promotion.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-research-self-improvement-runtime.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-research-to-docs-promotion

graph_title: Atlas AI Research To Documentation Promotion

graph_world: atlas

graph_layer: system

graph_kind: policy

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

owner: research-self-improvement

repo_paths:
  - docs/engineering-knowledge-base/research-self-improvement/research-to-docs-promotion.md

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
  - docs/engineering-knowledge-base/research-self-improvement/research-to-docs-promotion.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - policy
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
# Atlas AI Research To Documentation Promotion

Research is not law until it is promoted into the right canonical document and
validated by Documentation OS.

## Promotion Decision

| Research result | Destination |
|---|---|
| Changes Atlas identity or thesis | Layer -1 doc/AP first |
| Changes Kernel behavior | Kernel doc + AP + tests |
| Changes runtime boundary | Runtime boundary doc + AP |
| Changes memory/context | Cognitive Runtime / Memory docs |
| Changes provider absorption | Provider Evolution Intelligence |
| Changes source ingestion | Content Intelligence |
| Changes domain behavior | Domain spec |
| Is uncertain or weak | Archive as lead, do not promote |

## Required Doc Delta

Promoted research must state:

- source basis;
- decision;
- allowed actions;
- forbidden actions;
- owner;
- validation;
- promotion gate;
- rollback or fail-closed behavior.

## Source Trace

Docs do not need to paste long research. They must link or point to:

- AP;
- source packet;
- source URL or repo evidence;
- command/test output when local.

## Promotion Gate

Before implementation:

```text
research packet exists
source tier is acceptable
conflicts are documented
canonical owner doc updated
AP/plan exists for structural work
validation plan exists
```

If any item fails, implementation pauses or becomes an isolated spike.

## Resumo

Rules for promoting research into canonical documentation before Atlas implementation.

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
