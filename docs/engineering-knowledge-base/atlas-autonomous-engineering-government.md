---
id: atlas-autonomous-engineering-government
type: engineering_knowledge
title: Atlas Autonomous Engineering Government
status: active
category: architecture
priority: 100
summary: Final architecture for Atlas 24/7 autonomous engineering across Atlas and external projects: a governed engineering government above Self-Construction OS, Task Fabric, Maestro, workers, verification, merge, learning and knowledge sync.
tags:
  - atlas-ai
  - self-construction
  - autonomous-engineering
  - task-fabric
  - maestro
  - loop
  - multi-project
capabilities:
  - autonomous_engineering_government
  - self_construction_control_plane
  - task_fabric
  - multi_project_stewardship
  - continuous_engineering_24_7
decisions:
  - The final 24/7 architecture is not the old Loop monolith; it is Atlas Autonomous Engineering Government.
  - Atlas Self-Construction OS is the governed operating system inside that government for building, repairing and improving Atlas.
  - Simplicity is a constitutional requirement: prefer the simplest powerful mechanism that works under evidence; reject ornamental orchestration, unnecessary sandboxes, unnecessary worktrees and ceremony that increases failure modes.
  - The default execution topology is one shared local `main` branch with conflict-free task scopes and Atlas-scoped commits; worktrees/sandboxes are not the default path for Self-Construction.
  - The final target is 100% Atlas autonomy: zero normal-runtime dependency on the operator, any human, Claude Code, Codex, Cursor or external provider platforms. External workers and human approvals are bootstrap aids, observability or emergency brakes, not the destination.
  - If normal 24/7 progress still needs an operator to create tasks, approve ordinary work, unblock common packets, run gates, preserve context or keep an external coding platform alive, Atlas Self-Construction is not complete.
  - The Loop becomes Autopoiesis / Evolution Engine, not the whole OS and not the final authority.
  - Task Fabric and Maestro are permanent organs of the architecture; they are not temporary external-worker scaffolding.
  - Workers such as Loop, Claude Code, Codex, Cursor and internal agents are replaceable execution muscles, not authority; final authority and final execution must move into Atlas itself.
  - Verification Court and Merge / Release Governor are external to workers and must not trust worker self-report as final evidence.
  - The same architecture must support multiple 24/7 stewardship instances: one for Atlas itself and one or more for external projects.
  - Every stewardship instance starts with a bounded scope, proves value, then expands scope by evidence and risk class.
maintenance:
  - Update before changing Loop, Self-Construction, task-serving, Maestro, AAEL, AWEOS, 24h autonomy, merge autonomy or multi-project stewardship.
related_paths:
  - docs/loop-canonical-definition.md
  - docs/atlas-task-serving-runbook.md
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/self-construction/constitution.md
  - docs/engineering-knowledge-base/self-construction/runtime-implementation-roadmap.md
  - docs/engineering-knowledge-base/atlas-autonomous-evolution-loop.md
  - docs/engineering-knowledge-base/atlas-unified-evolution-loop.md
  - docs/engineering-knowledge-base/atlas-long-horizon-loop-control-plane.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 260
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-autonomous-engineering-government
graph_title: Atlas Autonomous Engineering Government
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-ai-canonical-architecture-index
graph_status: active
graph_source: repo
human_name: Atlas Autonomous Engineering Government
canonical_name: Atlas Autonomous Engineering Government
technical_name: atlas-autonomous-engineering-government
cartography_type: system
canonical_source: docs/engineering-knowledge-base/atlas-autonomous-engineering-government.md
repo_paths:
  - docs/engineering-knowledge-base/atlas-autonomous-engineering-government.md
allowed_changes:
  - Refine boundaries, contracts, phases and evidence requirements when runtime proof changes.
forbidden_changes:
  - Re-label the old Loop as the whole final architecture.
  - Replace the simple shared-main task execution model with default worktree/sandbox complexity.
  - Make Atlas Self-Construction depend permanently on a human operator or external provider platform.
  - Give workers authority to approve their own work.
  - Treat task count, green tests or changed files as value without user/system impact evidence.
  - Claim unattended 24/7 autonomy without server-side verification, rollback, learning transfer and merge governance.
depends_on:
  - atlas-ai-self-construction-os
  - atlas-ai-knowledge-governance-system
  - atlas-autonomous-work-execution-os
  - atlas-autonomous-evolution-loop
flows_to:
  - atlas-self-construction
  - atlas-task-serving
  - atlas-maestro
  - atlas-loop
  - atlas-forge
  - external-project-stewardship
unlocks:
  - governed_24_7_atlas_self_construction
  - governed_24_7_external_project_stewardship
  - engineering_company_runtime
