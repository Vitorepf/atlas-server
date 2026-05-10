---
id: atlas-ai-research-operating-system
type: engineering_knowledge
title: Atlas AI Research Operating System
status: active
category: architecture
priority: 100
summary: Enterprise architecture for automatic high-reliability research as an evidence-first operating system.
tags:
  - atlas-ai
  - research-os
  - evidence-first
  - enterprise-research
capabilities:
  - research_operating_system
  - evidence_first_research
  - deep_research_architecture
decisions:
  - Atlas research must produce evidence dossiers before knowledge claims.
  - Research OS is a governed system, not a single chat response or search call.
  - Reports publish verified claims, not unsupported generated knowledge.
maintenance:
  - Update when Source Registry, Evidence Lake, scheduler, agents or eval harness become executable.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-research-self-improvement-runtime.md
  - docs/engineering-knowledge-base/research-self-improvement/evidence-lake-and-citation-health.md
  - docs/engineering-knowledge-base/research-self-improvement/multi-agent-research-roles.md
  - docs/engineering-knowledge-base/research-self-improvement/scheduled-research-and-triggers.md
  - docs/engineering-knowledge-base/research-self-improvement/reporting-and-publication-contract.md
  - docs/engineering-knowledge-base/research-self-improvement/private-connectors-security-and-stack.md
  - docs/engineering-knowledge-base/research-self-improvement/source-connectors-and-capture.md
---

# Atlas AI Research Operating System

Research OS is the target architecture for automatic Atlas research at maximum
reliability.

It is not "web search plus summary". It is a factory for evidence-backed
knowledge where every important conclusion has source, timestamp, provenance,
confidence, contradiction search and review state.

## Architecture

```text
Research objective
-> Scheduler / trigger
-> Source Registry
-> Collectors
-> Evidence Lake
-> Hybrid Index
-> Multi-Agent Research
-> Claim Verification
-> Citation Health
-> Contradiction Search
-> Synthesis Report
-> Eval Harness
-> Memory / Docs / AP promotion
-> Self-Improvement proposal
```

## Components

| Component | Responsibility |
|---|---|
| Scheduler | Time/event based research jobs. |
| Source Registry | Allowed sources, trust tier, permissions, rate limits. |
| Collectors | API, RSS, GitHub, browser, PDF, transcript, dataset and repo capture. |
| Evidence Lake | Raw immutable evidence, hashes, snapshots, extracted text and screenshots. |
| Hybrid Index | BM25, embeddings, graph edges, temporal metadata and authority scoring. |
| Research Agents | Plan, scout, inspect, verify, red-team and synthesize. |
| Claim Store | Atomic claims and evidence links. |
| Citation Health | URL liveness, archive, quote support and source drift. |
| Eval Harness | Quality, factuality, citation, cost, latency and utility metrics. |
| Promotion Gate | Decides docs/AP/code/memory/report action. |

## Core Rule

Atlas must not publish "knowledge". Atlas publishes verified claims with
evidence.

## Implementation Phases

1. Read-only Source Registry and source scoring.
2. Manual Evidence Lake packet import.
3. Claim extraction and citation health checks.
4. Research report compiler.
5. Self-Improvement proposal integration.
6. Scheduled read-only research jobs.
7. Multi-agent parallel research.
8. Approved low-risk docs promotion.

No phase may skip evidence, citation health or promotion gates.
