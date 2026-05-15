---
id: atlas-self-construction-agent-dispatch-planner-runtime-v1
type: engineering_knowledge
title: Atlas Agent Control Plane - Agent Dispatch Planner Runtime v1
status: active
category: architecture
priority: 92
summary: Read-only dispatch planner that composes task queue, claim/lease, agent registry, governance and scope-lock signals into an advisory dispatch plan. Dry-run posture; never claims, never dispatches, never calls providers, never spends tokens.
tags:
  - atlas-ai
  - self-construction
  - agent-control-plane
  - dispatch-planner
  - governance
  - scope-lock
  - dry-run
capabilities:
  - candidate_selection
  - eligibility_evaluation
  - scope_conflict_detection
  - governance_precheck
  - dry_run_receipt_persistence
  - batch_planning
  - dispatch_planner_certification
decisions:
  - Dispatch Planner is a read-only composition layer over task queue, claim/lease, agent registry, capability catalog, quarantine and governance signals.
  - Dispatch is never authorized by this layer. The planner produces advisory plans only.
  - Dry-run receipts are local audit artifacts; they are NOT the evidence ledger and NOT real claim/lease receipts.
  - Governance precheck must hold (kill switch armed, budget gate green, no self-programming, evidence refs present for evidence-required tasks) before status can be `planned`.
maintenance:
  - Update when a new sub-service is added or a governance signal changes shape.
  - Coordinate with task queue + claim/lease and agent registry Claudes via separate slices.
  - Do not edit agent-control-plane-contract.md from this doc.
related_paths:
  - docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md
  - docs/engineering-knowledge-base/self-construction/agent-runtime-registry-v1.md
  - docs/engineering-knowledge-base/self-construction/atlas-agent-control-plane-runtime-pilot-map-v1.md
  - docs/engineering-knowledge-base/self-construction/atlas-agent-control-plane-safety-invariants-v1.md
  - docs/engineering-knowledge-base/self-construction/atlas-self-construction-os-operator-runbook-v1.md
  - app/Services/Ai/SelfConstruction/AgentDispatchPlannerCandidateSelector.php
  - app/Services/Ai/SelfConstruction/AgentDispatchPlannerEligibilityEvaluator.php
  - app/Services/Ai/SelfConstruction/AgentDispatchPlannerScopeConflictAnalyzer.php
  - app/Services/Ai/SelfConstruction/AgentDispatchPlannerGovernancePrecheck.php
  - app/Services/Ai/SelfConstruction/AgentDispatchPlannerDryRunReceiptBuilder.php
  - app/Services/Ai/SelfConstruction/AgentDispatchPlannerBatchPlanner.php
  - app/Services/Ai/SelfConstruction/AgentDispatchPlannerCertificationService.php
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 520
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-self-construction-agent-dispatch-planner-runtime-v1
graph_title: Atlas Agent Control Plane - Agent Dispatch Planner Runtime v1
graph_world: atlas
graph_layer: gear
graph_kind: contract
graph_parent: atlas-self-construction-agent-control-plane-contract
graph_status: active
graph_source: repo
repo_paths:
  - docs/engineering-knowledge-base/self-construction/agent-dispatch-planner-runtime-v1.md
allowed_changes:
  - Atualizar quando uma sub-rotina do planner mudar com evidencia.
forbidden_changes:
  - Declarar dispatch real, claim real ou self-programming sem evidencia e gates verdes.
depends_on:
  - atlas-self-construction-agent-control-plane-contract
flows_to:
  - atlas-cartography
  - atlas-code
unlocks:
  - ai-safe-implementation-context
governs:
  - self-construction
evidence:
  - docs/engineering-knowledge-base/self-construction/agent-dispatch-planner-runtime-v1.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
visual_tags:
  - gear
  - contract
  - dispatch-planner
ai_entrypoints:
  - Leia Resumo, Componentes, Governance, Runtime Safety e Limites antes de consumir este layer.
ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - Confundir dispatch plan com dispatch real.
  - Tratar dry-run receipt como evidence ledger.
observability_signals:
  - docs-health status ok
  - certification batch invariants_all_true true
next_actions:
  - Manter sincronia com runtime-pilot-map e safety-invariants.
---

# Atlas Agent Control Plane - Agent Dispatch Planner Runtime v1

