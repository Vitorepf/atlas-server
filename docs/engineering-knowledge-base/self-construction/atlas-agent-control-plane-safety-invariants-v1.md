---
id: atlas-agent-control-plane-safety-invariants-v1
type: engineering_knowledge
title: Atlas Agent Control Plane - Safety Invariants v1
status: active
category: architecture
priority: 99
summary: Hard-law safety invariants that must hold at all times for the Agent Control Plane projection and any future runtime. Each invariant is a stop condition, not a guideline.
tags:
  - atlas-ai
  - self-construction
  - agent-control-plane
  - safety
  - invariants
capabilities:
  - safety_invariants
  - hard_law_governance
  - kill_switch_required
  - rollback_required
decisions:
  - The 16 invariants below are hard law. Any violation downgrades the Self-Construction OS posture and blocks promotion.
  - No combination of features may relax an invariant.
  - Self-programming is explicitly disabled in the current stage. The invariant against it is non-negotiable.
maintenance:
  - Add a new invariant only with a decision receipt, evidence and gates.
  - Never remove an invariant; deprecate via a successor doc with audit trail.
  - Do not edit agent-control-plane-contract.md from this doc.
related_paths:
  - docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md
  - docs/engineering-knowledge-base/self-construction/self-programming-safety-contract.md
  - docs/engineering-knowledge-base/self-construction/atlas-self-construction-os-operator-runbook-v1.md
  - docs/engineering-knowledge-base/self-construction/atlas-agent-control-plane-runtime-pilot-map-v1.md
  - docs/engineering-knowledge-base/self-construction/atlas-self-construction-os-completion-roadmap-v1.md
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 520
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-agent-control-plane-safety-invariants-v1
graph_title: Atlas Agent Control Plane - Safety Invariants v1
graph_world: atlas
graph_layer: gear
graph_kind: policy
graph_parent: atlas-self-construction-agent-control-plane-contract
graph_status: active
graph_source: repo
human_name: Atlas Agent Control Plane - Safety Invariants v1
canonical_name: Atlas Agent Control Plane - Safety Invariants v1
technical_name: atlas-agent-control-plane-safety-invariants-v1
cartography_type: policy
canonical_source: docs/engineering-knowledge-base/self-construction/atlas-agent-control-plane-safety-invariants-v1.md
repo_paths:
  - docs/engineering-knowledge-base/self-construction/atlas-agent-control-plane-safety-invariants-v1.md
allowed_changes:
  - Adicionar uma nova invariant com decision receipt, evidencia e gates verdes.
forbidden_changes:
  - Remover ou relaxar uma invariant sem decision receipt, evidencia e successor doc.
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
  - docs/engineering-knowledge-base/self-construction/atlas-agent-control-plane-safety-invariants-v1.md
evidence_refs:
  - symbol: AtlasAgentControlPlaneSafetyInvariantsService
  - command: atlas:aaeos:agent-control-plane-safety-invariants
  - test: AtlasAgentControlPlaneSafetyInvariantsTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
visual_tags:
  - gear
  - invariants
  - safety
ai_entrypoints:
  - Leia toda a lista de invariantes antes de propor qualquer feature, slice ou promocao.
ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - Invariante violada e silenciada por reclassificacao para warning.
  - Invariante "verificada" por inspecao manual em vez de evidencia.
observability_signals:
  - docs-health status ok
  - certification batch status verde para cada invariant
next_actions:
  - Manter este doc sincronizado com agent-control-plane-contract.md e safety contract.
---
# Atlas Agent Control Plane - Safety Invariants v1

These are the hard-law invariants for the Agent Control Plane projection
and any future runtime. They are stop conditions, not guidelines. Any
violation downgrades posture and blocks promotion. There is no fast path
around them.

The canonical projection contract remains
`agent-control-plane-contract.md`. The canonical self-programming floor
remains `self-programming-safety-contract.md`. This doc sits beside both
and lists what must always be true.

