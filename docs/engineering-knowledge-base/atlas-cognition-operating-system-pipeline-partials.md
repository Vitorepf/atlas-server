---
id: atlas-cognition-operating-system-pipeline-partials
type: engineering_knowledge
title: Atlas Cognition OS Pipeline Partials 1:1
status: active
implementation_state: live_baseline_2026_07_09_four_pipeline_partials
category: macro-system
priority: 98
summary: Child of ACOS separating the current evidence-resolved pipeline baseline from the historical Residual Elite Obra8 table. Live counts always come from atlas:cognition:scorecard; receipts never substitute runtime repair.
tags: [atlas-ai, acos, pipeline-partials, residual-elite]
capabilities: [acos_pipeline_partials_inventory]
decisions:
  - Parent ACOS doc links here; never paste the full 1:1 table into the oversized parent.
  - Live scorecard overrides frozen receipt numbers.
maintenance:
  - Regenerate the live baseline when evidence-resolved scorecard output changes.
related_paths:
  - docs/engineering-knowledge-base/atlas-cognition-operating-system.md
  - storage/app/atlas/elite-compaction/OBRA8-ACOS-PARTIALS-1to1-2026-07-08.json
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-cognition-operating-system-pipeline-partials
graph_title: Atlas Cognition OS Pipeline Partials 1:1
graph_world: atlas
graph_layer: system
graph_kind: index
graph_parent: atlas-cognition-operating-system
graph_status: active
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-cognition-operating-system-pipeline-partials.md
allowed_changes:
  - Refresh table rows from scorecard/receipt evidence.
forbidden_changes:
  - Claim overall 10/10 while any pipeline_status=partial remains.
depends_on:
  - atlas-cognition-operating-system
flows_to:
  - atlas-open-gaps-regressions-ledger
unlocks:
  - acos_pipeline_partials_navigation
governs:
  - acos_pipeline_partial_inventory
evidence:
  - storage/app/atlas/elite-compaction/OBRA8-ACOS-PARTIALS-1to1-2026-07-08.json
evidence_refs:
  - receipt: storage/app/atlas/elite-compaction/OBRA8-ACOS-PARTIALS-1to1-2026-07-08.json
  - command: atlas:cognition:scorecard
required_tests:
  - "php artisan atlas:cognition:scorecard --json"
requires_evidence: true
risk_level: medium
line_limit: 200
next_actions:
  - Repair the four live partial capabilities and execute their owner tests.
  - Refresh the live baseline from the evidence-resolved scorecard; never mint around a runtime gap.
---
# Atlas Cognition OS Pipeline Partials 1:1

## Resumo

Inventario 1:1 dos subsistemas ACOS que estavam `pipeline_status=partial` apos
Residual Elite Obra8, mais o baseline vivo do programa ACOS Inteligencia Maxima.
Em 2026-07-09 o scorecard evidence-resolved retornou overall **9.94/10**,
code **10**, doc **10**, pipeline **9.83**, **4/73 facets partial** e
**3/69 services unicos partial**. O modo `--strict` retornou exit 3. A tabela
longa abaixo permanece historica.

## Papel no Atlas

Evita bloat no doc-mae ACOS e da a IAs uma lista acionavel de partials.

## Onde Se Encaixa

Filho de `atlas-cognition-operating-system.md`. Residuais de codigo cruzam
GAP-RE-* no open-gaps ledger.

## Contratos

- Fonte operacional: `php artisan atlas:cognition:scorecard --json`
- Fonte historica Obra8: `OBRA8-ACOS-PARTIALS-1to1-2026-07-08.json`
- `--strict` falha enquanto houver partials

## Fluxo

1. Rodar scorecard.
2. Comparar partials com esta tabela.
3. Corrigir runtime, teste ou doc-owner do capability parcial.
4. Executar o teste dono e registrar somente o receipt produzido pela execucao real.
5. Atualizar ledger GAP-RE-TEOS-ACOS quando partials cairem.

## Regras para IA

- Nao inventar green-run receipts.
- Nao copiar esta tabela de volta para o parent ACOS.

## Escopo de Implementacao

Documentacao + baseline vivo. Fechar partials pelo runtime e evidencia real,
nunca por receipt sem execucao.

## Dependencias

ACOS scorecard service, evidence resolver, owner tests e Obra8 JSON historico.

## Evidencias

- Scorecard hash vivo 2026-07-09: `sha256:e2254d9135fc01ae46aef2b52ce7bb3f5e402312c196fbf3cb5efb630acc6bef`
- Obra8 table below (historical rows only; it does not override the live scorecard)

## Riscos

Tabela stale se o scorecard nao for re-rodado depois de alterar codigo, owner
docs, testes ou receipts.

## Baseline Vivo 2026-07-09

