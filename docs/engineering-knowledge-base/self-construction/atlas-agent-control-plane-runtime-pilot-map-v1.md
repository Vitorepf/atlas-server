---
id: atlas-agent-control-plane-runtime-pilot-map-v1
type: engineering_knowledge
title: Atlas Agent Control Plane - Runtime Pilot Map v1
status: active
category: architecture
priority: 95
summary: Map of the Agent Control Plane runtime pilot components, distinguishing dry-run projections from runtime requirements, and the path from claim/lease simulation to real claim/lease enforcement.
tags:
  - atlas-ai
  - self-construction
  - agent-control-plane
  - runtime-pilot
  - dry-run
capabilities:
  - runtime_pilot_orchestration
  - claim_lease_simulation
  - scope_lock_planning
  - evidence_ledger_dry_run
  - cost_import_dry_run
  - multi_agent_parallelism_planning
decisions:
  - Runtime pilot stays in dry-run posture until claim/lease, scope-lock, evidence ledger and cost import are durable runtime objects with green certification.
  - Each pilot component has a single responsibility and is certified independently before being composed by the orchestrator.
  - The Runtime Pilot Certification is the only surface that gates promotion of a pilot component out of dry-run.
maintenance:
  - Update when a new pilot component is added or an existing component changes posture.
  - Do not edit agent-control-plane-contract.md from this map.
related_paths:
  - docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md
  - docs/engineering-knowledge-base/self-construction/atlas-self-construction-os-operator-runbook-v1.md
  - docs/engineering-knowledge-base/self-construction/atlas-self-construction-os-completion-roadmap-v1.md
  - docs/engineering-knowledge-base/self-construction/atlas-agent-control-plane-safety-invariants-v1.md
  - docs/engineering-knowledge-base/self-construction/runtime-implementation-roadmap.md
  - docs/engineering-knowledge-base/self-construction/self-programming-safety-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 520
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-agent-control-plane-runtime-pilot-map-v1
graph_title: Atlas Agent Control Plane - Runtime Pilot Map v1
graph_world: atlas
graph_layer: gear
graph_kind: module
graph_parent: atlas-self-construction-agent-control-plane-contract
graph_status: active
graph_source: repo
human_name: Atlas Agent Control Plane - Runtime Pilot Map v1
canonical_name: Atlas Agent Control Plane - Runtime Pilot Map v1
technical_name: atlas-agent-control-plane-runtime-pilot-map-v1
cartography_type: module
canonical_source: docs/engineering-knowledge-base/self-construction/atlas-agent-control-plane-runtime-pilot-map-v1.md
repo_paths:
  - docs/engineering-knowledge-base/self-construction/atlas-agent-control-plane-runtime-pilot-map-v1.md
allowed_changes:
  - Atualizar este mapa quando um componente do pilot mudar de postura (dry-run -> enforced) com evidencia.
forbidden_changes:
  - Declarar runtime real sem certificacao verde do componente correspondente.
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
  - docs/engineering-knowledge-base/self-construction/atlas-agent-control-plane-runtime-pilot-map-v1.md
evidence_refs:
  - symbol: AgentControlPlaneRuntimePilotCertificationService
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
visual_tags:
  - gear
  - map
  - runtime-pilot
ai_entrypoints:
  - Leia Resumo, Componentes, Dry-Run vs Runtime e Promocao antes de propor implementacao.
ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - Componente dry-run confundido com runtime real por operador ou agente.
  - Promocao sem certificacao verde do componente.
observability_signals:
  - docs-health status ok
  - certification batch status para cada componente
next_actions:
  - Manter este mapa sincronizado com agent-control-plane-contract.md e safety invariants.
---
# Atlas Agent Control Plane - Runtime Pilot Map v1

The Agent Control Plane runtime pilot is a set of projection-only components
that rehearse what real runtime would look like. None of them executes a
provider call, signs a real dispatch, or mutates real runtime state.

This map describes each component, what it proves, what it does not prove,
and what would have to be true for it to graduate from dry-run to enforced
runtime.

