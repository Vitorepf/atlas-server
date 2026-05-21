---
id: atlas-tool-runtime-specs-index
type: engineering_knowledge
title: Atlas Tool Runtime Specs Index
status: active
category: architecture
priority: 98
summary: Local index for Super Tool Runtime registry, policy, evidence, gates, recipes and optional programming tools.
tags:
  - atlas
  - tools
  - runtime
  - index
capabilities:
  - tool_registry
  - tool_policy_engine
  - evidence_store
  - tool_gates
decisions:
  - Tool Runtime docs are split into focused specs for AI-readable performance.
  - Tools are governed capabilities, not ad hoc shell commands.
maintenance:
  - Keep this index short.
related_paths:
  - docs/engineering-knowledge-base/super-tool-runtime-core.md
  - docs/engineering-knowledge-base/tool-runtime/contracts.md
  - docs/engineering-knowledge-base/tool-runtime/evidence-gates.md
  - docs/engineering-knowledge-base/tool-runtime/catalog-roadmap.md
  - docs/engineering-knowledge-base/tool-runtime/runbook.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-tool-runtime-specs-index

graph_title: Atlas Tool Runtime Specs Index

graph_world: atlas

graph_layer: system

graph_kind: index

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas Tool Runtime Specs Index
canonical_name: Atlas Tool Runtime Specs Index
technical_name: atlas-tool-runtime-specs-index
cartography_type: index
canonical_source: docs/engineering-knowledge-base/tool-runtime/README.md

owner: tool-runtime

repo_paths:
  - docs/engineering-knowledge-base/tool-runtime/README.md

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
  - tool-runtime

evidence:
  - docs/engineering-knowledge-base/tool-runtime/README.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - system
  - index
  - tool-runtime

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
# Atlas Tool Runtime Specs Index

Read this directory before adding tools, recipes, normalizers, approvals,
waivers, evidence queries, gates or external coding agents.

## Files

| File | Purpose |
|---|---|
| `contracts.md` | Registry, policy engine, tiers, authority matrix and external agent boundary |
| `evidence-gates.md` | Evidence Store, normalizer, generic gate, release gate and API Contract Harness |
| `catalog-roadmap.md` | Tool families for programming-heavy Atlas and optional backlog |
| `runbook.md` | CLI/API commands, approvals, waivers and validation |

## Rule

No tool bypasses Atlas policy, evidence, gates or provider-safety. Missing
optional tools create auditable `skipped/missing` state instead of silent gaps.

## Resumo

Local index for Super Tool Runtime registry, policy, evidence, gates, recipes and optional programming tools.

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
