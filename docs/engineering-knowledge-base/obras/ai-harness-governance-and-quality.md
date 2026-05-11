---
id: atlas-ai-obras-ai-harness-governance-and-quality
type: engineering_knowledge
title: Atlas Obras - AI Harness Governance And Quality
status: active
category: architecture
priority: 100
summary: AI harness, governance, quality gates, review flows and policy rules for Obras.
tags:
  - atlas-ai
  - obras
  - ai-harness
  - governance
capabilities:
  - obras_operating_system
  - quality_gates
  - governed_ai_execution
decisions:
  - AI inside Obras must operate against Obra context, not loose chat context.
  - Critical Obras require evidence, gates, permissions and human checkpoints.
maintenance:
  - Update before changing Obra AI actions, gates, policy, providers, evidence or approval rules.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-obras-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-cognitive-runtime.md
owner: atlas-ai
layer: 2-product-primitive
line_limit: 240
---

# Atlas Obras - AI Harness Governance And Quality

## Obra Context Pack

Every AI action inside an Obra must build the smallest sufficient context:

- Obra summary;
- current objective;
- current phase/status;
- relevant node/section;
- relevant sources;
- relevant decisions;
- recent feedback;
- applicable gates;
- operator preferences;
- domain policies.

The AI must not treat "review my methodology" or "build Atlas Educacional MVP"
as loose chat. It must build an Operation Envelope with `obra_id`, domain,
section, task type, sources, decisions, deadline, quality gate and risk.

## AI Harness Components

- Intent Parser;
- Context Builder;
- Skill System;
- Model Router;
- Policy Engine;
- Quality Gate Runner;
- Reviewer;
- Repair Loop;
- Memory Delta;
- Learning Loop;
- Trace System;
- Output Renderer.

## Execution Runtimes

ObraOS may call specialized runtimes:

- Research Runtime;
- Writing Runtime;
- Code Runtime;
- Document Runtime;
- Planning Runtime;
- Review Runtime;
- Output Runtime.

Each runtime must emit evidence and must respect policy.

## Governance

Required governance components:

- permissions;
- audit;
- approvals;
- risk policy;
- data policy;
- provider policy;
- human checkpoints.

Human checkpoints are required for:

- scope changes;
- strategic decisions;
- dubious sources;
- external publication;
- financial action;
- sensitive action;
- irreversible change.

## Provider Policy

Before selecting a model, Atlas must ask:

- does this Obra contain sensitive data?
- may data leave local environment?
- should data be anonymized?
- is local model required?
- what is the budget?
- is human review required?

## Universal Gates

Universal gates:

- objective clarity;
- definition of done;
- structure coherence;
- next step;
- decision coverage;
- evidence coverage;
- risk clarity;
- tradeoff clarity;
- current version;
- output definition;
- learning capture;
- strategic relationship.

## Domain Gates

Technical gates:

- spec clarity;
- testable requirements;
- architecture documented;
- tradeoffs registered;
- tests passed;
- technical risks mapped;
- documentation updated.

Strategic gates:

- greater objective;
- opportunity cost;
- value thesis;
- human integrity/health/relationship risk;
- success metric.

Educational gates:

- learning objective;
- progression;
- exercises;
- review;
- evaluation;
- feedback.

Academic gates:

- theme delimited;
- problem clear;
- objective answers the problem;
- methodology compatible;
- citations have references;
- references are cited;
- unsupported claims detected;
- plagiarism risk reviewed;
- institution manual followed;
- conclusion answers objective.

## Review And Repair

Reviewer must search for:

- contradictions;
- gaps;
- loose scope;
- unsupported claims;
- undeclared risk;
- unjustified decisions;
- deliverables without done criteria.

Repair Loop must attempt correction when a gate fails. Example:

```text
Gate failed: objective is generic.
Repair: refine objective into a measurable, scoped objective.
```

## AI Session Trace

Every AI session inside an Obra records:

- request;
- Obra and node;
- context used;
- model/provider used;
- sources used;
- answer generated;
- gates applied;
- corrections made;
- human decision;
- output created.

This is what separates Obras from a chat.
