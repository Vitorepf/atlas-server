---
id: atlas-ai-self-construction-parallel-session-plan-contract
type: engineering_knowledge
title: Atlas Self-Construction Parallel Session Plan Contract
status: active
category: architecture
priority: 100
summary: Contract for planning up to five parallel AI sessions from the read-only packet queue.
tags:
  - atlas-ai
  - self-construction
  - parallel-sessions
  - packet-queue
capabilities:
  - self_construction_os
  - packet_queue
  - parallel_ai
decisions:
  - Parallel sessions require a plan before durable reservation or execution.
  - The plan may assign session slots in preview only, never persist claims.
  - `--codex-launch-plan` may emit ready-to-run Codex start commands, but it must not claim packets or start sessions.
  - `--codex-execution-status` may monitor active/completed sessions, but it must not mutate reservations.
  - `--codex-integration-report` may consolidate completed packet evidence, but it must not approve code, merge, dispatch or complete work.
  - `--codex-merge-readiness` may decide whether the completed packet set is ready for human/governed merge review, but it must not grant merge approval.
  - `--codex-final-review-packet` may package the principal integrator checklist and decision slots, but it must not record approval.
  - `--codex-review-decision-template` may template the principal integrator decision, but it must not persist or infer that decision.
  - `--codex-review-receipt-draft` may produce a hash-bound unsigned review receipt draft, but it must not sign, approve or merge.
  - `--codex-review-signature-request` may prepare the signable review payload, but it must not present, validate or infer a signature.
  - `--codex-review-post-signature-runbook` may define conditional post-signature steps, but it must not validate a signature or enable merge.
  - `--codex-review-merge-action-template` may template a future explicit merge action, but it must not validate a signature, record approval, merge or dispatch.
  - Blocked and withheld work must remain visible to prevent unsafe overlap.
maintenance:
  - Update before adding durable slot reservation, live dispatch or auto-execution.
related_paths:
  - docs/engineering-knowledge-base/self-construction/packet-queue-contract.md
  - docs/engineering-knowledge-base/self-construction/reservation-ledger-contract.md
  - docs/engineering-knowledge-base/self-construction/ai-session-bootstrap-contract.md
  - docs/engineering-knowledge-base/self-construction/codex-review-chain-contract.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 300
---

# Atlas Self-Construction Parallel Session Plan Contract

Parallel Session Plan is the read-only plan for running multiple AI sessions
without making them collide.

## Purpose

It must define:

- maximum session slots;
- which packet each slot may preview;
- which slots are idle because dependencies block work;
- withheld hot work;
- per-slot packet-scoped bootstrap command;
- per-slot Codex start command;
- per-slot actor and session id;
- collision policy;
- session plan hash.

## Non Goals

- Do not start sessions.
- Do not claim packets.
- Do not write reservations.
- Do not dispatch work automatically.
- Do not allow overlapping write scopes.

## Slot Schema

```json
{
  "slot_id": "SESSION-SLOT-001",
  "state": "preview_assignable|blocked|withheld|idle",
  "packet_id": "AIP-SPLIT-...",
  "bootstrap_command": "php artisan atlas:ai:self-construction --ai-session-bootstrap --packet=AIP-SPLIT-... --json",
  "execution_allowed": false,
  "claim_persisted": false
}
```

## Five-Slot Preview Contract

When five disjoint cold-lane packets are available, the plan must fill all five
slots with `preview_assignable` packets and `idle_slot_count=0`. Each slot must
carry its own `--packet=` bootstrap and scope-validator commands so five Codex
sessions can start from the same human prompt while still receiving different
packet scopes.

## Codex Launch Plan

`--codex-launch-plan` is the operator-facing launch surface for opening up to
five Codex sessions:

```bash
php artisan atlas:ai:self-construction --codex-launch-plan --json
```

It must emit one `--codex-start-packet` command per launchable slot, with
deterministic actor/session names. The launch plan itself must not claim
packets. Each fresh Codex session claims its packet only when its own start
command runs.

