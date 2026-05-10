---
id: atlas-ai-sdd-context-discovery-business-context
type: engineering_knowledge
title: Atlas SDD Context Discovery And Business Context
status: active
category: architecture
priority: 99
summary: Context discovery and business grounding rules for Atlas Spec Operating System.
tags:
  - atlas-ai
  - sdd
  - context-discovery
  - business-context
capabilities:
  - sdd_context_discovery
  - business_context_grounding
decisions:
  - SDD execution starts by reading project, product, architecture, design and code context.
  - Business context prevents Atlas from being only a code generator.
maintenance:
  - Update when context packs, Code Intelligence, business contexts or design-system extraction change.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-business-contexts.md
  - docs/engineering-knowledge-base/code-intelligence.md
---

# Atlas SDD Context Discovery And Business Context

## Inputs

Atlas should discover:

- repo, branch and active files;
- current route/screen/selection/issue;
- canonical docs and APs;
- product/business docs;
- design system and UI tokens;
- API contracts and routes;
- database schema and migrations;
- tests and fixtures;
- AGENTS/CLAUDE as auxiliary projections only;
- Code Intelligence and Knowledge DB;
- prior specs, receipts and evidence.

## Business Questions

Before spec:

- What user/problem does this serve?
- What business object is affected?
- What action is requested?
- What rule governs it?
- What risk exists?
- What has already been decided?
- What should not change?

## Confidence Classes

Every context item is classified:

- `confirmed_fact`;
- `strong_inference`;
- `hypothesis`;
- `blocking_ambiguity`.

Atlas may execute without asking only when required fields are confirmed or
strongly inferred and risk is low/medium with available gates.

