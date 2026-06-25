---
id: atlas-ai-self-construction-os
type: engineering_knowledge
title: Atlas AI Self-Construction OS
status: active
implementation_state: partial_runtime_with_future_scope
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
  - self_construction_overview
  - self_construction_runtime_map
decisions:
  - Atlas Self-Construction OS lives inside Atlas Autonomous Engineering Government, the final 24/7 engineering architecture.
  - The Loop is Autopoiesis / Evolution Engine inside Self-Construction, not the whole OS and not the final authority.
  - Task Fabric, Maestro and Worker Swarm are permanent execution organs of Self-Construction, not temporary external-worker scaffolding.
  - Self-Construction must support Atlas itself and external-project stewardship instances through isolated scopes, lanes, task queues, gates and receipts.
  - Atlas may become self-programming only through documentation-as-law, SDD, receipts, evidence and gates.
  - Self-construction is not vibe coding; it is governed evolution of the system that builds itself.
  - Any AI must be able to continue Atlas construction from canonical docs without relying on chat history.
  - The most powerful form is research -> docs -> spec -> implementation -> tests -> evidence -> learning.
  - Self-improvement claims must pass the Self-Improvement Governance Ladder: strong proposal, before/after delta, invariant lock, regression sentinel and promotion policy.
  - Self-Directed Evolution Layer is a composition/read-model layer above this OS; it must reuse Self-Construction proposal primitives instead of creating a new self-construction authority.
  - `AtlasSelfConstructionSubsystemBuilderService` is the existing owner for subsystem gap detection, subsystem proposal, approval/rejection receipt and scaffold skeletons.
  - Self-Programming OS remains a maturity/safety patamar, not a free runtime; command surfaces and tests must keep self-programming blocked unless safety contracts, receipts, gates and Atlas-native autonomy authorization explicitly promote it. Operator approval is bootstrap or exception handling, not the final dependency.
  - LoopPatternRegistry is the governed bridge from skills/catalogs/outcomes into execution structures; it may guide self-construction but cannot approve itself.
maintenance:
  - Read before changing Atlas core, self-improvement, SDD runtime, memory, research automation, autonomous coding or governance.
  - Update when a new self-programming loop, maturity level, build dependency or core safety gate is promoted.
related_paths:
  - docs/engineering-knowledge-base/atlas-autonomous-engineering-government.md
  - docs/engineering-knowledge-base/atlas-self-construction-catalog.md
  - docs/engineering-knowledge-base/atlas-self-directed-evolution-layer.md
  - app/Services/Ai/SelfConstruction/AtlasSelfConstructionSubsystemBuilderService.php
  - app/Console/Commands/AtlasSelfConstructionDetectGapsCommand.php
  - app/Console/Commands/AtlasSelfConstructionProposeSubsystemCommand.php
  - app/Console/Commands/AtlasSelfConstructionApproveProposalCommand.php
  - docs/engineering-knowledge-base/atlas-cartography-nomenclature-contract.md
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
  - docs/engineering-knowledge-base/atlas-loop-pattern-registry.md
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
human_name: Atlas AI Self-Construction OS
canonical_name: Atlas AI Self-Construction OS
technical_name: atlas-ai-self-construction-os
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-ai-self-construction-os.md

patamar_current: Self-Construction OS

patamar_next: Self-Programming OS

patamar_after:
  - Self-Programming OS so pode avancar para runtime mais autonomo depois de safety contracts, receipts, gates, completion audit e evidencia real.

version_note: Este OS pode ter docs, contracts e fases internas, mas Self-Construction OS -> Self-Programming OS e patamar de maturidade, nao versao.

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
evidence_refs:
  - symbol: AtlasSelfConstructionSubsystemBuilderService
  - command: atlas:self-construction:approve-proposal

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
  - IA cria novo detector/proposal runtime para evolucao do Atlas ignorando `AtlasSelfConstructionSubsystemBuilderService`.
  - IA interpreta Self-Directed Evolution como OS novo ou como permissao de autoaprovacao.

observability_signals:
  - docs-health status ok

next_actions:
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
---
# Atlas AI Self-Construction OS

