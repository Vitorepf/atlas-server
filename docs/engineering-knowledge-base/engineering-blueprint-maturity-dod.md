---
id: atlas-engineering-blueprint-maturity-dod
type: engineering_knowledge
title: Atlas Engineering Blueprint Maturity And DoD
status: active
category: maturity
priority: 96
summary: Compact maturity and Definition of Done index for the Atlas Engineering Blueprint System.
tags:
  - atlas
  - engineering
  - maturity
  - dod
capabilities:
  - engineering_blueprint_maturity_dod
  - blueprint_maturity_pipeline
  - blueprint_maturity_task_contracts
  - blueprint_maturity_qa_evidence
  - blueprint_maturity_review_gates
  - blueprint_maturity_postgres_gate
decisions:
  - Current implementation is a strong operational base, not the final mature product.
  - Maturity detail lives in a focused child doc to keep this index readable.
maintenance:
  - Update when a phase is completed.
  - Do not mark complete without tests, docs, CLI/API/app coverage when applicable and usage evidence.
related_paths:
  - docs/engineering-knowledge-base/engineering-blueprint/README.md
  - docs/engineering-knowledge-base/engineering-blueprint/maturity-phases.md
  - docs/engineering-knowledge-base/archive/source-material/engineering-blueprint/maturity-dod-full-2026-05-08.md
  - docs/engineering-knowledge-base/engineering-blueprint.md
  - docs/engineering-knowledge-base/engineering-blueprint-quality-gates.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-engineering-blueprint-maturity-dod

graph_title: Atlas Engineering Blueprint Maturity And DoD

graph_world: atlas

graph_layer: module

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas Engineering Blueprint Maturity And DoD
canonical_name: Atlas Engineering Blueprint Maturity And DoD
technical_name: atlas-engineering-blueprint-maturity-dod
cartography_type: module
canonical_source: docs/engineering-knowledge-base/engineering-blueprint-maturity-dod.md

owner: maturity

repo_paths:
  - docs/engineering-knowledge-base/engineering-blueprint-maturity-dod.md

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
  - maturity

evidence:
  - docs/engineering-knowledge-base/engineering-blueprint-maturity-dod.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: low

visual_tags:
  - module
  - module
  - maturity

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
# Atlas Engineering Blueprint Maturity And DoD

This is the active maturity index. The full original maturity doc is preserved at
`archive/source-material/engineering-blueprint/maturity-dod-full-2026-05-08.md`.

## Current State

Atlas has an operational Engineering Harness foundation:

- task contracts;
- deterministic task blueprints;
- frozen snapshots;
- evidence;
- runs, attempts, controls, tests, scoring and replay;
- review findings;
- visual smoke and quality scan;
- Atlas-Bench;
- Knowledge Base and Code Intelligence;
- Super Tool Runtime phase 0.

## Remaining Product Maturity

| Area | Remaining work |
|---|---|
| UX | Unified Blueprint -> Run -> Review -> Evidence -> Promote flow. |
| Missing evidence | Global app filters for missing evidence/blocking gates. |
| Wireframes | Dedicated wireframe refs and visual baselines. |
| Calibration | Atlas-Bench and Memory Delta calibrated with real run volume. |
| Postgres | Real EXPLAIN/history against target connections. |

## Phase Detail

See `engineering-blueprint/maturity-phases.md`.

## Final DoD Rule

The system is complete only when a new AI session can take a project objective,
generate/freeze the blueprint, produce tasks, run harnesses, collect evidence,
pass review gates and promote memory without relying on hidden chat context.

## Resumo

Compact maturity and Definition of Done index for the Atlas Engineering Blueprint System.

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
