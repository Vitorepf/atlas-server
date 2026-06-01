---
id: atlas-engineering-blueprint-surfaces-runbook
type: engineering_knowledge
title: Atlas Engineering Blueprint Surfaces Runbook
status: active
category: runbook
priority: 88
summary: App, CLI and API entry points for operating the Engineering Blueprint System.
tags:
  - atlas
  - engineering
  - surfaces
capabilities:
  - engineering_blueprint_surfaces_runbook
decisions:
  - Blueprint operations should keep app, CLI and API parity unless an omission is explicitly documented.
maintenance:
  - Update when app screens, commands or routes change.
related_paths:
  - docs/engineering-knowledge-base/engineering-blueprint-runbook.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-engineering-blueprint-surfaces-runbook

graph_title: Atlas Engineering Blueprint Surfaces Runbook

graph_world: atlas

graph_layer: module

graph_kind: runbook

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas Engineering Blueprint Surfaces Runbook
canonical_name: Atlas Engineering Blueprint Surfaces Runbook
technical_name: atlas-engineering-blueprint-surfaces-runbook
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/engineering-blueprint/surfaces-runbook.md

owner: engineering-blueprint

repo_paths:
  - docs/engineering-knowledge-base/engineering-blueprint/surfaces-runbook.md

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
  - engineering-blueprint

evidence:
  - docs/engineering-knowledge-base/engineering-blueprint/surfaces-runbook.md

evidence_refs:
  - symbol: AtlasBlueprintSurfacesRunbookService
  - command: atlas:aaeos:blueprint-surfaces-runbook
  - test: AtlasBlueprintSurfacesRunbookTest

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: low

visual_tags:
  - module
  - runbook
  - engineering-blueprint

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
# Atlas Engineering Blueprint Surfaces Runbook

## App

- `Home > Projetos` exposes project/task engineering context.
- `Engenharia` panels show contract, blueprint, gates and evidence.
- `Home > Atlas Engineering` exposes benchmarks, knowledge, tool runtime, replay and runs.

Current maturity gap: app still needs a fully unified run-detail flow outside
benchmark context.

## CLI

```bash
atlas dev --task-id=<task-id>
atlas engineering run --task-id=<task-id> --workspace=/Users/vitorepf/develop/Atlas/atlas-server --sandbox=worktree --auto-test
atlas engineering replay --run-id=<run-id>
atlas engineering knowledge sync --prune
atlas engineering knowledge index-code --prune --workspace=/Users/vitorepf/develop/Atlas/atlas-server
```

## API

```text
GET  /tasks/{task}/engineering
POST /tasks/{task}/engineering/blueprint/freeze
POST /tasks/{task}/engineering/evidence
POST /tasks/{task}/engineering/runs
GET  /engineering/runs/{run}
```

## Parity Rule

If a Blueprint operation exists in one surface, the owner must decide whether
CLI, API and app need equivalent access or an explicit reason for omission.

## Resumo

App, CLI and API entry points for operating the Engineering Blueprint System.

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
