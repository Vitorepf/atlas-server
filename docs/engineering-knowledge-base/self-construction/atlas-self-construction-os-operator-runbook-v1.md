---
id: atlas-self-construction-os-operator-runbook-v1
type: engineering_knowledge
title: Atlas Self-Construction OS - Operator Runbook v1
status: active
category: architecture
priority: 96
summary: Operator runbook for safely driving Atlas Self-Construction OS macro-sprints, reading projection outputs and stopping before runtime claims that are not backed by evidence.
tags:
  - atlas-ai
  - self-construction
  - agent-control-plane
  - operator-runbook
  - safety
capabilities:
  - self_construction_os
  - agent_control_plane
  - operator_workflow
  - safety_governance
decisions:
  - Operator workflow must distinguish certification, replay, workbench, observatory and runtime pilot before any runtime claim.
  - next_required_slice is the canonical stop signal between macro-sprints.
  - runtime_safety_all_false must remain true until every authorization, guard, scope-lock, claim and lease invariant is durable and proven.
  - Any violation downgrades the Self-Construction OS posture and blocks promotion.
maintenance:
  - Update when a new self-construction command, certification surface or runtime pilot slice is added.
  - Update when new violation or warning categories are introduced.
  - Do not edit agent-control-plane-contract.md from this doc; cross-link only.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md
  - docs/engineering-knowledge-base/self-construction/runtime-implementation-roadmap.md
  - docs/engineering-knowledge-base/self-construction/self-programming-safety-contract.md
  - docs/engineering-knowledge-base/self-construction/atlas-agent-control-plane-runtime-pilot-map-v1.md
  - docs/engineering-knowledge-base/self-construction/atlas-self-construction-os-completion-roadmap-v1.md
  - docs/engineering-knowledge-base/self-construction/atlas-agent-control-plane-safety-invariants-v1.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 520
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-self-construction-os-operator-runbook-v1
graph_title: Atlas Self-Construction OS - Operator Runbook v1
graph_world: atlas
graph_layer: gear
graph_kind: runbook
graph_parent: atlas-ai-self-construction-os
graph_status: active
graph_source: repo
repo_paths:
  - docs/engineering-knowledge-base/self-construction/atlas-self-construction-os-operator-runbook-v1.md
allowed_changes:
  - Atualizar este doc quando um novo comando, slice ou categoria de violacao for promovido.
forbidden_changes:
  - Declarar runtime, autonomia ou prontidao real sem evidencia verificavel e gates verdes.
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
  - docs/engineering-knowledge-base/self-construction/atlas-self-construction-os-operator-runbook-v1.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
visual_tags:
  - gear
  - runbook
  - self-construction
ai_entrypoints:
  - Leia Resumo, Pre/Pos-sprint, next_required_slice e Stop Conditions antes de operar.
ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - Operador interpreta certification como runtime e dispara provider.
  - Operador ignora next_required_slice e pula etapa.
observability_signals:
  - docs-health status ok
  - runtime_safety_all_false true em todos os snapshots
next_actions:
  - Manter este runbook sincronizado com comandos, certificacoes e safety invariants.
---

# Atlas Self-Construction OS - Operator Runbook v1

This is the operator-facing runbook for driving Atlas Self-Construction OS
macro-sprints without breaking the safety contract. It does not authorize any
runtime execution. It explains what to run, what to read, where to stop, and
when to ask for human review.

It is a sibling of, not a replacement for, the canonical
`agent-control-plane-contract.md`. Read both.

## 1. What is Atlas Self-Construction OS

Atlas Self-Construction OS is the governed program by which Atlas evolves the
system that builds itself: research, canonical docs, SDD, governed
implementation, evidence, drift detection and learning, framed by hard laws
(no self-programming without SDD, no result without evidence, no completion
without aligned doc/code/test/evidence).

It is not "an agent writing code". It is the law and the projection that
makes that possible without losing safety, traceability or canonical truth.

## 2. What is the Agent Control Plane

