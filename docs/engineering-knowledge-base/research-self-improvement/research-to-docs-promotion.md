---
id: atlas-ai-research-to-docs-promotion
type: engineering_knowledge
title: Atlas AI Research To Documentation Promotion
status: active
category: documentation-governance
priority: 99
summary: Rules for promoting research into canonical documentation before Atlas implementation.
tags:
  - atlas-ai
  - research
  - documentation
  - promotion
capabilities:
  - research_to_docs_promotion
  - documentation_law
  - source_backed_docs
decisions:
  - Research becomes Atlas law only when promoted into the correct canonical doc.
  - Documentation must preserve source quality, uncertainty, forbidden actions and validation.
maintenance:
  - Update when Documentation OS gains automated source-backed promotion.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-research-self-improvement-runtime.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
---

# Atlas AI Research To Documentation Promotion

Research is not law until it is promoted into the right canonical document and
validated by Documentation OS.

## Promotion Decision

| Research result | Destination |
|---|---|
| Changes Atlas identity or thesis | Layer -1 doc/AP first |
| Changes Kernel behavior | Kernel doc + AP + tests |
| Changes runtime boundary | Runtime boundary doc + AP |
| Changes memory/context | Cognitive Runtime / Memory docs |
| Changes provider absorption | Provider Evolution Intelligence |
| Changes source ingestion | Content Intelligence |
| Changes domain behavior | Domain spec |
| Is uncertain or weak | Archive as lead, do not promote |

## Required Doc Delta

Promoted research must state:

- source basis;
- decision;
- allowed actions;
- forbidden actions;
- owner;
- validation;
- promotion gate;
- rollback or fail-closed behavior.

## Source Trace

Docs do not need to paste long research. They must link or point to:

- AP;
- source packet;
- source URL or repo evidence;
- command/test output when local.

## Promotion Gate

Before implementation:

```text
research packet exists
source tier is acceptable
conflicts are documented
canonical owner doc updated
AP/plan exists for structural work
validation plan exists
```

If any item fails, implementation pauses or becomes an isolated spike.

