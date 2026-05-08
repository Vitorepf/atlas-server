---
title: AP-217 AP Agent Workflow Release Evidence Preflight
status: foundation-contract-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidencePreflight.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidencePreflightTest.php
depends_on:
  - AP-216
---

# AP-217 AP Agent Workflow Release Evidence Preflight

## Purpose

Validate whether AP-216 can be handed to a future release or Evidence Ledger layer.

This AP does not publish, persist, emit Evidence Ledger events, create releases, or
choose implementation surfaces automatically.

## Contract

Class: `AtlasApAgentWorkflowReleaseEvidencePreflight`

Schema: `atlas.ap_agent_workflow_release_evidence_preflight.v1`

## Required Evidence

- `reviewed_closeout_acceptance_receipt`
- `selected_future_surface`
- `confirmed_release_or_ledger_owner`
- `confirmed_no_auto_publish`
- `confirmed_no_auto_evidence_emit`
- `confirmed_post_closeout_risks_recorded`

Each required key must be boolean `true`.

## Statuses

- `ready_for_future_release_or_evidence_layer`
- `blocked_by_closeout_acceptance_receipt`
- `blocked_invalid_release_evidence_preflight_shape`
- `release_evidence_preflight_incomplete`

## Rules

- AP-216 must be `closeout_acceptance_reported`.
- Missing preflight evidence blocks handoff.
- False preflight evidence blocks handoff.
- Wrong evidence types block handoff.
- Unknown evidence keys block handoff.
- The preflight never writes files.
- The preflight never runs commands.
- The preflight never publishes a release.
- The preflight never emits Evidence Ledger events.
- The preflight never persists itself.

## Output

The payload includes:

- closeout acceptance summary
- release/evidence preflight status
- failed evidence keys
- shape errors
- next action
- guardrails

## Human Meaning

This is a runway inspection, not takeoff. It tells a future AP whether the closeout
receipt is clean enough to consume, while keeping execution and persistence separate.

## Implementation Notes

This AP intentionally avoids API, MCP, scheduler, release automation, and Evidence
Ledger. Those surfaces require a separate AP with explicit ownership.

## Validation

Focused test:

`php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidencePreflightTest.php`

Expected coverage:

- accepted closeout plus complete evidence becomes ready
- blocked AP-216 blocks preflight
- false preflight evidence returns incomplete
- wrong shape blocks preflight