Agent Control Plane is the submodule of Self-Construction OS that coordinates
provider sessions, inferred agent runs, packet locks, liveness and
continuation. The first implementation is a read-only projection over the
durable reservation ledger and Forge Workspace state. Provider dispatch
remains disabled until runs, heartbeats, liveness, adapters, cost events and
work products become durable runtime objects.

The operator's job is to drive the projection through certification, replay,
workbench, observatory and runtime pilot surfaces - none of which execute a
real provider call.

## 3. Surfaces and what they prove

Each surface answers a different question. They are not interchangeable.

- Certification: does the projection contract hold under scenarios, fuzz,
  mutation and coverage? Output: certification status batch, scenario corpus,
  mutation guard, fuzz harness, coverage report, evidence query.
- Replay: given the same input ledger, does the projection produce the same
  authoritative snapshot? Output: replay diff, multi-snapshot comparison,
  release dossier.
- Workbench: can an operator inspect a single packet, lock, run, liveness or
  continuation state safely? Output: read-only views and decision context.
- Observatory: what is the longitudinal posture of the projection across
  time? Output: drift, anomalies, coverage trends.
- Runtime Pilot: what would runtime look like if we built it? Output:
  task-packet plans, claim/lease simulations, scope-lock plans, evidence
  ledger dry-runs, continuation summaries, work-product manifests, cost
  imports and multi-agent parallelism plans - all in dry-run mode, gated by
  the Runtime Pilot Certification.

If a surface cannot answer its own question with current evidence, the
operator stops. Do not patch the symptom by relabeling a surface.

## 4. Canonical commands

The operator commands below are projection-only. None of them dispatches a
real provider or starts a real process. Treat every output as advisory until
the Self-Construction OS completion roadmap reaches the matching slice.

```bash
# Top-level posture
php artisan atlas:ai:self-construction --json

# Agent Control Plane projection
php artisan atlas:ai:self-construction --agent-control-plane --json

# Runtime schema preflight (still projection)
php artisan atlas:ai:self-construction \
  --agent-control-plane-runtime-schema-preflight --json

# Adapter contract (no execution)
php artisan atlas:ai:self-construction --agent-adapter-contract --json

# Adapter invocation runtime policy (preview only)
php artisan atlas:ai:self-construction \
  --agent-provider-adapter-invocation-runtime-policy \
  --actor=<actor> --session=<session> --json
```

Exact runtime pilot commands and their flags are defined in
`atlas-agent-control-plane-runtime-pilot-map-v1.md`. This runbook only frames
their operational use.

## 5. Pre macro-sprint checklist

Run before opening any macro-sprint that touches Self-Construction OS.

- Confirm canonical docs are clean: `git status --short` shows no
  unintended edits to `docs/engineering-knowledge-base/self-construction/`.
- Confirm docs-health is green:
  `php artisan atlas:engineering:knowledge docs-health --json` returns
  `status: ok`.
- Confirm the latest certification batch is recorded and the snapshot is
  reachable.
- Confirm the latest replay diff has no unexplained divergence.
- Confirm `runtime_safety_all_false` is true in the projection.
- Confirm there is no active claim/lease that the sprint would invalidate.
- Confirm the sprint's `next_required_slice` is one slice ahead, not two.

If any item is red, the sprint does not start. Open a remediation slice
instead.

## 6. Post macro-sprint checklist

Run after closing any macro-sprint.

- Re-run docs-health.
- Re-run the relevant certification batch and replay diff.
- Confirm new violations are zero or explicitly accepted with rationale.
- Confirm new warnings are tracked with owner and target slice.
- Confirm the projection still reports `runtime_safety_all_false` true.
- Update `next_required_slice` to the next safe step, not the most ambitious.
- Record evidence path in the release dossier exporter.

If any post-check is red, do not declare the sprint complete. Roll back the
scope-lock and open a repair slice.

## 7. How to read next_required_slice

`next_required_slice` is the projection's answer to "what is the smallest,
safest unit of work that must happen next so the law still holds?". It is
the canonical stop signal between macro-sprints.