The canonical source for the projection contract is
`agent-control-plane-contract.md`. This map sits beside it.

## 1. Components at a glance

| Component | Posture | Proves |
|-----------|---------|--------|
| Task Packet Builder | dry-run | Packet shape, scope, allowed/forbidden paths, attached evidence refs. |
| Claim/Lease Simulator | dry-run | Claim ordering, lease lifecycle, collision matrix, expiry/renewal semantics. |
| Scope Lock Planner | dry-run | Lockable file/path set, conflicts, release conditions, rollback. |
| Evidence Ledger Dry-Run | dry-run | Evidence shape, append-only ordering, replay equivalence. |
| Continuation Summary Builder | dry-run | What an agent would carry across runs, what it must drop, what must be human-reviewed. |
| Work Product Manifest Planner | dry-run | Declared work products, file boundaries, expected diff shape, gating. |
| Cost Import Dry-Run | dry-run | Cost event shape, attribution, budget gate input, no real billing impact. |
| Multi-Agent Parallelism Planner | dry-run | Concurrent slice plans, lock graph, dependency order, no live dispatch. |
| Runtime Pilot Orchestrator | dry-run | Composition of the above into a single advisory plan. |
| Runtime Pilot Certification | gate | Whether the above are independently and jointly safe to promote. |

Every row is projection. There is no row that performs runtime today.

## 2. Task Packet Builder

Service surface: `AgentControlPlaneTaskPacketBuilder` (repo path - do not
edit from this doc).

Responsibility: build a packet that an agent would consume - intent, scope,
allowed/forbidden paths, evidence references, gates, rollback notes.

Inputs: gap report, meta-SDD candidate, prior receipts, durable reservation
state.

Outputs: packet draft (read-only).

Dry-run guarantees:

- No file write outside docs.
- No claim/lease created.
- No provider call.

Runtime requirements before promotion:

- Packet is persisted with an id reachable from the evidence ledger.
- Packet schema is durable, versioned and replay-stable.
- Packet acceptance requires signed receipt.

## 3. Claim/Lease Simulator

Service surface: `AgentControlPlaneClaimLeaseSimulator`.

Responsibility: simulate the lifecycle of an agent claim against a packet
and its corresponding lease - create, hold, renew, expire, conflict.

Inputs: packet draft, durable reservation ledger projection.

Outputs: simulated lease timeline, collision report, expected
authoritative-snapshot transitions.

Dry-run guarantees:

- No real lock acquired.
- No persistent state change.

Runtime requirements before promotion:

- Lease lifecycle is enforced against a durable reservation repository with
  collision guard, lease-lifecycle contract and readiness projection.
- Renewal/expiry is observable via heartbeats.
- Lease release is idempotent and rollback-safe.

## 4. Scope Lock Planner

Service surface: `AgentControlPlaneScopeLockPlanner`.

Responsibility: enumerate the files, paths and runtime objects a planned
slice would touch, expose conflicts against active claims, and define
release conditions.

Inputs: packet draft, current claim/lease snapshot, Forge Workspace state.

Outputs: scope-lock plan, conflict list, release plan.

Dry-run guarantees:

- No lock taken.
- No file marked off-limits in actual runtime.

Runtime requirements before promotion:

- Scope-lock is enforced by a durable mechanism in Forge Workspace.
- Conflicts block dispatch.
- Release is observable, audited and reversible.

## 5. Evidence Ledger Dry-Run

Service surface: `AgentControlPlaneEvidenceLedgerDryRun`.

Responsibility: rehearse the append-only evidence ledger - what events
would be recorded, in what order, with what payload, and whether replay
yields an equivalent authoritative snapshot.

Inputs: simulated packet lifecycle, simulated claim/lease, simulated
work-product manifest.

Outputs: ledger preview, replay diff report.

Dry-run guarantees:

- No real ledger write.
- No mutation of authoritative snapshot.

Runtime requirements before promotion:

- Ledger is durable, append-only and replay-verified.
- Snapshot drift is monitored via the replay diff service.
- Release dossier exporter references the ledger entries.

## 6. Continuation Summary Builder

Service surface: `AgentControlPlaneContinuationSummaryBuilder`.

Responsibility: produce a continuation summary that documents what an agent
would carry forward to a next run, what must be dropped, and what requires
human review before resumption.

Inputs: packet, claim/lease, evidence ledger preview, scope-lock plan.

Outputs: continuation summary draft.

Dry-run guarantees:

- No live session resumed.
- No state forwarded to a real provider.

Runtime requirements before promotion:

- Continuation summary is persisted and replay-stable.
- Resumption requires a signed receipt and a fresh authorization check.
- Continuation never re-uses a stale authorization.

## 7. Work Product Manifest Planner

Service surface: `AgentControlPlaneWorkProductManifestPlanner`.

Responsibility: declare in advance the work products a slice would produce
- file paths, diff boundaries, evidence attachments, completion gate
inputs.

Inputs: packet, scope-lock plan, evidence ledger preview.

Outputs: work product manifest draft.

Dry-run guarantees:

- No write to declared paths.
- No completion claim.

Runtime requirements before promotion:

- Manifest is persisted, signed and matched against actual writes at
  completion time.
- Mismatches block completion claims.

## 8. Cost Import Dry-Run

Service surface: `AgentControlPlaneCostImportDryRun`.

Responsibility: model cost events that a real provider call would generate
- per session, per packet, per slice - and feed the budget gate input.

Inputs: provider session projection, simulated packet lifecycle.

Outputs: cost event preview, budget gate preview.

Dry-run guarantees:

- No real billing impact.
- No external API call.

Runtime requirements before promotion:

- Cost events are durable, attributable and reconciled against provider
  invoices.
- Budget gate is enforced before any token spend.
- Overruns trigger kill switch and rollback.

## 9. Multi-Agent Parallelism Planner

Service surface: `AgentControlPlaneMultiAgentParallelismPlanner`.

Responsibility: plan concurrent slices across multiple agents - their lock
graph, dependency order, conflict windows, fallback to serial execution.

Inputs: scope-lock plans, claim/lease snapshot, evidence ledger preview.

Outputs: parallelism plan, lock graph, fallback policy.

Dry-run guarantees:

- No concurrent dispatch.
- No live coordination across agents.

Runtime requirements before promotion:

- Lock graph is enforced.
- Concurrent claims respect collision guard.
- Fallback to serial is observable and auditable.

## 10. Runtime Pilot Orchestrator

Service surface: `AgentControlPlaneRuntimePilotOrchestrator`.

Responsibility: compose the above components into a single advisory plan
that an operator can read end-to-end, with a single posture summary and a
single next-required-slice.

Inputs: outputs of all the components above.

Outputs: pilot plan, posture summary, next-required-slice.

Dry-run guarantees:

- The orchestrator never authorizes runtime.
- The orchestrator never marks itself "ready" without the Runtime Pilot
  Certification.

Runtime requirements before promotion:

- Every composed component is at runtime posture with green certification.
- The orchestrator's "ready" signal is itself certified and audited.

## 11. Runtime Pilot Certification

Service surface: `AgentControlPlaneRuntimePilotCertificationService`.

Responsibility: gate the promotion of any pilot component from dry-run to
enforced runtime, by checking scenario corpus, fuzz harness, mutation
guard, coverage report and evidence query.

Inputs: certification baseline, scenario corpus, fuzz seeds, mutation set,
coverage data, evidence queries.

Outputs: certification batch, coverage report, mutation report,
release-readiness verdict.

This is the only surface that produces "ready" verdicts for pilot
components. Operators must not bypass it.

## 12. From simulation to real claim/lease

The path from claim/lease simulation to enforced claim/lease has the
following non-negotiable steps. None is optional.

1. Durable reservation ledger is the authoritative source of claim/lease
   state. The simulator must replay-match it.