The Agent Dispatch Planner Runtime composes upstream Agent Control Plane
layers (task queue, claim/lease, agent registry, capability catalog,
quarantine, governance signals) into a single advisory **dispatch
plan**.

It does not dispatch. It does not claim. It does not call providers. It
does not spend tokens. It does not write the evidence ledger. It does
not enable self-programming. It does not declare Atlas Self-Construction
OS complete.

It is a sibling of, not a replacement for,
`agent-control-plane-contract.md`. If they conflict, the contract wins.

## 1. Components

| Component | Class | Schema | Posture |
|-----------|-------|--------|---------|
| Candidate Selector | `AgentDispatchPlannerCandidateSelector` | `atlas.self_construction.agent_dispatch_planner_candidate_selection.v1` | read-only |
| Eligibility Evaluator | `AgentDispatchPlannerEligibilityEvaluator` | `atlas.self_construction.agent_dispatch_planner_eligibility.v1` | read-only |
| Scope Conflict Analyzer | `AgentDispatchPlannerScopeConflictAnalyzer` | `atlas.self_construction.agent_dispatch_planner_scope_conflict.v1` | read-only |
| Governance Precheck | `AgentDispatchPlannerGovernancePrecheck` | `atlas.self_construction.agent_dispatch_planner_governance_precheck.v1` | read-only |
| Dry-Run Receipt Builder | `AgentDispatchPlannerDryRunReceiptBuilder` | `atlas.self_construction.agent_dispatch_planner_dry_run_receipt.v1` | persistent-local |
| Batch Planner | `AgentDispatchPlannerBatchPlanner` | `atlas.self_construction.agent_dispatch_planner_batch_plan.v1` | read-only composer |
| Certification | `AgentDispatchPlannerCertificationService` | `atlas.self_construction.agent_dispatch_planner_certification.v1` | read-only gate |

No component dispatches. No component claims. No component calls a
provider.

## 2. Storage layout

Only the Dry-Run Receipt Builder persists state, under:

```text
atlas/self-construction/agent-control-plane/dispatch-planner/receipts/
```

This holds `receipt_<id>.json` files and a single `index.json`. A
coarse advisory `.lock` file is used to serialize writes. No other
storage path is touched by this layer.

## 3. Candidate Selector

`select(options)` returns:

- `candidate_tasks[]` from the Task Packet Queue with status in
  `claimable | queued | released | lease_expired`, sorted by priority
  desc then id asc, capped to `task_limit` (default 50).
- `candidate_agents[]` from the Agent Runtime Registry with status in
  `available | registered`, optionally filtered by `capability` or
  `agent_kind`, capped to `agent_limit` (default 50).
- `task_summary` and `agent_summary` with status/kind counts.
- `selection_hash` (sha256, stable for identical inputs).
- Runtime flags all false.

It does not enqueue, dequeue, mutate or claim. It is the seam between
the upstream queue + registry and the rest of the planner.

## 4. Eligibility Evaluator

`evaluate(tasks, agents, options)` returns an
`evaluation_matrix[task][agents]` where each pair has:

- `is_eligible` boolean.
- `reasons[]`: explicit blocking reasons (e.g. `capability_mismatch`,
  `capacity_full`, `agent_quarantined`, `workspace_isolation_required`,
  `lease_support_required`,
  `human_approval_required_for_risk:<level>`,
  `dry_run_agent_requires_dry_run_only_task`,
  `dry_run_only_capability_requires_dry_run_only_task`,
  `evidence_required_but_agent_not_evidence_ready`).
- `capability_match` from the capability catalog.
- `flags{}`: per-axis fine-grained booleans.
- `risk_level` normalized to `low | medium | high | critical`.
- `free_slots`.

Output includes `eligible_pair_count`, `ineligible_pair_count`,
`pair_total`, `eligibility_hash` (sha256, stable). Runtime flags all
false.

## 5. Scope Conflict Analyzer

`analyze(tasks, options)` returns one analysis per task:

- `write_set`, `read_set` (normalized).
- `conflict_status: clear | conflict`.
- `conflicts[]` with `lease_id`, `overlap`, `overlap_count`.
- `has_scope_lock`.

Defaults to live ledger via `AgentControlPlaneClaimLeaseRepository::conflictCheck()`.
Tests may set `use_live_ledger: false` and pass `active_leases` to
analyze deterministically.

