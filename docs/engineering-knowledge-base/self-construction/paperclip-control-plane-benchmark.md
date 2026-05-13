---
id: atlas-self-construction-paperclip-control-plane-benchmark
type: engineering_knowledge
title: Atlas Self-Construction - Paperclip Control Plane Benchmark
status: active
category: architecture
priority: 100
summary: Canonical benchmark for what Atlas must absorb from Paperclip-style agent company control planes, and what Atlas must exceed through Obras, Forge, SDD and Self-Programming OS.
tags:
  - atlas-ai
  - self-construction
  - self-programming
  - forge-workspace
  - paperclip
  - multi-agent-control-plane
capabilities:
  - paperclip_benchmark
  - agent_runtime_state
  - heartbeat_runs
  - checkout_locks
  - execution_workspaces
  - forge_workspace
decisions:
  - Paperclip is a benchmark for agent company control-plane mechanics, not the target identity of Atlas.
  - Atlas must absorb Paperclip's operational primitives, then exceed them with Obras, SDD, Evidence, Decision Receipts, Spec Graph and Self-Programming safety.
  - The urgent implementation path is persistent multi-agent execution state before higher autonomy.
  - No Atlas provider collaboration should depend on loose chat handoffs when a governed workspace artifact can carry the state.
maintenance:
  - Read before implementing multi-agent execution, Forge Workspace runtime, provider adapters, heartbeats, task checkout, approvals, cost tracking or work products.
  - Update when Atlas promotes a Paperclip-inspired primitive into executable Laravel/Postgres runtime.
related_paths:
  - docs/engineering-knowledge-base/obras/shared-workspace-and-forge.md
  - docs/engineering-knowledge-base/self-construction/multi-provider-agent-orchestration-contract.md
  - docs/engineering-knowledge-base/self-construction/work-splitter-contract.md
  - docs/engineering-knowledge-base/self-construction/scope-validator-contract.md
  - docs/engineering-knowledge-base/self-construction/packet-queue-contract.md
  - docs/engineering-knowledge-base/self-construction/assignment-and-claim-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 300
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-self-construction-paperclip-control-plane-benchmark

graph_title: Atlas Self-Construction - Paperclip Control Plane Benchmark

graph_world: atlas

graph_layer: gear

graph_kind: module

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo

repo_paths:
  - docs/engineering-knowledge-base/self-construction/paperclip-control-plane-benchmark.md

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
  - self-construction

evidence:
  - docs/engineering-knowledge-base/self-construction/paperclip-control-plane-benchmark.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - gear
  - module
  - self-construction

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
# Atlas Self-Construction - Paperclip Control Plane Benchmark

## Position

Paperclip should be treated as a serious external benchmark for multi-agent
operations. Its useful category is:

```text
Agent Company Control Plane
```

Atlas must not shrink into that category. The target category is:

```text
Programming/Cognitive OS
+ Obras Shared Workspace
+ Forge Workspace
+ Spec Operating System
+ Self-Construction OS
+ Self-Programming OS
```

Paperclip proves that multi-agent work needs durable coordination. Atlas must
absorb the operational lessons, then add deeper specification, evidence,
quality, memory, context and self-modification governance.

## What Paperclip Validates

Paperclip validates these assumptions for Atlas:

- many AI sessions without a shared control plane become chaotic;
- tasks need owners, locks, statuses, dependencies and recovery paths;
- long-running agents need heartbeats, runtime state and liveness checks;
- provider-specific execution must sit behind adapter contracts;
- cost and budget must be first-class, not a report after the fact;
- every important mutation must be auditable;
- outputs should become work products, not disappear into chat;
- human approval must be modeled as a durable object;
- workspaces must be explicit: repo, branch/worktree, cwd, provider and status;
- context should be delivered by contract, not copied between provider chats.

## What Atlas Must Absorb

### Execution Core

1. Agent Runtime State: persistent state per AI/provider/session.
2. Heartbeat Runs: every execution becomes a run with lifecycle status.
3. Wakeup Queue: scheduled, event, retry and continuation wakeups.
4. Checkout Lock: a task must be atomically claimed before execution.
5. Ownership Lock vs Execution Lock: separate assignee from active run.
6. Run Liveness: detect silent, orphaned, stalled and detached runs.
7. Retry/Recovery Policy: bounded retries for transient and process failures.
8. Continuation Summary: continue from operational summary, not full chat.

### Workspace Core

9. Execution Workspace: repo, worktree/branch, cwd, provider and status.
10. Project Workspace vs Execution Workspace: base context vs isolated run.
11. Workspace Runtime Services: dev server, preview URL and support services.
12. Workspace Lifecycle: open, use, close, preserve, clean and recover.

### Work Core

13. Issue/Task System: task with project, goal, parent, priority and assignee.
14. Parent/Subtask Structure: decomposition is structural.
15. Blocker Dependencies: dependency is separate from hierarchy.
16. Task Liveness Contract: every non-terminal task has a next movement path.
17. Work Products: outputs are durable artifacts tied to task/run/version.

### Governance Core

18. Approvals: sensitive decisions are objects, not comments.
19. Activity Log: all important mutations become audit events.
20. Permissions/Roles: agent, owner, reviewer, approver and auditor differ.
21. Pause/Resume/Terminate: operator can stop agent, task or subtree.
22. Budget/Cost Events: cost by provider, model, task, project and goal.

### Agent Core

