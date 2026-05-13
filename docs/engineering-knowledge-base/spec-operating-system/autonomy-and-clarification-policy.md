---
id: atlas-ai-sdd-autonomy-clarification-policy
type: engineering_knowledge
title: Atlas SDD Autonomy And Clarification Policy
status: active
category: policy
priority: 99
summary: Autonomy levels and ask/act/block policy for Atlas Spec Operating System.
tags:
  - atlas-ai
  - sdd
  - autonomy
  - clarification
capabilities:
  - sdd_autonomy_policy
  - clarification_gate
decisions:
  - Atlas should avoid unnecessary questions when context is sufficient.
  - Atlas must ask or block when ambiguity can cause wrong or unsafe implementation.
maintenance:
  - Update when autonomy policy or model routing changes.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md
---

# Atlas SDD Autonomy And Clarification Policy

## Autonomy Levels

| Level | Meaning | Use |
|---|---|---|
| L0 manual | Spec/plan only | critical systems, unclear risk |
| L1 assisted | Human approves implementation | medium/high risk |
| L2 auto_patch | Atlas creates scoped patch | low-risk localized change |
| L3 auto_pr | Atlas opens PR with evidence | low/medium risk with gates |
| L4 restricted merge | Future only, very low-risk | docs/copy/tests after metrics |
| L5 proposal-only learning | Improve templates/policy by proposal | self-improvement |

## Ask / Act / Block

Act when:

- target context is known;
- business object is clear;
- design/API rules are known;
- risk is low/medium;
- gates can run.

Ask when:

- target screen/file is unknown;
- business object is ambiguous;
- security/permission rule is unclear;
- design system conflicts with user wording;
- multiple valid interpretations exist.

Block when:

- request bypasses validation/security;
- asks to modify critical policy/runtime without AP;
- demands hardcoded behavior against architecture;
- requires destructive action without explicit approval.

## Scheduled Work Error Hygiene

Long-running scheduled work may persist local status, output paths and delivery
state for operator review. Error metadata must be provider-safe: exception
messages are redacted before being written to `ai_scheduled_tasks.metadata`, and
rendered output files are redacted before storage.

Scheduler failure metadata is evidence, not authority. It must not expose API
keys, bearer tokens, cookies, private keys or raw provider credentials, and it
does not authorize retries, provider changes, notification delivery or task
mutation beyond the guarded scheduled-run contract.

Each scheduled execution persists `metadata.last_run_receipt` with schema
`atlas.scheduled_task_run_receipt.v1` plus `last_run_receipt_hash`. The receipt
is hash/pointer-only: it stores prompt, title, schedule, workspace, output and
error hashes, not raw prompt/output/workspace paths. It also states the autonomy
closures for background work: anti-recursion guarded, tool permissions read-only,
provider changes closed, retry authorization closed and schedule mutation closed.