governs:
  - atlas.autonomous_engineering_government
  - atlas.self_construction.final_architecture
  - atlas.task_fabric
  - atlas.maestro
  - atlas.loop.autopoiesis
  - atlas.external_project_stewardship
evidence:
  - docs/engineering-knowledge-base/atlas-autonomous-engineering-government.md
  - docs/loop-canonical-definition.md
  - docs/atlas-task-serving-runbook.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:engineering:knowledge sync --prune --json"
requires_evidence: true
risk_level: critical
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:architecture-validate --json"
next_actions:
  - Implement Task Fabric/Maestro contracts as first-class Self-Construction organs.
  - Add server-side Verification Court and Merge / Release Governor runtime contracts.
  - Add project-lane admission and isolation gates for external stewardship.
  - Internalize provider/bootstrap worker roles into Atlas-native workers without adding unnecessary orchestration layers.
---
# Atlas Autonomous Engineering Government

## Resumo

This is the final architecture for Atlas as a living engineering system. It is
the layer that makes Atlas able to improve itself and other software projects
24/7 by choosing high-leverage work, architecting it, decomposing it into safe
task packets, executing through replaceable workers, verifying independently,
merging or rejecting under governance, learning from outcomes and updating
knowledge.

The final target is stronger than assisted development: Atlas must become able
to operate its own construction loop 100% Atlas-native, with zero normal-runtime
dependency on the operator, a human reviewer, Claude Code, Codex, Cursor or
external provider products. Humans and external coding agents can bootstrap the
path, observe and emergency-stop, but they must not become structural
dependencies. Normal 24/7 progress must continue through Atlas decisions, Atlas
task fabric, Atlas-native workers, Atlas verification, Atlas merge/release
governance, Atlas rollback, Atlas learning transfer and Atlas knowledge sync.

This is a non-negotiable final-state acceptance rule, not positioning language.
If ordinary progress still requires the operator to keep the queue alive, a
human to approve normal work, Claude Code/Codex/Cursor to execute steady-state
packets, a person to preserve context, or an external provider platform to own
the runtime loop, the product is not finished. Human and external tools may be
bootstrap scaffolding, visibility or emergency controls only; they are never the
steady-state engine.

## Papel no Atlas

The old framing "the Loop is the whole thing" is no longer canonical. The Loop
is an important organ, but the final product is larger:

## Onde Se Encaixa

```text
Atlas Autonomous Engineering Government
  -> Atlas Self-Construction OS
      -> Constitution / Kernel
      -> Control Plane
      -> Cortex / World Model
      -> Goal & Value System
      -> Strategy Council
      -> Architecture Council
      -> Task Fabric / Task Economy
      -> Maestro Scheduler
      -> Worker Swarm
      -> Verification Court
      -> Merge / Release Governor
      -> Receipts / Evidence / Memory
      -> Learning Transfer System
      -> Autopoiesis Lab / Loop
      -> Docs / Knowledge Sync
      -> Operator Interface (visibility / emergency only)
```

## Core Thesis

The strongest autonomous engineering architecture separates powers:

- one organ observes;
- one organ decides value and priority;
- one organ designs;
- one organ decomposes work;
- one organ schedules;
- workers execute only scoped packets;
- an independent court verifies;
- a merge governor controls production entry;
- receipts prove events;
- learning transfer changes future context;
- docs remain the authoring source of truth.

The same agent or runtime must not create the objective, implement the patch,
judge its own work, merge it and promote its own learning.

The architecture must stay simple. If a mechanism adds worktrees, sandboxes,
locks, multi-stage approvals or new services without reducing real failure or
token waste, it is not architecture; it is drag.

## Simplicity And Mainline Autonomy Law

The canonical execution model is:

```text
one local main branch
-> conflict-free task packets
-> worker edits only allowed_files
-> Atlas commits only that task scope
-> next packet
```

This is not a temporary hack. It is the proven simple path that keeps multiple
workers productive without branch theater. Task Fabric must prevent conflicts
before serving work. Workers must not solve coordination by adding worktrees,
sandboxes or broad git operations.

Worktrees, sandboxes and isolated execution may exist only as exceptional tools
for a specific risk class. They are never the default Self-Construction path.

This law must be enforced in runtime, not only remembered by prompts.
Task Fabric gates must reject packets that encode a permanent dependency on a
human/operator/external provider, or that make worktree/sandbox/branch
isolation the default execution model. The normal packet contract is
`shared_local_main_with_scope_lock` plus Atlas-scoped commits; the final owner
is `atlas_native`.

The autonomy ladder must move from external muscles to Atlas-native muscles:

```text
bootstrap: human opens sessions + Claude/Codex/Cursor execute packets
transition: Atlas serves, verifies and learns while external workers remain replaceable
final: Atlas-native workers execute continuously without operator/human/provider dependency
```

