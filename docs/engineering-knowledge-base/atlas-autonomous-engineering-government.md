---
id: atlas-autonomous-engineering-government
type: engineering_knowledge
title: Atlas Autonomous Engineering Government
status: active
category: architecture
priority: 100
summary: Canonical v3 architecture for Atlas 24/7 autonomous engineering across Atlas and external projects: Constitution, Mission Control, Policy Plane, Engineering Kernel, Spec Court, Verification Court, Governor, Learning Controller and user-space runtimes such as Atlas Dev, Atlas Forge, Autonomos and external bootstrap workers.
tags:
  - atlas-ai
  - self-construction
  - autonomous-engineering
  - task-fabric
  - maestro
  - autonomos
  - aaeos
  - multi-project
capabilities:
  - autonomous_engineering_government
  - self_construction_control_plane
  - task_fabric
  - quality_intelligence
  - multi_project_stewardship
  - continuous_engineering_24_7
  - engineering_kernel
  - spec_court
  - verification_court
  - policy_plane
decisions:
  - The final 24/7 architecture is not the old Loop monolith; it is Atlas Autonomous Engineering Government.
  - The canonical v3 architecture separates Constitution, Mission Control, Policy Plane, Engineering Kernel, Spec Court, Verification Court, Governor, Learning Controller and user-space runtimes.
  - Atlas Self-Construction OS / Autonomos is the Atlas-building-Atlas runtime inside that government for building, repairing and improving Atlas.
  - Engineering Kernel is a minimal mechanism layer, not a god object: ProviderPort, WorkcellExecutor, ReceiptLedger, MergeActuator and BudgetMeter.
  - Policy Plane owns policies as data: gate profiles, merge/canary policy, budget policy, isolation policy and autonomy levels.
  - Atlas Dev, Atlas Forge, Autonomos and external workers are user-space runtimes; they do not own provider access, verified=true, main/release entry or canonical memory promotion.
  - Spec Court is the pre-execution tribunal; Verification Court is the post-execution tribunal.
  - Autonomy Tax plus Operator Rebate is canonical: less operator involvement requires stronger evidence; low-risk operated work must stay fast enough to avoid bypass.
  - Provider Independence Ladder is measured by receipts and replay, never by operator-fed optimism.
  - Simplicity is a constitutional requirement: prefer the simplest powerful mechanism that works under evidence; reject ornamental orchestration, unnecessary sandboxes, unnecessary worktrees and ceremony that increases failure modes.
  - The default execution topology is one shared local `main` branch with conflict-free task scopes and Atlas-scoped commits; worktrees/sandboxes are not the default path for Self-Construction.
  - The final target is 100% Atlas autonomy: zero normal-runtime dependency on the operator, any human, Claude Code, Codex, Cursor or external provider platforms. External workers and human approvals are bootstrap aids, observability or emergency brakes, not the destination.
  - If normal 24/7 progress still needs an operator to create tasks, approve ordinary work, unblock common packets, run gates, preserve context or keep an external coding platform alive, Atlas Self-Construction is not complete.
  - The old Loop / ACDE is pilot legacy. Useful capabilities migrate into Autonomos, Spec Court, Engineering Kernel, Governor or Learning Controller; the residue is retired.
  - Task Fabric and Maestro remain permanent organs of the architecture, but they are not final authority and must serve the v3 Constitution/Mission Control/Policy Plane contracts.
  - Workers such as Loop, Claude Code, Codex, Cursor and internal agents are replaceable execution muscles, not authority; final authority and final execution must move into Atlas itself.
  - Quality Intelligence is a transversal gate, not a new OS: every delivery must prove excellence for its work type, real impact, simplification, regression coverage and adversarial review before certification.
  - Verification Court and Governor are external to workers and must not trust worker self-report as final evidence.
  - The same architecture must support multiple 24/7 stewardship instances: one for Atlas itself and one or more for external projects.
  - Every stewardship instance starts with a bounded scope, proves value, then expands scope by evidence and risk class.
maintenance:
  - Update before changing Loop, Self-Construction, task-serving, Maestro, AAEL, AWEOS, 24h autonomy, merge autonomy or multi-project stewardship.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
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
line_limit: 700
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
  - Treat "tests passed" as enough for high-quality delivery when impact, simplification, regression scope and adversarial review were not checked.
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
  - Materialize the v3 ownership map in code and docs: Constitution, Mission Control, Policy Plane, Engineering Kernel, Spec Court, Verification Court, Governor and Learning-Application Controller.
  - Migrate useful Loop/ACDE capabilities into Autonomos or the correct v3 layer, then retire stale Loop-as-product language.
  - Split mechanism from policy: keep ProviderPort, WorkcellExecutor, ReceiptLedger, MergeActuator and BudgetMeter minimal while moving policies into Policy Plane.
  - Add project-lane admission and isolation gates for external stewardship through the same Kernel/Courts/Governor contracts.
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

