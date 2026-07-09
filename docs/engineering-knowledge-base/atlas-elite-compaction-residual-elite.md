---
id: atlas-elite-compaction-residual-elite
type: engineering_knowledge
title: Atlas Elite Compaction Residual Elite (Obra7)
status: active
implementation_state: runtime_available_prove_ok_phase1_partials
category: knowledge-governance
priority: 94
summary: Canonical owner for Residual Elite Obra7 elite-compaction claims — CMP post-compaction hooks, AAEOS prune-generated quarantine policy, NAM keep-list 26, progressive disclosure phase-1. Not the SCOS readiness compaction plan.
tags: [atlas-ai, elite-compaction, aaeos, naming, residual-elite]
capabilities: [elite_compaction_prove, post_compaction_hooks, quarantine_document_survivor, acde_keep_list]
decisions:
  - Operational receipts under storage/app/atlas/elite-compaction remain source material; this doc is the canonical owner for Obra7 CMP/AAEOS/NAM.
  - Keep-list 26 is sacred — never mark keep-list classes as dead; no blind prune --yes.
  - Physical MCP tier endpoints (OB-03) and SC-05/06 remain residuals, not DONE.
maintenance:
  - Update when atlas:elite:compaction prove/prune policy or keep-list changes.
  - Keep under 200 lines; detail lives in OBRA7 receipt and config/atlas_elite_compaction.php.
related_paths:
  - docs/engineering-knowledge-base/atlas-agentic-engineering-os.md
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/memory/open-brain-mcp.md
  - config/atlas_elite_compaction.php
  - storage/app/atlas/elite-compaction/OBRA7-FINAL-RECEIPT-2026-07-08.json
  - app/Services/Ai/AiCompactionService.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-elite-compaction-residual-elite
graph_title: Atlas Elite Compaction Residual Elite (Obra7)
graph_world: atlas
graph_layer: system
graph_kind: policy
graph_parent: atlas-agentic-engineering-os
graph_status: active
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-elite-compaction-residual-elite.md
allowed_changes:
  - Update status when CMP/AAEOS/NAM residuals close with evidence.
forbidden_changes:
  - Mass-delete ACDE corpses or rename keep-list classes without operator decision.
depends_on:
  - atlas-agentic-engineering-os
flows_to:
  - atlas-open-gaps-regressions-ledger
unlocks:
  - elite_compaction_canonical_owner
governs:
  - elite_compaction_obra7_claims
evidence:
  - storage/app/atlas/elite-compaction/OBRA7-FINAL-RECEIPT-2026-07-08.json
  - config/atlas_elite_compaction.php
evidence_refs:
  - receipt: storage/app/atlas/elite-compaction/OBRA7-FINAL-RECEIPT-2026-07-08.json
  - command: atlas:elite:compaction
  - symbol: AiCompactionService
required_tests:
  - "php artisan atlas:elite:compaction prove --json"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: medium
line_limit: 200
next_actions:
  - Close OB-03 physical MCP tier endpoints.
  - Close NAM-K3 / SC-05/06 residuals with evidence.
---
# Atlas Elite Compaction Residual Elite (Obra7)

## Resumo

Owner canonico dos claims Obra7 Residual Elite: post-compaction hooks (CMP),
prune-generated com quarantine `document_survivor` (AAEOS), keep-list 26 (NAM).
Nao substitui o plano SCOS de readiness sprawl.

## Papel no Atlas

Fecha o gap de locate UNRESOLVED para "elite compaction" apos a promocao
documental 2026-07-09, sem promover o mapa operacional a SoT.

## Onde Se Encaixa

Filho de Agentic Engineering OS. Cruza Self-Construction (SC-05/06 residual),
Open Brain MCP (OB-03 phase-2) e ACDE keep-list.

## Contratos

- `atlas:elite:compaction prove` deve passar (failed_count=0).
- Keep-list 26 + aliases de config intactos.
- prune-generated default dry-run; `quarantine_policy=document_survivor`.
- Progressive disclosure phase-1 (manifest); tools fisicos = phase-2.

## Fluxo

1. Compaction run emite markers fail-open.
2. Continuity inject default ON.
3. Prove battery valida keep-list / hooks / token economy observe.
4. Residuals NAM-K3 / SC-05/06 / OB-03 ficam no open-gaps.

## Regras para IA

- Nao executar prune --yes sem candidatos rg0 e decisao do operador.
- Nao renomear classes keep-list.
- Nao confundir este doc com `atlas-ai-self-construction-os-compaction-plan.md`.

## Escopo de Implementacao

CMP-01..03 done; AAEOS-01/02 done (dry-run); NAM-K1/2/4/5/6 done; NAM-K3
partial (aliases config-only). SC-05/06 e OB-03/05 residual.

## Dependencias

`config/atlas_elite_compaction.php`, `AiCompactionService`, AOBG MCP describe,
token economy observe mode.

## Evidencias

- `storage/app/atlas/elite-compaction/OBRA7-FINAL-RECEIPT-2026-07-08.json`
- `php artisan atlas:elite:compaction prove --json`

## Riscos

Blind prune; keep-list false-dead; over-claim phase-2 MCP como done.

## Exemplos

```bash
php artisan atlas:elite:compaction prove --json
php artisan atlas:elite:compaction prune-generated --dry-run --json
```

## Proximas Acoes

1. OB-03 physical MCP tier endpoints.
2. NAM-K3 class alias files se necessario alem de config.
3. SC-05/06 apos CodexSection chain repair.
