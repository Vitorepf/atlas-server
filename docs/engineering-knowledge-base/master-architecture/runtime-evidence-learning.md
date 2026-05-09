---
id: atlas-ai-master-runtime-evidence-learning
type: engineering_knowledge
title: Master Runtime Evidence Learning
status: active
category: architecture
priority: 98
summary: Defines how Runtime, Evidence and Learning planes interact in the Atlas AI final product architecture.
tags:
  - atlas-ai
  - runtime
  - evidence
  - learning
capabilities:
  - evidence_driven_execution
  - super_tool_runtime
  - self_improvement_evolution
decisions:
  - Runtime executes receipts; Evidence records outcomes; Learning proposes improvements.
  - Critical learning changes require proposal review.
  - Language runtimes are divided by scope, not fashion.
maintenance:
  - Keep aligned with runtime language boundaries and telemetry docs.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md
  - docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md
---

# Runtime Evidence Learning

## Runtime Roles

| Runtime | Role |
|---|---|
| Laravel | Kernel, API, auth, policy, receipts, ledger, orchestration |
| Python | AI/data runtime, Graph RAG, embeddings, ML, agents, simulations |
| Go | edge ingestion, concurrency, streaming, webhooks, LiveKit server layer |
| Swift | mobile and Apple-native edge, sensors, Secure Enclave, local UX, Mac/iOS integration |
| Providers | reasoning engines behind Provider Drivers |
| Super Tool Runtime | tools, recipes, normalizers, gates and evidence |

## Evidence Plane

Every meaningful runtime action emits Evidence: decision, provider call, tool
run, gate result, repair attempt, output rendered, proposal created and human
review outcome.

## Learning Plane

Learning consumes Evidence and produces:

1. memory signals;
2. provider performance changes;
3. retrieval improvements;
4. repair heuristics;
5. Curator proposals;
6. documentation health findings.

Learning does not silently mutate critical behavior.