The old framing "the Loop is the whole thing" is obsolete. The Loop / ACDE was
the pilot that proved pieces of recursive evolution, but it is not the final
operating system, not the authority model and not the canonical name for the
24/7 product.

The canonical product is the Atlas Autonomous Engineering Government. It governs
all engineering runtimes that can change software: Atlas Dev, Atlas Forge,
Autonomos / Self-Construction, external bootstrap workers through AOBG and
future Atlas-native workers.

## Onde Se Encaixa

```text
Atlas
-> Atlas Autonomous Engineering Government
   -> Constitution
   -> Mission Control / AWEOS
   -> Policy Plane
   -> Engineering Kernel
   -> Spec Court
   -> Verification Court
   -> Governor
   -> Learning-Application Controller
   -> User-space runtimes
      -> Atlas Dev
      -> Atlas Forge
      -> Autonomos / Self-Construction
      -> external bootstrap workers via AOBG
```

## Arquitetura V3 Canonica

```text
Atlas Autonomous Engineering Government
  -> Constitution
      -> autonomy tax and operator rebate
      -> ownership map
      -> provider independence ladder
      -> simplicity law
  -> Mission Control / AWEOS
      -> intent compiler
      -> routing policy by risk and AEMOR evidence
      -> outcome registrar
  -> Policy Plane
      -> gate profiles
      -> merge and canary policy
      -> budget policy
      -> isolation policy
  -> Engineering Kernel
      -> ProviderPort
      -> WorkcellExecutor
      -> ReceiptLedger
      -> MergeActuator
      -> BudgetMeter
  -> Spec Court
      -> pre-execution design, packet and evidence review
  -> Verification Court
      -> post-execution proof, replay and anti-false-green review
  -> Governor
      -> admit
      -> land
      -> canary
      -> revert
  -> Learning-Application Controller
      -> apply
      -> measure
      -> settle or revert
  -> User-space runtimes
      -> Atlas Dev
      -> Atlas Forge
      -> Autonomos / Self-Construction
      -> external bootstrap workers via AOBG
```

## Ownership Canonico

| Layer | Owns | Must not own |
|---|---|---|
| Constitution | Laws, autonomy levels, ownership, forbidden dependency, simplicity and provider-independence ladder. | It does not execute work or become a runtime. |
| Mission Control / AWEOS | Intent compilation, routing, orchestration state, outcome registration and continuation. | It does not call providers directly, certify final truth or merge code. |
| Policy Plane | Policies as data: gate profile, merge/canary, budget, isolation and autonomy policy. | It does not execute tools or hide policy inside prompt text. |
| Engineering Kernel | Minimal mechanisms behind small interfaces: provider, workcell, receipts, merge actuator and budget. | It does not choose product strategy or encode business policy. |
| Spec Court | Pre-execution quality of proposal, design, packet, allowed files, acceptance and evidence. | It does not implement. |
| Verification Court | Post-execution proof, replay, tests, impact, simplification and anti-Goodhart review. | It does not author the patch it judges. |
| Governor | Admission, landing, canary, rollback and release safety. | It does not trust worker self-report. |
| Learning-Application Controller | Applies, measures, settles or reverts learning changes. | It does not promote narrative memory without outcome evidence. |
| User-space runtimes | Dev, Forge, Autonomos and external workers perform bounded work through Kernel/Courts/Governor. | They do not own `verified=true`, main entry, provider secrets or canonical memory promotion. |

## Core Thesis

The strongest autonomous engineering architecture separates powers:

- Constitution says what is allowed.
- Mission Control decides how an intent becomes a governed mission.
- Policy Plane keeps policy explicit, inspectable and changeable as data.
- Engineering Kernel exposes a small interface for dangerous mechanisms.
- Spec Court rejects bad work before execution.
- user-space runtimes execute only admitted work through the Kernel.
- Verification Court proves or rejects the result after execution.
- Governor controls landing, canary and rollback.
- ReceiptLedger proves events.
- Learning-Application Controller changes future behavior only after measured
  outcomes.
- docs remain the authoring source of truth.

The same agent or runtime must not create the objective, implement the patch,
judge its own work, merge it and promote its own learning.

The architecture must stay simple. If a mechanism adds worktrees, sandboxes,
locks, multi-stage approvals or new services without reducing real failure or
token waste, it is not architecture; it is drag.

## Kernel / Policy Split

The Engineering Kernel is deliberately small. It is a deep module: a small
interface with dangerous, high-leverage mechanisms behind it.

Canonical Kernel interfaces:

- `ProviderPort`: invoke provider or local model capability through governed
  policy, never by runtime-specific prompt hacks.
- `WorkcellExecutor`: run an admitted workcell with scope, budget and tool
  contract.