## How to read this doc

Each invariant has:

- ID and name.
- Statement (hard law).
- Why it exists.
- What violates it.
- Evidence that satisfies it.

If an invariant cannot be satisfied by evidence, treat it as violated.
"Looks fine" is not evidence.

## I-01 No provider call before authorization

Statement: no provider API call (Codex, Claude or any other) is performed
unless a signed authorization is present and current.

Why: provider calls cost tokens, generate side effects, and may mutate
external state. They must be gated.

Violations:

- Adapter code calling a provider client without checking authorization.
- Cached authorization reused past its scope.
- "Test mode" code path that calls a real provider.

Evidence: authorization gate snapshot, replay diff over runs, audit log
showing call -> signed authorization mapping.

## I-02 No token spend before budget gate

Statement: no token spend is incurred before the budget gate has reported
green for the requesting actor, session and packet.

Why: cost overruns are silent if there is no pre-call gate.

Violations:

- Provider call without budget gate evaluation.
- Budget gate bypassed under "fallback" mode.
- Budget gate evaluating against stale or estimated costs only.

Evidence: budget gate decision log, cost import reconciliation with real
invoices, kill-switch trigger evidence for overruns.

## I-03 No adapter execution before guard

Statement: no provider adapter executes a real invocation unless the
adapter execution guard has reported green.

Why: adapters are the boundary between projection and runtime. They are
the surface where dry-run leaks become real runtime.

Violations:

- Adapter executing without consulting the guard.
- Guard evaluating only schema, not authorization.
- Guard bypassed by an "internal" code path.

Evidence: guard decision log per invocation, certification batch over
adapter contract, mutation guard report.

## I-04 No dispatch before signed receipt

Statement: no dispatch (kicking off an agent run, claim, lease or work
slice) is performed unless a signed decision receipt is present.

Why: dispatch creates state. State without a signed origin cannot be
audited or rolled back.

Violations:

- Dispatch invoked by a script that does not carry a receipt.
- Receipt reused across slices.
- Receipt signed by ambient credentials instead of an operator.

Evidence: dispatch log -> receipt id mapping, receipt signature audit,
release dossier reference.

## I-05 No process start before final authorization

Statement: no external process (codex, claude, or any spawn) is started
unless a final-process-start authorization gate has reported green for
that exact spawn.

Why: process spawn is the last point of reversibility. After spawn,
external state can change.

Violations:

- Process spawn invoked without final authorization gate.
- Final authorization gate evaluating against earlier checks only.
- "Rehearsal" code path that spawns a real process.

Evidence: final authorization gate log per spawn, scenario corpus
exercising every spawn path, mutation guard over authorization gates.

## I-06 No self-programming in current stage

Statement: Atlas does not modify Atlas code based on its own outputs in
the current stage. Self-programming is explicitly disabled.

Why: self-programming is a Phase 6 future target. Enabling it earlier
breaks the Self-Construction OS law.

Violations:

- A pipeline that writes Atlas code from agent output without operator
  receipt and Self-Programming Safety Contract acceptance.
- A "learning" packet that mutates Atlas code without governance.
- Any orchestrator that composes adapter output into Atlas code edits.

Evidence: code paths reviewed for write surfaces over Atlas code,
absence of write-mode pipelines, signed acceptance of the Self-Programming
Safety Contract for any future enablement.

## I-07 No silent completion claim

Statement: no slice, packet, claim, lease or sprint is marked complete
without aligned evidence in docs, code, tests and evidence ledger.

Why: silent completion is how systems lie. The Self-Construction OS law
forbids it.

Violations:

- Sprint closed with red docs-health.
- Sprint closed without release dossier reference.
- Slice marked "ready" without certification batch.

Evidence: completion claim -> evidence ledger entry mapping, release
dossier exporter output, replay diff over claimed slices.

## I-08 No cross-axis edits in one slice

