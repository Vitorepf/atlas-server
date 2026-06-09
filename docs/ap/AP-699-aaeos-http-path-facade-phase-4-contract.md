# AP-699 AAEOS HTTP Path Facade Phase 4 Contract

Status: implemented
Owner: atlas-ai
Area: aaeos-http-path
Risk: high

## Problem

AP-696/AP-697/AP-698 delivered envelopes P0..P6 in the canonical AAEOS
HTTP path facade. T1.4 Phase 4 requires P7 (spec), P8 (tasks) and
P9 (receipt) to be present BEFORE the provider call, plus a future
refactor of `AiWorker` into a thin delegator. This AP delivers the
ENVELOPE emission contract for Phase 4. The `AiWorker` LOC reduction
target (-60%) is tracked under a separate follow-up AP (AP-799 future)
because the refactor surface is far broader than the HTTP path facade.

## Goal

Extend `AtlasAaeosHttpPathFacadeService` to emit P7 spec, P8 tasks and
P9 receipt envelopes when configured phase is `4`. Consistent with
AP-698, synchronous invocation of Spec OS / Work Splitter / Decision
Receipt v2 is DEFERRED to async workers to preserve p95 latency. The
envelopes are canonical declarations that the downstream pipeline must
complete those phases.

- R1-R2 fast-path → canonical skips with reason
  `r1_r2_fast_path_preserved`.
- R3+ → P7 declares `spec_invocation: deferred`; P8 declares
  `task_pack_invocation: deferred`; P9 declares
  `decision_receipt_v2_invocation: deferred` with `receipt_required: yes`.
- Phase 1/2/3 behavior preserved (regression guards in tests).
- Counter `phases_executed_count` is exposed in patched payload so
  downstream services can detect "completed phases vs deferred phases"
  without re-parsing every envelope.

## Non Goals

- Not refactoring AiWorker into thin delegator (separate AP).
- Not running Spec OS / Work Splitter / Decision Receipt v2 synchronously.
- Not changing the legacy productive pipeline behavior in `legacy` mode.

## Implementation Note

As of 2026-06-09, P7/P8/P9 envelope emission is implemented in
`AtlasAaeosHttpPathFacadeService`; repeated envelope construction lives in
`AaeosHttpPathEnvelopeFactory`. The facade remains the compatibility
coordinator for phase flag, placement cache, blocking and telemetry.

## Overlap Decision

| Candidate | Decision |
|---|---|
| Spec OS services | Reuse, deferred invocation. |
| Work Splitter services | Reuse, deferred invocation. |
| Decision Receipt v2 emit services | Reuse, deferred invocation. |
| `AiWorker.php` | NOT touched in this AP; future AP-799 handles thin-delegator refactor. |

## Required Docs

- `docs/engineering-knowledge-base/atlas-aaeos-http-path-integration-spec.md`
- AP-696/AP-697/AP-698 (predecessors)
- `docs/ap/AP-699-aaeos-http-path-facade-phase-4-contract.md` (this AP)

## Acceptance Criteria

- Phase 4 emits 10 envelopes total (P0..P9).
- R1-R2 → P7/P8/P9 canonical skips.
- R3+ → P7/P8/P9 declared deferred with `receipt_required: yes` on P9.
- Phase 3 still emits 7 envelopes (regression guard).
- Phase 2 still emits 5 envelopes (regression guard).
- Phase 1 still emits 3 envelopes (regression guard).
- Legacy still emits 0 envelopes (regression guard).
- `php artisan atlas:aaeos http-path-status --json` includes
  `configured_phase: "4"` and reports counters.

## Rollback

`atlas.aaeos.http_path_phase = 3` reverts Phase 4 work.

## Risks

- **Medium**: deferred invocation means actual receipt is not emitted
  synchronously. Mitigation: P9 envelope sets `receipt_required: yes`;
  downstream async receipt worker MUST honor this within the SLO.
- **Low**: ten envelopes per request increases payload size. Mitigation:
  envelopes are short structured records; total overhead <2KB per request.

## What This AP Is NOT

- Not the AiWorker refactor.
- Not synchronous Spec OS / Work Splitter / Receipt invocation.
- Not Phase 5+ (Phase 5 doesn't exist; canonical migration is 4 phases).
