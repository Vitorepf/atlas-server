---
id: atlas-self-construction-os-completion-roadmap-v1
type: engineering_knowledge
title: Atlas Self-Construction OS - Completion Roadmap v1
status: active
category: architecture
priority: 94
summary: Phased completion roadmap for Atlas Self-Construction OS, with entry/exit criteria, risks, dependencies on Forge and Self-Improvement, and honest maturity estimate. Does not declare Self-Construction OS complete.
tags:
  - atlas-ai
  - self-construction
  - agent-control-plane
  - completion-roadmap
  - maturity
capabilities:
  - self_construction_atlas_self_construction_os_completion_roadmap_v1
  - completion_roadmap
  - maturity_promotion
decisions:
  - Completion is phased: contract-complete -> certification-complete -> dry-run pilot complete -> runtime pilot complete -> multi-agent execution complete.
  - Self-programming readiness is a separate later phase and is not part of this roadmap as a current target.
  - No phase is declared complete without docs, code, tests, evidence and drift agreement.
maintenance:
  - Update when a phase moves from in-progress to complete or when entry/exit criteria change.
  - Do not edit agent-control-plane-contract.md from this roadmap.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md
  - docs/engineering-knowledge-base/self-construction/runtime-implementation-roadmap.md
  - docs/engineering-knowledge-base/self-construction/self-programming-safety-contract.md
  - docs/engineering-knowledge-base/self-construction/atlas-self-construction-os-operator-runbook-v1.md
  - docs/engineering-knowledge-base/self-construction/atlas-agent-control-plane-runtime-pilot-map-v1.md
  - docs/engineering-knowledge-base/self-construction/atlas-agent-control-plane-safety-invariants-v1.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 520
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-self-construction-os-completion-roadmap-v1
graph_title: Atlas Self-Construction OS - Completion Roadmap v1
graph_world: atlas
graph_layer: gear
graph_kind: module
graph_parent: atlas-ai-self-construction-os
graph_status: active
graph_source: repo
human_name: Atlas Self-Construction OS - Completion Roadmap v1
canonical_name: Atlas Self-Construction OS - Completion Roadmap v1
technical_name: atlas-self-construction-os-completion-roadmap-v1
cartography_type: module
canonical_source: docs/engineering-knowledge-base/self-construction/atlas-self-construction-os-completion-roadmap-v1.md
repo_paths:
  - docs/engineering-knowledge-base/self-construction/atlas-self-construction-os-completion-roadmap-v1.md
allowed_changes:
  - Atualizar este roadmap quando uma fase mudar de status com evidencia verificavel.
forbidden_changes:
  - Declarar Self-Construction OS completo ou runtime real sem evidencia e gates verdes.
depends_on:
  - atlas-ai-self-construction-os
flows_to:
  - atlas-cartography
  - atlas-code
unlocks:
  - ai-safe-implementation-context
governs:
  - self-construction
evidence:
  - docs/engineering-knowledge-base/self-construction/atlas-self-construction-os-completion-roadmap-v1.md
evidence_refs:
  - symbol: AtlasSelfConstructionOsCompletionRoadmapService
  - command: atlas:aaeos:self-construction-os-completion-roadmap
  - test: AtlasSelfConstructionOsCompletionRoadmapTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
visual_tags:
  - gear
  - roadmap
  - self-construction
ai_entrypoints:
  - Leia Resumo, Fases, Criterios e Estimativa de Maturidade antes de propor sprints.
ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - Fase declarada completa sem evidencia.
  - Confusao entre fase de certificacao e fase de runtime real.
observability_signals:
  - docs-health status ok
  - status por fase auditavel via release dossier
next_actions:
  - Manter este roadmap sincronizado com contrato, runbook, pilot map e safety invariants.
---
# Atlas Self-Construction OS - Completion Roadmap v1

This is the honest, phased completion roadmap for Atlas Self-Construction
OS. It does not declare Self-Construction OS complete. It does not declare
any runtime real. It lays out entry and exit criteria so the operator and
the system can tell what is actually done and what is still pretending.

The canonical law remains `../atlas-ai-self-construction-os.md`. The
canonical contract for the projection remains
`agent-control-plane-contract.md`. This roadmap sits beside both.