Statement: a single slice does not edit multiple axes (e.g.
claim/lease and scope-lock and evidence ledger) in the same commit or
sprint.

Why: cross-axis edits hide regressions and make rollback ambiguous.

Violations:

- A PR that adds a claim/lease change and a scope-lock change in the
  same commit.
- A sprint with two next-required-slices in flight.
- A doc that documents one axis while code edits another.

Evidence: scope-lock plan per slice, manifest planner matching actual
diff, sprint receipt referencing one axis.

## I-09 No untracked cleanup

Statement: no cleanup operation removes untracked files, untracked
worktrees, untracked branches, untracked locks or untracked queue entries
without an explicit decision receipt.

Why: untracked state is often in-flight human work or evidence. Removing
it silently destroys auditability.

Violations:

- Scripts that `rm -rf` untracked directories.
- Cleanup jobs that delete untracked workspaces by age.
- "Stale lock" cleanup that does not check liveness.

Evidence: cleanup audit log, decision receipt per cleanup batch,
heartbeat check before lock cleanup.

## I-10 No worktree revert to bypass scope-lock

Statement: no Forge Workspace worktree is reverted to bypass an active
scope-lock or to discard in-flight work, except via a signed rollback
receipt.

Why: worktree revert is a destructive operation that can erase evidence
and bypass governance.

Violations:

- `git checkout .` or `git reset --hard` invoked by orchestration code.
- Worktree revert as a side effect of "retry" logic.
- Worktree revert without scope-lock release.

Evidence: rollback receipt per revert, scope-lock release log, replay
diff showing pre/post state.

## I-11 Evidence required

Statement: every claim that a slice, gate, certification or runtime step
holds must be backed by evidence reachable from the evidence ledger.

Why: claims without evidence are noise. The Self-Construction OS law
forbids them.

Violations:

- A sprint that reports green without ledger entry.
- A certification batch with no scenario, fuzz or mutation evidence.
- A release dossier with missing evidence refs.

Evidence: evidence ledger entries per claim, release dossier completeness,
evidence query results matching contract shapes.

## I-12 Continuation summary required

Statement: any run that can be resumed must have a continuation summary
declaring what is carried, what is dropped, what requires human review.

Why: silent continuation reuses stale authorization and stale state.

Violations:

- Resume code path that does not read a continuation summary.
- Continuation summary reused without freshness check.
- Continuation that carries a closed authorization forward.

Evidence: continuation summary per run, freshness check log, audit of
resumption events.

## I-13 Kill switch required

Statement: every dispatch, claim, lease, scope-lock and runtime pilot
must be stoppable by an operator-accessible kill switch without ambient
credentials.

Why: a runtime without a kill switch cannot be safely operated.

Violations:

- Dispatch path with no observable stop mechanism.
- Kill switch that requires elevated credentials not held by the operator.
- Kill switch that does not propagate to in-flight slices.

Evidence: kill switch exercised in scenario corpus, kill switch audit
log, replay over stopped runs.

## I-14 Rollback required

Statement: every state-mutating action (claim creation, lease change,
scope-lock acquisition, evidence write, dispatch, process start) must
have a documented and exercised rollback path.

Why: irreversibility is incompatible with governance.

Violations:

- Action with no rollback documented.
- Rollback path that requires manual database edit.
- Rollback path that is documented but never exercised by certification.

Evidence: rollback receipts per action class, scenario corpus exercising
rollback paths, replay diff over rolled-back slices.

## I-15 Scope lock required

Statement: every planned write must declare a scope-lock plan in advance,
have it acquired before write, and release it on completion or rollback.

Why: scope-lock is the boundary that prevents cross-axis writes and
concurrent corruption.

Violations:

- Write executed without scope-lock plan.
- Scope-lock acquired after write begins.
- Scope-lock not released on rollback.

Evidence: scope-lock plan per slice, acquisition/release audit, conflict
report against active claims.