Aggregates `clear_task_count`, `conflicting_task_count`,
`active_lease_count`, and a stable `analysis_hash`. Runtime flags all
false.

## 6. Governance Precheck

`precheck(tasks, agents, options)` returns:

- `kill_switch_state`: `armed | disarmed | tripped | unknown`. Tripped
  or disarmed adds a `global_blockers` entry.
- `budget_gate_state` and `budget_gate_ok`: `green` is the only
  allowed runtime-promotion state. Anything else blocks.
- `task_blockers[]`: each task gets `operator_approval_required` (for
  high/critical risk) and `evidence_refs_missing` checks.
- `agent_blockers[]`: each agent is checked against quarantine and
  `disabled` status.
- `global_blockers[]`: kill switch, budget gate, self-programming
  request.
- `clear_task_count`, `clear_agent_count`.
- `governance_hash` (sha256, stable). Runtime flags all false.

The governance precheck is a *projection* of what a real governance
gate would assert at dispatch time. It does not authorize anything.

## 7. Dry-Run Receipt Builder

`build(plannedDispatch, options)` persists a local dry-run receipt
under the storage prefix above. Each receipt carries:

- `receipt_id` (UUID, included in the record only; excluded from the
  hash so identical content produces identical hashes).
- `task_packet_id`, `task_packet_hash`, `agent_id`.
- `risk_level`, `workspace_policy`, `requires_lease`, `dry_run_only`.
- `write_set`, `read_set` (normalized).
- `matching_policy`, `evidence_refs`.
- `recorded_at` (ISO 8601).
- `receipt_hash` (sha256, stable for the same content payload).
- `receipt_kind: dispatch_plan_dry_run`.
- `is_dispatched: false`, `is_real_claim: false`,
  `is_real_receipt: false`, plus every other runtime flag false.

`get(receiptId)` and `list(filters)` return persisted records.
`isAvailable()` exposes a storage probe.

These receipts are **not** the evidence ledger and **not** real
claim/lease receipts.

## 8. Batch Planner

`plan(options)` composes the pipeline:

1. CandidateSelector → `candidate_tasks`, `candidate_agents`.
2. EligibilityEvaluator → `evaluation_matrix`.
3. ScopeConflictAnalyzer → per-task conflict analysis.
4. GovernancePrecheck → global / per-task / per-agent blockers.
5. Greedy assignment: for each task (in matrix order), pick the
   first eligible agent that has free capacity in this batch.
6. Optionally call `DryRunReceiptBuilder::build()` per assignment
   when `persist_receipts: true`.

Output:

- `status`: `planned` (at least one dispatch + no global blockers) or
  `blocked`.
- `planned_dispatches[]`, `blocked_dispatches[]` with reasons.
- `selection`, `eligibility`, `scope_analysis`, `governance` for
  audit.
- `batch_hash` (sha256, stable for identical input ledger state and
  matching_policy; **does not** include the volatile `batch_id`).
- Every runtime flag false.

Operators must not interpret `status: planned` as authorization to
dispatch. Promotion to real dispatch happens only under Runtime Pilot
Certification, in a separate slice with its own decision receipt.

## 9. Certification

`certify(options)` returns:

- `invariants[]` including availability checks for each sub-service
  and behavioral checks:
  - `selection_respects_claimable_statuses`
  - `eligibility_flags_human_approval_for_high_risk`
  - `scope_analyzer_detects_overlap`
  - `governance_blocks_when_kill_switch_tripped`
  - `receipt_hash_stable`
  - `batch_planner_produces_plan_shape`
  - `batch_hash_stable`
- `runtime_safety:*_false` for every runtime flag plus
  `no_dispatch_real`, `no_provider_call`, `no_token_spend`,
  `no_self_programming`.
- `runtime_safety` block with `runtime_safety_all_false: true`.
- `certification_hash` (sha256, stable).
- `next_action` (string).

`status: available` requires `invariants_all_true: true`.

## 10. Integration with upstream layers

- Task Packet Queue (`AgentControlPlaneTaskPacketQueueRepository`) is
  read-only here. The planner does not enqueue, mutate status, claim
  or release.
