---
id: atlas-cognition-operating-system-pipeline-partials
type: engineering_knowledge
title: Atlas Cognition OS Pipeline Partials 1:1
status: active
implementation_state: pipeline_partials_enumerated_live_scorecard_required
category: macro-system
priority: 98
summary: Child of ACOS listing pipeline_status=partial subsystems 1:1 (from Residual Elite Obra8 table). Live counts always from atlas:cognition:scorecard — do not treat this table as omniscience.
tags: [atlas-ai, acos, pipeline-partials, residual-elite]
capabilities: [acos_pipeline_partials_inventory]
decisions:
  - Parent ACOS doc links here; never paste the full 1:1 table into the oversized parent.
  - Live scorecard overrides frozen receipt numbers.
maintenance:
  - Regenerate table when mint-pipeline-receipts flips partials or Obra8 JSON updates.
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
  - Soak mint-pipeline-receipts until live partial count drops.
  - Refresh table when live partial set diverges from Obra8 JSON.
---
# Atlas Cognition OS Pipeline Partials 1:1

## Resumo

Inventario 1:1 dos subsistemas ACOS que estavam `pipeline_status=partial` apos
Residual Elite Obra8. Snapshot vivo Onda 3 closeout 2026-07-09: overall
**10/10**, code 10, doc 10, pipeline **10**, partials **0/73** (tabela abaixo =
historico Obra8; live count via scorecard).

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
3. Mint receipts: `atlas:cognition:mint-pipeline-receipts --limit=30`.
4. Atualizar ledger GAP-RE-TEOS-ACOS quando partials cairem.

## Regras para IA

- Nao inventar green-run receipts.
- Nao copiar esta tabela de volta para o parent ACOS.

## Escopo de Implementacao

Documentacao + ponte para mint soak. Fechar partials e trabalho de runtime
(Onda 5 closeout).

## Dependencias

ACOS scorecard service, mint-pipeline-receipts, Obra8 JSON.

## Evidencias

- Scorecard hash Onda 3 closeout: `sha256:5b1f7225454f6791e7f008dc89d8f4495d32da29f020e4a572008e5cf799ed48`
- Obra8 table below (historical rows; live partial count = 0 apos mint path-scope + test evidence_refs)

## Riscos

Tabela stale se scorecard nao for re-rodado apos mint.

## Exemplos

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

1. Soak mint-pipeline-receipts ate partials cairem.
2. Re-gerar esta tabela quando live partial set divergir do Obra8 JSON.