---
id: atlas-ai-research-intelligence-pipeline
type: engineering_knowledge
title: Atlas AI Research Intelligence Pipeline
status: active
category: research
priority: 99
summary: Canonical pipeline for long, high-quality research that feeds Atlas documentation, planning and implementation.
tags:
  - atlas-ai
  - research-pipeline
  - evidence-synthesis
capabilities:
  - research_intelligence_runtime
  - evidence_synthesis
  - research_packet
decisions:
  - Research must be packetized before it changes docs, APs, memory or runtime.
  - The pipeline must preserve uncertainty and conflicting evidence.
maintenance:
  - Update when research scheduler, source registry or evaluator commands exist.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-research-self-improvement-runtime.md
  - docs/engineering-knowledge-base/research-self-improvement/source-quality-and-trust-ladder.md
---

# Atlas AI Research Intelligence Pipeline

Research is a production pipeline, not casual browsing.

## Pipeline

```text
1. Define objective
2. Discover source candidates
3. Classify source tier
4. Extract claims and limits
5. Detect conflicts
6. Synthesize Atlas impact
7. Create research packet
8. Decide promote / hold / archive / research more
```

## Research Packet

```json
{
  "schema_version": "atlas.research_packet.v1",
  "objective": "string",
  "question": "string",
  "sources": [],
  "claims": [],
  "conflicts": [],
  "uncertainties": [],
  "atlas_impact": [],
  "recommended_action": "promote_to_doc|create_ap|benchmark|archive|research_more",
  "forbidden_actions": [],
  "created_at": "datetime"
}
```

## Discovery Strategy

Use a balanced source mix:

- repo evidence and existing docs first;
- official docs/specs for external capability;
- papers and benchmarks for state of art;
- engineering postmortems for operational lessons;
- community only as discovery lead.

## Output Quality

Good research output is:

- specific;
- source-backed;
- conflict-aware;
- time-aware;
- actionable;
- bounded by what cannot be concluded.

Bad research output is:

- generic;
- citation-free;
- hype-driven;
- implementation-first;
- blind to uncertainty;
- detached from Atlas docs and code.

