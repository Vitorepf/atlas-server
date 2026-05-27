---
id: AP-712-night-shift-area-focus-loop-contract
type: architecture_proposal
title: AP-712 Night Shift Area Focus Loop Contract
status: accepted
owner: programming
created_at: 2026-05-26
summary: Adds Area Focus Loop to Product Mode / Atlas Continuous Stewardship Loop so the operator can choose a canonical area, such as Agentic Engineering OS, and let Atlas scan that area for bugs, failures, gaps and improvements while routing work through Atlas Dev, Forge, Self-Directed Evolution and Morning Inbox under governed maximum capacity.
related_paths:
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift.md
  - docs/engineering-knowledge-base/atlas-agentic-engineering-os.md
  - docs/engineering-knowledge-base/atlas-agentic-software-engineering-authority-map.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
requires_evidence: true
risk_level: critical
---
# AP-712 Night Shift Area Focus Loop Contract

## Decision

Product Mode and Atlas Continuous Stewardship Loop must support Area Focus Loop:
the operator selects a canonical area and Atlas keeps a governed improvement
loop on that area. If the run is scheduled/batch it belongs to Night Shift; if
it is 24h/always-on it belongs to Atlas Continuous Stewardship Loop.

This does not create a new OS or a parallel executor. It is a Product Mode
control plane over Night Shift, Atlas Continuous Stewardship Loop,
Self-Directed Evolution, Atlas Dev, Forge, Self-Construction, Evidence and
Morning Inbox.

## Area Contract

An area focus run must declare:

- `area_id`;
- owner docs;
- repo scope;
- autonomy tier;
- Atlas Dev budget;
- Forge budget;
- risk policy;
- WIP limit;
- stop conditions;
- Morning Inbox destination.

## Agentic Engineering OS Example

When the operator chooses `area_id: agentic_engineering_os`, Atlas must scan the
full Atlas software development flow: AAEOS, Atlas Dev, Forge, Self-Construction,
Evidence, Mission Control, branch sandbox, replay, governance and Desktop
surfaces.

The loop should look for bugs, failing gates, stale docs, missing tests, broken
flows, weak handoffs, duplicate runtimes, implementation gaps and high-leverage
improvements.

## Maximum Governed Mode

`max_governed` means maximum useful throughput inside the canonical safety
boundary:

- no merge without operator;
- no deploy without operator;
- no secrets;
- no destructive change;
- branch isolation required;
- budget and WIP limits required;
- kill switch required;
- evidence pack required;
- Morning Inbox required.

## Acceptance

- Product Mode documents Area Focus Loop.
- Area Focus Loop routes small scoped work to Atlas Dev.
- Area Focus Loop routes long-horizon, multi-agent or cross-system work to
  Forge.
- Self-Directed Evolution owns gap/spec proposal.
- Evidence and Morning Inbox remain mandatory.
- `max_governed` is defined as governed capacity, not permissionless autonomy.
