---
title: Constelacao Lens 1 Usage Review
status: implemented_partial
owner: Atlas Surface Plane
line_limit: 220
related_paths:
  - docs/engineering-knowledge-base/atlas-constelacao-surface.md
  - docs/engineering-knowledge-base/atlas-ai-mobile-surface-gateway.md
  - docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md
  - app/Services/Ai/Surface/ConstelacaoPositionsService.php
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php
  - tests/Feature/Ai/AtlasConstelacaoPositionsApiTest.php
---

# AP-685 - Constelacao Lens 1 Usage Review

## 1. Purpose

Make Constelacao Lens 1 a governed contemplative surface, not an operational
dashboard, graph runtime, Command Sky, lineage explorer, or parallel memory.

## 2. Real Status

Backend v1 is implemented as `implemented_partial`: positions endpoint, mobile
payload, deterministic fallback, privacy/evidence projection, semantic readiness,
vector-only positioning gate, Lens 1 maturity gate, `lens1_usage_review_contract`,
mobile open/load/fail/tap telemetry, and Curator usage review finding exist.

What remains blocked: Lens 2, Command Sky, lineage, Graph RAG positioning,
Python graph runtime, operational dashboard, provider prompt and policy patch.
The usage contract also exposes human decision, rollback, policy patch review,
evidence and `forbidden_until_review` fields as machine-readable gates.

## 3. Authority

Schema: `atlas.constelacao.lens1_usage_review.v1`  
Mode: `observation_required` / `proposal_only`  
Authority: `lens1_must_prove_contemplative_value_before_operational_or_graph_promotion`

This AP is subordinate to the Kernel, Surface Adapter contract, Evidence Ledger,
AP-683 Local RAG promotion review and AP-684 External Graph Harness.

## 4. Canonical Flow

1. Mobile/App opens Constelacao through the surface adapter.
2. Kernel serves Lens 1 `bilderatlas` only.
3. Service emits no raw content, no raw note text and no operational chrome.
4. Positions are served with privacy-safe refs and metadata only.
5. `CONSTELACAO_POSITIONS_SERVED` records lens, counts, source refs, readiness
   and promotion gates in the Evidence Ledger.
6. Mobile emits open/load/fail/tap telemetry as usage signals.
7. `self_improvement.docs_drift_review` reads usage events and emits
   `atlas.self_improvement.constelacao_usage_review.v1` in proposal-only mode.
8. Human/Curator reviews at least 30 days of real usage before any Lens 2 or
   Graph/Command Sky AP can be drafted.

## 5. Hard Rules

- Lens 1 is always `bilderatlas`.
- `allowed_lenses` must remain `['bilderatlas']` until a future AP changes it.
- `promotion_allowed=false` and `auto_promotion_allowed=false` are invariant.
- `command_sky_allowed=false`, `lineage_allowed=false`,
  `lens2_promotion_allowed=false` and `graph_rag_positioning_allowed=false`.
- Graph RAG/Python stays `future_governed`; Vector positioning may be allowed
  only as local fallback and never as runtime promotion.
- No query param, mobile flag, provider output or Curator finding may bypass
  the 30-day observation window.
- Unsupported/future lens requests must be sanitized, preserved as
  `requested_lens`, served as `bilderatlas`, marked `lens_gate.blocked=true`,
  and written to the Ledger with a future-AP reason. Silent fallback is not
  allowed because it destroys evidence of attempted scope creep.
- Curator may propose review, but cannot auto-apply policy, runtime, UI mode or
  memory/context changes.
- Any future runtime must use AP-201 `atlas.runtime_invocation_contract.v1`
  with Kernel first, Decision Receipt, privacy class and evidence sink.
- Any Graph RAG promotion must pass AP-683 or explicit successor.

## 6. Required Contract Fields

`lens1_usage_review_contract` must include:

- `schema_version=atlas.constelacao.lens1_usage_review.v1`;
- `status=observation_required`;
- `current_lens=bilderatlas`;
- `observation_window_days_required=30`;
- `human_review_required=true`;
- `curator_review_required=true`;
- `decision_receipt_required=true`;
- `required_human_decision=approve_or_reject_constelacao_lens1_promotion_after_usage_review`;
- `rollback_plan_required=true`;
- `policy_patch_review_required=true`;
- `promotion_allowed=false`;
- `auto_promotion_allowed=false`;
- `evidence_required`;
- `rollback_required`;
- `forbidden_until_review`;
- `future_runtime_invocation_contract`;
- `next_action=collect_constelacao_lens1_usage_telemetry_for_30_days_before_review`.

## 7. Blocked Targets

- `lens2`;
- `command_sky`;
- `lineage`;
- `graph_rag_positioning`;
- `operational_dashboard`;
- `decision_surface`;
- `provider_prompt`;
- `policy_patch`;
- `python_graph_rag_runtime`.

## 8. Evidence

Minimum evidence before future promotion discussion:

| Evidence | Required |
|---|---|
| `CONSTELACAO_POSITIONS_SERVED` | yes |
| `constelacao_opened` telemetry | yes |
| `constelacao_backend_loaded` telemetry | yes |
| `constelacao_star_tapped` telemetry | yes |
| 30-day observation window | yes |
| Curator usage review | yes |
| Human review | yes |
| Decision Receipt for any future AP | yes |
| rollback plan | yes |

## 9. Non Scope

- Do not build Command Sky here.
- Do not add graph runtime or Python service here.
- Do not expose raw Obsidian/AtlasVault/private content.
- Do not turn Constelacao into Inbox, dashboard or execution surface.
- Do not make a provider call to rank stars directly.
- Do not create lineage UI, operational filters or decision buttons in Lens 1.

## 10. Definition Of Done

- `ConstelacaoPositionsService` returns Lens 1 only and blocks promotion targets.
- API tests prove no raw content is exposed.
- API tests prove maturity and usage review contracts are present.
- API tests prove unsupported/future lens requests are preserved and blocked,
  never silently defaulted.
- Ledger event records promotion gates with Graph/Python blocked.
- Curator finding is proposal-only and references usage evidence.
- Static scan AP-685 passes.
- `architecture-validate`, docs-health and `git diff --check` pass.