- `ReceiptLedger`: record durable evidence, replay refs, hashes, gates and
  outcomes.
- `MergeActuator`: perform scoped land/canary/revert operations only when the
  Governor authorizes them.
- `BudgetMeter`: measure token, time, cost, retries and waste.

Everything that can vary by risk, project, autonomy level, provider, runtime or
release mode belongs in the Policy Plane, not inside the Kernel. The Kernel
must not become a giant service full of product decisions. The Policy Plane must
not become an executor.

## User-Space Runtimes

Atlas Dev, Atlas Forge, Autonomos / Self-Construction and external bootstrap
workers are user-space. They can be powerful, but they are not sovereign.

| Runtime | Purpose | Authority limit |
|---|---|---|
| Atlas Dev | Fast path for operator-assisted engineering: bugfix, refactor, QA, feature slice, review and explanation. | Uses Operator Rebate for speed, but final proof still goes through receipts/gates. |
| Atlas Forge | Long-horizon Obras: enterprise work, SDD, multiagent/multiprovider, months-long continuity and heavy engineering. | Does not bypass Governor, receipts or Court just because the work is large. |
| Autonomos / Self-Construction | 24/7 Atlas building Atlas with no normal operator dependency. | Pays the highest Autonomy Tax and must prove provider independence, rollback and self-repair. |
| External bootstrap workers | Claude Code, Codex, Cursor, Hermes or other tools accelerating task execution through AOBG/task packets. | Temporary muscles; never final dependency and never final authority. |

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
- Governor integrates, canaries, rolls back or quarantines by evidence;
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

### Constitutional Contracts

- `operator_dependency_allowed=false` for steady-state Autonomos work.
- `external_provider_dependency_allowed=false` for final-state autonomy.
- `shared_local_main_with_scope_lock` remains the default task execution
  topology unless a Policy Plane risk exception says otherwise.
- `verified=true` can only come from Verification Court receipts.
- `main/release_entry` can only come from Governor authorization.
- `learning_promoted=true` can only come from Learning-Application Controller
  after apply/measure/settle.

### Mission Contracts

- Mission Control compiles intent into an admitted route: Dev, Forge,
  Autonomos, external bootstrap worker or blocked.
- Routing must use risk, scope, ambiguity, AEMOR outcome evidence, worker
  history, budget and autonomy level. Substring-only routing is a bug.
- Outcomes must be recorded in a common shape so future routing, queue repair,
  worker selection and learning can improve.

### Spec Court Contracts

Before work is served or executed, Spec Court checks:

- objective is real leverage, not quota padding;
- allowed files are sufficient and not contradictory;
- acceptance criteria are runnable and non-contradictory;
- dependencies are explicit;
- the packet is not template farm or blind orphan wiring;
- rollback and required evidence fit the risk class;
- the proposal reduces a real gap, risk, bug, simplification target or
  capability blocker.

### Verification Court Contracts

After work returns, Verification Court checks:

- required tests/gates actually ran;
- evidence matches the files and behavior changed;
- false green, proxy metrics and Goodhart patterns are rejected;
- simplification or added complexity is justified;
- regressions and rollback are accounted for;
- the result is user/system-impactful, not only line churn.

## Fluxo

```text
Intent
-> Mission Control compiles route and risk
-> Context Cortex / Domain Map assemble evidence
-> Leverage Ranker selects highest structural move
-> Proposal Arena competes designs
-> Spec Court approves or rejects proposal/packet
-> Policy Plane binds gate, budget, isolation and merge policy
-> Engineering Kernel executes mechanisms
-> User-space runtime performs work
-> Verification Court proves or rejects
-> Governor lands, canaries, reverts or quarantines
-> ReceiptLedger records evidence
-> Learning-Application Controller applies and measures learning
-> Knowledge Sync updates docs, code index and memory
-> Mission Control reprioritizes
```

## Regras para IA

- Do not treat Loop / ACDE as the whole OS. It is pilot legacy; migrate useful
  mechanisms to v3 layers and retire the rest.
- Do not call the final product "Loop" when the intended architecture is
  Autonomos / Atlas Autonomous Engineering Government.
- Do not put product policy inside Engineering Kernel.
- Do not let Mission Control / AWEOS call providers, certify final truth or
  merge code directly.
- Do not let Atlas Dev, Atlas Forge, Autonomos or external workers own
  `verified=true`, main/release entry or canonical memory promotion.
- Do not over-engineer the operating model.
- Do not replace the shared local `main` task model with default worktrees or
  sandboxes.
- Do not make the final architecture depend on the operator, any human reviewer,
  external coding session or external provider product.
- Do not treat worker self-report as final evidence.
- Do not create a new parallel OS for task serving, stewardship or project care.
- Do not mutate an external project outside its admitted project lane.
- Do not count task volume, line churn or green self-report as real value.
- Do not certify a delivery that lacks work-type excellence, impact proof,
  simplification check, regression intelligence and adversarial review.

