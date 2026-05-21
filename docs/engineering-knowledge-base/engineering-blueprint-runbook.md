---
id: atlas-engineering-blueprint-runbook
type: engineering_knowledge
title: Atlas Engineering Blueprint Runbook
status: active
category: runbook
priority: 96
summary: Compact operational index for Engineering Blueprint app, CLI, API and lifecycle runbooks.
tags:
  - atlas
  - engineering
  - runbook
  - cli
  - app
capabilities:
  - engineering_blueprint_runbook
  - blueprint_runbook_task_contracts
  - blueprint_runbook_qa_evidence
  - blueprint_runbook_review_gates
  - blueprint_context_recall
decisions:
  - App, CLI and API must expose equivalent Engineering Blueprint operations.
  - Commands are wrappers over tested services.
  - Full historical runbook is archived; active operational detail lives in child docs.
maintenance:
  - Update when commands, routes, screens or services change.
  - Keep this file as an index under the line limit.
related_paths:
  - docs/engineering-knowledge-base/engineering-blueprint/README.md
  - docs/engineering-knowledge-base/engineering-blueprint/surfaces-runbook.md
  - docs/engineering-knowledge-base/engineering-blueprint/lifecycle-runbook.md
  - docs/engineering-knowledge-base/archive/source-material/engineering-blueprint/runbook-full-2026-05-08.md
  - bin/atlas
  - app/Console/Commands/AtlasEngineeringRunCommand.php
  - app/Http/Controllers/EngineeringRunController.php
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-engineering-blueprint-runbook

graph_title: Atlas Engineering Blueprint Runbook

graph_world: atlas

graph_layer: module

graph_kind: runbook

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas Engineering Blueprint Runbook
canonical_name: Atlas Engineering Blueprint Runbook
technical_name: atlas-engineering-blueprint-runbook
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/engineering-blueprint-runbook.md

owner: runbook

repo_paths:
  - docs/engineering-knowledge-base/engineering-blueprint-runbook.md

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
  - runbook

evidence:
  - docs/engineering-knowledge-base/engineering-blueprint-runbook.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: low

visual_tags:
  - module
  - runbook
  - runbook

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
# Atlas Engineering Blueprint Runbook

This is the active runbook index. The full original runbook is preserved at
`archive/source-material/engineering-blueprint/runbook-full-2026-05-08.md`.

## Read Order

| Need | Read |
|---|---|
| Local index | `engineering-blueprint/README.md` |
| App, CLI and API operations | `engineering-blueprint/surfaces-runbook.md` |
| End-to-end blueprint lifecycle | `engineering-blueprint/lifecycle-runbook.md` |
| Historical full command detail | archived full runbook |

## Current Operating Principle

Engineering Blueprint exists to turn product/engineering intent into governed
task contracts, context, harness runs, QA, review, database checks, evidence and
memory deltas.

## Minimal Flow

```text
Project intent
-> Project Blueprint
-> Task Blueprint / Contract
-> Context Pack
-> Harness Run
-> QA / Review / Postgres Gates
-> Evidence Packet
-> Memory Delta
```

## Required Validation After Blueprint Work

```bash
php artisan test tests/Feature/Engineering
php artisan atlas:ai:architecture-validate --json
atlas engineering knowledge sync --prune
atlas engineering knowledge index-code --prune --workspace=/Users/vitorepf/develop/Atlas/atlas-server
```

## Failure Rule

If any required evidence, gate or context is missing, the system should pause,
record the gap and expose a review action instead of pretending the run is
complete.

## Resumo

Compact operational index for Engineering Blueprint app, CLI, API and lifecycle runbooks.

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