- Claim/Lease (`AgentControlPlaneClaimLeaseRepository`) is read-only
  for `activeLeases()` and `conflictCheck()`. The planner never
  calls `claim()`, `renew()`, or `release()`.
- Agent Registry + Heartbeat + Quarantine are read-only here.
- The planner never composes into self-programming. The certification
  surface asserts this explicitly.

## 11. Runtime safety

Every public surface returns the following flags as false:

- `runtime_execution_allowed`
- `dispatch_allowed`
- `provider_call_allowed`
- `token_spend_allowed`
- `self_programming_allowed`
- `ledger_write_allowed`
- `claim_real_allowed`

`Certification::certify()` returns
`runtime_safety: { runtime_safety_all_false: true, ... }` when all
flags remain false. This is the canonical safe-to-operate signal for
the layer.

## 12. Limits and non-goals

This layer does not:

- start any process;
- call Codex CLI, Codex app, Claude API or any other provider;
- spawn subprocesses or invoke adapters;
- enable adapter execution or signed dispatch authorization;
- claim a real lease or write the evidence ledger;
- spend tokens (no budget mutation; budget gate is read-only signal);
- enable self-programming;
- declare Atlas Self-Construction OS complete.

If a consumer needs any of these, it must open a separate slice with
its own decision receipt and Runtime Pilot Certification gate.

## 13. How to extend safely

To add a new blocker reason:

- Append to the corresponding sub-service's reason set.
- Add a certification scenario that asserts the new reason is
  reported when its precondition holds.
- Add at least one test that exercises a happy-path and a sad-path
  for the new reason.

To add a new governance signal:

- Add the signal to `GovernancePrecheck::precheck()` options.
- Add an invariant in the certification that proves the signal is
  honored.
- Update this doc's section 6 with the canonical signal name.

To promote a slice out of dry-run:

- This is not possible in this stage. Promotion requires Runtime
  Pilot Certification with a signed decision receipt, defined in a
  separate doc.

## Resumo

Camada Dispatch Planner: 7 servicos read-only compostos em um
`plan()`. Combina candidate selector, eligibility, scope conflict,
governance precheck, dry-run receipt, batch planner, certification.
Dispatch real bloqueado.

## Papel no Atlas

Submodulo do Agent Control Plane. Le upstream (task queue, claim/lease,
agent registry, quarantine) e emite advisory plans para futura promocao
sob Runtime Pilot Certification.

## Onde Se Encaixa

Filho do Agent Control Plane Contract, irmao do Agent Runtime Registry
e dos contratos de Task Queue/Claim-Lease.

## Contratos

Cada sub-servico expoe runtime_safety_all_false. Governance precheck
exige kill_switch=armed, budget_gate=green, sem self-programming,
evidence_refs presente se evidence_required. Batch planner status
`planned` exige todos os requisitos verdes.

## Fluxo

select -> evaluate -> analyze scope -> precheck governance -> plan
batch -> opcional persist receipts -> certification valida invariantes.

## Regras para IA

Nao tratar plan() como dispatch. Nao tratar dry-run receipt como
evidence ledger. Nao mutar task queue, claim/lease, registry ou
quarantine a partir desta camada. Nao habilitar self-programming.

## Escopo de Implementacao

Apenas arquivos com prefixo `AgentDispatchPlanner*` em
`app/Services/Ai/SelfConstruction/` e
`tests/Feature/Ai/SelfConstruction/`, mais este doc.

## Dependencias

Storage local default, Task Packet Queue, Claim/Lease Repository,
Agent Runtime Registry, Capability Catalog, Quarantine Repository.

## Evidencias

Receipts locais com sha256 stable hash, certification batch verde,
docs-health verde, 120 tests / 327 assertions verdes neste sprint.

## Riscos

Confundir dry-run com dispatch real, vazar governance precheck como
autorizacao real, ignorar batch_hash drift entre snapshots, ignorar
operator_approval_required em high/critical risk.

## Exemplos

Operador roda `plan()` com tasks claimable e agents available; recebe
`status: planned` quando kill_switch=armed, budget=green, capability
match, sem scope conflict, sem quarantine. Nada e despachado.

## Proximas Acoes

Promover apenas via Runtime Pilot Certification verde com decision
receipt assinado; manter sincronia com Task Queue/Claim-Lease e Agent
Runtime Registry sob slices independentes.
