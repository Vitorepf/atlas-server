# AP-696 AAEOS HTTP Path Facade Phase 1 Contract

Status: proposed
Owner: atlas-ai
Area: aaeos-http-path
Risk: high

## Problem

Atlas already has the AAEOS service tree (`AaeosPhaseHandoffService`,
`DepartmentContractRuntime`, `AtlasUniversalGatesEvaluator`,
`RunbookOrchestrator`, `AtlasMissionControlCockpitService`,
`AutonomousWorkExecutionOs`) and an `AtlasAaeosCommand` CLI. None of these
run on the productive HTTP path. The HTTP entry point
(`AiInteractionController::store`) already invokes a substantial canonical
pipeline (HyperflowEntry → AtlasAiRouter → ProductDeliveryRuntime →
AssistedExecutionQuality → AtlasDevRuntime → SpecialistFlow → ForgeObra) but
it does NOT emit canonical `atlas.aaeos.phase.v1` envelopes for those
steps, and it never invokes the Place Feature step (`P2 placement`). As a
result the AAEOS 6 services are orphaned in HTTP and the productive path
cannot be audited as a 17-phase canonical sequence.

The canonical spec
`docs/engineering-knowledge-base/atlas-aaeos-http-path-integration-spec.md`
declares a four-phase migration. Phase 1 mandates Place Feature and
optionally Mission Foundation, with a regression gate of
"100% requests carry placement_decision; p95 latency ≤ +20% vs legacy".
No code currently delivers that.

## Goal

Deliver Phase 1 of the canonical T1.4 migration with full governance:

- Introduce feature flag `atlas.aaeos.http_path_phase` with values
  `legacy|1|2|3|4` and default `legacy` (zero risk to existing flows).
- Introduce one new service `AtlasAaeosHttpPathFacadeService` that wraps
  the EXISTING controller pipeline and emits `atlas.aaeos.phase.v1`
  envelopes via `AaeosPhaseHandoffService` for each canonical step,
  plus the missing `P2 placement` step delegated to the existing
  `php artisan atlas:ai:place-feature` runtime contract.
- Integrate the facade in `AiInteractionController::store` behind the
  flag — only the `1+` flag value invokes the facade; `legacy` preserves
  100% of current behavior.
- Add telemetry counters `http_path_phase_active`,
  `http_path_canonical_call_rate`, `http_path_p95_latency_ms`.
- Add `tests/Feature/AaeosHttpPath/Phase1Test.php` covering:
  - flag=legacy preserves current behavior byte-for-byte (regression test).
  - flag=1 emits `atlas.aaeos.phase.v1` envelopes for at least
    `intent_capture` and `placement` phases.
  - flag=1 attaches `placement_decision` to trace metadata when
    `place-feature` returns `status=ok`.
  - flag=1 with `place-feature` blocked surfaces a 422
    `placement_gate_blocked` response instead of silently calling
    provider.
  - flag=1 keeps p95 within +20% of legacy in a sampled smoke
    (assertion: tracked counter exists; absolute number is observability).
- Extend the existing `atlas:aaeos` CLI with a `http-path-status`
  subcommand that returns the active phase, telemetry counters and
  feature-flag value as JSON.

## Non Goals

- Phase 2 (AI Router + Policy gate mandatory) is out of scope; tracked
  under AP-697 future.
- Phase 3 (AAWR + Decide mandatory for R3+) is out of scope; AP-698 future.
- Phase 4 (AiWorker thin delegator) is out of scope; AP-699 future.
- No removal of legacy code paths in this AP.
- No change to Atlas Dev fast-path A1 ergonomics.
- No surface (Desktop/Mobile) UI changes in this AP.

## Overlap Decision

`session-bootstrap` flagged eight high-overlap duplicate candidates. Each
is addressed below — none are superseded; the facade composes with them.

| Candidate | Decision |
|---|---|
| `tests/Feature/AtlasCodeContractTest.php` | Reuse: existing contract test stays. New `Phase1Test` is additive. |
| `tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionOperatorEvidenceSubmissionReadinessTest.php` | Reuse: unrelated to HTTP path; co-exists. |
| `tests/Feature/Ai/Programming/Frontend/AtlasFrontendWorkspaceApiTest.php` | Reuse: unrelated. |
| `app/Services/Engineering/EngineeringCodeIntelligenceService.php` | Reuse: read model, untouched. |
| `app/Services/Ai/Mission/MissionFollowThroughService.php` | Reuse: Mission Mode runtime; facade calls Mission detection optionally (P1 disambiguation). |
| `docs/engineering-knowledge-base/archive/source-material/atlas-ai-memory-context-core-open-brain-full-2026-05-08.md` | Reuse: archive doc only. |
| `docs/engineering-knowledge-base/self-construction/agent-control-plane-contract-part-02.md` | Reuse: unrelated. |
| `tests/Feature/Ai/Vox/AtlasAiVoxControllerTest.php` | Reuse: Vox uses its own controller; not on AAEOS HTTP path. |

## Required Docs

- `docs/engineering-knowledge-base/atlas-aaeos-http-path-integration-spec.md` (T1.4 canonical spec — exists)
- `docs/engineering-knowledge-base/atlas-agentic-engineering-os-runbook.md` (17 phases — exists)
- `docs/engineering-knowledge-base/atlas-agentic-engineering-os.md` (mother doc — exists)
- `docs/ap/AP-696-aaeos-http-path-facade-phase-1-contract.md` (this AP — new)

No new module doc is created. T1.4 spec is the canonical owner doc; this
AP records the implementation contract for its Phase 1 only.

## Acceptance Criteria

- `config/atlas.php` declares `aaeos.http_path_phase` with default `legacy`.
- `AtlasAaeosHttpPathFacadeService` exists at
  `app/Services/Ai/AgenticEngineeringOs/AtlasAaeosHttpPathFacadeService.php`.
- Facade is invoked in `AiInteractionController::store` only when flag
  is in `[1,2,3,4]`; legacy keeps byte-identical behavior.
- Facade emits at minimum two `atlas.aaeos.phase.v1` envelopes per HTTP
  request when flag=1: `intent_capture` and `placement`.
- When `place-feature` returns `status != ok` (gate blocked), facade
  surfaces 422 with `code: placement_gate_blocked` and includes
  `blocked_when` reasons; no provider call happens.
- Telemetry counters incremented per request; readable via
  `php artisan atlas:aaeos http-path-status --json`.
- `tests/Feature/AaeosHttpPath/Phase1Test.php` passes.
- `php artisan atlas:ai:architecture-validate` exits 0.
- `php artisan atlas:engineering:knowledge docs-health` exits 0.

## Rollback

`config/atlas.aaeos.http_path_phase = legacy` (default) instantly
disables the facade in production. No code revert required.

## Risks

- **High**: facade silently changes HTTP response shape. Mitigation:
  the facade only DECORATES the existing response with
  `aaeos_phase_envelopes` metadata; the canonical `trace` field stays
  unchanged. Phase1Test asserts response shape compatibility.
- **High**: `place-feature` adds latency. Mitigation: facade calls
  place-feature with `--cache` mode (existing capability) so identical
  intents reuse decisions; p95 counter tracked.
- **Medium**: telemetry counters drift from reality. Mitigation: the
  status CLI reads counters from the same source the facade writes to;
  divergence is structurally impossible.

## What This AP Is NOT

- Not the canonical T1.4 spec (that exists separately).
- Not the AAEOS mother contract.
- Not a refactor of `AiInteractionController`; only one branch is added.
- Not Phase 2/3/4 work.
