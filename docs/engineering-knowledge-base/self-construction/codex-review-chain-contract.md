---
id: atlas-ai-self-construction-codex-review-chain-contract
type: engineering_knowledge
title: Atlas Self-Construction Codex Review Chain Contract
status: active
category: architecture
priority: 100
summary: Contract for the non-executing review, signature and merge-action chain after Codex or provider packet flows complete.
tags:
  - atlas-ai
  - self-construction
  - codex-review
  - governance
capabilities:
  - self_construction_os
  - parallel_ai
  - review_governance
decisions:
  - Review commands may package evidence and templates, but must not approve code.
  - Signature commands may prepare signable payloads, but must not validate or infer a signature.
  - Merge templates may define required fields, but must not merge, dispatch or grant approval.
  - Merge preflight may aggregate readiness, but must not validate signature, approve, merge or dispatch.
  - Merge action drafts are governed by `codex-merge-action-draft-contract.md`.
  - Merge authorization and final authorization preflight are governed by `codex-merge-authorization-contract.md`.
  - Approval and merge require a separate explicit human or governed action.
maintenance:
  - Update before adding any command that records a decision, validates a signature or performs a merge.
related_paths:
  - docs/engineering-knowledge-base/self-construction/multi-provider-agent-orchestration-contract.md
  - docs/engineering-knowledge-base/self-construction/parallel-session-plan-contract.md
  - docs/engineering-knowledge-base/self-construction/codex-merge-action-draft-contract.md
  - docs/engineering-knowledge-base/self-construction/codex-merge-authorization-contract.md
  - docs/engineering-knowledge-base/self-construction/reservation-ledger-contract.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 300
---

# Atlas Self-Construction Codex Review Chain Contract

This contract governs the review chain after the five-packet implementation
flow reaches integration readiness. The first concrete runtime names are
Codex-oriented, but the chain must also accept normalized evidence from Claude,
Gemini, local agents and future providers. It exists to prevent a dangerous
shortcut: treating a ready review artifact, signature request, runbook or merge
template as approval to change the repository.

## Codex Final Review Packet

`--codex-final-review-packet` is the read-only packet handed to the principal
integrator after merge-readiness:

```bash
php artisan atlas:ai:self-construction --codex-final-review-packet --json
```

It must include:

- source merge-readiness hash;
- review status;
- ready packets;
- blocking failures, if any;
- principal integrator checklist;
- required review gates;
- conservative decision slots;
- review boundaries.

Decision slots are intentionally conservative. The default must be
`request_changes` or equivalent, never approval. This command must not persist
the decision. A future governed receipt or explicit human review surface must
record the decision separately.

Principal integrator checklist:

- confirm every provider final response names packet, provider and reservation;
- confirm provider-specific evidence was normalized to the universal schema;
- confirm each packet diff only touches allowed files;
- confirm evidence hashes match reported final evidence;
- run all required review gates;
- inspect documentation for contract drift;
- verify hot Voice/Kernel scopes were not modified by Self-Construction;
- write a human review decision before merge.

## Codex Review Decision Template

`--codex-review-decision-template` is the read-only decision form for the
principal integrator:

```bash
php artisan atlas:ai:self-construction --codex-review-decision-template --json
```

It may include `approve_for_merge` as an allowed human decision value, but the
template itself must keep:

- `decision_recording_allowed=false`;
- `approval_granted=false`;
- `merge_allowed=false`;
- `dispatch_allowed=false`.

The default decision must remain conservative, normally `request_changes`.
Approval requires a separate signed receipt or explicit human review surface.

Required inputs:

- principal integrator name;
- review timestamp;
- selected decision;
- scope integrity result;
- evidence integrity result;
- gates run with outputs;
- files reviewed;
- decision rationale;
- remaining risks.

The template must define safe defaults:

- if any precondition is missing, request changes;
- if scope violation is found, reject or request changes;
- if evidence is missing, request changes;
- if hot scope is touched, reject.

## Codex Review Receipt Draft

`--codex-review-receipt-draft` is the unsigned receipt draft generated from the
decision template:

```bash
php artisan atlas:ai:self-construction --codex-review-receipt-draft --json
```

