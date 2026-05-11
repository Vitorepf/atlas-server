# AP-691 Atlas Self-Construction OS Contract

Status: proposed
Owner: atlas-ai
Area: self-construction
Risk: critical

## Problem

Atlas now has Documentation OS, Knowledge Governance, Cognitive Runtime,
Research Self-Improvement Runtime and Spec Operating System. The next layer is
the law for Atlas building Atlas: how it researches, documents, specifies,
implements, validates, repairs and improves itself without losing the thread,
creating parallel architectures or bypassing governance.

## Goal

Document Atlas Self-Construction OS as the critical layer that governs
self-programming:

- every Atlas core change is framed as governed construction work;
- self-programming is SDD-driven, evidence-driven and rollback-aware;
- implementation order follows the build graph and priority engine;
- capability maturity is measured, not guessed;
- autonomous loops are allowed only inside receipts, gates and safety contracts;
- failure modes are explicit and auditable;
- any AI can continue construction from docs without relying on conversation
  memory.

## Non Goals

- No autonomous runtime implementation in this AP.
- No auto-merge or self-mutation without human/governance gate.
- No new provider, daemon, external runtime or parallel memory.
- No bypass of APs, SDD, Decision Receipts, Evidence Ledger or docs-health.
- No claim that Atlas is already self-programming at full autonomy.
- No structural subsystem runtime before its contract document is complete.

## Required Docs

- `docs/engineering-knowledge-base/atlas-ai-self-construction-os.md`
- `docs/engineering-knowledge-base/self-construction/constitution.md`
- `docs/engineering-knowledge-base/self-construction/structural-contract-gate.md`
- `docs/engineering-knowledge-base/self-construction/ai-implementation-packet-contract.md`
- `docs/engineering-knowledge-base/self-construction/work-splitter-contract.md`
- `docs/engineering-knowledge-base/self-construction/scope-validator-contract.md`
- `docs/engineering-knowledge-base/self-construction/assignment-and-claim-contract.md`
- `docs/engineering-knowledge-base/self-construction/packet-consumption-runbook-contract.md`
- `docs/engineering-knowledge-base/self-construction/packet-evidence-report-contract.md`
- `docs/engineering-knowledge-base/self-construction/packet-completion-gate-contract.md`
- `docs/engineering-knowledge-base/self-construction/reservation-ledger-contract.md`
- `docs/engineering-knowledge-base/self-construction/durable-reservation-ledger-implementation-plan.md`
- `docs/engineering-knowledge-base/self-construction/durable-reservation-ap-candidate.md`
- `docs/engineering-knowledge-base/self-construction/durable-reservation-approval-request.md`
- `docs/engineering-knowledge-base/self-construction/durable-reservation-approval-decision-template.md`
- `docs/engineering-knowledge-base/self-construction/durable-reservation-post-approval-preflight.md`
- `docs/engineering-knowledge-base/self-construction/durable-reservation-implementation-packet.md`
- `docs/engineering-knowledge-base/self-construction/durable-reservation-storage-schema.md`
- `docs/engineering-knowledge-base/self-construction/durable-reservation-repository-contract.md`
- `docs/engineering-knowledge-base/self-construction/durable-reservation-collision-guard-contract.md`
- `docs/engineering-knowledge-base/self-construction/durable-reservation-lease-lifecycle-contract.md`
- `docs/engineering-knowledge-base/self-construction/durable-reservation-readiness-projection-contract.md`
- `docs/engineering-knowledge-base/self-construction/durable-reservation-implementation-preflight-contract.md`
- `docs/engineering-knowledge-base/self-construction/durable-reservation-migration-blueprint-contract.md`
- `docs/engineering-knowledge-base/self-construction/durable-reservation-repository-blueprint-contract.md`
- `docs/engineering-knowledge-base/self-construction/durable-reservation-collision-guard-blueprint-contract.md`
- `docs/engineering-knowledge-base/self-construction/durable-reservation-lease-lifecycle-blueprint-contract.md`
- `docs/engineering-knowledge-base/self-construction/durable-reservation-readiness-projection-blueprint-contract.md`
- `docs/engineering-knowledge-base/self-construction/durable-reservation-runtime-build-packet-contract.md`
- `docs/engineering-knowledge-base/self-construction/ai-session-bootstrap-contract.md`
- `docs/engineering-knowledge-base/self-construction/packet-queue-contract.md`
- `docs/engineering-knowledge-base/self-construction/parallel-session-plan-contract.md`
- `docs/engineering-knowledge-base/self-construction/collision-matrix-contract.md`
- `docs/engineering-knowledge-base/self-construction/dependency-unlock-plan-contract.md`
- `docs/engineering-knowledge-base/self-construction/multi-session-readiness-gate-contract.md`
- `docs/engineering-knowledge-base/self-construction/single-session-instruction-packet-contract.md`
- `docs/engineering-knowledge-base/self-construction/meta-sdd-contract.md`
- `docs/engineering-knowledge-base/self-construction/capability-maturity-ladder.md`
- `docs/engineering-knowledge-base/self-construction/build-graph.md`
- `docs/engineering-knowledge-base/self-construction/implementation-priority-engine.md`
- `docs/engineering-knowledge-base/self-construction/autonomous-implementation-loop.md`
- `docs/engineering-knowledge-base/self-construction/self-programming-safety-contract.md`
- `docs/engineering-knowledge-base/self-construction/quality-bar-and-metrics.md`
- `docs/engineering-knowledge-base/self-construction/failure-modes.md`
- `docs/engineering-knowledge-base/self-construction/builder-persona-and-handoff.md`
- `docs/engineering-knowledge-base/self-construction/runtime-implementation-roadmap.md`

