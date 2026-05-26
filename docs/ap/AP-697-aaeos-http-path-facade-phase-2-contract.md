# AP-697 AAEOS HTTP Path Facade Phase 2 Contract

Status: proposed
Owner: atlas-ai
Area: aaeos-http-path
Risk: high

## Problem

AP-696 / T1.4 Phase 1 delivered the canonical facade that emits
`atlas.aaeos.phase.v1` envelopes for P0 (intent_capture),
P1 (disambiguation) and P2 (placement). The productive HTTP path already
runs `AtlasAiRouterService` and a security rejection gate via
`rejectUnsafeAssistedExecution`, but it does NOT emit the canonical
P3 (classification) and P4 (policy_gate) envelopes that prove these
phases were executed. T1.4 Phase 2 requires both envelopes on 100% of
requests when `atlas.aaeos.http_path_phase >= 2`.

## Goal

Extend `AtlasAaeosHttpPathFacadeService` Phase 1 emission to also produce
canonical envelopes for the classification and policy_gate steps when
the configured phase is `2`, `3` or `4`:

- Add `classification` envelope that records the Atlas Router decision
  (flow_id, command_intent, target department) without re-running the
  router. The facade reads the existing decision attached to
  `payload.atlas_ai_router` by the controller before calling the facade.
- Add `policy_gate` envelope that records whether the request passed
  the assisted-execution safety check. The facade reads the existing
  decision attached to `payload.atlas_ai_assisted_execution_quality.status`
  before calling the facade.
- Block 422 `policy_gate_blocked` with full reason and `blocked_when`
  array when the assisted-execution status is not
  `ready_for_assisted_execution` (mirrors the existing
  `rejectUnsafeAssistedExecution` behavior but emits canonical envelope
  first so the block is auditable).
- Phase 1 behavior preserved when configured phase = `1`.
- Phase 2 envelopes never run when configured phase = `legacy`.

## Non Goals

- Not implementing AAWR / Decide for R3+ (Phase 3 / AP-698).
- Not converting AiWorker to thin delegator (Phase 4 / AP-699).
- Not changing AtlasAiRouterService or the security policy rules.

## Overlap Decision

| Candidate | Decision |
|---|---|
| `app/Services/Ai/Router/AtlasAiRouterService.php` | Reuse: facade reads the router decision attached to payload by the controller; does not call it directly. |
| `app/Services/Ai/Product/AtlasAiAssistedExecutionQualityService.php` | Reuse: facade reads the status field attached to payload; does not call it directly. |
| `AiInteractionController::rejectUnsafeAssistedExecution` | Reuse: facade emits the canonical envelope BEFORE this method runs; legacy rejection method continues to enforce the security gate when facade is in legacy mode. |

## Required Docs

- `docs/engineering-knowledge-base/atlas-aaeos-http-path-integration-spec.md`
- `docs/ap/AP-696-aaeos-http-path-facade-phase-1-contract.md`
- `docs/ap/AP-697-aaeos-http-path-facade-phase-2-contract.md` (this AP)

## Acceptance Criteria

- Facade emits a P3 classification envelope with the canonical gate
  `intent_classification_target_department_declared` when configured
  phase >= 2.
- Facade emits a P4 policy_gate envelope with the canonical gate
  `policy_decision_allowed_true` when configured phase >= 2.
- If the assisted-execution status is not
  `ready_for_assisted_execution`, the facade returns
  `RESULT_BLOCKED` with code `policy_gate_blocked` and HTTP 422.
- Unit tests cover: legacy no-op; phase 1 emits 3 envelopes only;
  phase 2 emits 5 envelopes (P0/P1/P2/P3/P4); phase 2 blocks on unsafe
  assisted execution.
- `php artisan atlas:ai:architecture-validate` exits 0.
- `php artisan atlas:engineering:knowledge docs-health` exits 0.

## Rollback

`atlas.aaeos.http_path_phase = legacy` or `= 1` reverts Phase 2 work.

## Risks

- **Medium**: classification envelope reads from `payload.atlas_ai_router`
  which is set BEFORE the facade runs in the controller flow. If router
  decision is missing, facade falls back to `unknown` flow_id and the
  gate is marked blocked. Mitigation: controller integration order
  remains the same — facade still runs after router.
- **Medium**: policy_gate envelope conflicts with the existing
  `rejectUnsafeAssistedExecution`. Mitigation: facade rejects first
  with canonical envelope; legacy method becomes a defense-in-depth.

## What This AP Is NOT

- Not the T1.4 canonical spec.
- Not Phase 3 or Phase 4 work.
- Not a refactor of AtlasAiRouterService or AssistedExecutionQuality.
