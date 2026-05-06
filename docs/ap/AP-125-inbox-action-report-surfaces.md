# AP-125 — Inbox Action Report Surfaces

## Problem

`INBOX_ACTION_RECORDED` and `inboxActionReportForWindow` made human Inbox actions
auditable, but the operator still needed observability, MCP, or direct service
usage to inspect them. That leaves the operational surface weaker than the
Ledger contract.

## Contract

- CLI command: `atlas:ai:inbox-action-report`.
- API route: `/ai/inbox-actions/report`.
- Both surfaces must call `inboxActionReportForWindow`.
- Both surfaces must use `KernelReplayReportInput` for the hours window and
  scalar filters.
- Supported filters:
  - `action`
  - `actor_type`
  - `inbox_type`
  - `recommended_action`
  - `result`
  - `emitter_stage`
- JSON shape must expose:
  - `status`
  - `hours`
  - `filters`
  - `inbox_actions`
- Missing Ledger table must return `ledger_unavailable` and preserve review
  signal `wait_for_inbox_action_evidence`.

## Implementation

- `AtlasAiInboxActionReportCommand`
- `AtlasAiInboxActionReportController`
- `routes/api.php`
- `AtlasAiInboxActionReportCommandTest`
- `AtlasAiInboxActionReportApiTest`
- Architecture static scan key `ap125_inbox_action_report_surfaces`

## Value

Inbox review actions are now first-class operational evidence for humans and
automation. The same read model is consumed by CLI, API, MCP, Observability, and
Self-Improvement, preventing duplicated parsing logic and keeping review gaps
visible to the Curator loop.
