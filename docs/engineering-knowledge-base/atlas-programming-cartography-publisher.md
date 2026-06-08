---
id: atlas-programming-cartography-publisher
type: engineering_knowledge
title: Atlas Programming Cartography Publisher
slug: atlas-programming-cartography-publisher
status: building
risk_level: medium
authority_class: read_model_publisher
category: programming-governance
priority: 89
summary: Publisher read-only que transforma work items, specs, tasks, files, evidencias e drift reports de Programming Governance em grafo navegavel, sem substituir Vault Cartography nem Universal Reality Cartography.
tags:
  - atlas-ai
  - programming
  - cartography
  - read-model
  - governance
capabilities:
  - programming_cartography_graph
  - work_item_dependency_projection
  - drift_visualization_projection
  - provider_safe_cartography_snapshot
decisions:
  - Programming Cartography Publisher e read model focado em Programming Governance.
  - Nao substitui Vault Cartography nem Universal Reality Cartography.
  - Publisher nao muta work items, specs, tasks, evidence ou drift reports.
  - Command atlas:programming:cartography existe, mas o fluxo permanece building ate integracao visual/consumer estar provada.
maintenance:
  - Atualizar quando ProgrammingCartographyGate, publisher service, command ou schema mudarem.
  - Nao adicionar mutacao neste publisher.
  - Manter alinhado ao runbook de Programming Governance e a Cartografia canonica.
related_paths:
  - app/Services/Ai/Programming/Cartography/AtlasProgrammingCartographyPublisherService.php
  - app/Console/Commands/AtlasProgrammingCartographyCommand.php
  - app/Services/Ai/Programming/Governance/Gates/ProgrammingCartographyGate.php
  - tests/Unit/Ai/Programming/Cartography/AtlasProgrammingCartographyPublisherServiceTest.php
  - docs/engineering-knowledge-base/atlas-programming-governance-system-runbook.md
  - docs/engineering-knowledge-base/atlas-cartographic-knowledge-os.md
  - docs/engineering-knowledge-base/atlas-universal-reality-cartography.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-programming-cartography-publisher
human_name: Atlas Programming Cartography Publisher
canonical_name: Atlas Programming Cartography Publisher
technical_name: AtlasProgrammingCartographyPublisherService
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-programming-cartography-publisher.md
graph_title: Atlas Programming Cartography Publisher
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-programming-governance-system
graph_status: building
graph_source: repo
owner: programming-governance
repo_paths:
  - docs/engineering-knowledge-base/atlas-programming-cartography-publisher.md
  - app/Services/Ai/Programming/Cartography/AtlasProgrammingCartographyPublisherService.php
depends_on:
  - atlas-programming-governance-system
  - atlas-cartographic-knowledge-os
allowed_changes:
  - Evoluir grafo, snapshots e command mantendo read-only.
  - Adicionar consumers visuais sem transformar publisher em fonte primaria.
forbidden_changes:
  - mutate_work_items_from_publisher
  - claim_winner_in_graph
  - replace_universal_reality_cartography
flows_to:
  - programming-governance
  - cartography
unlocks:
  - programming-work-item-map
  - programming-drift-map
governs:
  - programming-cartography-snapshots
evidence:
  - app/Services/Ai/Programming/Cartography/AtlasProgrammingCartographyPublisherService.php
  - app/Console/Commands/AtlasProgrammingCartographyCommand.php
  - tests/Unit/Ai/Programming/Cartography/AtlasProgrammingCartographyPublisherServiceTest.php
evidence_refs:
  - symbol: AtlasProgrammingCartographyPublisherService
  - command: atlas:programming:cartography
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan test --filter=AtlasProgrammingCartographyPublisherServiceTest"
  - "php artisan atlas:programming:cartography --action=publish --json"
requires_evidence: true
next_actions:
  - Provar consumer visual ou API antes de declarar active.
  - Manter publisher read-only e provider-safe.
schema:
  - atlas.programming.cartography_graph.v1
---

# Atlas Programming Cartography Publisher

## Resumo

Atlas Programming Cartography Publisher emite um grafo read-only dos objetos de Programming Governance. Ele existe para navegação e auditoria, nao para decidir, mutar ou substituir as fontes canonicas.

## Papel no Atlas

Seu papel e reduzir confusao operacional para humano e IA ao mostrar ownership, dependencies, coverage e drift de work items/specs/tasks/evidence em uma forma navegavel.

## Onde Se Encaixa

Fica no owner `programming-governance`, abaixo do runbook de Programming Governance e ao lado da Cartografia canonica. Universal Reality Cartography continua mapa de realidade amplo; este publisher e um read model focado em programming.

## Contratos

- `atlas.programming.cartography_graph.v1` define nodes, edges, stats, claim_policy e snapshot_hash.
- Snapshots sao append-only.
- O publisher nao altera work items, evidence ou drift reports.

## Fluxo

1. Programming Governance produz work items, specs, tasks, evidence e drift reports.
2. Publisher le esses objetos.
3. Service gera grafo provider-safe.
4. Command publica/lista snapshots.
5. Renderer visual consome o envelope em etapa separada.