2. Collision guard is enforced at write time, not at read time.
3. Lease lifecycle has explicit create, renew, expire, release, override
   states with audited transitions.
4. Heartbeats prove liveness. Missing heartbeat triggers expiry.
5. Override is signed by an operator decision receipt, not by ambient
   credentials.
6. Release dossier exporter records every transition.
7. Runtime Pilot Certification reports green for claim/lease scenarios,
   fuzz and mutation.

Until all seven hold, claim/lease stays simulated.

## 13. What is still dry-run

- Every component in this map.
- Every "ready" verdict that is not signed by Runtime Pilot Certification.
- Every cost event the cost import dry-run produces.
- Every continuation summary the builder produces.
- Every parallelism plan the planner produces.

If a downstream surface treats any of these as runtime, that surface is
wrong.

## 14. What would be required for real runtime

- Every component above at enforced posture.
- Every safety invariant in
  `atlas-agent-control-plane-safety-invariants-v1.md` durable and
  monitored.
- The Self-Programming Safety Contract read and accepted in the decision
  receipt.
- Forge Workspace exposes kill switch, rollback, scope-lock surface.
- A human review packet is signed.
- Self-Construction OS completion roadmap reports
  "runtime pilot complete".

Anything less is dry-run. There is no fast path.

## 15. Cross-links

- Contract: `agent-control-plane-contract.md` (do not edit from here).
- Operator flow: `atlas-self-construction-os-operator-runbook-v1.md`.
- Completion phases: `atlas-self-construction-os-completion-roadmap-v1.md`.
- Invariants: `atlas-agent-control-plane-safety-invariants-v1.md`.
- Phased runtime: `runtime-implementation-roadmap.md`.
- Safety floor: `self-programming-safety-contract.md`.

## 16. Doc maturity

This map is projection v1. It documents components that exist today as
dry-run projections. It must be revised when any component is promoted to
enforced runtime, and rewritten if the contract changes shape.

## Resumo

Mapa dos componentes do Runtime Pilot do Agent Control Plane, separando o
que e dry-run hoje do que precisa ser verdadeiro para virar runtime real.

## Papel no Atlas

Camada de orquestracao da projection: rehearsa runtime sem executar
provider, claim, lease, dispatch ou processo.

## Onde Se Encaixa

Filho do Agent Control Plane Contract, irmao do Operator Runbook, do
Completion Roadmap e dos Safety Invariants.

## Contratos

Cada componente certifica responsabilidade unica via Runtime Pilot
Certification antes de qualquer promocao para enforced runtime.

## Fluxo

Task Packet Builder -> Claim/Lease Simulator -> Scope Lock Planner ->
Evidence Ledger Dry-Run -> Continuation Summary Builder -> Work Product
Manifest Planner -> Cost Import Dry-Run -> Multi-Agent Parallelism Planner
-> Runtime Pilot Orchestrator -> Runtime Pilot Certification.

## Regras para IA

Nao propor codigo que execute provider, dispatch ou processo. Nao tratar
saida de orchestrator como autorizacao runtime. Respeitar invariants.

## Escopo de Implementacao

Apenas docs em docs/engineering-knowledge-base/self-construction/. Nao
edita codigo, testes, contratos vizinhos nem atlas-desktop.

## Dependencias

Durable reservation ledger, collision guard, lease lifecycle contract,
Forge Workspace surfaces, evidence ledger, cost event store futuro.

## Evidencias

Certification batch por componente, replay diff verde, release dossier
ref, scenario corpus, fuzz seeds, mutation guard report, coverage report.

## Riscos

Confundir "ready" dry-run com runtime, compor componentes individualmente
verdes em plano contraditorio, vazar autorizacao stale em continuation.

## Exemplos

Claim/Lease Simulator emite timeline simulada, comparada via replay com a
durable reservation projection - sem locks reais.

## Proximas Acoes

Promover cada componente apenas via Runtime Pilot Certification verde,
respeitando ordering e escopo unico por slice.