## 1. Phases at a glance

| Phase | Posture | Outcome |
|-------|---------|---------|
| 1. Contract Complete | in progress | Every contract doc that governs the projection exists, is canonical and is read by docs-health. |
| 2. Certification Complete | in progress | Every contract is exercised by scenario corpus, fuzz harness, mutation guard, coverage report and evidence query. |
| 3. Dry-Run Pilot Complete | early | Every runtime pilot component runs end-to-end in dry-run with green certification. |
| 4. Runtime Pilot Complete | not started | Every runtime pilot component is enforced runtime, audited, observable and rollback-safe. |
| 5. Multi-Agent Execution Complete | not started | Multiple agents execute concurrent slices safely with enforced lock graph, kill switch, rollback, evidence and human-review packet. |
| 6. Self-Programming Ready | future | Atlas can propose, plan, execute and verify changes to itself under the Self-Programming Safety Contract. Not a current target. |

The phases are sequential. Skipping a phase is forbidden. Returning to an
earlier phase after regression is mandatory.

## 2. Phase 1 - Contract Complete

Goal: every contract that governs the Agent Control Plane projection and
the Self-Construction OS law exists as canonical doc, is referenced from
the Self-Construction OS index, and passes docs-health.

Entry criteria:

- Self-Construction OS law doc is canonical.
- Agent Control Plane contract doc is canonical.
- Durable reservation contracts (ledger, repository, collision guard,
  lease lifecycle, readiness projection) are canonical.

Exit criteria:

- docs-health green for the full self-construction folder.
- Every contract has owner, allowed/forbidden changes, evidence and
  required tests.
- No untracked contract drafts in `docs/engineering-knowledge-base/`.

Risks:

- Contract drift between doc and code introduces silent runtime
  assumptions.
- Cross-cutting contracts (e.g. evidence ledger) get partial coverage in
  multiple docs without a single canonical owner.

Dependencies:

- Docs Operating System.
- Knowledge governance system.

## 3. Phase 2 - Certification Complete

Goal: every contract is exercised by certification surfaces (scenario
corpus, fuzz harness, mutation guard, coverage report, evidence query)
with auditable batches.

Entry criteria:

- Phase 1 exit criteria satisfied.
- Certification baseline service exists and is auditable.
- Certification batch status service emits batch ids reachable from the
  release dossier.

Exit criteria:

- Every contract has a scenario set, a fuzz seed, a mutation set and a
  coverage report.
- Every contract has at least one evidence query that proves runtime
  events match the contract shape.
- Replay diff service reports no unexplained divergence over the latest
  ledger.
- Release dossier exporter references every certification batch.

Risks:

- Certification coverage looks high while the scenario corpus is shallow.
- Mutation guard reports false negatives if seeds are biased.
- Replay equivalence holds locally but not under multi-snapshot
  comparison.

Dependencies:

- Forge Workspace certification surfaces (read-only).
- Knowledge governance (for doc-test-evidence alignment).

## 4. Phase 3 - Dry-Run Pilot Complete

Goal: every runtime pilot component (task packet builder, claim/lease
simulator, scope-lock planner, evidence ledger dry-run, continuation
summary builder, work-product manifest planner, cost import dry-run,
multi-agent parallelism planner, runtime pilot orchestrator) runs
end-to-end in dry-run with green Runtime Pilot Certification.

Entry criteria:

- Phase 2 exit criteria satisfied.
- All pilot components implemented as dry-run projections.
- Runtime Pilot Certification service exists.

Exit criteria:

- Every pilot component reports green certification batch.
- Orchestrator produces a coherent end-to-end plan with single
  next-required-slice.
- `runtime_safety_all_false` remains true.
- Release dossier records dry-run completion with evidence refs.

Risks:

- Operator confuses dry-run "ready" with runtime "ready".
- Orchestrator composes components that individually look green but
  produce contradictory plans.
- Cost import dry-run drifts from real provider invoices once those exist.

Dependencies:

- Forge Workspace dry-run surfaces.
- Self-Improvement (read-only) for learning feedback. No dispatch.

