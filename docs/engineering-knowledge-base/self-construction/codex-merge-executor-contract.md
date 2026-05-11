---
id: atlas-ai-self-construction-codex-merge-executor-contract
type: engineering_knowledge
title: Atlas Self-Construction Codex Merge Executor Contract
status: active
category: architecture
priority: 100
summary: Contract for read-only executor release, future executor and execution receipt templates.
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
  - Executor release preflights may define executor prerequisites, but must not accept persisted receipt evidence or release execution.
  - Executor contract templates may define future executor behavior, but must not execute patches or merge.
  - Execution receipt templates may define post-execution evidence, but must not execute patches, record execution or merge.
  - Future executors must consume persisted signed final receipts only.
  - Future executors must stop on any hash, scope, diff, documentation, architecture or test mismatch.
  - Future merges require a persisted execution receipt plus post-execution gates.
maintenance:
  - Update before adding any surface that applies patches from a signed final receipt.
related_paths:
  - docs/engineering-knowledge-base/self-construction/codex-merge-signed-final-receipt-contract.md
  - docs/engineering-knowledge-base/self-construction/codex-merge-authorizing-action-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
---

# Atlas Self-Construction Codex Merge Executor Contract

This contract governs the read-only surfaces that prepare a future merge
executor. It starts after signed final receipt persistence is defined.

The release preflight command is:

```bash
php artisan atlas:ai:self-construction --codex-review-merge-executor-release-preflight --json
```

The executor contract template command is:

```bash
php artisan atlas:ai:self-construction --codex-review-merge-executor-contract-template --json
```

The execution receipt template command is:

```bash
php artisan atlas:ai:self-construction --codex-review-merge-execution-receipt-template --json
```

## Boundary

These surfaces may define a future executor, but must keep:

- `execution_allowed=false`;
- `patch_execution_allowed=false`;
- `patch_executed=false`;
- `execution_recorded=false`;
- `executor_allowed=false`;
- `merge_allowed=false`;
- `receipt_persisted=false`;
- `ledger_write_allowed=false`;
- `dispatch_allowed=false`.

They must not:

- accept persisted receipt evidence;
- persist receipts;
- record decisions;
- release an executor;
- execute patches;
- record execution;
- merge;
- dispatch work.

## Release Preflight

The release preflight defines what must be externally proven before an executor
can even be considered.

It requires:

- persisted signed final receipt id;
- persisted signed final receipt hash;
- append-only receipt event hash;
- executor contract hash;
- final diff check hash;
- hot-scope check hash;
- docs health hash;
- architecture validation hash;
- focused test matrix hash;
- human executor release confirmation hash.

It blocks on:

- missing persisted signed final receipt;
- missing append-only receipt event;
- receipt source hash mismatch;
- executor contract hash mismatch;
- final diff failure;
- hot Voice or Kernel scope touch;
- docs health failure;
- architecture validation failure;
- focused test matrix failure;
- missing human executor release confirmation.

## Executor Contract Template

The executor contract template describes how a future executor must behave.

It may become ready only after executor release preflight is ready.

It must require the future executor to revalidate:

- persisted signed final receipt hash;
- executor release authority hash;
- final diff check;
- hot-scope check;
- docs health;
- architecture validation;
- focused test matrix;
- rollback plan availability.

It may define these future executor actions:

- read persisted signed final receipt;
- read current diff;
- read scope validator report;
- read gate reports;
- apply only the receipt-bound patch set;
- emit executor evidence receipt;
- stop on any mismatch.

It must forbid:

- modifying Voice or Kernel hot scope without a new receipt;
- expanding scope beyond the signed receipt;
- skipping final diff, hot-scope, docs, architecture or tests;
- merging without post-execution receipt;
- dispatching new packets.

## Execution Receipt

A future executor must emit a receipt with:

- executor run id;
- source executor contract hash;
- source executor release preflight hash;
- source persisted signed final receipt hash;
- applied patch hash;
- files changed;
- final diff check hash;
- hot-scope check hash;
- docs health hash;
- architecture validation hash;
- focused test matrix hash;
- rollback plan hash;
- executor identity;
- execution timestamp.

The read-only execution receipt template defines the evidence shape before that
future executor exists.

It may become ready only after the executor contract template is ready.

It must require:

- pre-execution diff hash;
- post-execution diff hash;
- applied patch hash;
- files changed;
- commands run;
- focused test matrix hash;
- docs health hash;
- architecture validation hash;
- hot-scope check hash;
- rollback plan hash;
- execution start and completion timestamps.

It must block future merge if:

- applied patch hash does not match the receipt-bound patch set;
- changed files are outside signed receipt scope;
- hot-scope check fails after execution;
- docs health fails after execution;
- architecture validation fails after execution;
- focused tests fail after execution;
- rollback plan is missing after execution.

It must keep `patch_executed=false`, `execution_recorded=false`,
`receipt_persisted=false` and `merge_allowed=false`.

It must not execute patches, record execution, persist execution receipts, merge
or dispatch work.

## Principle

The executor is the first place where patch execution could eventually happen.
Everything before it exists to make that execution narrow, receipt-bound,
reversible, observable and stoppable.
