---
id: atlas-ai-self-construction-os
type: engineering_knowledge
title: Atlas AI Self-Construction OS
status: active
category: architecture
priority: 100
summary: Canonical law for Atlas building Atlas through governed research, documentation, SDD, execution, evidence, repair and learning.
tags:
  - atlas-ai
  - self-construction
  - self-programming
  - governance
capabilities:
  - self_construction_os
  - meta_sdd
  - autonomous_implementation_loop
  - capability_maturity_ladder
decisions:
  - Atlas may become self-programming only through documentation-as-law, SDD, receipts, evidence and gates.
  - Self-construction is not vibe coding; it is governed evolution of the system that builds itself.
  - Any AI must be able to continue Atlas construction from canonical docs without relying on chat history.
  - The most powerful form is research -> docs -> spec -> implementation -> tests -> evidence -> learning.
  - Self-improvement claims must pass the Self-Improvement Governance Ladder: strong proposal, before/after delta, invariant lock, regression sentinel and promotion policy.
maintenance:
  - Read before changing Atlas core, self-improvement, SDD runtime, memory, research automation, autonomous coding or governance.
  - Update when a new self-programming loop, maturity level, build dependency or core safety gate is promoted.
related_paths:
  - app/Console/Commands/AtlasAiSelfConstructionCommand.php
  - app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php
  - tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php
  - docs/engineering-knowledge-base/atlas-self-construction-os-handoff.md
  - docs/engineering-knowledge-base/self-construction/constitution.md
  - docs/engineering-knowledge-base/self-construction/structural-contract-gate.md
  - docs/engineering-knowledge-base/self-construction/ai-implementation-packet-contract.md
  - docs/engineering-knowledge-base/self-construction/multi-provider-agent-orchestration-contract.md
  - docs/engineering-knowledge-base/self-construction/work-splitter-contract.md
  - docs/engineering-knowledge-base/self-construction/scope-validator-contract.md
  - docs/engineering-knowledge-base/self-construction/assignment-and-claim-contract.md
  - docs/engineering-knowledge-base/self-construction/packet-consumption-runbook-contract.md
  - docs/engineering-knowledge-base/self-construction/packet-evidence-report-contract.md
  - docs/engineering-knowledge-base/self-construction/packet-completion-gate-contract.md
  - docs/engineering-knowledge-base/self-construction/reservation-ledger-contract.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-ledger-implementation-plan.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-ap-candidate.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-approval-request.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-approval-decision-template.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-post-approval-preflight.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-implementation-packet.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-storage-schema.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-repository-contract.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-collision-guard-contract.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-lease-lifecycle-contract.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-readiness-projection-contract.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-implementation-preflight-contract.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-migration-blueprint-contract.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-repository-blueprint-contract.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-collision-guard-blueprint-contract.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-lease-lifecycle-blueprint-contract.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-readiness-projection-blueprint-contract.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-runtime-build-packet-contract.md
  - docs/engineering-knowledge-base/self-construction/ai-session-bootstrap-contract.md
  - docs/engineering-knowledge-base/self-construction/packet-queue-contract.md
  - docs/engineering-knowledge-base/self-construction/parallel-session-plan-contract.md
  - docs/engineering-knowledge-base/self-construction/collision-matrix-contract.md
  - docs/engineering-knowledge-base/self-construction/dependency-unlock-plan-contract.md
  - docs/engineering-knowledge-base/self-construction/multi-session-readiness-gate-contract.md
  - docs/engineering-knowledge-base/self-construction/single-session-instruction-packet-contract.md
  - docs/engineering-knowledge-base/self-construction/meta-sdd-contract.md
  - docs/engineering-knowledge-base/self-construction/capability-maturity-ladder.md
  - docs/engineering-knowledge-base/self-construction/build-graph.md
  - docs/engineering-knowledge-base/self-construction/implementation-priority-engine.md
  - docs/engineering-knowledge-base/self-construction/autonomous-implementation-loop.md
  - docs/engineering-knowledge-base/self-construction/self-programming-safety-contract.md
  - docs/engineering-knowledge-base/self-construction/quality-bar-and-metrics.md
  - docs/engineering-knowledge-base/self-construction/failure-modes.md
  - docs/engineering-knowledge-base/self-construction/builder-persona-and-handoff.md
  - docs/engineering-knowledge-base/self-construction/runtime-implementation-roadmap.md
  - docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md
  - docs/engineering-knowledge-base/obras/shared-workspace-and-forge.md
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-research-self-improvement-runtime.md
  - docs/engineering-knowledge-base/atlas-ai-cognitive-runtime.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 280
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-os

graph_title: Atlas AI Self-Construction OS

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-ai-documentation-operating-system

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - ai-safe-implementation-context

governs:
  - architecture

evidence:
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - system
  - module
  - architecture

ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de implementar.

ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Contexto desatualizado entre doc, codigo, teste e evidencia.

observability_signals:
  - docs-health status ok

