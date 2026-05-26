# AP-700 Atlas Dev Plan-Visible HTTP Read Model Contract

Status: proposed
Owner: atlas-ai
Area: atlas-dev-plan-visible
Risk: medium

## Problem

Atlas Dev Patamar A2 requires `Plan-Visible` to be addressable from
the Desktop surface so the operator can see the proposed plan before
provider execution. The canonical schema `atlas.dev.plan_visible.v1`
exists and is persisted by `AtlasDevPlanProjectionService::projectAndPersist`.
There is NO HTTP read model that exposes the persisted plan; Desktop
cannot fetch it.

T1.4 ships envelope emission; A2 ships the actual surface access.
This AP delivers the minimum useful HTTP read model: a GET endpoint
that returns the latest persisted Plan-Visible for a work item, with
ETag-based conditional GET so polling is cheap. SSE streaming is OUT
OF SCOPE here — it is reserved for AP-700b (a follow-up that wraps
this read model in a stream). Polling at 2s intervals is sufficient
for the operator's review cadence and avoids the operational
complexity of dedicating a streaming worker per work item.

## Goal

- Add controller `AtlasDevPlanVisibleController` in
  `app/Http/Controllers/Ai/Programming/` with two methods:
  - `show(AtlasProgrammingWorkItem $workItem): JsonResponse` returns
    the persisted Plan-Visible projection with proper ETag header.
  - `index(Request $request): JsonResponse` lists work items with
    persisted plan-visible (limited and filtered for the operator).
- Register routes under `routes/api.php`:
  - `GET /ai/programming/work-items/{workItem}/plan-visible`
  - `GET /ai/programming/plan-visible`
- Returns `atlas.dev.plan_visible.v1` provider-safe payload.
- 404 when no plan-visible persisted for the work item.
- 304 Not Modified when client If-None-Match matches current hash.
- Unit tests: 200 with payload, 404 missing, 304 conditional GET.

## Non Goals

- Not SSE streaming (separate AP-700b).
- Not mutating Plan-Visible from HTTP.
- Not Desktop React surface (AP-702 / surface parity).

## Overlap Decision

| Candidate | Decision |
|---|---|
| `AtlasDevPlanProjectionService` | Reuse: controller uses `loadPersisted()`. |
| `PlanVisible` schema class | Reuse: controller calls `toProviderSafeArray()`. |
| `AiInteractionController::stream` SSE pattern | Reuse: deferred to AP-700b. |

## Required Docs

- `docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md`
- `docs/ap/AP-700-atlas-dev-plan-visible-http-readmodel-contract.md` (this AP)

## Acceptance Criteria

- Controller `AtlasDevPlanVisibleController` exists.
- Routes registered for GET `/ai/programming/work-items/{workItem}/plan-visible`
  and GET `/ai/programming/plan-visible`.
- 200 OK returns `atlas.dev.plan_visible.v1` provider-safe payload with
  `ETag: "<sha256>"` header.
- 404 when no plan-visible persisted.
- 304 when If-None-Match matches.
- Unit test covers all three responses.

## Rollback

Routes can be unregistered without other dependencies.

## Risks

- **Low**: clients may rely on polling cadence. Mitigation: ETag makes
  polling cheap (304 with empty body); typical cadence 2s is fine.

## What This AP Is NOT

- Not SSE streaming (AP-700b future).
- Not React surface code.