23. Adapter Abstraction: Codex, Claude, Gemini, Bash, HTTP and future agents.
24. Runtime Skill Injection: inject skills/context at run time.
25. Thin Ping vs Fat Payload: choose context delivery per agent capability.
26. Org Chart/Reporting Lines: agents have roles, managers and delegation.
27. Agent Capabilities: each agent declares when it is useful.

### Portability Core

28. Workspace/Company Templates: export/import teams, skills and configs.
29. Tenant/Obra Isolation: separate users, Obras, providers and evidence.
30. Plugin System: optional capabilities live at edges, not in core.

## What Atlas Must Add Beyond Paperclip

Paperclip's strongest primitives are operational. Atlas needs a higher layer:

- Spec Compiler;
- Plan Compiler;
- Task Compiler;
- Spec Graph;
- Decision Receipts;
- Scope Validator;
- Work Splitter;
- Evidence Ledger with claim-level traceability;
- Quality Gates;
- Repair Loop;
- Obras as live artifact workspace;
- Forge Workspace as programming specialization;
- Self-Construction governance;
- Self-Programming safety.

The Atlas implementation rule:

```text
Do not implement Paperclip as a clone.
Implement Paperclip primitives as substrate for Atlas SDD/Obras/Self-Programming.
```

## Additional Valuable Patterns

The thirty primitives above are the load-bearing control-plane patterns. A
second Paperclip pass also exposes adjacent patterns Atlas should remember when
building the enterprise version:

- Run Transcript: durable execution timeline for debugging and audit.
- Successful Run Handoff: convert a completed run into a next-step artifact.
- Productivity Review: detect whether work moved the task forward.
- Signoff Policy: explicit rules for when work can be considered accepted.
- Issue Thread Interactions: comments, notices and prompts are stateful UI.
- Inbox/Notifications: human attention is routed, dismissed and reviewed.
- Goals: work should connect to larger outcomes, not only tasks.
- Routines/Cron: recurring work is a first-class operating mode.
- Environment Runtime Config: execution depends on declared env capabilities.
- Secrets Management: credentials are scoped, encrypted and auditable.
- Redaction: logs, prompts and outputs need privacy-safe projections.
- Assets/Documents: files are managed artifacts, not arbitrary attachments.
- Search: agent companies need cross-project discovery and retrieval.
- Quota Windows: usage limits are operational gates, not afterthoughts.
- Workspace Restore/Merge: isolated work must return safely to the base repo.
- Local Service Supervisor: persistent dev servers need lifecycle ownership.
- Sandbox Provider Abstraction: local, remote and cloud sandboxes are adapters.
- Company Import/Export: workspace portability must preserve governance.

These patterns should not expand the first implementation batch unless they are
required by a selected primitive. They are kept here so Atlas does not lose the
value while still protecting the shortest path to two-Codex and five-provider
execution.

## Canonical Mapping

| Paperclip concept | Atlas concept |
|---|---|
| Company | Obra portfolio / strategic workspace context |
| Project | Obra or Forge project scope |
| Issue | Work packet / task / SDD task |
| Agent | Provider session / AI worker / specialist |
| Heartbeat run | Atlas agent run |
| Agent runtime state | Provider session state |
| Checkout lock | Packet claim / assignment lease |
| Execution workspace | Forge execution workspace |
| Work product | Artifact / output / evidence-bound deliverable |
| Activity log | Evidence event / activity projection |
| Approval | Decision Receipt / human approval object |
| Budget event | Provider cost event |
| Org chart | Provider role topology / delegation graph |
| Plugin | Capability adapter / harness extension |

## Urgent Build Order For Atlas

To reach reliable two-Codex and then five-provider execution, build in this
order:

1. Provider Session State.
2. Agent Run / Heartbeat Run table.
3. Durable Wakeup Queue.
4. Packet Checkout Lock.
5. Execution Workspace registry.
6. Activity/Event Log.
7. Continuation Summary.
8. Run Liveness detector.
9. Approval/Decision object.
10. Adapter abstraction for Codex first, then Claude/Gemini.
11. Cost Events.
12. Work Products.
13. Blocker Dependencies.
14. Workspace Lifecycle.
15. Runtime Skill Injection.

This is the minimum substrate for:

```text
2 Codex safely
-> 5 provider sessions
-> Forge Workspace runtime
-> Self-Programming OS
```

## Non-Negotiable Invariants

- No provider starts work without a claimed packet or explicit run scope.
- No task becomes `in_progress` without an execution path or human owner.
- No non-terminal task may be invisible, ownerless or next-actionless.
- No provider copies another provider's chat as source of truth.
- No work product is accepted without evidence and producing run.
- No sensitive decision is represented only as prose.
- No budget/cost is optional for repeated or autonomous execution.
- No retry loop is unbounded.
- No workspace mutation bypasses scope validation.
- No self-programming action bypasses Decision Receipt and human policy.

## What This Changes In Atlas Strategy

The next step is not "make agents smarter". The next step is making Atlas
operationally capable of supervising agents:

```text
state -> wakeup -> claim -> workspace -> run -> liveness -> artifact
-> evidence -> approval -> integration -> learning
```

Once this substrate exists, SDD and Obras stop being documents and become the
operating environment where multiple IAs can safely build Atlas.

## Resumo

Canonical benchmark for what Atlas must absorb from Paperclip-style agent company control planes, and what Atlas must exceed through Obras, Forge, SDD and Self-Programming OS.

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