The operator interface is a visibility and emergency override surface, not a
required runtime organ for steady-state construction. If the system needs a
person to keep creating tasks, repairing common packets, validating ordinary
gates, preserving context or moving work forward, the autonomy target has not
been met.

Every task packet and runtime plan must therefore preserve this invariant:
`final_runtime_owner=atlas_native`, `steady_state_runtime_owner=atlas_server`,
`operator_dependency_allowed=false`, `human_dependency_allowed=false` and
`external_provider_dependency_allowed=false`. A packet that says otherwise is a
malformed autonomy regression, even if its implementation would be technically
simple.

## 100% Autonomy Completion Gate

Atlas Self-Construction is complete only when the ordinary path is fully
Atlas-native:

- Atlas originates and prioritizes the next high-leverage work;
- Atlas decomposes it into self-sufficient packets;
- Atlas serves and schedules packets without manual queue babysitting;
- Atlas-native workers can execute the normal packet stream;
- Verification Court proves or rejects work independently;
- Merge / Release Governor integrates, rolls back or quarantines by evidence;
- Learning Transfer repairs future packets, templates and context after
  failures;
- docs, memory, receipts and indexes update without becoming optional cleanup.

Claude Code, Codex, Cursor, external providers and humans can remain useful
bootstrap, surge, audit or emergency surfaces. They are not allowed to be a
required runtime organ. The acceptance test is blunt: unplug external coding
sessions and remove the operator from ordinary queue maintenance; Atlas must
still keep improving within its admitted scope through its own server-side
runtime, gates and rollback.

## Contratos

### Orgaos

| Organ | Authority | Non-authority |
|---|---|---|
| Constitution / Kernel | Laws, forbidden scopes, autonomy levels, bootstrap human-gate constraints, rollback requirements. | Does not execute product work. |
| Control Plane | Chooses what evolves, when, risk, budget, scope and mode. | Does not write code directly. |
| Cortex / World Model | Read-only understanding of code, docs, memory, evidence, graph, risk and history. | Does not decide or promote learning alone. |
| Goal & Value System | Defines real value and anti-proxy criteria. | Does not accept task count or green tests as value by itself. |
| Strategy Council | Selects the next highest-leverage direction. | Does not create executable packets directly. |
| Architecture Council | Produces criticized contracts, interfaces, invariants and decomposition boundaries. | Does not schedule workers or approve merges. |
| Task Fabric / Task Economy | Converts approved architecture into self-sufficient packets with scope, dependencies, gates, rollback and evidence. | Does not decide final priority or verify merit. |
| Maestro Scheduler | Serves tasks, leases, retries, worker affinity, blocked repair, throughput and queue health. | Does not rewrite architecture or relax gates. |
| Worker Swarm | Executes small packets in exact allowed-file scopes on the simple mainline model. | Does not approve, merge, widen scope or self-certify. |
| Verification Court | Re-runs gates server-side, validates evidence, detects Goodhart/proxy and rejects false green. | Does not author the candidate. |
| Merge / Release Governor | Controls integration, main, rollback, canary, release and blast-radius policy. | Does not trust worker self-report. |
| Receipts / Evidence / Memory | Records facts, decisions, completions, failures, rollbacks and promotions. | Receipts alone are not learning. |
| Learning Transfer System | Promotes reusable lessons into future packets/context/docs after evidence. | Does not promote narrative memory without proof. |
| Autopoiesis Lab / Loop | Proposes self-improvement and recursive evolution under gates. | Does not become the OS or bypass the Kernel. |
| Docs / Knowledge Sync | Authoring source of truth and worker context freshness. | Not optional post-work decoration. |

## Fluxo

```text
Observe
-> Decide value and scope
-> Architect
-> Decompose into task packets
-> Schedule
-> Execute
-> Verify independently
-> Merge / reject / rollback
-> Record evidence
-> Transfer learning
-> Update docs and indexes
-> Reprioritize
```

## Regras para IA

- Do not treat Loop as the whole OS.
- Do not over-engineer the operating model.
- Do not replace the shared local `main` task model with default worktrees or
  sandboxes.
- Do not make the final architecture depend on the operator, any human reviewer,
  external coding session or external provider product.
- Do not treat worker self-report as final evidence.
- Do not create a new parallel OS for task serving, stewardship or project care.
- Do not mutate an external project outside its admitted project lane.
- Do not count task volume, line churn or green self-report as real value.

## Escopo de Implementacao

The first implementation scope is intentionally small: Loop /
AutonomousEvolution inside Atlas. After proof, the same government expands to
broader Atlas subsystems and then to external project lanes.

## Scope Ladder

This government starts small and expands by proof:

1. One bounded scope inside Atlas, currently Loop / AutonomousEvolution.
2. Broader Atlas engineering scopes: context, memory, docs, task-serving,
   Maestro, gates, self-construction.