## I-16 Claim/lease required

Statement: every agent action that consumes a packet must hold a valid
claim and current lease against that packet, backed by the durable
reservation ledger.

Why: without claim/lease, multiple agents can consume the same packet,
or a stale agent can resume a closed packet.

Violations:

- Agent action with no claim.
- Agent action with an expired lease.
- Claim ordering that bypasses the collision guard.

Evidence: claim/lease audit per action, collision guard certification,
durable reservation readiness projection.

## Cross-cutting rules

- Every invariant is monitored by certification (scenario corpus, fuzz
  harness, mutation guard, coverage report, evidence query).
- Every invariant has at least one observability signal (log, metric,
  audit ref) usable by the operator.
- An invariant cannot be "temporarily relaxed". If a slice cannot satisfy
  it, the slice does not ship.

## How an invariant gets added

- Decision receipt with rationale.
- Evidence that the projection or runtime can satisfy it.
- Certification surfaces updated to monitor it.
- Cross-links updated in the contract and the operator runbook.

## How an invariant gets deprecated

- It does not get deprecated in this version of the doc. A future
  successor doc may deprecate an invariant only with full audit trail,
  decision receipt and evidence of equivalent or stronger replacement.

## Cross-links

- Contract: `agent-control-plane-contract.md` (do not edit from here).
- Operator flow: `atlas-self-construction-os-operator-runbook-v1.md`.
- Pilot scope: `atlas-agent-control-plane-runtime-pilot-map-v1.md`.
- Completion phases: `atlas-self-construction-os-completion-roadmap-v1.md`.
- Self-programming floor: `self-programming-safety-contract.md`.
- Self-Construction OS law: `../atlas-ai-self-construction-os.md`.

## Doc maturity

This invariants doc is projection v1. It documents the invariants that
hold today against a projection-only Self-Construction OS. The same
invariants must hold under any future runtime. Strengthening an invariant
is allowed. Relaxing one is not.

## Resumo

Lista de 16 invariantes hard-law do Agent Control Plane que precisam ser
verdadeiras a todo momento na projection e em qualquer runtime futuro.

## Papel no Atlas

Camada de seguranca da projection: define stop conditions sob as quais
nenhuma slice, sprint ou runtime promotion pode prosseguir.

## Onde Se Encaixa

Filho do Agent Control Plane Contract, irmao do Self-Programming Safety
Contract, do Operator Runbook, do Runtime Pilot Map e do Completion
Roadmap.

## Contratos

Cada invariant tem statement, why, violations e evidencia que a satisfaz.
Sem evidencia, trate como violada.

## Fluxo

I-01..I-16 monitoradas continuamente via certification, replay,
observability. Violacao bloqueia promotion e dispara repair slice.

## Regras para IA

Nao propor codigo que viole qualquer invariant. Nao relaxar invariant
sob justificativa temporaria. Nao remover invariant sem successor doc.

## Escopo de Implementacao

Apenas docs em docs/engineering-knowledge-base/self-construction/. Nao
edita codigo, testes, agent-control-plane-contract.md, atlas-desktop.

## Dependencias

Agent Control Plane Contract, Self-Programming Safety Contract, durable
reservation contracts, scenario corpus, fuzz harness, mutation guard.

## Evidencias

Authorization gate logs, guard decision logs, dispatch -> receipt mapping,
ledger entries, replay diff verde, scope-lock audit, claim/lease audit.

## Riscos

Silenciar violation reclassificando como warning, verificar invariant por
inspecao manual em vez de evidencia, relaxar invariant para destravar
sprint.

## Exemplos

I-01 satisfeita quando todo provider call no log mapeia para autorizacao
assinada vigente e nao expirada, com replay verde no diff.

## Proximas Acoes

Manter invariants sincronizadas com agent-control-plane-contract.md e com
safety contract; promover invariant nova apenas via decision receipt.

