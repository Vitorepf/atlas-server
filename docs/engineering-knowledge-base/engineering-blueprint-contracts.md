---
id: atlas-engineering-blueprint-contracts
type: engineering_knowledge
title: Atlas Engineering Blueprint Contracts
status: active
category: contracts
priority: 97
summary: Compact contract index for Engineering Blueprint schemas, invariants and compatibility rules.
tags:
  - atlas
  - engineering
  - contracts
  - schema
capabilities:
  - engineering_blueprint_contracts
  - blueprint_task_contracts
  - blueprint_scenario_inventory
  - blueprint_qa_evidence
  - blueprint_review_gates
  - blueprint_postgres_gate
decisions:
  - Operational contracts live in Postgres and versioned payloads.
  - Markdown describes schema, invariants and evolution expectations.
  - Detailed schema summaries live in focused child docs.
maintenance:
  - Update when payloads, migrations, models, controllers or app types change.
  - Every schema change requires API/CLI tests and Code Intelligence update.
related_paths:
  - docs/engineering-knowledge-base/engineering-blueprint/README.md
  - docs/engineering-knowledge-base/engineering-blueprint/schema-contracts.md
  - docs/engineering-knowledge-base/archive/source-material/engineering-blueprint/contracts-full-2026-05-08.md
  - app/Services/Engineering/EngineeringTaskContractService.php
  - app/Services/Engineering/EngineeringBlueprintService.php
  - app/Models/AtlasEngineeringBlueprint.php
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-engineering-blueprint-contracts

graph_title: Atlas Engineering Blueprint Contracts

graph_world: atlas

graph_layer: module

graph_kind: contract

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas Engineering Blueprint Contracts
canonical_name: Atlas Engineering Blueprint Contracts
technical_name: atlas-engineering-blueprint-contracts
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/engineering-blueprint-contracts.md

owner: contracts

repo_paths:
  - docs/engineering-knowledge-base/engineering-blueprint-contracts.md

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
  - contracts

evidence:
  - docs/engineering-knowledge-base/engineering-blueprint-contracts.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: low

visual_tags:
  - module
  - contract
  - contracts

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
# Atlas Engineering Blueprint Contracts

This is the active contract index. The full original contract doc is preserved at
`archive/source-material/engineering-blueprint/contracts-full-2026-05-08.md`.

## Sources Of Truth

| Information | Primary source |
|---|---|
| Architecture and rules | Engineering Blueprint docs in this KB. |
| Frozen blueprints | Postgres blueprint tables. |
| QA/review/db evidence | Postgres evidence, test runs and artifacts. |
| Runs and attempts | Engineering Harness Runner tables. |
| Code location | Code Intelligence Index. |
| Reusable memory | Memory Core. |

## Contract Families

| Contract | Detail |
|---|---|
| Project Engineering Blueprint | `engineering-blueprint/schema-contracts.md` |
| Task Engineering Blueprint | `engineering-blueprint/schema-contracts.md` |
| Engineering Task Contract | `engineering-blueprint/schema-contracts.md` |
| Inventory and Scenarios | `engineering-blueprint/schema-contracts.md` |
| QA Evidence | `engineering-blueprint/schema-contracts.md` |
| Review Finding | `engineering-blueprint/schema-contracts.md` |
| Postgres Review | `engineering-blueprint/schema-contracts.md` |
| Context Pack | Memory/Open Brain docs plus schema summary. |

## Hard Invariants

- Frozen payloads are immutable; changes create new version/supersede.
- Hashes are deterministic over canonicalized payloads.
- Task contract and blueprint snapshot are mandatory execution context.
- Missing blocking gates or evidence must be visible to app/API/CLI.
- Compatibility changes require tests and docs update in the same wave.

## Validation

```bash
php artisan test tests/Feature/Engineering
php artisan atlas:ai:architecture-validate --json
```

## Resumo

Compact contract index for Engineering Blueprint schemas, invariants and compatibility rules.

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
