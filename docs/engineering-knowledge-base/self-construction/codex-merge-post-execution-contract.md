---
id: atlas-ai-self-construction-codex-merge-post-execution-contract
type: engineering_knowledge
title: Atlas Self-Construction Codex Merge Post-Execution Contract
status: active
category: architecture
priority: 100
summary: Contract for read-only post-execution merge preflight and future merge action templates.
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
  - Post-execution merge preflights may define merge prerequisites, but must not accept execution receipt evidence or merge.
  - Post-execution merge action templates may define a future merge action, but must not approve, merge or dispatch work.
  - Post-execution merge action receipt drafts may bind hashes and decisions, but must remain unsigned and non-authorizing.
  - Post-execution merge action signature requests may prepare a signable payload, but must not accept, validate, persist or authorize it.
  - Future merge actions must consume persisted execution receipts only.
  - Future merge actions must revalidate post-execution diff, gates, hot-scope and human confirmation.
maintenance:
  - Update before adding any surface that performs a real merge after execution.
related_paths:
  - docs/engineering-knowledge-base/self-construction/codex-merge-executor-contract.md
  - docs/engineering-knowledge-base/self-construction/codex-merge-signed-final-receipt-contract.md
  - docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-receipts.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 200
---

# Atlas Self-Construction Codex Merge Post-Execution Contract

This contract governs the read-only preflight that sits after a future executor
has produced a persisted execution receipt.

The command is:

```bash
php artisan atlas:ai:self-construction --codex-review-merge-post-execution-preflight --json
```

The action template command is:

```bash
php artisan atlas:ai:self-construction --codex-review-merge-post-execution-action-template --json
```

The action receipt and signature commands are governed by:

- `docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-receipts.md`.

## Boundary

These surfaces may define future merge prerequisites and a future merge action,
but must keep:

- `execution_allowed=false`;
- `execution_receipt_persisted=false`;
- `approval_granted=false`;
- `merge_allowed=false`;
- `ledger_write_allowed=false`;
- `dispatch_allowed=false`.

They must not:

- accept execution receipt evidence;
- persist execution receipts;
- approve code;
- merge;
- dispatch work.

## Required Inputs

A future merge surface must provide:

- persisted execution receipt id;
- persisted execution receipt hash;
- append-only execution receipt event hash;
- post-execution diff hash;
- post-execution gate report hash;
- human post-execution confirmation hash;
- merge candidate hash.

## Required Checks

The future merge surface must prove:

- execution receipt template is ready;
- persisted execution receipt exists;
- persisted execution receipt hash is verified;
- append-only execution receipt event exists;
- execution receipt sources match templates;
- post-execution diff matches receipt;
- post-execution gates passed;
- hot-scope is clean after execution;
- docs health is clean after execution;
- architecture validation is clean after execution;
- focused tests are clean after execution;
- human post-execution confirmation exists.

## Blocking Conditions

The future merge surface must block on:

- missing persisted execution receipt;
- missing append-only execution receipt event;
- execution receipt source hash mismatch;
- post-execution diff mismatch;
- post-execution gate failure;
- hot-scope failure after execution;
- docs health failure after execution;
- architecture validation failure after execution;
- focused tests failure after execution;
- missing human post-execution confirmation.

## Future Merge Action

A future merge action must:

- consume the persisted execution receipt only;
- revalidate post-execution diff;
- revalidate gate hashes;
- revalidate no hot-scope drift;
- require human confirmation hash;
- emit a final merge receipt.

## Action Template

The action template describes a future merge action without authorizing it.

It may become ready only after post-execution preflight is ready.

It must default to:

- `do_not_merge`.

It must require:

- post-execution preflight hash;
- persisted execution receipt hash;
- post-execution gate report hash;
- merge candidate hash;
- human post-execution confirmation hash;
- merge operator identity.

It must validate:

- post-execution preflight is ready;
- persisted execution receipt hash matches preflight;
- merge candidate hash matches post-execution diff;
- post-execution gate report hash matches preflight;
- human post-execution confirmation hash is present;
- no hot-scope drift since preflight;
- no unreviewed diff since preflight.

It may define future action steps:

- read persisted execution receipt;
- read post-execution gate report;
- read merge candidate diff;
- verify merge candidate hashes;
- request final merge confirmation;
- emit unsigned final merge action receipt.

It must still forbid approval, merge, dispatch and final receipt persistence.

## Principle

Execution is not enough. The Atlas Self-Construction OS may only approach merge
after execution evidence is persisted, checked, confirmed and reduced to a
separate merge action contract.
