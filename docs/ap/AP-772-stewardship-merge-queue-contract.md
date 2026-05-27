# AP-772 · Stewardship Merge Queue

- **Status:** building
- **Stack:** Atlas Software Company Stewardship Stack.
- **Owner service:** `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipMergeQueueService.php`
- **CLI:** `php artisan atlas:software-company-stewardship merge-queue` (+ `merge-queue-records`)
- **Composes:** AP-769 branch merge governor · AP-780 branch review packet · AP-771 priority engine · AP-770 branch lifecycle registry · AP-775 repo merge lease.

## Purpose

AP-772 is the merge train for the 24/7 stewardship loop. AP-769 governs one
branch at a time; AP-772 governs a batch of branches so Atlas does not
auto-merge stale assumptions in parallel.

Core rule:

```text
rank branches by advancement/robustness, then re-check the live base before
each optional ff-only auto-merge.
```

## Flow

```
branch refs
  -> AP-769 initial governance per branch
  -> AP-780 operator review packet per branch
  -> AP-771 priority order
  -> sequential live AP-769 recheck
  -> fresh AP-780 packet for the live governance result
  -> auto_merged_ff_only | review_required | blocked
```

## Review Packet Contract

Every planned or executed queue item MUST include:

- `branch_review_packet`: schema
  `atlas.software_company_stewardship.branch_review_packet.v1`;
- `branch_review_packet_status`: compact packet status for filters;
- `governance`: the AP-769 report used to build the packet.

The top-level queue report MUST also expose `branch_review_packets[]`, a flat
list of the same packets in result order. Product Mode, Morning Inbox, GitKraken
review surfaces and operator approval flows consume this flat list directly.
They MUST NOT reconstruct branch review state from raw AP-769 JSON when AP-772
has already produced AP-780 packets.

When `execute_queue=true`, AP-772 re-runs AP-769 immediately before each
candidate merge and rebuilds AP-780 from that live governance report. A stale,
conflicted or newly blocked branch therefore receives a blocked review packet
with repair options instead of a misleading packet from the initial plan.

## Command

Plan/review only:

```bash
php artisan atlas:software-company-stewardship merge-queue \
  --branch-ref="atlas/area-focus/a,atlas/area-focus/b" \
  --base-ref=main \
  --json
```

Policy-gated execution:

```bash
php artisan atlas:software-company-stewardship merge-queue \
  --queue-file=/path/to/branches.json \
  --auto-merge \
  --execute-queue \
  --record-queue \
  --json
```

The queue file may be a list of branch refs or an object with `branch_refs` or
`branches`.

## Schema

- `atlas.software_company_stewardship.merge_queue.v1`
- `atlas.software_company_stewardship.merge_queue_record.v1`
- `atlas.software_company_stewardship.merge_queue_records.v1`

## Enterprise Guarantees

- No parallel merge.
- Every branch receives AP-769 GitKraken review metadata.
- Every branch receives an AP-780 operator-review packet.
- Every branch is ranked by AP-771 before execution.
- Each branch is re-evaluated against the live base immediately before merge.
- Auto-merge still uses AP-769 fast-forward-only policy.
- If one auto-merge updates `main`, later branches based on the old `main` are
  blocked/reviewed instead of silently merged.
- No provider call, branch creation, worktree creation, deploy, push or secret access.

## Tests

`tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipMergeQueueServiceTest.php`

Coverage:

- plans visible order without merging;
- executes one safe ff-only merge, then blocks/reviews a stale next branch after
  live-base recheck;
- emits AP-780 review packets for planned, merged and blocked queue items;
- exposes top-level `branch_review_packets[]` for Product Mode/Morning Inbox;
- blocks when no branches are supplied.
