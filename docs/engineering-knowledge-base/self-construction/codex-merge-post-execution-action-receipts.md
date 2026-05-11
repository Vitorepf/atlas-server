---
id: atlas-ai-self-construction-codex-merge-post-execution-action-receipts
type: engineering_knowledge
title: Atlas Self-Construction Codex Merge Post-Execution Action Receipts
status: active
category: architecture
priority: 100
summary: Contract for read-only post-execution merge action receipt drafts and signature requests.
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
  - Post-execution merge action receipt drafts may bind hashes and decisions, but must remain unsigned and non-authorizing.
  - Post-execution merge action signature requests may prepare signable payloads, but must not accept, validate, persist or authorize signatures.
  - Post-execution merge action post-signature runbooks may sequence external evidence checks, but must not accept, validate, persist or authorize signatures.
maintenance:
  - Update before adding any surface that accepts or validates a post-execution merge action signature.
related_paths:
  - docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-contract.md
  - docs/engineering-knowledge-base/self-construction/codex-merge-executor-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 200
---

# Atlas Self-Construction Codex Merge Post-Execution Action Receipts

This contract governs read-only receipt and signature-request surfaces for a
future Codex merge action after execution evidence exists.

The action receipt draft command is:

```bash
php artisan atlas:ai:self-construction --codex-review-merge-post-execution-action-receipt-draft --json
```

The action signature request command is:

```bash
php artisan atlas:ai:self-construction --codex-review-merge-post-execution-action-signature-request --json
```

The action post-signature runbook command is:

```bash
php artisan atlas:ai:self-construction --codex-review-merge-post-execution-action-post-signature-runbook --json
```

## Boundary

These surfaces may prepare a receipt and a signable payload, but must keep:

- `execution_allowed=false`;
- `ledger_write_allowed=false`;
- `dispatch_allowed=false`;
- `approval_granted=false`;
- `merge_allowed=false`;
- `receipt_signed=false`;
- `signature_valid=false`;
- `receipt_persisted=false`.

They must not:

- accept signatures;
- validate signatures;
- approve code;
- persist receipts;
- merge;
- dispatch work.

## Action Receipt Draft

The action receipt draft binds the future merge action to hashes and decision
fields while remaining unsigned.

It may become ready only after the post-execution action template is ready.

It must default to:

- `do_not_merge`.

It must require signature before any future merge can proceed.

It must bind:

- post-execution action template hash;
- post-execution preflight hash;
- execution receipt template hash;
- executor contract template hash;
- final receipt hash;
- selected decision;
- merge candidate hash;
- persisted execution receipt hash;
- human post-execution confirmation hash.

It must require decision fields for:

- selected decision;
- decision rationale;
- merge candidate hash;
- persisted execution receipt hash;
- post-execution gate report hash;
- human post-execution confirmation hash;
- merge operator identity.

It must still forbid:

- signature acceptance;
- signature validation;
- approval;
- merge;
- receipt persistence;
- dispatch.

## Action Signature Request

The action signature request prepares the signable payload for the future
post-execution merge action receipt.

It may become pending only after the action receipt draft is ready.

It must include:

- action receipt hash;
- post-execution action template hash;
- post-execution preflight hash;
- execution receipt template hash;
- executor contract template hash;
- final receipt hash;
- required authority inputs;
- required action validations;
- required decision fields.

It must require external fields:

- final merge action signature value;
- signature validator identity;
- signature validation timestamp;
- append-only signed action receipt persistence proof.

It must still forbid:

- signature acceptance;
- signature validation;
- approval;
- merge;
- receipt persistence;
- dispatch.

## Action Post-Signature Runbook

The action post-signature runbook sequences the evidence checks a future
validator must perform after external signature evidence exists.

It may become ready only after the action signature request is pending.

It must require external evidence for:

- final merge action signature value;
- signature validator identity;
- signature validation timestamp;
- signed action receipt persistence event hash.

It must sequence:

- collect external signature evidence;
- verify signature request hash matches signable payload;
- verify action receipt hash matches signed payload;
- verify required authority inputs are present;
- verify required action validations are present;
- prepare signed action receipt persistence candidate;
- stop before signature acceptance or merge.

It must still forbid:

- signature acceptance;
- signature validation;
- decision recording;
- approval;
- merge;
- receipt persistence;
- dispatch.

## Principle

The signature-request surface is only a payload builder. A later, separate
surface must validate external signature evidence and persist any signed receipt.
