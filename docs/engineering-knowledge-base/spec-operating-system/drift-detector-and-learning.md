---
id: atlas-ai-sdd-drift-detector-learning
type: engineering_knowledge
title: Atlas SDD Drift Detector And Learning
status: active
category: learning
priority: 99
summary: Spec drift detection and proposal-only learning contract for Atlas SDD.
tags:
  - atlas-ai
  - sdd
  - drift
  - learning
capabilities:
  - spec_drift_detector
  - sdd_learning_proposals
decisions:
  - Specs are living contracts, not dead markdown.
  - Learning from SDD patterns remains proposal-only until reviewed.
maintenance:
  - Update when drift detector or learning proposal flow becomes executable.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
  - docs/engineering-knowledge-base/domains/self-improvement.md
---

# Atlas SDD Drift Detector And Learning

## Drift Types

- code changed without spec;
- spec changed without test;
- test covers behavior without requirement;
- endpoint exists without contract;
- business rule exists only in code;
- design-system token violated;
- Decision Receipt allowed X but patch did Y;
- evidence missing for implemented requirement.

## Drift Output

```json
{
  "schema_version": "atlas.sdd_drift.v1",
  "status": "pass|warn|fail",
  "spec_id": "string",
  "findings": [],
  "recommended_action": "none|repair|create_spec|update_test|proposal"
}
```

## Learning Rule

Repeated patterns become proposals, not automatic policy changes.

Example:

```text
Observation: 14 form specs needed loading, disabled, success and error states.
Proposal: update UI form action template.
Gate: human review + docs update + tests.
```

Learning can update templates after approval. It cannot silently alter Kernel,
Policy, provider routing, memory truth or runtime critical behavior.

