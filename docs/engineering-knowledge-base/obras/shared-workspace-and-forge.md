---
id: atlas-ai-obras-shared-workspace-and-forge
type: engineering_knowledge
title: Atlas Obras - Shared Workspace And Forge
status: active
category: architecture
priority: 100
summary: Canonical contract for Obras Shared Workspace, the office where Forge and multiple providers collaborate through shared context, artifacts, evidence and integration.
tags:
  - atlas-ai
  - obras
  - forge
  - multi-provider
  - shared-workspace
capabilities:
  - obras_shared_workspace
  - forge_workspace
  - multi_provider_orchestration
  - programming_operating_system
decisions:
  - The canonical name is Obras Shared Workspace, with Portuguese label Workspace Compartilhado de Obras.
  - Forge Workspace is the Programming/Atlas Forge specialization of Obras Shared Workspace.
  - Providers do not pass context to each other by loose chat; they exchange governed artifacts through the workspace.
  - Context is compiled once into canonical artifacts, then sliced per provider role to control token cost and quality.
  - Obras Shared Workspace complements Kernel, Forge, Self-Construction OS and Provider Drivers; it replaces ad hoc cross-provider copy/paste, not those systems.
maintenance:
  - Read before changing Atlas Forge, multi-provider programming flows, work packets, artifact bus, context compiler, provider routing or Obras runtime.
  - Update when a new provider collaboration pattern or shared programming workspace is promoted.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-obras-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-flow-visual-map.md
  - docs/engineering-knowledge-base/domains/programming.md
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/self-construction/multi-provider-agent-orchestration-contract.md
owner: atlas-ai
layer: 2.2-obras-shared-workspace
line_limit: 220
---

# Atlas Obras - Shared Workspace And Forge

## Canonical Name

Canonical component name:

```text
Obras Shared Workspace
```

Portuguese product label:

```text
Workspace Compartilhado de Obras
```

Programming specialization:

```text
Forge Workspace = Obras Shared Workspace for Programming / Atlas Forge.
```

Do not create competing names such as "provider office", "AI room",
"Forge memory" or "multi-agent project space". Those may be metaphors, not
architecture.

## Role In The Atlas Flow

Obras Shared Workspace is the persistent production workspace where long or
multi-agent work keeps common state:

- mother contract;
- spec and plan;
- work packets;
- provider-specific context packs;
- artifact bus;
- scope map and collision matrix;
- status board;
- decisions;
- diffs and outputs;
- quality gates;
- evidence and integration queue.

It is not a domain, runtime, provider, memory system or ledger. It is the
workspace that binds those systems for a concrete Obra.

## Relationship To Kernel, Forge And Providers

```text
Atlas Kernel decides and authorizes.
Programming Domain defines engineering rules.
Atlas Forge executes heavy programming workflows.
Obras Shared Workspace holds shared construction state.
Provider Drivers perform bounded roles.
Evidence Ledger audits what happened.
```

Therefore:

```text
Obras Shared Workspace governs collaboration.
Forge runs programming work.
Providers execute assigned roles.
Kernel remains the authority.
```

## Why The Current Provider Chain Is Not Enough

The old pattern is:

```text
Gemini reads context -> Claude plans -> Codex implements -> another model reviews
```

This is useful, but it is a chain of handoffs. It repeats context, loses nuance
and makes each provider reinterpret the task from its own chat state.

The target pattern is:

```text
Obras Shared Workspace
-> Context Compiler
-> Provider Router
-> Artifact Bus
-> Provider-specific packets
-> Evidence
-> Integration Queue
```

Providers do not need to talk to each other directly. They write artifacts into
the shared workspace, and Atlas validates those artifacts.

## Token And Context Law

Never send the same large context to every provider by default.

The workspace must separate:

- canonical raw context;
- curated project context;
- mother contract;
- task-specific packet;
- provider-specific context pack;
- delta since last run.

Examples:

| Provider role | Context it should receive |
|---|---|
| Gemini scout | broad repo/source map, alternatives, long-context synthesis |
| Claude planner/reviewer | architecture, risks, specs, tradeoffs, acceptance criteria |
| Codex implementer | allowed files, task, tests, gates, local commands, evidence contract |
| Local agent | deterministic command, expected output, parser rule |

## Artifact Bus

Models must exchange artifacts, not vague conversation:

- research brief;
- codebase map;
- mother contract;
- spec;
- plan;
- task packet;
- implementation diff;
- test output;
- review findings;
- repair request;
- evidence report;
- integration note.

Each artifact should have an id, source, timestamp, owning provider/session,
input hash, output hash and status.

## Minimum Workspace Contract For Heavy Programming

Before using multiple providers for heavy programming, the Obra must have:

1. objective and definition of done;
2. canonical mother contract;
3. work split with disjoint scopes;
4. allowed and forbidden files per packet;
5. dependency and collision map;
6. provider role per packet;
7. required gates and commands;
8. integration queue;
9. evidence normalization contract;
10. rollback/repair policy.

Without this, multi-provider programming is only ad hoc chaining.

## Implementation Direction

Initial runtime may be simple:

```text
Postgres state + Markdown projection + command surfaces
```

But the source of truth must be structured state. Markdown is an export, not
the whole workspace.

The first strategic pilot should be:

```text
Obra: Atlas Self-Construction OS
Workspace type: Forge Workspace
Goal: coordinate multiple AI sessions/providers building Atlas safely.
```

Operational projection: `--forge-workspace-status` shows the office; `--agent-launch-plan` plans sessions; `--agent-start-packet` claims work; status/report/readiness/final-review/decision/receipt/signature/post-signature/merge-action/preflight/draft/receipt/merge-signature/merge-post-signature/execution-checklist/authorization-template/authorization-receipt/authorization-signature/authorization-post-signature/final-authorization-preflight govern it.

## Non-Negotiable Rule

Obras Shared Workspace is the canonical name for the shared office in the Atlas
flow. Any future diagram, doc or runtime that describes multi-provider
collaboration for production work must use this term or explicitly state that it
is a specialization of it.
