---
id: atlas-ai-self-construction-self-programming-safety-contract
type: engineering_knowledge
title: Atlas Self-Programming Safety Contract
status: active
category: architecture
priority: 100
summary: Safety contract for Atlas implementing or modifying itself.
tags:
  - atlas-ai
  - self-construction
  - safety
capabilities:
  - self_programming_safety
  - autonomous_implementation_loop
decisions:
  - Self-programming requires stronger scope, rollback and evidence than normal implementation.
  - Autonomy must shrink, not expand, when context or gates are weak.
maintenance:
  - Update before enabling self-programming beyond documentation or low-risk patches.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
---

# Atlas Self-Programming Safety Contract

Self-programming is allowed only when safety conditions are explicit and
enforceable.

## Required Preconditions

- Canonical docs are current.
- Context pack is fresh.
- Target capability has a Meta-SDD spec.
- Allowed files and forbidden files are declared.
- Gates exist and are runnable.
- Rollback strategy exists.
- Evidence requirements are known.
- Drift detector can run or manual drift review is defined.

## Forbidden Mutations Without Human Gate

- provider/model selection policy;
- memory deletion, promotion or privacy policy;
- auth/security boundary;
- data exfiltration boundary;
- MCP/tool write access;
- production deployment behavior;
- self-improvement auto-apply;
- Kernel receipt or Evidence Ledger semantics;
- irreversible data changes.

## Autonomy Shrink Rule

When uncertainty rises, autonomy falls.

| Condition | Max Autonomy |
|---|---|
| docs stale | propose only |
| context missing | ask |
| high risk | human approval |
| no tests | docs/spec only |
| no rollback | no execution |
| forbidden files involved | block |
| validation fails | repair inside scope or escalate |

## Receipt Scope

The receipt must include:

```yaml
self_programming_scope:
  max_files_changed:
  max_runtime_surfaces:
  allowed_layers:
  forbidden_layers:
  allowed_commands:
  forbidden_commands:
  rollback_strategy:
  evidence_required:
```

## Patch Shape

Prefer patches that are:

- small;
- reversible;
- localized;
- covered by focused tests;
- linked to one spec;
- easy to review;
- validated by architecture/doc gates.

## Safety Closeout

Every self-programming closeout reports:

- what changed;
- why it was safe;
- gates run;
- evidence recorded;
- what was not touched;
- residual risk;
- next recommended maturity step.