> **Canonical parent:** `Atlas Autonomous Engineering Government`
> (`docs/engineering-knowledge-base/atlas-autonomous-engineering-government.md`).
> This document defines the Atlas-building-Atlas operating system inside that
> government. If this doc, an older Loop doc or a task-serving doc appears to
> imply that the Loop alone is the final 24/7 authority, the Government doc wins.

> **Simplicity law:** Self-Construction must preserve the simple mainline task
> model that works: shared local `main`, conflict-free packets, edits limited to
> `allowed_files`, and Atlas-scoped commits. Worktrees/sandboxes are exceptional
> tools, not the default architecture.

> **Final autonomy target:** Claude Code, Codex, Cursor, external providers and
> the operator are bootstrap, observability or emergency aids only. The finished
> Self-Construction OS must be 100% Atlas-native: Atlas decides, designs, creates
> tasks, executes, verifies, merges or rejects, learns, rolls back and updates
> knowledge without depending on any human, operator or external provider
> platform to keep the 24/7 construction loop alive.

> **Runtime gate:** task packets that contradict this target must be rejected
> before serving. A packet cannot require a permanent human/operator/provider
> dependency, and cannot make worktree/sandbox/branch isolation the default
> Self-Construction path. The default packet topology is
> `shared_local_main_with_scope_lock`.

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

## Final Architecture Position

