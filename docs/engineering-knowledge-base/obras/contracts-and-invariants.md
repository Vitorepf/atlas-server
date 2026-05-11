---
id: atlas-ai-obras-contracts-and-invariants
type: engineering_knowledge
title: Atlas Obras - Contracts And Invariants
status: active
category: architecture
priority: 100
summary: Non-negotiable implementation contracts, invariants and promotion rules for the Obras Operating System.
tags:
  - atlas-ai
  - obras
  - contracts
  - invariants
capabilities:
  - obras_operating_system
  - governance_contracts
decisions:
  - L0 implementation must not block L2-L5 maturity.
  - Every Obra must remain an artifact-production system, not a renamed project, note or folder.
  - Obras Shared Workspace is mandatory for multi-provider or parallel-agent production work.
  - Promotion between levels requires evidence, not UI presence.
maintenance:
  - Update before changing Obras implementation contracts, level promotion rules, persistence boundaries or MVP acceptance criteria.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-obras-operating-system.md
  - docs/engineering-knowledge-base/obras/shared-workspace-and-forge.md
  - docs/engineering-knowledge-base/obras/patamares-l0-l5.md
  - docs/engineering-knowledge-base/obras/data-model-and-production-graph.md
  - docs/engineering-knowledge-base/obras/metrics-risks-and-excellence.md
  - docs/ap/AP-692-atlas-obras-operating-system-contract.md
owner: atlas-ai
layer: 2-product-primitive
line_limit: 260
---

# Atlas Obras - Contracts And Invariants

## Implementation Invariants

These rules must hold from the first MVP:

- Obra has a stable id and persists outside chat history.
- Obra has objective, type, domain, status and next step.
- Obra can have structure nodes from day one.
- Obra can link to notes, tasks, sources, decisions, versions, gates and outputs.
- Obra state lives in structured persistence, not only Markdown files.
- Markdown export is allowed, but cannot be the only source of truth.
- Every AI action inside an Obra must be traceable to Obra id and intent.
- Multi-provider work inside an Obra must use Obras Shared Workspace.
- Provider outputs must return as artifacts, not as unstructured chat handoffs.
- No Obra may be called complete without output or explicit closure.
- No Foundry claim may exist without asset classification.
- No Sovereign claim may exist without autonomy and constraint review.

## Persistence Boundary

L0 may start small, but the schema must not force future rewrites that destroy
history. Minimum persistence must support:

- `obra_id`;
- lifecycle status;
- type/domain classification;
- current phase;
- next action;
- hierarchical nodes;
- relation table or graph edge table;
- evidence/event append path.

If the first implementation cannot support relationships, it must at least
reserve identifiers and extension points for relationships.

## MVP Acceptance Contract

The first runtime implementation is acceptable only if Atlas can:

1. create an Obra with objective, type, domain, status and next step;
2. create at least one structure node;
3. update status and next step;
4. list active Obras;
5. archive an Obra without deleting history;
6. expose a read-only status summary;
7. validate that an Obra without next step is incomplete;
8. keep implementation independent from TCC-specific fields.

## Level Promotion Rules

L0 -> L1 requires:

- structure nodes;
- notes/tasks/sources can attach to Obra;
- Obra summary can be generated;
- AI context can be scoped by `obra_id`.

L1 -> L2 requires:

- decision records;
- version records;
- gate templates and gate runs;
- evidence events;
- role/permission model or explicit personal-mode substitute.

L2 -> L3 requires:

- intent parser;
- spec and plan generation;
- reviewer;
- repair loop;
- output renderer;
- human checkpoints.

L3 -> L4 requires:

- asset registry;
- Obra dependencies;
- portfolio view;
- strategic score;
- opportunity cost record.

L4 -> L5 requires:

- operator model;
- autonomy graph;
- capital stack;
- constraint system;
- periodic strategic review.

## Anti-Patterns

Forbidden implementations:

- TCC-only schema;
- folder-only model;
- chat-only memory;
- UI cards without production graph;
- task board renamed as Obras;
- output without evidence;
- gates as static checklist with no run history;
- AI sessions that cannot say which Obra, node, sources and decisions were used.
- multi-provider programming where providers relay context to each other without
  shared packets, artifact ids, scope map and evidence.

## Relationship To Existing Systems

Obras orchestrates existing systems rather than replacing them:

- Documentation OS remains canonical law.
- APs remain structural governance.
- Postgres remains live state.
- Obsidian/Vault remains human knowledge.
- Cognitive Runtime remains memory and retrieval.
- Evidence Ledger remains proof.
- Spec OS remains implementation discipline.
- Self-Construction OS remains Atlas building Atlas.

Obras is the production unit that binds these into an artifact.

## First Pilot Contract

The first pilot should be `Atlas Self-Construction OS` and must prove:

- Obra can point to existing docs and APs;
- Obra can represent current status and next step;
- Obra can show gates already used by the construction process;
- Obra can distinguish external hot blockers from its own scope;
- Obra can generate a useful handoff for another AI session.

If this pilot does not improve Atlas construction clarity, Obras should not be
expanded to broader domains yet.