Rules:

- It always points to one slice. If you read more than one, the projection
  is reporting a fan-out that must be collapsed before work starts.
- If it is empty, the projection cannot recommend a next step. That is a
  stop, not a green light.
- If it changed without an evidence delta, treat it as drift. Do not act on
  it until the evidence supports the change.

Operator interpretation:

- "Promote scope-lock planner from dry-run to enforced" - this is a slice;
  cross-axis edits are forbidden in the same sprint.
- "Add multi-agent parallelism planner coverage report" - this is a slice
  scoped to the planner; do not touch claim/lease in the same sprint.
- "Fix evidence ledger dry-run divergence" - this is a repair slice; runtime
  pilot work pauses until the divergence closes.

## 8. How to read runtime_safety_all_false

`runtime_safety_all_false` is a single boolean over the projection's safety
invariants. While it is true, the Self-Construction OS is in
projection/dry-run posture and provider dispatch is disabled.

The flag must remain true until every invariant in
`atlas-agent-control-plane-safety-invariants-v1.md` is durable, monitored
and proven by evidence. Flipping the flag is a separate macro-sprint with
its own decision receipt and human review packet. It is not a side effect of
any single feature slice.

Operator rule: if the flag is true and an output suggests runtime, the
output is wrong. Trust the flag.

## 9. How to read violations and warnings

The projection emits two streams:

- Violations are hard-law breaches. They block promotion, block runtime
  pilot enablement and block scope-lock release. They must be remediated in
  the same sprint or trigger an explicit rollback.
- Warnings are soft signals that the projection is drifting toward a
  violation. They do not block, but every warning needs an owner, a target
  slice and a deadline. Warnings older than the deadline graduate to
  violations.

Operator rule: never close a sprint with unowned warnings. Either accept
them with rationale and date, or open follow-up slices.

Examples of violation categories (non-exhaustive, owned by the safety
invariants doc):

- provider call attempted before authorization gate;
- adapter execution attempted before guard;
- dispatch attempted without signed receipt;
- scope-lock missing for a planned write;
- cleanup attempted on untracked state;
- evidence absent for a claimed completion.

## 10. When to stop and ask for human review

Stop unconditionally if any of the following is true:

- A violation references provider call, adapter execution, dispatch,
  authorization or scope-lock.
- `runtime_safety_all_false` flipped to false in any snapshot.
- A replay diff cannot be explained by a documented ledger change.
- `next_required_slice` is empty or contradictory.
- The release dossier exporter reports missing evidence for a slice marked
  available.
- The certification fuzz harness reports a new untriaged mutation.
- The projection reports two slices in flight in the same axis.

A human review packet must include: snapshot id, evidence ledger ref,
replay diff ref, certification batch id, violations, warnings, requested
decision, rollback plan.

## 11. Pre-runtime checklist - what must hold before any real runtime

Even after every certification, replay, workbench, observatory and runtime
pilot slice is green, the following must all be true before the operator
recommends flipping any runtime authorization:

- Every safety invariant in
  `atlas-agent-control-plane-safety-invariants-v1.md` is durable and
  monitored, with evidence.
- Every dry-run surface (task packet builder, claim/lease simulator,
  scope-lock planner, evidence ledger dry-run, continuation summary
  builder, work-product manifest planner, cost import dry-run, multi-agent
  parallelism planner, runtime pilot orchestrator) has a green
  certification batch with no untriaged mutation or fuzz failure.
- Runtime Pilot Certification reports no open violations.
- Self-Programming Safety Contract is read and accepted in the decision
  receipt.
- Forge Workspace exposes a kill switch, rollback path and scope-lock
  surface that the operator can exercise without ambient credentials.
- A human review packet is signed.

If even one item is red, runtime stays disabled. There is no fast path.

## 12. Non-goals

This runbook does not:

- Authorize provider dispatch.
- Define the shape of runtime persistence beyond what
  `agent-control-plane-contract.md` already documents.
- Define how Forge/Self-Improvement/Rivals/Cartografia surfaces should
  consume the projection - those have their own contracts.