| Acronym | Name | Group | Code | Doc | Pipeline |
|---|---|---|---|---|---|
| MEM-RECALL | Memory Recall (hybrid) | memory_core | ready | ready | partial |
| AHRI | Hybrid Retrieval Infrastructure | aucri | ready | ready | partial |
| AGRN | Graph Retrieval Network | aucri | ready | ready | partial |
| AKIF | Knowledge Ingestion Fabric | aucri | ready | ready | partial |

Sinais adjacentes congelados no mesmo baseline: memory quality **63/100**;
AEMOR readiness **6/9 (blocked)**; AURG **586 nodes / 637 edges / 1 linker
edge**; architecture validation com tres violacoes estaticas preexistentes.

## Exemplos

### Historico Obra8

| Acronym | Name | Residual | Group | Code | Doc | Pipeline |
|---|---|---|---|---|---|---|
| G5 | Decision Gate | ACOS-02 | ACOS-immune | ready | ready | partial |
| G6 | Outcome Replay | ACOS-02 | ACOS-immune | ready | ready | partial |
| MEM-RECALL | Memory Recall (hybrid) | MEM-07 | ACOS-memory | ready | ready | partial |
| AHRI | Hybrid Retrieval Infrastructure | RAG-09 | ACOS-rag | ready | ready | partial |
| AARF | Agentic RAG Framework | RAG-01 | ACOS-rag | ready | ready | partial |
| ACRS | Context Ranking System | RAG-02 | ACOS-rag | ready | ready | partial |
| AREBA | Retrieval Evaluation Arena | RAG-06 | ACOS-rag | ready | ready | partial |
| ACMF | Cognitive Memory Fabric | CTX-06 | ACOS-context | ready | ready | partial |
| ADML | Atlas Decide Meta-Learning | DECIDE-01 | DECIDE | ready | ready | partial |
| ASCB | Self-Construction Subsystem Builder | ACOS-05 | ACOS-sc | ready | building | partial |
| AURG-4D | Unified Reality Graph Temporal (4D) | TEOS-03 | TEOS | ready | ready | partial |
| ACDM | Cross-Domain Mesh | TEOS-04 | TEOS | ready | ready | partial |
| TEOS-I3 | TEOS-I3 Counterfactual Runtime | TEOS-01 | TEOS | ready | ready | partial |
| ACK | Constitutional Kernel | GOV-01 | GOV | ready | ready | partial |
| AARR | Autonomous Reconciliation Runtime | GOV-03 | GOV | ready | ready | partial |
| ADLF | Atlas Decide Live Outcome Feedback | DECIDE-02 | DECIDE | ready | ready | partial |
| ACMF-SE | Cognitive Memory Fabric Schema Evolution | CTX-06 | ACOS-context | ready | ready | partial |
| ASCB-EX | Self-Construction Scaffold Staging Executor | ACOS-05 | ACOS-sc | ready | building | partial |
| ASCB-PP | Self-Construction Promotion Plan | ACOS-05 | ACOS-sc | ready | building | partial |
| ATBS | Trust Budget Service | GOV-02 | GOV | ready | ready | partial |
| ANCF | Nightly Counterfactuals | TEOS-05 | TEOS | ready | ready | partial |
| ASAR | Subsystem Auto-Rebalance | GOV-04 | GOV | ready | ready | partial |
| ACSR | Cognitive Function Swarm Router (P6 closure) | DECIDE-03 | DECIDE | ready | ready | partial |
| ASAF | Swarm Auto-Failover (A4) | DECIDE-04 | DECIDE | ready | ready | partial |
| ARDS | Runtime Degradation Signal Ingress | GOV-05 | GOV | ready | ready | partial |
| ASDM | Self-Divergence Model (target vs current) | ACOS-05 | ACOS-sc | ready | building | partial |
| AEMB | Embodiment Integration (P7 closure) | GOV-06 | GOV | ready | ready | partial |
| ACOP-ACRS | ACOP→ACRS Reflexive Streaming Bridge | CTX-11 | ACOS-context | ready | ready | partial |
| ABDD | BDD Acceptance Runtime | CART-01 | ACOS-domain | ready | ready | partial |
| APCP | Programming Cartography Publisher | CART-02 | ACOS-domain | ready | ready | partial |

## Proximas Acoes

1. Restaurar recall PostgreSQL, schema de deltas e testes do hot path.
2. Fechar MEM-RECALL/AHRI, AGRN e AKIF por comportamento comprovado.
3. Re-gerar o baseline quando o scorecard vivo mudar.

## ACOS scorecard claim stamp

<!-- atlas:acos-scorecard-claims:start -->
{"schema":"atlas.acos.scorecard_claims.v1","source":"AtlasCognitionScoreCardService::build()","overall_score":10,"pipeline_score":10,"scorecard_hash":"sha256:c347207f423c524d1eea672bffdc8356df373dfbed2a63d6c6eeb81d1abe4211","partial_facets":[],"partial_facet_count":0,"updated_at":"2026-07-11T19:50:59+00:00","note":"Doc stamp mirror only; runtime scorecard is authoritative."}
<!-- atlas:acos-scorecard-claims:end -->
