---
id: atlas-ai-sdd-implementation-roadmap
type: engineering_knowledge
title: Atlas SDD Implementation Roadmap
status: active
category: roadmap
priority: 98
summary: Phased implementation path for Atlas Spec Operating System.
tags:
  - atlas-ai
  - sdd
  - roadmap
capabilities:
  - sdd_roadmap
decisions:
  - Build SDD in phases: foundation, auto-spec, controlled execution, state-of-art sync.
maintenance:
  - Update when APs implement any SDD subsystem.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
  - docs/ap/AP-690-atlas-spec-operating-system-contract.md
---

# Atlas SDD Implementation Roadmap

## Phase 1 - Foundation

- confirm canonical docs/AP/Knowledge DB as source of truth;
- map Programming harness to SDD docs;
- add read-only SDD packet/schema tests;
- expose context discovery preview.

## Phase 2 - Auto-Spec

- Spec Compiler;
- Spec Critic;
- Assumption Ledger;
- clarification gate;
- spec registry/read model.

## Phase 3 - Controlled Execution

- Plan Compiler;
- Task Compiler;
- Decision Receipt extension;
- allowed-files enforcement;
- quality gate runner;
- evidence traceability.

## Phase 4 - State Of Art

- Spec Graph;
- Spec Drift Detector;
- Learning proposals;
- versioned context packages;
- low-risk auto-patch/PR flow.

## First Safe Block

Implement read-only SDD preview:

```text
raw intent -> context summary -> draft spec -> assumptions -> questions -> no code
```

This reduces future risk without giving runtime new autonomy.

