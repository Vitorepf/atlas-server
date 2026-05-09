---
id: atlas-ai-architecture-audit-programming-pipeline-target
type: engineering_knowledge
title: Atlas AI Architecture Audit Programming Pipeline Target
status: active
category: architecture
priority: 87
summary: Target programming pipeline from the architecture audit for unifying Atlas Dev, Forge, Fix, Continue, app and workers.
tags:
  - atlas-ai
  - architecture
  - programming
capabilities:
  - programming_pipeline
  - flow_consolidation
decisions:
  - Atlas Dev, Forge, Fix and Continue are surfaces/flows of Programming, not separate products.
  - Programming must use one context, quality, repair and evidence model.
maintenance:
  - Keep aligned with domains/programming.md and programming specialist docs.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-architecture-audit.md
  - docs/engineering-knowledge-base/domains/programming.md
  - docs/engineering-knowledge-base/engineering-blueprint.md
  - docs/engineering-knowledge-base/super-tool-runtime-core.md
---

# Atlas AI Architecture Audit Programming Pipeline Target

## Target Flow

```text
raw input
-> ProgrammingIntentClassifier
-> ProgrammingTaskContractBuilder
-> ProgrammingContextCompiler
-> Atlas Decide policy/profile/model receipt
-> ProgrammingExecutorSelector
-> executor: simple_provider | dev_repair_executor | engineering_harness
-> ProgrammingQualityMatrix
-> ProgrammingRepairLoop
-> ProgrammingEvidencePacket
-> MemoryDelta / EngineeringLearning
```

## Proposed Components

| Component | Responsibility |
|---|---|
| `AtlasProgrammingPipeline` | Orchestrates the complete programming flow. |
| `ProgrammingIntentClassifier` | Detects implementation, bugfix, repair, review, refactor, DB, UI, security and harness needs. |
| `ProgrammingTaskContractBuilder` | Converts loose prompt into task contract or consumes an existing contract. |
| `ProgrammingContextCompiler` | Combines repo profile, Open Brain, Engineering Context Pack, code refs, likely files and tests. |
| `ProgrammingExecutorSelector` | Selects simple, dev-repair or harness execution using policy, risk and intent. |
| `ProgrammingQualityMatrix` | Selects and evaluates gates by task type. |
| `ProgrammingRepairLoop` | Controls repair capsule and iteration limits. |
| `ProgrammingEvidencePacketBuilder` | Produces auditable final packet. |

## Priority Sequence

| Priority | Target |
|---|---|
| P0 | Stop adding business logic inside commands. |
| P1 | Create or finish `AtlasProgrammingPipeline`. |
| P2 | Create `ProgrammingContextCompiler`. |
| P3 | Create `ProgrammingQualityMatrix`. |
| P4 | Create `ProgrammingRepairCapsule`. |
| P5 | Enforce horizontal Capability Registry. |

## Success Criteria

- `atlas dev` interactive and one-shot share the same internal pipeline.
- `atlas forge` is a heavier Programming intensity, not a second programming product.
- `atlas fix` is a repair intent/flow inside Programming.
- Context, gates, repair and final evidence are consistent across surfaces.
- Architecture validation can detect a new surface bypass.
