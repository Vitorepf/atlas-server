# AP-698 AAEOS HTTP Path Facade Phase 3 Contract

Status: proposed
Owner: atlas-ai
Area: aaeos-http-path
Risk: high

## Problem

AP-696 / Phase 1 and AP-697 / Phase 2 delivered envelopes for
P0..P4 (intent_capture, disambiguation, placement, classification,
policy_gate). Phase 3 of T1.4 requires P5 (topology) and P6 (routing)
to be canonical on the HTTP path. The current pipeline does not emit
either envelope. For Atlas Dev fast-path A1 (R1-R2 risk band), the
spec mandates that the fast-path is PRESERVED — only R3+ requests
must traverse AAWR (Atlas Agentic Workcell Runtime) and Atlas Decide
for topology selection and Company Runtime routing.

## Goal

Extend `AtlasAaeosHttpPathFacadeService` to emit P5 topology and
P6 routing envelopes whenever the configured phase is `3` or `4`.
Phase 3 does NOT invoke the AAWR `design()` runtime synchronously
(deferred to AP-700 family to keep p95 latency under +20%). Instead
it records a CANONICAL `topology_required` marker that downstream
async workers can pick up. For R1-R2 tasks, the envelope is a
justified skip with reason `r1_r2_fast_path_preserved`.

- Emit P5 topology envelope for all Phase 3+ requests.
- Emit P6 routing envelope for all Phase 3+ requests.
- Classify R1-R2 vs R3+ from existing `payload.atlas_ai_router.command_intent`
  and `payload.routing_task`:
  - `command_intent in {dev, debug, review, repair}` → R1-R2 (fast-path).
  - `command_intent in {plan, forge}` or `routing_task in {plan, forge}`
    → R3+ (requires topology).
  - Default unknown → R1-R2 (conservative fast-path).
- Phase 1/2 behavior preserved.

## Non Goals

- Not invoking AAWR `design()` synchronously (latency).
- Not invoking AtlasDecide `decide()` synchronously (latency).
- Not refactoring Company Runtime route resolution.
- Not Phase 4 (AiWorker thin delegator) work.

## Overlap Decision

| Candidate | Decision |
|---|---|
| `app/Services/Ai/AgenticWorkcell/AtlasAgenticWorkcellRuntimeService.php` | Reuse: facade emits topology marker; actual runtime invocation deferred. |
| `app/Services/Ai/AtlasDecide/` services | Reuse: facade does not invoke directly in Phase 3. |
| `app/Services/Ai/Router/AtlasAiRouterService.php` | Reuse: Phase 3 reads command_intent already attached. |

## Required Docs

- `docs/engineering-knowledge-base/atlas-aaeos-http-path-integration-spec.md`
- `docs/ap/AP-696-aaeos-http-path-facade-phase-1-contract.md`
- `docs/ap/AP-697-aaeos-http-path-facade-phase-2-contract.md`
- `docs/ap/AP-698-aaeos-http-path-facade-phase-3-contract.md` (this AP)

## Acceptance Criteria

- Phase 3 emits 7 envelopes total (P0..P6).
- R1-R2 classified requests emit P5/P6 as canonical skips with reason
  `r1_r2_fast_path_preserved` (skip envelopes carry their own
  `skip_reason` field; never silent).
- R3+ classified requests emit P5 envelope with output
  `topology_required: yes` and `aawr_invocation: deferred`.
- Phase 1 still emits 3 envelopes; Phase 2 still emits 5 envelopes
  (regression guards).
- Unit tests cover R1-R2 path + R3+ path + Phase 2 regression.
- `php artisan atlas:engineering:knowledge docs-health` exits 0 for
  AAEOS-owned docs (pre-existing genesis-os violations stay flagged
  but are not in this AP's scope).

## Rollback

`atlas.aaeos.http_path_phase = 2` reverts Phase 3 work.

## Risks

- **Medium**: Without synchronous AAWR call, Phase 3 P5 envelope is
  "intent declared" rather than "topology resolved". Mitigation:
  envelope output explicitly says `aawr_invocation: deferred`; downstream
  AAWR worker can read this and complete the topology resolution later.
- **Low**: R1-R2 vs R3+ classification heuristic is rough. Mitigation:
  conservative default to R1-R2 preserves fast-path; can be refined in
  follow-up APs without breaking the envelope schema.

## What This AP Is NOT

- Not the T1.4 canonical spec.
- Not Phase 4.
- Not synchronous AAWR or Atlas Decide execution.