## 5. Phase 4 - Runtime Pilot Complete

Goal: every pilot component is enforced runtime, audited, observable and
rollback-safe.

Entry criteria:

- Phase 3 exit criteria satisfied.
- Every safety invariant in
  `atlas-agent-control-plane-safety-invariants-v1.md` is durable and
  monitored.
- Self-Programming Safety Contract is read and accepted in the decision
  receipt for the promotion.
- Forge Workspace exposes kill switch, rollback, scope-lock surface.

Exit criteria:

- Every pilot component is enforced and proven by evidence over real
  runs.
- Replay diff is green over runtime ledger entries.
- Cost import is reconciled against real invoices.
- Multi-snapshot comparison shows no drift.
- A human review packet is signed for the promotion.

Risks:

- Single-agent runtime exposes coordination bugs that only manifest under
  multi-agent load.
- Cost reconciliation lags and overruns are not caught by budget gate.
- Continuation summary leaks stale authorization across runs.

Dependencies:

- Forge Workspace runtime surfaces.
- Real durable reservation ledger.
- Real cost event store.

## 6. Phase 5 - Multi-Agent Execution Complete

Goal: multiple agents execute concurrent slices safely with enforced lock
graph, kill switch, rollback, evidence and human-review packet.

Entry criteria:

- Phase 4 exit criteria satisfied.
- Multi-Agent Parallelism Planner is at enforced posture.
- Collision guard is proven under concurrent load.

Exit criteria:

- Concurrent slices complete without violating safety invariants.
- Lock graph enforces ordering under contention.
- Kill switch demonstrably stops in-flight slices without orphaning
  state.
- Rollback is observable for each agent independently.

Risks:

- Concurrent runs amplify rare bugs in continuation/cost/evidence.
- Operator overrides under contention violate signed authorization
  ordering.
- Cost overruns scale faster than the budget gate's reaction time.

Dependencies:

- Phase 4.
- Self-Improvement read-only learning loops that can absorb concurrent
  evidence.

## 7. Phase 6 - Self-Programming Ready (future, not current)

Goal: Atlas can propose, plan, execute and verify changes to itself
under the Self-Programming Safety Contract. This phase is explicitly not
a current target.

Entry criteria:

- Phase 5 exit criteria satisfied.
- Self-Programming Safety Contract is fully canonical and proven by
  evidence over Phase 4 and Phase 5 runtime.
- Cross-axis edits are blocked by enforced scope-lock under concurrent
  load.
- Human-review packet flow is exercised regularly with non-trivial
  rollbacks.

Exit criteria: deliberately undefined here. The exit shape will be
defined in a future doc when the prior phases are real, not now.

Risks:

- Treating self-programming as a routine slice rather than a
  governance-grade promotion.
- Allowing self-programming over Atlas core without decision receipt.

Dependencies:

- All earlier phases at real runtime.

## 8. Dependencies on Forge and Self-Improvement

Forge (Code Forge / Forge Workspace):

- Provides kill switch, rollback, scope-lock surface and workbench views.
- Provides ergonomic UI for operator decision receipts and human review
  packets.
- This roadmap does not authorize edits to Forge code from
  Self-Construction OS sprints. Forge has its own contracts.

Self-Improvement:

- Read-only learning feedback consumer.
- May propose learning packets based on evidence ledger entries.
- Must not bypass Self-Programming Safety Contract.

Rivals, Cartografia, Voice:

- Out of scope of this roadmap. Listed only to make the boundary
  explicit. No edits to those surfaces from Self-Construction OS sprints.

## 9. Honest maturity estimate

As of this doc's first publication:

- Phase 1 (Contract Complete): in progress. The Agent Control Plane
  contract and durable reservation contracts are canonical. Pilot
  contracts are partially canonical and being closed under active work.
- Phase 2 (Certification Complete): in progress. Certification baseline,
  scenario corpus, fuzz harness, mutation guard, coverage report and
  evidence query exist. Coverage breadth and seed quality are still
  evolving.
- Phase 3 (Dry-Run Pilot Complete): early. All dry-run pilot components
  exist; Runtime Pilot Certification gate is being established; orchestrator
  composition is forming. No declared "ready".