## Acceptance Criteria

- Self-Construction OS is linked from START_HERE, README and canonical index.
- Docs define what Atlas may and may not change by itself.
- Docs define Meta-SDD for changes to Atlas itself.
- Docs define maturity levels from documented to strategic self-programming.
- Docs define build graph dependencies between Memory, SDD, Research, Runtime,
  Voice, Mobile, Evidence and Self-Improvement.
- Docs define priority rules that prevent distraction by low-leverage features.
- Docs define autonomous implementation loop from gap detection to evidence.
- Docs define self-programming safety contract with rollback and gates.
- Docs define "absurd level" quality bar as measurable behavior.
- Docs define failure modes, builder persona and handoff packet.
- Docs define Structural Contract Gate for AI Implementation Packet, Work
  Splitter, Scope Validator and other core self-construction subsystems.
- Docs define packet, split and scope validation contracts so multiple AIs can
  continue implementation without colliding or relying on chat history.
- Docs define assignment and claim preview rules so one AI session receives one
  bounded packet without durable claim persistence or hidden execution.
- Docs define the packet consumption runbook so assigned work returns evidence
  instead of free-form completion claims.
- Docs define packet evidence reporting so completion remains blocked until
  gates, evidence and scope validation agree.
- Docs define packet completion gate rules so false completion is blocked and
  durable completion stays disabled until a future persistence AP.
- Docs define reservation ledger rules so future multi-session claims prevent
  duplicate packet ownership without enabling writes in this phase.
- Docs define durable reservation ledger implementation planning so storage,
  locks, states, invariants and tests are known before claim persistence.
- Docs define a durable reservation AP candidate so future claim persistence is
  split into reviewable storage, repository, collision and readiness packets.
- Docs define durable reservation approval request rules so signers, evidence,
  blockers and rollback are explicit before migrations or storage writes.
- Docs define durable reservation approval decision templates so approvals are
  signed, hash-bound and never confused with request generation.
- Docs define durable reservation post-approval preflight so signed approval is
  rechecked before any implementation, migration or storage write.
- Docs define durable reservation implementation packets so future approved
  work is ordered while remaining blocked until preflight passes.
- Docs define durable reservation storage schema rules so future migrations know
  tables, events, projections, indexes, states, invariants and tests before any
  storage write exists.
- Docs define durable reservation repository contract rules so future claim,
  renew, release, expire and complete methods are event-backed and tested.
- Docs define durable reservation collision guard rules so hot scopes, stale
  hashes, dependency blockers and active overlap are rejected before claims.
- Docs define durable reservation lease lifecycle rules so renew, release,
  expiry, reclaim and completion timing are explicit before storage writes.
- Docs define durable reservation readiness projection rules so packet queue,
  collision matrix and multi-session gates consume durable state consistently.
- Docs define durable reservation implementation preflight rules so contract
  hashes, gates and blockers are checked before migrations, storage or claims.
- Docs define durable reservation migration blueprint rules so future migrations
  have fixed table names, columns, indexes, rollback and tests before creation.
- Docs define durable reservation repository blueprint rules so future runtime
  code has fixed classes, methods, errors, transactions and tests before files.
- Docs define durable reservation collision guard blueprint rules so future
  claims are blocked by explicit inputs, blockers, outputs and tests.
- Docs define durable reservation lease lifecycle blueprint rules so future
  renew, release, expire, reclaim and completion semantics are explicit.
- Docs define durable reservation readiness projection blueprint rules so queue,
  collision matrix and multi-session gates consume durable state consistently.
- Docs define durable reservation runtime build packet rules so future runtime
  files, migrations, gates and evidence are ordered before implementation.
- Docs define AI session bootstrap rules so a new AI can resume from one
  canonical packet without chat history, claims or execution authority.
- Docs define packet queue rules so parallel sessions can see available,
  blocked and withheld work without mutating packet state.
- Docs define parallel session planning so up to five AI sessions can be
  preview-slotted without claims, ledger writes, dispatch or execution.
- Docs define collision matrix rules so packet overlap and hot scopes are
  detected before parallel claims or execution exist.
- Docs define dependency unlock planning so completed packets can preview which
  later work becomes assignable without mutating queue state.
- Docs define multi-session readiness gating so the operator knows whether
  parallel AI continuation is safe, preview-only or blocked.
- Docs define a single-session instruction packet so one AI can continue from a
  self-contained instruction while broader dispatch remains blocked.

## Validation

```bash
php artisan atlas:engineering:knowledge docs-health --json
php artisan atlas:engineering:knowledge sync --prune --json
php artisan atlas:ai:architecture-validate --json
php artisan atlas:engineering:knowledge index-code --prune --json
git diff --check
```