3. Full Atlas Self-Construction stewardship.
4. External project stewardship for one project at a time.
5. Multiple simultaneous stewardship instances, each with its own scope,
   mainline lane, task economy, gates, receipts and autonomy policy.

No scope receives 24/7 authority by ambition. It earns it through receipts,
server-side gates, rollback, low waste, real value and clean recovery.

## Multi-Project Stewardship

The architecture is not Atlas-only. A project can be admitted as a stewardship
instance:

```text
Project objective + repository + owner policy
-> Cortex project model
-> Strategy Council project roadmap
-> Architecture Council contracts
-> Task Fabric project packet queue
-> Maestro project lane
-> Workers
-> Project-specific Verification Court
-> Merge / Release Governor
-> Learning Transfer back into project docs and Atlas memory
```

Each project gets an isolated lane. Cross-project reuse is allowed only through
provider-safe memory, patterns, docs and receipts. One project's worker must
never mutate another project's files, secrets, releases or policies.

## Dependencias

- Atlas Self-Construction OS.
- Atlas Knowledge Governance and docs sync.
- Task Serving / Task Fabric.
- Maestro scheduler.
- AWEOS / AAEOS / Company Runtime worker surfaces.
- Evidence, receipts, verification and merge governance.

## Value Contract

The government optimizes for the highest real leverage:

- bugs fixed with regression prevention;
- architecture made more deterministic and simpler;
- high-impact features delivered with tests and docs;
- dead wiring connected into product flows;
- repeated failure modes eliminated;
- verification strengthened;
- throughput increased without token waste;
- human dependency removed through safe Atlas-native autonomy;
- project quality and delivery speed compounded.

It does not optimize for task count, line churn, test count, green self-report,
coverage padding, cyclomatic cosmetics, or behavior-preserving refactors that
do not unlock real capability.

## Task Fabric Is Permanent

Task packets are the atomic execution unit of the government. They are not a
temporary way to use Claude Code or Codex. External workers proved the shape:
small, scoped, testable, conflict-free units with rich give-back. The final OS
keeps that unit and makes it internal, governed and multi-project.

Every executable packet should carry:

- objective;
- owner scope;
- allowed files/actions;
- forbidden files/actions;
- dependencies and wave;
- risk class;
- required context freshness;
- acceptance criteria;
- required evidence;
- rollback;
- server-side verification plan;
- blocker/give-back policy.

## Safety Contract

For 24/7 autonomy, worker output is an allegation until Atlas verifies it.

- Workers may propose patches and evidence.
- Atlas re-runs required gates for the risk class.
- During bootstrap, high-risk/autonomy/security/kernel/provider/memory/merge
  policy changes may require human gates. In the final target, those gates must
  be replaced by Atlas-native evidence, rollback and autonomy policies so the
  operator is not a runtime dependency.
- Main/release entry belongs to the Merge / Release Governor.
- Rollback must be executable before higher autonomy is granted.
- If learning would change future decisions, it needs a Learning Transfer
  Receipt, not just a completion log.

## Evidencias

Evidence for this architecture comes from canonical docs, task-serving behavior,
successful task packet delivery, give-back diagnostics, server-side tests,
receipts and future runtime gates. A 24/7 claim is not valid until Verification
Court and Merge / Release Governor prove it independently.

## Riscos

- Loop-monolith framing returns and hides missing organs.
- Workers approve their own outputs and create false green.
- Project lanes leak files, memory, secrets or merge policy across repos.
- Task count replaces real leverage.
- Learning transfer promotes narrative without evidence.

## Exemplos

- Atlas lane: the Government improves Loop / AutonomousEvolution first, then
  expands to memory, docs, task-serving, Maestro and gates.
- External project lane: a repo is admitted with its own objective, mainline,
  task queue, gates, receipts and release policy.

## Relationship To Existing Names

- **Self-Construction OS**: the Atlas-building-Atlas operating system inside
  this government.
- **Loop / ACDE / Autopoiesis**: evolution engine and self-improvement lab, not
  the entire government.
- **AAEL**: portfolio/evolution governor that must plug into the Control Plane,
  not create a parallel authority.
- **AWEOS**: mission/work execution OS; it executes governed work but does not
  override Self-Construction authority.
- **Task Serving / Task Fabric**: the permanent decomposition and serving layer.
- **Maestro**: scheduler/orchestrator/health router, not architect.
- **Cortex**: read model/world model, not judge.

## Proximas Acoes

1. Materialize Task Fabric and Maestro as explicit Self-Construction organs.
2. Add Verification Court and Merge / Release Governor contracts/runtime.
3. Add project-lane admission for external project stewardship.
4. Wire Learning Transfer and Docs / Knowledge Sync as required post-merge steps.