- Override `agent-control-plane-contract.md`. If they conflict, the
  contract wins.

## 13. Hard prohibitions

The operator must not:

- Edit `agent-control-plane-contract.md` to make a violation disappear.
- Relabel a violation as a warning to ship a sprint.
- Promote a slice without docs-health green.
- Promote a slice without an updated release dossier reference.
- Run any command that performs a real provider call.
- Run any cleanup that removes untracked state without an explicit
  decision receipt.
- Revert a Forge Workspace worktree to bypass a scope-lock.
- Bundle two axes of work into one sprint to "save time".
- Accept "looks fine" as evidence.

## 14. Quick reference - operator daily flow

```text
1. read snapshot id, runtime_safety_all_false, next_required_slice
2. open the matching surface (cert/replay/workbench/observatory/pilot)
3. run the projection command, capture output
4. compare to last green evidence
5. if delta is expected and documented -> proceed to slice work
6. if delta is unexpected -> stop, open repair slice
7. close slice with evidence, update next_required_slice
8. run docs-health, certification, replay
9. if all green -> close sprint
10. if any red -> rollback scope-lock, open repair slice
```

## 15. Cross-links

- Contract: `agent-control-plane-contract.md` (do not edit from here).
- Self-Construction OS law: `../atlas-ai-self-construction-os.md`.
- Phased runtime: `runtime-implementation-roadmap.md`.
- Safety floor: `self-programming-safety-contract.md`.
- Runtime pilot scope: `atlas-agent-control-plane-runtime-pilot-map-v1.md`.
- Completion phases: `atlas-self-construction-os-completion-roadmap-v1.md`.
- Invariants: `atlas-agent-control-plane-safety-invariants-v1.md`.

## 16. Doc maturity

Status of this runbook itself: projection v1. It documents the operator
flow over a projection-only Self-Construction OS. It must be revised when
the runtime pilot moves any slice from dry-run to enforced, and again when
the first real provider authorization gate is signed. Until then, no
sentence in this doc authorizes runtime.

## Resumo

Runbook operacional para conduzir macro-sprints do Atlas Self-Construction
OS sem violar o contrato de seguranca da projection.

## Papel no Atlas

Camada operacional do Self-Construction OS: traduz contratos canonicos em
comandos, leituras e condicoes de parada para o operador humano.

## Onde Se Encaixa

Filho do Self-Construction OS, irmao do Agent Control Plane Contract, do
Runtime Pilot Map, do Completion Roadmap e dos Safety Invariants.

## Contratos

Honra agent-control-plane-contract.md, self-programming-safety-contract.md
e os safety invariants. Nao autoriza dispatch, processo ou self-programming.

## Fluxo

Pre-sprint -> sprint slice unico -> pos-sprint -> docs-health -> evidencia
no release dossier. Stop conditions sempre vencem ergonomia.

## Regras para IA

Antes de propor codigo, ler runtime_safety_all_false, next_required_slice,
violations e warnings. Nunca reclassificar violation para warning.

## Escopo de Implementacao

Apenas docs em docs/engineering-knowledge-base/self-construction/. Nao
edita codigo, testes, agent-control-plane-contract.md, atlas-desktop,
routes/api.php ou modulos vizinhos.

## Dependencias

Self-Construction OS law, Agent Control Plane Contract, Runtime Pilot
Certification, durable reservation contracts e Forge Workspace surfaces.

## Evidencias

Snapshot id, evidence ledger ref, replay diff ref, certification batch id,
release dossier ref e decision receipts assinados pelo operador.

## Riscos

Confundir certification com runtime real, ignorar next_required_slice,
violar I-01..I-16, perder evidencia ao reverter worktree.

## Exemplos

Operador roda --agent-control-plane --json, le runtime_safety_all_false
true e next_required_slice, abre uma slice, fecha com docs-health verde.

## Proximas Acoes

Sincronizar com novos comandos ou slices conforme o Runtime Pilot
Certification promove componentes do dry-run.

