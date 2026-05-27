---
id: AP-717-agentic-engineering-os-area-finding-engine-contract
type: architecture_proposal
title: AP-717 Agentic Engineering OS Area Finding Engine Contract
status: accepted
owner: programming
created_at: 2026-05-26
summary: Adds a read-only Area Finding Engine for area_id agentic_engineering_os inside the Atlas Software Company Stewardship Stack. The engine scans owner docs, AP docs, architecture docs, service/test paths and optional safe read-only command output to emit deduplicated, deterministic, risk-classified, confidence-scored findings with a route hint, feeding the Area Focus Loop. It writes no code, opens no branch, creates no spec and calls no provider.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
  - docs/engineering-knowledge-base/atlas-area-stewardship-layer.md
  - docs/ap/AP-712-night-shift-area-focus-loop-contract.md
  - docs/ap/AP-715-software-company-stewardship-stack-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AgenticEngineeringOsFindingEngineService.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AgenticEngineeringOsFindingEngineServiceTest.php
requires_evidence: true
risk_level: high
---
# AP-717 Agentic Engineering OS Area Finding Engine Contract

## Decision

The Area Focus Loop (AP-712), inside the Atlas Software Company Stewardship
Stack (AP-715), gets a dedicated read-only Area Finding Engine for
`area_id: agentic_engineering_os`.

Atlas Software Company Stewardship Stack is a stack/capability family inside the
Atlas Autonomous Software Company Runtime, not a new OS. The finding engine is a
scanner/read model, not a parallel runtime and not an executor. It reuses
canonical owners (Self-Directed Evolution stays the gap/spec owner; Atlas Dev /
Forge stay the executors) and only proposes findings.

## Scope (read-only)

The engine must NOT:

- write code or docs;
- open a branch or worktree;
- create a spec, AP or doc;
- call a provider;
- merge, deploy, touch secrets or run destructive change;
- run heavy global commands.

Every finding requires operator review and is `safe_to_autofix=false`.

## Finding Schema

```text
atlas.software_company_stewardship.area_finding.v1
```

Each finding declares: `finding_id`, `finding_hash` (deterministic), `area_id`,
`finding_type`, `title`, `detail`, `severity`, `risk_level`, `confidence`,
`confidence_score`, `route_hint`, `evidence_refs`, `affected_paths`,
`recommended_action`, `source`, `safe_to_autofix=false`,
`requires_operator_review=true`.

## Finding Types (minimum)

```text
docs_stale              missing_test            failing_gate_hint
weak_handoff            duplicate_runtime_risk  missing_evidence
replay_gap              desktop_surface_gap     dev_forge_routing_gap
self_directed_spec_gap
```

## Sources (initial, read-only)

- canonical owner docs and AP docs (frontmatter + bounded keyword scan);
- known architecture docs in the area scope;
- service file paths and test file paths in the area scope;
- optional safe read-only command output (never required, never heavy).

Sources are gathered behind overridable seams so the classification core stays
deterministic and unit-testable with synthetic fixtures.

## Route Hint

```text
route_hint ∈ { self_directed_evolution, atlas_dev, forge, operator_review }
```

- `self_directed_evolution`: uncontracted gap that needs a reviewable spec draft.
- `atlas_dev`: small, local, verifiable fix.
- `forge`: long-horizon, multi-agent or cross-system work.
- `operator_review`: high-risk, sensitive or evidence/decision-gated item.

Critical severity and sensitive items always escalate to `operator_review`.

## Output Guarantees

- deduplicated by deterministic `finding_hash`;
- deterministic report hash (excludes the timestamp);
- risk-classified (`severity`/`risk_level`) and confidence-scored
  (`confidence`/`confidence_score`);
- `claim_policy` enforces read-only, no provider, no branch, no spec, no new OS,
  no parallel runtime.

## Integration With Area Focus Loop (Slice 1)

The Area Focus Loop read model consumes the engine as an OPTIONAL source. If the
engine class is absent it degrades cleanly to its existing sources; if present,
its findings are normalized into the loop's finding list with the engine route
hint preserved. The engine never replaces Self-Directed Evolution as the gap
owner.

## Acceptance

- AP doc exists and links the canonical stewardship docs.
- Engine emits `atlas.software_company_stewardship.area_finding.v1` findings.
- All ten finding types are produced from synthetic fixtures.
- Output is deduplicated, deterministic, risk-classified, confidence-scored and
  route-hinted.
- Read-only policy is enforced and tested.
- Area Focus Loop integrates the engine optionally with a clean fallback.
- docs-health and architecture validation stay green.
