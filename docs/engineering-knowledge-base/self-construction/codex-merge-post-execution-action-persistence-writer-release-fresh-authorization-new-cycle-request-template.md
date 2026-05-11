---
id: atlas-ai-self-construction-codex-merge-post-execution-action-persistence-writer-release-fresh-authorization-new-cycle-request-template
type: engineering_knowledge
title: Atlas Self-Construction Codex Merge Post-Execution Action Persistence Writer Release Fresh Authorization New Cycle Request Template
status: active
category: architecture
priority: 100
summary: Read-only new cycle request template for future fresh authorization health decisions.
tags:
  - atlas-ai
  - self-construction
  - codex-review
  - merge-governance
capabilities:
  - self_construction_os
  - review_governance
  - merge_authorization
decisions:
  - A future new fresh authorization cycle must restart the entire chain.
  - Previous request, receipt, signature, execution and observability hashes must not be reused as current authority.
  - This template must not approve, accept signatures, create writer files, write ledger, persist receipts, merge or dispatch.
maintenance:
  - Update before adding any new-cycle authorization request, receipt draft or signature request surface.
related_paths:
  - docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-release-fresh-authorization-health-decision-template.md
  - docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-release-fresh-authorization-request-template.md
  - docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-release-fresh-authorization-new-cycle-authorization-request-template.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 200
---

# Atlas Self-Construction Codex Merge Post-Execution Action Persistence Writer Release Fresh Authorization New Cycle Request Template

This document governs the read-only new cycle request template that follows a
future fresh authorization health decision.

The command is:

```bash
php artisan atlas:ai:self-construction --codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-request-template --json
```

## Boundary

The template must keep:

- `execution_allowed=false`;
- `writer_file_creation_allowed=false`;
- `ledger_write_allowed=false`;
- `dispatch_allowed=false`;
- `approval_granted=false`;
- `merge_allowed=false`;
- `signature_valid=false`;
- `receipt_signed=false`;
- `receipt_persisted=false`;
- `decision_recorded=false`.

It must not:

- grant approval;
- reuse prior authorization as current authority;
- accept a signature;
- validate a signature;
- persist a receipt;
- create a writer file;
- write ledger events;
- record a decision;
- merge;
- dispatch work.

## Required Upstream Contract

The new cycle request template depends on the fresh authorization health
decision template.

If the health decision template is not ready, this surface must return:

```text
blocked_before_writer_release_fresh_authorization_health_decision_template
```

## Full Restart Requirements

A future new cycle must require new artifacts for:

- authorization request;
- receipt draft;
- signature request;
- signed receipt template;
- execution contract preflight;
- execution contract;
- disable contract;
- observability contract;
- post-monitoring review.

## Reuse Is Forbidden

The request must explicitly forbid reuse of previous:

- authorization request hash;
- receipt draft hash;
- signature request hash;
- signed receipt hash;
- execution contract hash;
- observability contract hash.

## Required Evidence

A future new cycle request must include:

- health decision hash;
- selected health decision state;
- request actor identity;
- request rationale;
- previous cycle summary hash;
- previous cycle failure or watch result hash;
- human reviewer identity.

## Future Outputs

This template may describe future output names only:

- new cycle request hash;
- new cycle authorization request hash;
- new cycle receipt draft hash;
- new cycle signature request hash.

None of these outputs are persisted by this command.

## Authorization Request Handoff

If a future new cycle request is selected, the next surface is the new cycle
authorization request template. That surface starts the fresh chain again, but
still cannot grant approval, accept signatures, persist receipts, create writer
files or execute work.

## Human Meaning

This surface answers:

```text
How would Atlas ask to restart fresh authorization from zero after a health decision?
```

It does not answer:

```text
Can Atlas approve, reuse old authority, sign, persist, create writer files or merge anything now?
```

The answer remains no. This template only defines the future restart request
shape.
