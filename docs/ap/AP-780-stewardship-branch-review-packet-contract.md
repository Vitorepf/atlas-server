---
title: "AP-780 Stewardship Branch Review Packet Contract"
status: implemented
kind: contract
ap: AP-780
owner: software_company_stewardship
---

# AP-780 Stewardship Branch Review Packet Contract

AP-780 turns AP-769/AP-772 branch governance into one operator-reviewable packet
for GitKraken, Product Mode and inbox surfaces. It does not create another
branch system; it composes the existing branch merge governor and merge queue.

## Purpose

The operator goal is not only "avoid conflicts". The operator needs one stable
object that says:

- which branch to open in GitKraken;
- which base branch it targets;
- which commits and files are reviewable;
- which finding/spec/receipt/handoff/sandbox produced it;
- whether it is blocked, review-only, auto-merge candidate or already merged;
- which operator decisions are safe now.

AP-780 is that object.

## Owner

- Service:
  `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipBranchReviewPacketService.php`
- Test:
  `tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipBranchReviewPacketServiceTest.php`
- Certification:
  AP-776 includes AP-780 in the branch system certificate.

## Input

AP-780 accepts either:

- `governance_report`: an AP-769
  `atlas.software_company_stewardship.branch_merge_governor.v1` report; or
- `queue_item.governance`: an AP-772 queue item carrying the same AP-769
  governance report.

Optional context:

- `evidence_refs[]`;
- `queue_context`;
- `area_id`.

## Output

Schema: `atlas.software_company_stewardship.branch_review_packet.v1`

Required sections:

- `branch_identity`;
- `gitkraken_review_surface`;
- `cycle_traceability`;
- `classification`;
- `risk_summary`;
- `decision_options`;
- `operator_next_action`;
- `claim_policy`;
- `packet_hash`.

## Status Mapping

| AP-769 input | AP-780 status |
|---|---|
| `merged` | `merged` |
| blockers or `blocked` | `blocked` |
| auto-merge eligible | `auto_merge_candidate` |
| otherwise | `ready_for_operator_review` |

## Decision Options

AP-780 may expose:

- `execute_policy_gated_auto_merge`;
- `accept_for_manual_ff_merge`;
- `defer`;
- `reject`;
- `request_changes`;
- `repair_branch_before_review`.

The packet never performs these decisions. Execution stays in AP-769/AP-772 and
operator decision surfaces.

## Hard Boundary

AP-780 is read-only:

- no provider invocation;
- no branch creation;
- no worktree creation;
- no merge;
- no push;
- no deploy;
- no secret access.

Any code-like or mixed branch still requires operator review unless AP-769/AP-774
explicitly mark it eligible under focused validation.

## Acceptance

- auto-merge candidate packet includes the AP-769 ff-only command;
- code branch packet stays operator-reviewable;
- blocked governance packet exposes repair decision and blockers;
- missing AP-769 governance blocks;
- AP-776 certification includes AP-780.