It must bind:

- decision template hash;
- final review packet hash;
- default decision;
- required signer roles;
- required inputs;
- approval preconditions;
- verification commands;
- non-authorizing invariants.

The receipt draft must keep:

- `signature_required=true`;
- `signature_valid=false`;
- `decision_recorded=false`;
- `approval_granted=false`;
- `merge_allowed=false`.

This command prepares audit structure only. A future signed receipt or explicit
human review surface must complete and record the actual decision.

## Codex Review Signature Request

`--codex-review-signature-request` is the read-only signature request for the
review receipt draft:

```bash
php artisan atlas:ai:self-construction --codex-review-signature-request --json
```

It must produce a signable payload containing:

- receipt id;
- receipt hash;
- decision template hash;
- final review packet hash;
- requested signature type;
- allowed human decisions;
- required signer roles;
- required inputs;
- approval preconditions;
- verification commands;
- scopes still forbidden after signature.

It must keep:

- `signature_present=false`;
- `signature_valid=false`;
- `decision_recorded=false`;
- `approval_granted=false`;
- `merge_allowed=false`.

The signature request is not a signature. Even a future valid signature must not
authorize automatic merge unless a separate explicit merge action or governed
receipt allows it.

## Codex Review Post-Signature Runbook

`--codex-review-post-signature-runbook` is the conditional runbook for what the
principal integrator must do after a future valid signature exists:

```bash
php artisan atlas:ai:self-construction --codex-review-post-signature-runbook --json
```

It must define:

- source signature request hash;
- source signable payload hash;
- signature requirements;
- post-signature steps;
- evidence required for each step;
- commands to rerun;
- conditions required before any merge action;
- actions still forbidden after signature.

It must keep:

- `signature_valid=false`;
- `approval_granted=false`;
- `merge_allowed=false`;
- `auto_merge_allowed=false`.

The runbook is not a validator and not an executor. It is a checklist for a
future signed/governed path.

## Codex Review Merge Action Template

`--codex-review-merge-action-template` is the non-executing form for a future
explicit merge action after a valid signed/governed review path exists:

```bash
php artisan atlas:ai:self-construction --codex-review-merge-action-template --json
```

It must define:

- source post-signature runbook hash;
- source signature request hash;
- source signable payload hash;
- required inputs for a future merge decision;
- allowed future decisions;
- default decision;
- merge boundaries;
- commands to rerun before any merge.

It must keep:

- `signature_valid=false`;
- `approval_granted=false`;
- `merge_allowed=false`;
- `auto_merge_allowed=false`;
- `execution_allowed=false`.

The merge action template is not the merge action. It does not validate a
signature, it does not record approval, and it does not merge. Its job is to
make the later explicit action precise enough that a human or governed executor
cannot confuse a prepared template with permission to change the repository.

## Codex Review Merge Preflight

`--codex-review-merge-preflight` is the final read-only readiness aggregator
before a future explicit merge action:

```bash
php artisan atlas:ai:self-construction --codex-review-merge-preflight --json
```

It must aggregate:

- execution status;
- integration report;
- merge readiness;
- final review packet;
- signature request;
- post-signature runbook;
- merge action template.

It may report `ready_for_explicit_merge_action_review` only when the review
chain is structurally ready. Even then, it must keep:

- `signature_valid=false`;
- `approval_granted=false`;
- `merge_allowed=false`;
- `dispatch_allowed=false`;
- `execution_allowed=false`.

The preflight is not evidence of a valid signature. It only says the Atlas
review chain is ready for a separate explicit governed action that supplies
external signature evidence, human decision, fresh gates, clean scope result and
verified evidence integrity.

## Codex Review Merge Action Draft

`--codex-review-merge-action-draft` is governed by
`codex-merge-action-draft-contract.md`. This chain contract owns only the
handoff rule: the draft may become structurally ready after merge preflight, but
it must still keep signature validation, approval, dispatch and merge disabled.

## Completion Criteria

This contract is complete when every review-chain command can be refreshed
repeatedly, returns deterministic hashes, exposes conservative defaults, and
keeps claim, completion, approval, signature validation, dispatch and merge
disabled until a separate explicit governed action exists.
