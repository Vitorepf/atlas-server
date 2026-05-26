---
id: atlas-subsystem-auto-rebalance
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Subsystem Auto-Rebalance
slug: atlas-subsystem-auto-rebalance
status: building
implementation_state: runtime_available
category: patamar_4
priority: 93
summary: Planner + applier honesto de rebalance interno do substrato (cache, AGRN, AEMOR, MCP pool). plan() é read-only; apply() consome Trust Budget na tier apropriada.
tags: [atlas-ai, self-repair, rebalance, trust-budget, patamar-4]
capabilities: [rebalance_planner, trust_budget_gated_apply, rebalance_advice]
decisions:
  - 4 rebalance kinds canon.
  - plan() é READ-ONLY; apply() consome Trust Budget.
  - Tier por kind: cache_compact=low, agrn_reindex=medium, aemor_recompact=high, mcp_pool=low.
  - Apply emite advice receipt; infra real é manual fora deste service.
maintenance:
  - Wirar diagnostics reais com ACOP observability service no rodada futura.
risk_level: medium
owner: atlas-ai
graph_id: atlas-subsystem-auto-rebalance
graph_title: Atlas Subsystem Auto-Rebalance
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-trust-budget
graph_status: building
graph_source: repo
depends_on: [atlas-constitutional-kernel, atlas-trust-budget]
flows_to: [atlas-ai-context-panel]
unlocks: [substrate_self_repair_planner]
governs: [autonomous_subsystem_rebalance_advice]
authority_class: rebalance
related_paths:
  - app/Services/Ai/Patamar4/AtlasSubsystemAutoRebalanceService.php
  - app/Console/Commands/AtlasAutoRebalanceCommand.php
  - tests/Unit/Ai/Patamar4/AtlasSubsystemAutoRebalanceServiceTest.php
repo_paths:
  - docs/engineering-knowledge-base/atlas-subsystem-auto-rebalance.md
  - app/Services/Ai/Patamar4/AtlasSubsystemAutoRebalanceService.php
evidence:
  - app/Services/Ai/Patamar4/AtlasSubsystemAutoRebalanceService.php
  - tests/Unit/Ai/Patamar4/AtlasSubsystemAutoRebalanceServiceTest.php
required_tests:
  - "php artisan test tests/Unit/Ai/Patamar4/AtlasSubsystemAutoRebalanceServiceTest.php"
next_actions:
  - Wirar diagnostics reais (ACOP cache size, AGRN stale fraction, AEMOR redundancy).
allowed_changes:
  - Adicionar novos rebalance kinds preservando schema.
forbidden_changes:
  - skip_trust_budget_consume
  - apply_without_kernel_gate
  - benchmark_or_rivals_claim
requires_evidence: true
line_limit: 480
schema:
  - atlas.patamar4.rebalance_plan.v1
  - atlas.patamar4.rebalance_apply_receipt.v1
---

# Atlas Subsystem Auto-Rebalance

## Resumo

Service planner + applier honesto. 4 rebalance kinds canon: cache_compact (low), agrn_reindex_advice (medium), aemor_recompact_advice (high), mcp_pool_warmup_advice (low). plan() emite diagnostics + recommended_actions sem efeito; apply() exige Trust Budget consume + Kernel allow + emite advice receipt.

## Papel no Atlas

Cenário canon "ACOP detecta latency subindo em ASEF → Atlas roda diagnóstico → propõe rebalance".

**F4 (2026-05-26) — diagnostics reais via opt-in probes.** O service agora aceita `setProbe(kind, Closure)`. AppServiceProvider wira 2 probes reais:

| Kind | Probe real (wired) | Fonte real | Métrica |
|---|---|---|---|
| `aemor_recompact_advice` | ✅ | `AtlasAemorRuntimeService.memoryAudit()` | `(watch+blocked)/total` candidates |
| `mcp_pool_warmup_advice` | ✅ | `AtlasMcpTierService.tierManifest()` | `tier3_count/total_tools` |
| `cache_compact` | ⏳ unwired | sem CachePoolService canônico | `probe_status=unwired` |
| `agrn_reindex_advice` | ⏳ unwired | sem `indexStaleFraction()` ainda | `probe_status=unwired` |

Diagnóstico passa a expor `probe_status: ok | unwired | error` no envelope. Operador estende wirings adicionando probes na resolving callback de `AppServiceProvider`. Sem mock, sem fake — quando probe não existe, `observed:null` + `probe_status=unwired` é a verdade emitida.

## Onde Se Encaixa

- `plan(kind)` — emite plan + diagnostics + recommended_actions
- `apply(kind, actor, reason)` — Kernel gate → Trust Budget consume → advice receipt
- Operator inspect via list/latest

## Contratos

- `atlas.patamar4.rebalance_plan.v1` — plan envelope
- `atlas.patamar4.rebalance_apply_receipt.v1` — apply receipt com trust_budget_action_id

## Fluxo

1. `plan(kind)` → diagnostics + recommended_actions + plan_hash
2. Operator revisa
3. `apply(kind, actor, reason)` → Kernel validate → Trust Budget consume → status canon
4. Status canon: planned | applied | denied_kernel | denied_budget | noop

## Regras para IA

- NUNCA apply sem Trust Budget consume.
- NUNCA emitir status=applied se kind desconhecido.
- NUNCA passar receipt sem hash sha256.

## Escopo de Implementacao

Service + CLI + tests + receipt JSONL. Diagnostics reais wired em rodada futura.

## Dependencias

- AtlasConstitutionalKernelService (gate)
- AtlasTrustBudgetService (budget consume)

## Evidencias

Service + tests + CLI + receipts JSONL.

## Riscos

- Diagnostics placeholder → operator pode aplicar sem dado real. Mitigação: receipt tem nota explícita "advice emitted; operator runs underlying infra command".
- Budget esgotado em apply repetido. Mitigação: tier por kind canon limita.

## Exemplos

```bash
php artisan atlas:rebalance --action=plan --kind=cache_compact --json
php artisan atlas:rebalance --action=apply \
  --kind=cache_compact --actor=operator \
  --reason="latency upticks observed" --json
```

## Proximas Acoes

Wirar diagnostics reais. Cron daily plan() de cada kind para alimentar inbox.

## Safety

- Kernel gate antes de apply.
- Trust Budget consume com receipt.
- Advice-only: infra real é manual.
- Append-only JSONL.