- Phase 4 (Runtime Pilot Complete): not started.
- Phase 5 (Multi-Agent Execution Complete): not started.
- Phase 6 (Self-Programming Ready): future, not a current target.

`runtime_safety_all_false` is true. Provider dispatch is disabled. Real
runtime does not exist.

## 10. Suggested next macro-sprints

These are suggestions for the next slices the operator may consider. Each
is one slice, not a fan-out. Each must be opened with its own decision
receipt.

- Close any remaining gaps in Phase 1 contract coverage in
  `docs/engineering-knowledge-base/self-construction/`.
- Bring every pilot component to a green certification batch (Phase 2 ->
  Phase 3).
- Close the Runtime Pilot Certification gate for the orchestrator with a
  signed batch.
- Define the Phase 4 entry decision receipt template (doc only, no
  enablement).
- Define the Phase 5 kill-switch and lock-graph evidence requirements
  (doc only, no enablement).

None of these sprints authorize runtime. They tighten the projection so a
future runtime sprint can be opened safely.

## 11. Hard prohibitions

- No phase is declared complete without aligned docs, code, tests,
  evidence and drift checks.
- No phase is skipped.
- No cross-axis bundling: a Phase 3 sprint does not edit Phase 4 code.
- No phase exits over warnings without owner, target slice and deadline.
- No phase exits with `runtime_safety_all_false` false.
- No phase exits over a missing release dossier reference.

## 12. Cross-links

- Contract: `agent-control-plane-contract.md` (do not edit from here).
- Self-Construction OS law: `../atlas-ai-self-construction-os.md`.
- Operator flow: `atlas-self-construction-os-operator-runbook-v1.md`.
- Pilot scope: `atlas-agent-control-plane-runtime-pilot-map-v1.md`.
- Invariants: `atlas-agent-control-plane-safety-invariants-v1.md`.
- Phased runtime: `runtime-implementation-roadmap.md`.
- Safety floor: `self-programming-safety-contract.md`.

## 13. Doc maturity

This roadmap is projection v1. It must be revised when any phase moves
status, when entry/exit criteria change, or when a new dependency is
introduced. Until then, no sentence here authorizes runtime, completion
claims or self-programming.

## Resumo

Roadmap honesto, fasado, de conclusao do Atlas Self-Construction OS, com
criterios de entrada e saida por fase e estimativa de maturidade atual.

## Papel no Atlas

Define onde o Self-Construction OS realmente esta hoje, o que falta para
cada fase e o que separa projection de runtime real.

## Onde Se Encaixa

Filho do Self-Construction OS law, irmao do Operator Runbook, do Runtime
Pilot Map e dos Safety Invariants.

## Contratos

Cada fase declara criterio de entrada, criterio de saida, dependencias,
riscos e proibicoes. Pular fase e proibido.

## Fluxo

Contract Complete -> Certification Complete -> Dry-Run Pilot Complete ->
Runtime Pilot Complete -> Multi-Agent Execution Complete -> Self-Programming
Ready (futuro, nao atual).

## Regras para IA

Nao declarar fase completa sem docs, codigo, testes, evidencia e drift
alinhados. Nao bundlar dois eixos no mesmo sprint.

## Escopo de Implementacao

Apenas docs em docs/engineering-knowledge-base/self-construction/. Nao
edita codigo, testes, agent-control-plane-contract.md, atlas-desktop.

## Dependencias

Self-Construction OS law, durable reservation contracts, Forge Workspace,
Self-Improvement como consumidor read-only de learning.

## Evidencias

Release dossier por fase, certification batch verde, replay diff verde,
human review packet assinado quando aplicavel.

## Riscos

Declaracao prematura de completude, salto de fase, regressao silenciosa,
cost overrun fora do budget gate, override sem decision receipt.

## Exemplos

Fase 3 considera-se completa quando todo componente do Runtime Pilot tem
certification batch verde e release dossier registra dry-run completion.

## Proximas Acoes

Fechar Phase 1 contract gaps, levar pilot components a green certification
batch e definir templates de decision receipt para Phase 4 entry.

