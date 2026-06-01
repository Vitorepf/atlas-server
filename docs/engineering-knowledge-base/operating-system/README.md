---
id: atlas-ai-operating-system-specs-index
type: engineering_knowledge
title: Atlas AI Operating System Specs Index
status: source_material
category: architecture
priority: 100
summary: Local index for Atlas AI operating system concepts: surfaces, profiles, pipeline, anti-duplication and domain ownership.
tags:
  - atlas-ai
  - operating-system
  - orchestration
capabilities:
  - atlas_ai_operating_system
  - domain_flow_registry
  - anti_duplication_governance
decisions:
  - Atlas AI is the central orchestration intelligence.
  - Surfaces are entry points, not products.
maintenance:
  - Keep this index short.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-operating-system.md
  - docs/engineering-knowledge-base/operating-system/surfaces-and-profiles.md
  - docs/engineering-knowledge-base/operating-system/domain-pipelines.md
  - docs/engineering-knowledge-base/operating-system/governance-and-dod.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-operating-system-specs-index

graph_title: Atlas AI Operating System Specs Index

graph_world: atlas

graph_layer: system

graph_kind: index

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Operating System Specs Index
canonical_name: Atlas AI Operating System Specs Index
technical_name: atlas-ai-operating-system-specs-index
cartography_type: index
canonical_source: docs/engineering-knowledge-base/operating-system/README.md

owner: operating-system

repo_paths:
  - docs/engineering-knowledge-base/operating-system/README.md

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
  - operating-system

evidence:
  - docs/engineering-knowledge-base/operating-system/README.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - index
  - operating-system

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
# Atlas AI Operating System Specs Index

Read this directory when adding commands, surfaces, domains, flows, aliases,
repair behavior, model selection behavior or horizontal capabilities.

## Files

| File | Purpose |
|---|---|
| `surfaces-and-profiles.md` | Surface aliases, Domain Profile, Flow Profile and Atlas Decide authority |
| `domain-pipelines.md` | Programming, Personal Development, Finance and Self-Improvement pipeline shapes |
| `governance-and-dod.md` | Horizontal layers, anti-duplication, ownership and maturity DoD |

## Resumo

Local index for Atlas AI operating system concepts: surfaces, profiles, pipeline, anti-duplication and domain ownership.

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
