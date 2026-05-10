---
id: atlas-ai-sdd-spec-graph-traceability
type: engineering_knowledge
title: Atlas SDD Spec Graph And Traceability
status: active
category: architecture
priority: 99
summary: Traceability graph from user intent to requirements, tasks, files, tests and evidence.
tags:
  - atlas-ai
  - sdd
  - spec-graph
  - traceability
capabilities:
  - spec_graph
  - traceability
  - requirement_to_evidence
decisions:
  - Markdown alone is insufficient for enterprise SDD.
  - Every important requirement must trace to task, file, test and evidence.
maintenance:
  - Update when Spec Graph tables/read models are implemented.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
  - docs/engineering-knowledge-base/engineering-blueprint-contracts.md
---

# Atlas SDD Spec Graph And Traceability

Spec Graph links:

```text
user_intent
-> interpreted_goal
-> requirement
-> acceptance_criteria
-> plan
-> task
-> file
-> test
-> evidence_event
-> decision
-> learning_proposal
```

## Required Questions

Atlas must be able to answer:

- Which test proves this requirement?
- Which file implements this acceptance criterion?
- Which evidence proves the gate passed?
- Did the patch modify files outside receipt?
- Did code change without matching spec?
- Did spec change without test/evidence?

## Minimum Traceability Row

```json
{
  "spec_id": "SPEC-...",
  "requirement_id": "R1",
  "acceptance_criteria_id": "AC1",
  "task_id": "T2",
  "file_path": "ProfileForm.tsx",
  "test_path": "ProfileForm.test.tsx",
  "evidence_event_id": "EVT-..."
}
```

## Promotion Rule

A feature is not enterprise-complete until critical requirements have traceable
evidence.