## Codex Execution Status

`--codex-execution-status` is the read-only monitor for parallel work:

```bash
php artisan atlas:ai:self-construction --codex-execution-status --json
```

It must aggregate queue state, reservation status and launch plan state, then
report active sessions, completed packets, launchable commands and the next
operator action. It must not claim, release, complete, dispatch or start work.

## Codex Integration Report

`--codex-integration-report` is the read-only handoff surface for the principal
operator after one or more Codex sessions complete packets:

```bash
php artisan atlas:ai:self-construction --codex-integration-report --json
```

It must consolidate:

- completed packet ids;
- lane and objective;
- completion actor and reservation id;
- completion evidence hash;
- packet allowed files;
- missing or still-active packets;
- withheld hot work;
- integrator commands and review sequence.

Completion in the reservation ledger is only state. It is not approval. The
integration report must therefore keep these boundaries explicit:

- packet completion does not approve code;
- integration report does not grant merge authority;
- human or governed receipt review is still required;
- hot Voice/Kernel scopes remain withheld.

The integrator sequence is:

1. Refresh `--codex-execution-status`.
2. Review each completed session final response contract.
3. Verify the evidence hash reported at completion.
4. Run packet-scoped validators where files changed.
5. Run focused Self-Construction tests.
6. Run docs-health, architecture-validate and diff check.
7. Prepare a human summary before any merge or approval.

## Codex Merge Readiness

`--codex-merge-readiness` is the read-only gate for the principal operator after
parallel sessions report completion:

```bash
php artisan atlas:ai:self-construction --codex-merge-readiness --json
```

It may return `ready_for_human_merge_review` only when:

- no Codex session is active;
- no assignable packet is missing completion;
- every completed packet has a completion evidence hash;
- the integration report can list all ready packets.

Even when ready, the gate must keep these boundaries:

- merge readiness is not merge approval;
- packet completion is not code approval;
- human review or signed governed receipt is required;
- hot Voice/Kernel scopes remain out of scope.

Required review gates before any merge decision:

- `php artisan atlas:ai:self-construction --codex-execution-status --json`
- `php artisan atlas:ai:self-construction --codex-integration-report --json`
- `php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php`
- `php artisan atlas:engineering:knowledge docs-health --json`
- `php artisan atlas:ai:architecture-validate --json`
- `git diff --check`

## Codex Review Chain

The principal integrator chain is governed by
`codex-review-chain-contract.md`. The parallel plan owns the handoff point only:
after all assignable packets are completed and merge-readiness is true, Atlas
may emit read-only review packets, decision templates, receipt drafts,
signature requests, post-signature runbooks and merge-action templates.

Every review-chain surface must keep approval, signature validation, dispatch
and merge disabled until a separate explicit human or governed action exists.

## Rules

- One packet may appear in at most one preview-assignable slot.
- Dependency-blocked packets must not be assigned.
- Withheld hot work must never be assignable.
- Empty slots are allowed when safe work is unavailable.
- Durable execution requires a future reservation ledger AP.
- Launch plan commands must be safe to paste into fresh sessions independently.
- Execution status must be safe to refresh repeatedly while sessions are active.
- Integration report must be safe to refresh repeatedly and must not mutate ledger state.
- Merge readiness must never set `merge_allowed=true` or `approval_granted=true`.
- Final review packet must never persist, imply or grant approval.
- Review decision template must never persist, infer or grant the selected decision.
- Review receipt draft must remain unsigned and non-authorizing.
- Review signature request must never claim a signature is present or valid.
- Post-signature runbook must never validate signature, approve or merge.
- Review merge action template must never validate signature, record approval, merge or dispatch.

## Completion Criteria

This contract is complete when Atlas emits a deterministic plan for up to five
AI sessions, monitors active work, and consolidates completed packet evidence
while keeping dispatch, execution, approval and merge disabled.