next_actions:
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
---
# Atlas AI Self-Construction OS
The goal is not "AI writes code". The goal:
```text
Atlas detects the right gap
-> researches at source-backed quality
-> updates canonical docs
-> compiles SDD
-> implements small governed blocks
-> validates with gates
-> records evidence
-> detects drift
-> proposes learning
-> improves its future construction ability
```
## Hard Laws
- No self-programming without SDD.
- No SDD without context and source-of-truth docs.
- No structural core implementation before contract documentation.
- No Atlas core mutation without Decision Receipt.
- No result without evidence.
- No learning that changes critical behavior without proposal/review.
- No parallel architecture, memory, runtime, provider or daemon outside AP law.
- No implementation priority based on novelty, hype or surface beauty.
- No "complete" claim unless docs, code, tests, evidence and drift checks agree.
## What This Layer Adds
Spec Operating System teaches Atlas how to turn a request into implementation.
Self-Construction OS teaches Atlas how to evolve the system that performs that
implementation.
```text
SDD Core:
  user intent -> spec -> plan -> tasks -> receipt -> patch -> evidence

Self-Construction OS:
  system gap -> research -> docs -> meta-spec -> phased build -> validation
  -> drift -> learning -> maturity promotion
```
## The Highest Form
The most advanced Atlas is not merely self-coding. The highest form is governed self-construction:
```text
research-backed
documentation-first
spec-driven
receipt-scoped
test-proven
evidence-led
drift-aware
learning-governed
priority-aligned
rollback-capable
```

Autoprogramming without this layer is dangerous. Autoprogramming with this
layer becomes compounding engineering power.
## Authority Map
| Area | Doc |
|---|---|
| Constitution | `self-construction/constitution.md` |
| Structural contract gate | `self-construction/structural-contract-gate.md` |
| AI implementation packet | `self-construction/ai-implementation-packet-contract.md` |
| Multi-provider agent orchestration | `self-construction/multi-provider-agent-orchestration-contract.md` |
| Work splitter | `self-construction/work-splitter-contract.md` |
| Scope validator | `self-construction/scope-validator-contract.md` |
| Assignment and claim | `self-construction/assignment-and-claim-contract.md` |
| Packet consumption runbook | `self-construction/packet-consumption-runbook-contract.md` |
| Packet evidence report | `self-construction/packet-evidence-report-contract.md` |
| Packet completion gate | `self-construction/packet-completion-gate-contract.md` |
| Reservation ledger / AP | `self-construction/reservation-ledger-contract.md`, `self-construction/durable-reservation-ledger-implementation-plan.md`, `self-construction/durable-reservation-ap-candidate.md`, `self-construction/durable-reservation-approval-request.md`, `self-construction/durable-reservation-approval-decision-template.md`, `self-construction/durable-reservation-post-approval-preflight.md`, `self-construction/durable-reservation-implementation-packet.md`, `self-construction/durable-reservation-storage-schema.md`, `self-construction/durable-reservation-repository-contract.md`, `self-construction/durable-reservation-collision-guard-contract.md`, `self-construction/durable-reservation-lease-lifecycle-contract.md`, `self-construction/durable-reservation-readiness-projection-contract.md`, `self-construction/durable-reservation-implementation-preflight-contract.md`, `self-construction/durable-reservation-migration-blueprint-contract.md`, `self-construction/durable-reservation-repository-blueprint-contract.md`, `self-construction/durable-reservation-collision-guard-blueprint-contract.md`, `self-construction/durable-reservation-lease-lifecycle-blueprint-contract.md`, `self-construction/durable-reservation-readiness-projection-blueprint-contract.md`, `self-construction/durable-reservation-runtime-build-packet-contract.md` |
| AI session bootstrap | `self-construction/ai-session-bootstrap-contract.md` |
| Packet queue | `self-construction/packet-queue-contract.md` |
| Parallel session plan | `self-construction/parallel-session-plan-contract.md` |
| Collision matrix | `self-construction/collision-matrix-contract.md` |
| Dependency unlock plan | `self-construction/dependency-unlock-plan-contract.md` |
| Multi-session readiness / single instruction | `self-construction/multi-session-readiness-gate-contract.md`, `self-construction/single-session-instruction-packet-contract.md` |
| Agent Control Plane | `self-construction/agent-control-plane-contract.md` |
| OS completion handoff | `atlas-self-construction-os-handoff.md` |
| Meta-SDD | `self-construction/meta-sdd-contract.md` |
| Maturity levels | `self-construction/capability-maturity-ladder.md` |
| Build dependencies | `self-construction/build-graph.md` |
| Priority engine | `self-construction/implementation-priority-engine.md` |
| Autonomous loop | `self-construction/autonomous-implementation-loop.md` |
| Safety contract | `self-construction/self-programming-safety-contract.md` |
| Quality bar | `self-construction/quality-bar-and-metrics.md` |
| Failure modes | `self-construction/failure-modes.md` |
| Builder persona / runtime roadmap | `self-construction/builder-persona-and-handoff.md`, `self-construction/runtime-implementation-roadmap.md` |
## Core Loop
```text
1. Detect gap or opportunity.
2. Classify layer and risk.
3. Research source-backed state of the art.
4. Promote durable findings to docs.
5. Compile Meta-SDD spec.
6. Build plan and tasks.
7. Sign Decision Receipt.
8. Execute smallest safe block.
9. Run gates.
10. Append evidence.
11. Detect drift.
12. Propose learning.
13. Promote maturity only if metrics prove it.
```
## Maturity Target
Atlas is elite when a new AI session can ask:
```text
What is the most important next construction step?
```
and Atlas can answer with:

- current layer;
- missing capability;
- why it matters;
- dependencies;
- spec;
- safe task slice;
- allowed files;
- gates;
- rollback;
- evidence expected;
- residual risk.

The target is not five copies of one provider. The target is provider-neutral
construction: Codex, Claude, Gemini, local agents and future tools can all
consume the same Atlas packet, work in disjoint scopes and return normalized
evidence. Codex-specific command names are current operational surfaces, not a
limit of the architecture.

For Atlas building Atlas, the canonical shared office is Obras Shared Workspace.
When the work is Programming/Forge-heavy, call that specialization Forge
Workspace. Self-Construction OS supplies governance and packets; Obras Shared
Workspace holds shared state, artifact exchange, status and integration.
## Integration With Existing Atlas
Self-Construction OS depends on:
- Documentation OS for canonical law;
- Knowledge Governance for source truth;
- Cognitive Runtime for memory, retrieval and long sessions;
- Research Self-Improvement Runtime for source-backed evolution;
- Spec Operating System for SDD and receipts;
- Kernel and Evidence Ledger for execution authority;
- Code Intelligence for repo awareness;
- Architecture Validate and docs-health for structural integrity.
## Completion Signal
This layer is complete as documentation when any capable AI can implement Atlas construction work without relying on conversation memory. It is complete as
runtime only when Atlas can run the autonomous loop with scoped patches, validated gates, evidence, drift detection and proposal-first learning.
## Current Runtime Surface
All commands below are read-only and keep `execution_allowed=false`.
| Command | Purpose |
|---|---|
| `php artisan atlas:ai:self-construction --json` | Readiness, docs, maturity, build graph, priority bias and safety contract. |
| `php artisan atlas:ai:self-construction --meta-sdd --json` | Candidate Meta-SDD packet with assumptions, priority, tasks and gates. |
| `php artisan atlas:ai:self-construction --receipt-preview --json` | Preview receipt with allowed/forbidden scope, rollback and evidence. |
| `php artisan atlas:ai:self-construction --traceability --json` | Required-doc reachability, tag and layer audit; related cross-layer docs must declare a `layer:` value, but do not need to claim `0.8-self-construction`. |
| `php artisan atlas:ai:self-construction --promotion-gate --json` | Consolidated promotion recommendation for human-reviewed planning. |
| `php artisan atlas:ai:self-construction --execution-candidate --json` | Deterministic Phase 5 candidate for docs/tests/report scope only. |
| `php artisan atlas:ai:self-construction --approval-packet --json` | Human review packet with checklist, reviewers and decision fields. |
| `php artisan atlas:ai:self-construction --receipt-draft --json` | Unsigned receipt draft with hash and preview signature. |
| `php artisan atlas:ai:self-construction --execution-preflight --json` | Expected blocked preflight while no valid human signature exists. |
| `php artisan atlas:ai:self-construction --signature-request --json` | Signable payload, hashes, signer roles and confirmations. |
| `php artisan atlas:ai:self-construction --execution-runbook --json` | Post-signature ordered steps, stop conditions, evidence, gates and rollback. |
| `php artisan atlas:ai:self-construction --evidence-packet --json` | Required proof template, claim checks and failure policy for a future signed run. |
| `php artisan atlas:ai:self-construction --completion-readiness --json` | Blocks false completion until signed execution evidence exists. |
| `php artisan atlas:ai:self-construction --residual-risk --json` | Classifies residual blockers before promotion or completion claims. |
| `php artisan atlas:ai:self-construction --handoff-packet --json` | Gives the next operator hashes, blockers, commands and forbidden hot scope. |
| `php artisan atlas:ai:self-construction --next-action --json` | Selects the next safe action while execution remains blocked. |
| `php artisan atlas:ai:self-construction --surface-matrix --json` | Lists every command surface, schema and read-only invariant. |
| `php artisan atlas:ai:self-construction --external-blockers --json` | Reports hot-file blockers outside Self-Construction ownership. |
| `php artisan atlas:ai:self-construction --cold-lane-certification --json` | Certifies the Self-Construction cold lane with external blockers separated. |
| `php artisan atlas:ai:self-construction --operator-checklist --json` | Orders the next human/operator review steps without signing or execution. |
| `php artisan atlas:ai:self-construction --promotion-blockers --json` | Consolidates promotion and completion blockers without execution. |
| `php artisan atlas:ai:self-construction --readiness-digest --json` | Emits a compact hashable handoff digest for operators and other AIs. |
| `php artisan atlas:ai:self-construction --governance-scorecard --json` | Scores governed readiness while execution, promotion and completion stay blocked. |
| `php artisan atlas:ai:self-construction --integrity-manifest --json` | Bundles governed packet hashes for audit and handoff integrity checks. |
| `php artisan atlas:ai:self-construction --continuation-token --json` | Emits a compact audited resume token with must-run and must-not-touch constraints. |
| `php artisan atlas:ai:self-construction --ownership-boundary --json` | Declares cold allowed files, hot forbidden scopes and required operator behavior. |
| `php artisan atlas:ai:self-construction --phase-ledger --json` | Summarizes phase status, hard blocks and promotion boundaries. |
| `php artisan atlas:ai:self-construction --implementation-packet --json` | Emits a read-only packet so another AI can continue one bounded block. |
| `php artisan atlas:ai:self-construction --work-splitter --json` | Emits disjoint read-only packets for parallel AI sessions and withholds hot work. |
| `php artisan atlas:ai:self-construction --scope-validator --json` | Classifies current diff against packet scope before any completion claim. |
| `php artisan atlas:ai:self-construction --assignment-preview --json` | Selects one safe packet for one AI session without persisting a claim. |
| `php artisan atlas:ai:self-construction --packet-runbook --json` | Emits ordered consumption steps, gates and evidence for the selected packet. |
| `php artisan atlas:ai:self-construction --packet-evidence-report --json` | Reviews packet evidence and blocks completion when gates or scope are unsafe. |
| `php artisan atlas:ai:self-construction --packet-completion-gate --json` | Converts packet evidence into a blocked/review/candidate completion decision. |
| `php artisan atlas:ai:self-construction --reservation-ledger-preview --json` / `--durable-reservation-ledger-plan --json` / `--durable-reservation-ap-candidate --json` / `--durable-reservation-approval-request --json` / `--durable-reservation-approval-decision --json` / `--durable-reservation-post-approval-preflight --json` / `--durable-reservation-implementation-packet --json` / `--durable-reservation-storage-schema --json` / `--durable-reservation-repository-contract --json` / `--durable-reservation-collision-guard --json` / `--durable-reservation-lease-lifecycle --json` / `--durable-reservation-readiness-projection --json` / `--durable-reservation-implementation-preflight --json` / `--durable-reservation-migration-blueprint --json` / `--durable-reservation-repository-blueprint --json` / `--durable-reservation-collision-guard-blueprint --json` / `--durable-reservation-lease-lifecycle-blueprint --json` / `--durable-reservation-readiness-projection-blueprint --json` / `--durable-reservation-runtime-build-packet --json` | Plans durable reservation approval, implementation contracts and blueprints without writes. |
| `php artisan atlas:ai:self-construction --ai-session-bootstrap --json` | Bundles packet, reservation, runbook, scope and gates for a new AI session. |
| `php artisan atlas:ai:self-construction --packet-queue --json` | Lists available, blocked and withheld packets without changing state. |
| `php artisan atlas:ai:self-construction --parallel-session-plan --json` | Plans up to five AI session slots without claims or dispatch. |
| `php artisan atlas:ai:self-construction --collision-matrix --json` | Proves packet overlap and parallel safety without claims or dispatch. |
| `php artisan atlas:ai:self-construction --dependency-unlock-plan --json` | Shows which completed packets would unlock later work without mutating queue state. |
| `php artisan atlas:ai:self-construction --multi-session-readiness-gate --json` / `--single-session-instruction-packet --json` / `--forge-workspace-status --json` / `--agent-control-plane --json` / `--agent-run-sync --json` / `--agent-heartbeat --json` / `--agent-run-liveness --json` / `--agent-cost-event --json` / `--agent-work-product --json` / `--agent-adapter-contract --json` / `--agent-wakeup-queue --json` / `--agent-wakeup-write --json` / `--agent-wakeup-scheduler --json` / `--agent-wakeup-claim --json` / `--agent-dispatch-preflight --json` / `--agent-dispatch-receipt-template --json` / `--agent-dispatch-receipt-validation-preflight --json` / `--agent-dispatch-receipt-write --json` / `--agent-dispatch-executor-preflight --json` / `--agent-dispatch-executor-contract-template --json` / `--agent-dispatch-executor-release-preflight --json` / `--agent-dispatch-executor-release-authorization-template --json` / `--agent-launch-plan --json` / `--agent-start-packet --json` / `--agent-execution-status --json` / `--agent-integration-report --json` / `--agent-merge-readiness --json` / `--agent-final-review-packet --json` / `--agent-review-decision-template --json` / `--agent-review-receipt-draft --json` / `--agent-review-signature-request --json` / `--agent-review-post-signature-runbook --json` / `--agent-review-merge-action-template --json` / `--agent-review-merge-preflight --json` / `--agent-review-merge-action-draft --json` / `--agent-review-merge-receipt-draft --json` / `--agent-review-merge-signature-request --json` / `--agent-review-merge-post-signature-runbook --json` / `--agent-review-merge-execution-checklist --json` / `--agent-review-merge-authorization-template --json` / `--agent-review-merge-authorization-receipt-draft --json` / `--agent-review-merge-authorization-signature-request --json` / `--agent-review-merge-authorization-post-signature-runbook --json` / `--agent-review-merge-final-authorization-preflight --json` / `--agent-review-merge-authorizing-action-template --json` / `--agent-review-merge-final-receipt-draft --json` / `--agent-review-merge-final-signature-request --json` / `--agent-review-merge-final-post-signature-runbook --json` / `--agent-review-merge-signed-final-receipt-template --json` / `--agent-review-merge-signed-final-receipt-preflight --json` / `--agent-review-merge-signed-final-receipt-persistence-template --json` / `--agent-review-merge-executor-release-preflight --json` / `--agent-review-merge-executor-contract-template --json` / `--agent-review-merge-execution-receipt-template --json` / `--agent-review-merge-post-execution-preflight --json` / `--agent-review-merge-post-execution-action-template --json` / `--agent-review-merge-post-execution-action-receipt-draft --json` / `--agent-review-merge-post-execution-action-signature-request --json` / `--agent-review-merge-post-execution-action-post-signature-runbook --json` / `--agent-review-merge-post-execution-action-signed-receipt-template --json` / `--agent-review-merge-post-execution-action-signed-receipt-preflight --json` / `--agent-review-merge-post-execution-action-signed-receipt-persistence-template --json` / `--agent-review-merge-post-execution-action-signed-receipt-persistence-receipt-draft --json` / `--agent-review-merge-post-execution-action-signed-receipt-persistence-preflight --json` / `--agent-review-merge-post-execution-action-signed-receipt-persistence-post-preflight-runbook --json` / `--agent-review-merge-post-execution-action-signed-receipt-persistence-append-only-event-payload-template --json` / `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-preflight --json` / `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-contract-template --json` / `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-implementation-preflight --json` / `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-template --json` / `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-preflight --json` / `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-receipt-draft --json` / `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-signature-request --json` / `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-post-signature-runbook --json` / `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-signed-receipt-template --json` / `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-preflight --json` / `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-receipt-draft --json` / `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-signature-request --json` / `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-post-signature-runbook --json` / `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-signed-receipt-template --json` / `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-execution-contract-preflight --json` / `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-execution-contract-template --json` / `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-disable-contract-template --json` / `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-observability-contract-template --json` / `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-post-monitoring-review-template --json` / `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-reenable-review-packet-template --json` / `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-request-template --json` / `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-receipt-draft-template --json` / `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-signature-request-template --json` / `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-post-signature-runbook-template --json` / `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-signed-receipt-template --json` / `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-execution-contract-preflight-template --json` / `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-execution-contract-template --json` / `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-disable-contract-template --json` / `--agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-observability-contract-template --json` | Projects Forge Workspace and Agent Control Plane, syncs agent runs/heartbeats, inspects liveness and records cost events and work products, and exposes adapter invocation contracts and wakeup queue projections and controlled wakeup writes, read-only wakeup scheduler selection, controlled wakeup claims, read-only dispatch preflight, dispatch receipt templates, dispatch receipt validation preflight, durable dispatch receipt schema, controlled dispatch receipt writes, dispatch executor preflight, provider dispatch executor contract templates, executor release preflight blockers and executor release authorization templates when runtime schema is available, starts agents and prepares governed review/decision/receipt/signature/post-signature/merge authorization chain. |
None of these surfaces signs, patches, approves, persists approval, mutates policy, touches hot runtime files or enables autonomous self-programming; agent dispatch executor release authorization now exposes unsigned receipt draft, signature request, post-signature runbook, signed receipt template/preflight, persistence template/preflight, writer contract, writer implementation preflight, implementation packet, persistence status, receipt-use writer contract/preflight/implementation packet, sandbox binding contract/preflight/implementation packet, provider start driver contract/preflight/implementation packet, adapter invocation boundary contract/preflight/implementation packet, provider adapter registry contract/preflight/implementation packet, provider adapter execution guard contract/preflight/implementation packet, Codex provider execution, process start release, supervised start executor, process spawn enablement, process spawn executor, external process runtime driver, invocation authorization, invoker dry-run, real invoker release preflight, signed real invoker release gate, real invoker implementation boundary, real invoker executor plan, real invoker executor fresh release gate, real invoker executor enablement gate, real invoker supervised start activation gate, real invoker guarded process start executor, real invoker final process start authorization gate, real invoker actual process start rehearsal executor, real invoker process start envelope builder, real invoker start execution gate, real invoker process starter readiness gate, real invoker manual start executor receipt writer, real invoker operator start handoff builder, real invoker post-start receipt contract builder, real invoker post-start evidence receipt writer, real invoker post-start evidence acceptance bridge, real invoker post-start liveness monitor, real invoker post-start dispatch release gate, real invoker post-start signed dispatch authorization gate, real invoker post-start dispatch executor handoff, real invoker post-start dispatch receipt-use executor, real invoker post-start provider start driver gate, real invoker post-start adapter invocation boundary gate, real invoker post-start adapter execution guard gate, real invoker post-start provider execution contract gate, real invoker post-start process start release gate, real invoker post-start supervised start executor gate, real invoker post-start process spawn enablement gate, real invoker post-start final process spawn executor gate, real invoker post-start external process runtime gate, real invoker post-start process invocation authorization gate, real invoker post-start external process invoker dry-run gate, real invoker post-start real invoker release preflight gate, real invoker post-start signed real invoker release gate, real invoker post-start implementation boundary gate, real invoker post-start executor plan gate, real invoker post-start executor fresh release gate, real invoker post-start executor enablement gate, real invoker post-start supervised start activation gate, real invoker post-start guarded process start gate, real invoker post-start final process start authorization gate, real invoker post-start actual process start rehearsal gate, real invoker post-start process start envelope gate, real invoker post-start start execution gate, real invoker post-start process starter readiness gate, real invoker post-start manual start executor receipt writer, real invoker post-start operator start handoff builder and real invoker post-start evidence acceptance bridge contract/preflight/implementation packet chain through the `--agent-codex-*` and `--agent-dispatch-executor-*` command families, all read-only and unable to persist release authorization into execution, write hot runtime files, create writer/driver/boundary/registry/guard/Codex execution/start/executor/spawn/runtime/authorization/dry-run/release/boundary/executor-plan files, bind workspaces, call adapters, run real invoker execution, spend tokens, start providers or dispatch work. Executor enablement gates may mark executor metadata enabled only as a prerequisite for a later supervised start contract; supervised start activation, guarded process start, final process start authorization, actual process start rehearsal, process start envelope, start execution and process starter readiness gates may arm disabled process-start metadata only as prerequisites for later manual start executor contracts; manual receipt writer surfaces may prepare manual receipt metadata only as prerequisites for later operator handoff; post-start operator handoff surfaces may mirror handoff metadata only as prerequisites for later post-start receipt contracts; post-start evidence receipt surfaces must carry the accepted evidence bridge id from the receipt contract; post-start evidence acceptance bridge surfaces may accept externally observed Codex start evidence only as governed evidence before liveness monitoring; post-start dispatch release, signed dispatch authorization, executor handoff, receipt-use executor, provider start driver, adapter invocation boundary, adapter execution guard, provider execution contract, process start release, supervised start, process spawn enablement, final process spawn executor, external process runtime, process invocation authorization, external process invoker dry-run, real invoker release preflight, signed real invoker release, implementation boundary, executor plan, executor fresh release, executor enablement, supervised start activation, guarded process start, final process start authorization, actual process start rehearsal, process start envelope, start execution, process starter readiness, manual start executor receipt writer, operator start handoff builder, post-start receipt contract builder and evidence receipt writer gates require liveness from an accepted evidence bridge, never a loose heartbeat. None starts or dispatches Codex.
Fresh authorization also exposes the provider-neutral post-monitoring review projection through `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-post-monitoring-review-template --json`; it is read-only and converts observability evidence into a governed health-review template without approving, merging, dispatching or persisting receipts.
The next read-only decision projection is `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-health-decision-template --json`; it prepares the allowed health states for the Forge Workspace chain without recording a decision or granting execution authority.
The disable-request continuation is `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-disable-request-template --json`; it prepares a provider-neutral shutdown request if fresh authorization sees provider drift, provider substitution, cross-workspace/Obra attempts or forbidden writer activity, but still cannot execute the shutdown.
The new-cycle continuation is `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-request-template --json`; it forces a full fresh authorization restart and forbids reusing previous receipts, signatures, provider identity hashes or execution authority.
The new-cycle authorization request is `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-authorization-request-template --json`; it binds that restarted cycle to fresh Workspace, Obra and provider identity evidence before any later receipt draft can exist, while still forbidding signature acceptance, approval, writer file creation, ledger writes, receipt persistence, merge and dispatch.
The new-cycle receipt draft is `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-receipt-draft-template --json`; it creates the unsigned, non-persisted receipt draft shape for that fresh authorization cycle and requires fresh Workspace, Obra and provider identity statements before any signature request can exist.
The new-cycle signature request is `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-signature-request-template --json`; it defines required signer roles and fresh identity payload fields, but still cannot accept signatures, validate signatures, persist receipts, approve, create writer files, write ledger events, merge or dispatch.
The new-cycle post-signature runbook is `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-post-signature-runbook-template --json`; it sequences the future work after signatures are collected, including fresh Workspace, Obra and provider identity verification, but remains read-only and still cannot accept or validate signatures, persist receipts, approve, create writer files, write ledger events, merge or dispatch.
The new-cycle signed receipt template is `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-signed-receipt-template --json`; it defines the future signed receipt fields and required external evidence for a fresh Forge Workspace/Obra/provider identity cycle, but still cannot accept or validate signatures, persist receipts, approve, create writer files, write ledger events, merge or dispatch.
The new-cycle execution contract preflight is `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-execution-contract-preflight-template --json`; it checks the future execution contract prerequisites, including fresh Workspace, Obra and provider identity rechecks, external signature validation evidence, rollback, disable and monitoring plans, but still cannot accept or validate signatures, create writer files, write ledger events, persist receipts, approve, merge or dispatch.
The new-cycle execution contract template is `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-execution-contract-template --json`; it defines the future provider-neutral writer re-enable contract after the preflight, including actor evidence, authority-reuse checks, hot-scope checks, disable, rollback and monitoring evidence, but still grants no execution authority and cannot create writer files, write ledger events, persist receipts, approve, merge or dispatch.
The new-cycle disable contract template is `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-contract-template --json`; it defines rollback, revocation, quarantine and provider-identity recheck requirements for any future writer re-enable failure, while remaining read-only and unable to stop runtimes, mutate capability flags, create writer files, write ledger events, persist receipts, approve, merge or dispatch.
The new-cycle observability contract template is `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-observability-contract-template --json`; it defines the provider-neutral monitoring signals, metrics, alerts, provider-identity drift checks, previous-authority reuse watchpoints and required evidence for any future writer re-enable, while remaining read-only and unable to create writer files, write ledger events, persist receipts, approve, merge or dispatch.
The new-cycle post-monitoring review template is `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-post-monitoring-review-template --json`; it defines the provider-neutral health review after the monitoring window, including allowed decisions, provider-identity drift review, previous-authority reuse review, failure-to-decision mapping and future health decision outputs, while still unable to record decisions, create writer files, write ledger events, persist receipts, approve, merge or dispatch.
The new-cycle health decision template is `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-health-decision-template --json`; it defines the provider-neutral non-recording decision envelope after the post-monitoring review, including allowed decision states, provider-identity drift policy, old-authority reuse policy, required evidence and future disable/later-cycle outputs, while still unable to record decisions, create writer files, write ledger events, persist receipts, approve, merge or dispatch.
The new-cycle disable request template is `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-request-template --json`; it defines the provider-neutral non-executing disable request after a health decision, including provider-drift triggers, old-authority reuse triggers, required disable evidence and future disable execution outputs, while still unable to execute disable, mutate writer state, record decisions, create writer files, write ledger events, persist receipts, approve, merge or dispatch.
The new-cycle disable execution preflight template is `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-preflight-template --json`; it defines the provider-neutral preflight before any future disable execution, including provider-drift review checks, old-authority reuse review checks, disable path verification, writer-state snapshot evidence and future receipt/evidence outputs, while still unable to execute disable, mutate writer state, record decisions, create writer files, write ledger events, persist receipts, approve, merge or dispatch.
The new-cycle disable execution receipt draft template is `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-receipt-draft-template --json`; it defines the provider-neutral unsigned, non-persisted receipt draft after the disable execution preflight, including actor identity, actor provider, provider-identity drift review, old-authority reuse review, writer-state before/after hashes and human reviewer evidence, while still unable to execute disable, mutate writer state, record decisions, create writer files, write ledger events, persist receipts, approve, merge or dispatch.
The new-cycle disable execution signed receipt template is `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-signed-receipt-template --json`; it defines the provider-neutral non-validating signed receipt template after the receipt draft, including required signer roles, signer identity/provider evidence, exact draft-hash matching, provider-identity drift review and future persistence preflight outputs, while still unable to accept signatures, validate signatures, execute disable, mutate writer state, record decisions, create writer files, write ledger events, persist receipts, approve, merge or dispatch.
The new-cycle disable execution persistence preflight template is `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-persistence-preflight-template --json`; it defines the provider-neutral non-writing preflight before any future disable execution persistence, including signer identity/provider evidence, provider-identity drift review, idempotency, append-only ledger target and writer-state snapshot requirements, while still unable to write ledger events, persist receipts, execute disable, mutate writer state, record decisions, create writer files, approve, merge or dispatch.
The new-cycle disable execution persistence receipt template is `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-persistence-receipt-template --json`; it defines the provider-neutral future receipt shape after the persistence preflight, including preflight hash, signed receipt hash, provider identity snapshot, provider drift review, idempotency key, append-only ledger target/event hashes, writer-state snapshot and actor provider/role fields, while still unable to write ledger events, persist receipts, execute disable, mutate writer state, record decisions, create writer files, approve, merge or dispatch.
The new-cycle disable execution post-persistence review template is `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-post-persistence-review-template --json`; it defines the provider-neutral future review shape after the persistence receipt, including allowed review decisions, provider identity/drift evidence, idempotency matching, writer-disabled checks and follow-up outputs, while still unable to write ledger events, persist receipts, record decisions, execute disable, mutate writer state, create writer files, approve, merge or dispatch.
The new-cycle disable execution follow-up observability template is `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-follow-up-observability-template --json`; it defines the provider-neutral future observation shape after the post-persistence review, including writer-disabled signals, provider identity snapshot matching, provider drift absence, no-dispatch/no-merge/no-writer/no-ledger evidence and later repair/authorization outputs, while still unable to write ledger events, persist receipts, record decisions, execute disable, mutate writer state, create writer files, approve, merge or dispatch.
The new-cycle disable execution evidence repair request template is `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-evidence-repair-request-template --json`; it defines the provider-neutral future repair request shape after follow-up observability, including failed observation signals, missing evidence keys, replacement hashes, provider identity snapshot/drift repair evidence and future repaired-evidence outputs, while still unable to write ledger events, persist receipts, record decisions, execute disable, mutate writer state, create writer files, approve, merge or dispatch.
The new-cycle disable execution repaired evidence packet template is `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-repaired-evidence-packet-template --json`; it defines the provider-neutral future repaired evidence packet after the repair request, including failed signal identity, missing evidence key, replacement evidence/source hashes, replacement provider identity snapshot/drift review hashes, repair actor provider and integrity outputs, while still unable to write ledger events, persist receipts, record decisions, execute disable, mutate writer state, create writer files, approve, merge or dispatch.
The new-cycle disable execution repair review/outcome/later-cycle request/preflight/authorization request/authorization receipt draft/authorization signature request/post-signature runbook/signature validation report/signed receipt/signed receipt preflight/persistence preflight/persistence receipt/post-persistence review/follow-up observability/evidence repair request/repaired evidence packet templates are `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-repair-review-template --json`, `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-repair-outcome-packet-template --json`, `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-request-template --json`, `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-preflight-template --json`, `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-request-template --json`, `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-receipt-draft-template --json`, `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-signature-request-template --json`, `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-post-signature-runbook-template --json`, `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-signature-validation-report-template --json`, `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-signed-receipt-template --json`, `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-signed-receipt-preflight-template --json`, `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-persistence-preflight-template --json`, `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-persistence-receipt-template --json`, `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-post-persistence-review-template --json`, `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-follow-up-observability-template --json`, `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-evidence-repair-request-template --json`, `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-repaired-evidence-packet-template --json`, `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-repair-review-template --json` and `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-persistence-rejection-template --json`; they define provider-neutral repaired-evidence review, selected non-authorizing outcome, fresh later-cycle request fields, later-cycle preflight checks, non-approving authorization request evidence, an unsigned/non-persisted authorization receipt draft, a non-accepting signature request, a non-validating post-signature runbook, a descriptive validation report, a future non-signing signed receipt shape, a non-persisting signed receipt preflight, a non-writing persistence preflight, a non-persisting persistence receipt, a non-writing post-persistence review, a non-writing follow-up observability shape, a non-dispatching evidence repair request shape, a non-writing repaired evidence packet shape, a non-writing later-cycle authorization repair review shape, a non-writing persistence rejection shape and a non-dispatching human escalation shape with provider identity, Workspace/Obra/scope/risk hashes, human approver identity, signer identity/provider scope, request actor provider, explicit bounds, detached signature binding, validation authority deferral, signature authority deferral, future persistence target, future ledger target, idempotency key, future operation hashes, observation window evidence, no-later-cycle/no-prior-reuse/no-signature-authority/no-ledger/no-decision/no-dispatch evidence, replacement provider identity receipt draft hash, replacement Workspace/Obra receipt draft hash, replacement validated signer provider scope, repair actor provider, rejection actor provider, escalation actor provider, human escalation reason, required human role, review packet hash, non-dispatch statement, non-signature-authority statement, reviewer/human reviewer identity, non-persistence/non-ledger-write/non-authorization statements, fresh authorization required and prior-authorization reuse forbidden, while still unable to accept signatures, become signature authority, sign receipts, grant approval, authorize a later cycle, write ledger events, persist receipts, record decisions, execute disable, mutate writer state, create writer files, merge or dispatch.
The new-cycle disable execution later-cycle authorization human escalation and manual decision request templates are `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-human-escalation-template --json` and `php artisan atlas:ai:self-construction --agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-manual-decision-request-template --json`; they make the escalation step and future provider-neutral decision request shape canonical after persistence rejection/human escalation, including persistence rejection hash, human escalation hash, required human role, decision question, decision context hash, allowed decision options, risk summary, provider identity receipt draft hash, Workspace/Obra receipt draft hash, validated signer provider scope, non-dispatch, non-authorization, non-persistence and non-signature-authority statements, actor provider and human reviewer identity, while still unable to request a real decision, notify a human, create a task, accept signatures, become signature authority, sign or persist receipts, grant approval, authorize a later cycle, reuse prior authorization, write ledger events, record decisions, execute disable, mutate writer state, create writer files, merge or dispatch.

## Resumo

Canonical law for Atlas building Atlas through governed research, documentation, SDD, execution, evidence, repair and learning.

## Papel no Atlas

Define a responsabilidade desta peca dentro da arquitetura Atlas.

## Onde Se Encaixa

Relaciona esta peca com seu sistema, camada, fluxo ou modulo pai.

## Contratos

Declara invariantes, entradas, saidas, limites e obrigacoes relevantes.

## Fluxo

Descreve o caminho operacional ou a sequencia de uso quando aplicavel.

## Regras para IA

Agentes devem respeitar escopo, evidencias, testes e proibicoes antes de alterar codigo.

## Escopo de Implementacao

Mudancas devem permanecer nos caminhos e limites declarados no frontmatter.

## Dependencias

Dependencias canonicas vivem em frontmatter e no corpo deste documento.

## Evidencias

Evidencias aceitas incluem docs, comandos, testes, receipts, reports e paths verificaveis.

## Riscos

Riscos principais devem ser tratados antes de promover status, runtime ou claims de prontidao.

## Exemplos

Exemplos concretos devem ser adicionados quando reduzirem ambiguidade para humanos ou IAs.

## Proximas Acoes

Proximas acoes devem ser concretas, verificaveis e ligadas a gates de qualidade.