Self-Construction OS is the governed operating system for Atlas building Atlas.
It is not a monolithic Loop. It is a separation-of-powers system:

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
```

Responsibilities are deliberately split:

- `Control Plane` decides scope, risk, budget, priority and mode.
- `Cortex` supplies read-only understanding, not authority.
- `Strategy Council` selects highest-leverage directions.
- `Architecture Council` converts strategy into contracts and invariants.
- `Task Fabric` creates executable packets with `allowed_files`, dependencies,
  risk, gates, evidence and rollback.
- `Maestro` schedules, routes, repairs queue health and learns worker affinity.
- `Workers` execute packets and may include Loop, Claude Code, Codex, Cursor
  and internal agents during bootstrap; the final target is Atlas-native
  workers without operator/human/provider dependency. External workers are
  training wheels and surge capacity, never the permanent engine.
- `Verification Court` re-runs gates and treats worker output as an allegation
  until independently verified.
- `Merge / Release Governor` owns entry into main/release.
- `Learning Transfer System` promotes proven lessons into future packets,
  docs, memory and context packs.
- `Autopoiesis Lab / Loop` proposes recursive self-improvement under gates.

## Multi-Project Stewardship

Self-Construction is Atlas-first, but the architecture must also run as a
project stewardship system. A project instance gets its own:

- objective and value contract;
- repository/mainline boundary;
- Cortex project model;
- task queue and Maestro lane;
- worker pool policy;
- Verification Court gates;
- Merge / Release Governor policy;
- receipts, learning transfer and docs sync.

This allows one 24/7 lane for Atlas and another 24/7 lane for a separate
software project without mixing files, secrets, policies or releases.

Each project starts with a bounded scope, proves value, then expands by
evidence. The goal is exceptional engineering throughput and quality: clean
code, tests, refactors that unlock capability, bug prevention, documentation,
architecture hardening and compounding delivery speed.

## Hard Laws
- No self-programming without SDD.
- No SDD without context and source-of-truth docs.
- No structural core implementation before contract documentation.
- No Atlas core mutation without Decision Receipt.
- No result without evidence.
- No learning that changes critical behavior without proposal/review.
- No parallel architecture, memory, runtime, provider or daemon outside AP law.
- No default worktree/sandbox/branch choreography when conflict-free mainline
  packets solve the problem.
- No permanent dependency on the operator, humans, external coding sessions or
  external provider products. Operator surfaces may observe, audit or
  emergency-stop, but normal progress must not require a person to create,
  approve, repair, preserve context or unblock every step.
- No implementation priority based on novelty, hype or surface beauty.
- No "complete" claim unless docs, code, tests, evidence and drift checks agree.
- No worker self-report is final evidence for autonomous 24/7 operation.
- No cross-project mutation without an explicit stewardship instance boundary.
- No task packet without enough context freshness for the claimed scope.
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

## Existing Proposal Primitives

Antes de criar qualquer novo runtime de "Atlas detecta gap" ou "Atlas escreve
spec sozinho", IA deve verificar os primitivos ja existentes:

| Primitive | Runtime/comando atual | Autoridade |
|---|---|---|
| Detectar gaps de subsystem | `AtlasSelfConstructionSubsystemBuilderService::detectGaps()` / `atlas:self-construction:detect-gaps` | Self-Construction OS |
| Propor subsystem/capability | `AtlasSelfConstructionSubsystemBuilderService::propose()` / `atlas:self-construction:propose-subsystem` | Self-Construction OS |
| Aprovar/rejeitar proposta | `AtlasSelfConstructionSubsystemBuilderService::approve()` / `atlas:self-construction:approve-proposal` | autoridade atual/bootstrap via receipt append-only; destino final = Atlas-native Verification Court / Merge Governor |
| Staging de scaffold aprovado | `AtlasSelfConstructionScaffoldStagingExecutorService` / `atlas:scaffold:stage` | staging, nao producao |
| Promocao de scaffold | `atlas:scaffold:promote` | dry-run/bootstrap promotion, nao auto-merge; destino final = autonomy-level authorization |

Self-Directed Evolution pode compor esses primitivos em uma inbox de curadoria,
mas nao pode substitui-los. Se a proposta for spec/AP/doc, routeie para Spec OS
e Documentation Governance; se for subsystem, use o Subsystem Builder; se for
portfolio de evolucao, use AAEL; se for melhoria com delta, use Self-Improvement.

Regra anti-duplicacao: novo detector/proposal service so e permitido como
adapter/read-model quando declara quais primitives existentes consome e quais
owners preserva.

## The Highest Form
The most advanced Atlas is not merely self-coding. The highest form is
governed autonomous engineering:
```text
research-backed
documentation-first
spec-driven
task-fabricated
maestro-scheduled
receipt-scoped
test-proven
server-verified
evidence-led
drift-aware
learning-governed
priority-aligned
rollback-capable
multi-project-capable
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
| Loop pattern selection | `atlas-loop-pattern-registry.md` |
| Autonomous loop | `self-construction/autonomous-implementation-loop.md` |
| Safety contract | `self-construction/self-programming-safety-contract.md` |
| Quality bar | `self-construction/quality-bar-and-metrics.md` |
| Failure modes | `self-construction/failure-modes.md` |
| Builder persona / runtime roadmap | `self-construction/builder-persona-and-handoff.md`, `self-construction/runtime-implementation-roadmap.md` |
## Core Loop
```text
1. Detect gap or opportunity.
2. Classify layer and risk.
3. Select a governed loop pattern or create a pattern challenger when no ready pattern fits.
4. Research source-backed state of the art.
5. Promote durable findings to docs.
6. Compile Meta-SDD spec.
7. Architecture Council defines contracts and invariants.
8. Task Fabric builds small executable packets.
9. Maestro schedules workers by lane, dependency and affinity.
10. Workers execute the smallest safe block.
11. Verification Court re-runs gates and rejects false green.
12. Merge / Release Governor merges, rejects or rolls back.
13. Append evidence and receipts.
14. Transfer proven learning into docs, memory and future packets.
15. Detect drift.
16. Promote maturity only if metrics prove it.
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
limit of the architecture. Likewise, command names or legacy templates that say
`operator`, `human`, `Codex` or provider names describe bootstrap surfaces,
audit envelopes or read-only transition contracts; they do not define the final
runtime dependency. The final owner remains Atlas-native.

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
This layer is complete as documentation when any capable AI can implement Atlas
construction work without relying on conversation memory. It is complete as
runtime only when Atlas can run the autonomous loop with scoped patches,
validated gates, evidence, drift detection, proposal-first learning, rollback
and docs/knowledge sync without requiring a human/operator/provider to keep the
cycle alive.
## Current Runtime Surface

Detailed read-only command catalog moved to [`self-construction/command-surface-catalog.md`](self-construction/command-surface-catalog.md) so this parent doc stays focused on architecture. The `AtlasAiSelfConstructionCommand` family exposes every governed pipeline stage (readiness, Meta-SDD, receipts, execution preflight/runbook, evidence, residual risk, handoff, packet queue, collision matrix, parallel session planning, agent control plane, agent dispatch chain and post-monitoring/health-decision/disable-execution recovery) as read-only `--json` projections with `execution_allowed=false`; nothing signs, persists ledger writes, mutates hot runtime files or starts providers. See the catalog for per-command line items.


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