## Escopo de Implementacao

The first implementation scope is intentionally small: Autonomos /
Self-Construction inside Atlas, starting from the useful Loop /
AutonomousEvolution mechanisms that already exist. After proof, the same
government expands to broader Atlas subsystems and then to external project
lanes.

Loop / ACDE code and docs are migration material, not the final product shape.
Each useful piece must be assigned to one v3 owner: Constitution, Mission
Control, Policy Plane, Engineering Kernel, Spec Court, Verification Court,
Governor, Learning-Application Controller or a user-space runtime. Anything that
does not earn an owner should be simplified or deleted.

## Scope Ladder

This government starts small and expands by proof:

1. One bounded scope inside Atlas, currently Loop / AutonomousEvolution.
2. Rename/migrate that scope into Autonomos / Self-Construction language and
   v3 ownership.
3. Broader Atlas engineering scopes: context, memory, docs, task-serving,
   Maestro, gates, self-construction.
4. Full Atlas Self-Construction stewardship.
5. External project stewardship for one project at a time.
6. Multiple simultaneous stewardship instances, each with its own scope,
   mainline lane, task economy, gates, receipts and autonomy policy.

No scope receives 24/7 authority by ambition. It earns it through receipts,
server-side gates, rollback, low waste, real value and clean recovery.

## Multi-Project Stewardship

The architecture is not Atlas-only. A project can be admitted as a stewardship
instance:

```text
Project objective + repository + owner policy
-> Constitution admits the project lane
-> Mission Control compiles project route and risk
-> Context Cortex / Domain Map builds project model
-> Proposal Arena and Spec Court approve architecture and packets
-> Task Fabric creates project packet queue
-> Maestro schedules project lane
-> Workers
-> Project-specific Verification Court gates
-> Governor land/canary/revert policy
-> Learning-Application Controller and Knowledge Sync
```

Each project gets an isolated lane. Cross-project reuse is allowed only through
provider-safe memory, patterns, docs and receipts. One project's worker must
never mutate another project's files, secrets, releases or policies.

## Dependencias

- Constitution / ownership / autonomy policy.
- Mission Control / AWEOS.
- Policy Plane.
- Engineering Kernel.
- Spec Court and Verification Court.
- Governor and Learning-Application Controller.
- Atlas Dev, Atlas Forge and Autonomos user-space runtimes.
- Atlas Knowledge Governance and docs sync.
- Task Serving / Task Fabric.
- Maestro scheduler.
- AOBG / external bootstrap worker surfaces.
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
- Main/release entry belongs to the Governor.
- Rollback must be executable before higher autonomy is granted.
- If learning would change future decisions, it needs a Learning Transfer
  Receipt, not just a completion log.

## Evidencias

Evidence for this architecture comes from canonical docs, task-serving behavior,
successful task packet delivery, give-back diagnostics, server-side tests,
receipts and future runtime gates. A 24/7 claim is not valid until Verification
Court and Governor prove it independently.

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

- **Autonomos / Self-Construction OS**: the Atlas-building-Atlas user-space
  runtime inside this government.
- **Loop / ACDE / Autopoiesis**: pilot legacy and migration source. It is not
  the whole government and should not remain the product name.
- **AAEL**: evolution/portfolio capability that must plug into Mission Control,
  Policy Plane or Learning-Application Controller; it must not create a
  parallel authority.
- **AWEOS / Mission Control**: mission/work orchestration. It compiles route,
  risk and continuation; it does not call providers directly, verify final
  truth or merge code.
- **Task Serving / Task Fabric**: permanent packetization and serving layer.
  It feeds user-space runtimes under Spec Court, Policy Plane and Maestro.
- **Maestro**: scheduler/orchestrator/health router, not architect and not
  verifier.
- **Quality Intelligence**: cross-court quality criteria that upgrades "green"
  into excellent by checking work-type DoE, impact proof, simplification,
  regression intelligence and adversarial review.
- **Cortex / Domain Map**: read model/world model, not judge.
- **AEMOR**: outcome memory and judgment evidence, not the controller that
  applies learning.
- **Learning-Application Controller**: the controller that applies, measures and
  settles or reverts learning changes.

## Proximas Acoes

1. Materialize the v3 ownership map in code and docs.
2. Rename/migrate Loop/ACDE-era docs and runtime language to Autonomos /
   Self-Construction where it is still valid.
3. Implement the Engineering Kernel as five small interfaces, not a broad
   orchestrator.
4. Move gate, merge, budget and isolation decisions into Policy Plane.
5. Promote pre-execution proposal review into Spec Court and post-execution
   proof into Verification Court.
6. Wire Learning-Application Controller and Knowledge Sync as required
   post-outcome steps.