## Regras para IA

- Nao use este publisher como fonte primaria da verdade.
- Nao implemente mutacao dentro do publisher.
- Nao confunda este grafo com Vault Cartography ou Universal Reality Cartography.

## Escopo de Implementacao

Escopo atual: service, command e testes unitarios do publisher. Fora do escopo atual: renderer visual final, API publica e decisao automatica baseada no grafo.

## Dependencias

- `ProgrammingCartographyGate`
- Programming Governance runbook
- Cartographic Knowledge OS
- Universal Reality Cartography

## Evidencias

- `app/Services/Ai/Programming/Cartography/AtlasProgrammingCartographyPublisherService.php`
- `app/Console/Commands/AtlasProgrammingCartographyCommand.php`
- `tests/Unit/Ai/Programming/Cartography/AtlasProgrammingCartographyPublisherServiceTest.php`

## Riscos

- Uma IA pode tratar read model como owner de fluxo.
- Um consumer pode usar grafo stale para decidir patch.
- Cartografias diferentes podem parecer duplicadas sem boundary claro.

## Exemplos

Um work item com spec, task, files tocados, evidence verde e drift report deve aparecer como nodes ligados por edges tipadas, nunca como mutacao do work item original.

## Proximas Acoes

1. Rodar teste unitario do publisher.
2. Provar command publish/list/latest.
3. Conectar renderer ou API sem mudar a fonte primaria.

## Por que existe

Audit canon 2026-05-26 leverage 7/10: `ProgrammingCartographyGate` (governance/Gates) **emite gap "cartography_publishing_required"** mas **não existe publisher real** que transforme work items + specs + tasks + evidence + drift reports do Programming Governance em um grafo navegável de ownership/dependencies/coverage/drift.

Este publisher fecha o gap **lado backend**. Frontend renderer (canvas pan/zoom já existente em surfaces/cartografia/) consome este envelope como follow-up.

## Princípio de não-duplicação

| Conceito | Fonte canon |
|----------|-------------|
| Work items + specs + tasks + evidence | Programming Governance models (não duplica) |
| Cartography emit gap | `ProgrammingCartographyGate` (advisory, mantém-se) |
| Universal Reality map | `AtlasUniversalRealityCartographyService::map()` (Vault/cream warm — escopo diferente) |
| Vault Cartography Schema | `docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema.md` (knowledge graph humano — escopo diferente) |
| SDD drift reports | `AtlasSddDriftReport` model |
| Atlas Decide Cartografia surface | `surfaces/cartografia/` (cream warm canon — exception) |

**Este publisher é PROGRAMMING-FOCUSED**, não vault, não universal-reality. Emite grafo dos work items + dependencies, consumível pela surface code (slate dark) ou nova surface programming-cartography.

## API

```php
publish(array $context = []): array  // atlas.programming.cartography_graph.v1
listSnapshots(): array
latestSnapshot(): ?array
```

## Graph envelope

```json
{
  "schema_version": "atlas.programming.cartography_graph.v1",
  "snapshot_id": "carto_...",
  "generated_at": "ISO",
  "scope": {"workspace": "...", "filter": "..."},
  "nodes": [
    {"id":"wi_X","kind":"work_item","label":"Atlas Dev intake","status":"verified"},
    {"id":"sp_Y","kind":"spec","label":"compact-sdd","status":"approved"},
    {"id":"tk_Z","kind":"task","label":"refactor X","status":"completed"},
    {"id":"fi_A","kind":"file","label":"app/Services/X.php"},
    {"id":"ev_B","kind":"evidence","label":"test result","status":"green"},
    {"id":"dr_C","kind":"drift_report","label":"spec drift X","severity":"medium"}
  ],
  "edges": [
    {"from":"wi_X","to":"sp_Y","kind":"has_spec"},
    {"from":"sp_Y","to":"tk_Z","kind":"contains_task"},
    {"from":"tk_Z","to":"fi_A","kind":"touches"},
    {"from":"fi_A","to":"ev_B","kind":"evidenced_by"},
    {"from":"sp_Y","to":"dr_C","kind":"has_drift"}
  ],
  "stats": {"node_count":N,"edge_count":N,"by_kind":{...}},
  "claim_policy": {"benchmark_claim_allowed":false,"rivals_claim_allowed":false},
  "snapshot_hash": "sha256:..."
}
```

## Read-only (NÃO muta)

- Não atualiza work_items
- Não cria evidência
- Não fechaa drift
- Apenas LÊ → projeta grafo → grava snapshot append-only

## Storage

- `storage/atlas/programming/cartography_snapshots.jsonl` — append-only

## CLI

```
php artisan atlas:programming:cartography --action=publish [--workspace=X] [--json]
php artisan atlas:programming:cartography --action=list [--json]
php artisan atlas:programming:cartography --action=latest [--json]
```

## Não-objetivos

- Não substitui Vault Cartografia (cream warm canon)
- Não substitui Universal Reality Cartography
- Não renderiza visual — emite envelope canônico
- Não claim de winner em ranking de work items
