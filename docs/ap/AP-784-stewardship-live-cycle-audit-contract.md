# AP-784 · Stewardship Live Cycle Audit

- **Status:** building
- **Stack:** Atlas Software Company Stewardship Stack / Area Focus Loop
- **Owner service:** `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipLiveCycleAuditService.php`
- **CLI:** `php artisan atlas:software-company-stewardship:live-cycle-audit --json`
- **Composes (read-only):** AP-781 first live branch proof · AP-782 integration lane · AP-769 governance records · AP-780 review packets · AP-765 runtime bridge · AP-768 first full cycle · AP-766 runner · AP-778 day start

## Purpose

AP-784 is the operator-facing **truth inspector** for the real stewardship git
loop. It answers, without simulating or upgrading deferred stages:

- Which proof branches and integration lanes exist in git right now?
- Is `main` clean enough for ff-only promotion?
- Which steps are **actually real** (branch, worktree, commit, lane, packet)?
- Which steps are **not real yet** (provider, owner runtime, scheduler, merge)?
- What is the next honest operator action?

AP-784 is not AP-762 certification and not AP-783. It does not execute the loop.

## Output

Schema: `atlas.software_company_stewardship.live_cycle_audit.v1`

Statuses:

- `ready` — promotion can proceed now or main already reflects integrated lane
- `blocked` — unsafe to promote (e.g. dirty base with lane ahead)
- `partial` — some real git artifacts exist; more steps required
- `failed` — repo/base ref unusable for audit

Required top-level fields match the CLI contract: `status`, `area_id`, `base_ref`,
`base_clean`, `proof_branches`, `integration_lanes`, `latest_lane_commit`,
`main_commit`, `real_steps`, `blockers`, `next_real_action`, `operator_truth`.

`real_steps` never marks deferred AP-768 stages as done. `not_yet_real` lists
explicit gaps (provider, owner runtime, scheduler, merge).

## Safety Boundary

AP-784 may:

- run read-only git commands (`rev-parse`, `status`, `for-each-ref`, `worktree list`);
- read append-only JSONL receipts from AP-781/AP-782/AP-769/AP-765/AP-766/AP-778;
- report promotion readiness from git facts.

AP-784 must not:

- create branches, worktrees or commits;
- merge, rebase, push, deploy;
- invoke providers, Dev, Forge or schedulers;
- mutate refs or fabricate receipts;
- treat `deferred` as `done`.

## CLI

```bash
php artisan atlas:software-company-stewardship:live-cycle-audit \
  --area=agentic_engineering_os \
  --base-ref=main \
  --json
```

## Acceptance

- Clean repo with integration lane ahead of `main` → `ready` or `promotion_ready`.
- Dirty repo with lane ahead → `blocked` + `base_worktree_dirty`.
- No lane → `partial` + next action to run AP-782.
- Proof branch without lane → `partial`.
- Audit is idempotent on git state (no ref mutation between runs).
- Focused unit tests cover the matrix above.
