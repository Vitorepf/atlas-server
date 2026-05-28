---
id: AP-805-ten-cycle-readiness-governor
type: ap_contract
title: AP-805 Ten-Cycle Readiness Governor Contract
status: active
implementation_state: implemented
requires_evidence: true
owner: software_company_stewardship
summary: Read-only pre-flight governor that decides whether Atlas may attempt 10 real consecutive multi-agent Stewardship cycles. It composes existing read-only probes (repo/git, providers, AP-790 budgets/locks/kill-switch, AP-800 capabilities, merge-truth, Product Mode memory safety, leases) into a deterministic ready|partial|blocked verdict, a non-destructive branch cleanup PLAN, and the exact safe command to run the cycles. It never runs the loop, a provider, a merge, or deletes a branch.
related_paths:
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/TenCycleReadinessGovernorService.php
  - app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php
  - docs/ap/AP-790-reliable-24h-autonomous-loop-runner-contract.md
  - docs/ap/AP-792-24h-loop-certification-harness-contract.md
  - docs/ap/AP-793-atlas-isolated-agent-execution-substrate-contract.md
  - docs/ap/AP-800-multi-agent-cycle-certification-visibility-contract.md
---
# AP-805 Ten-Cycle Readiness Governor Contract

## Authority

AP-805 is the read-only readiness gate that answers, before anyone starts 10 real
consecutive multi-agent Stewardship cycles: **is it safe to start, or must we
block honestly?** It exists so a 10-cycle attempt can never become a false
positive, false merge, starvation, provider timeout, OOM, branch pollution,
duplicate-finding loop, no-op commit, docs-only waste, synthetic recovery or a
lying Product Mode.

AP-805 is **not** the loop and does not fork it. It composes existing read-only
surfaces:

```text
reuse_or_extend:
  AP-790 Reliable24hLoopRunnerService (lock/kill/pause/ledger/per-run budget)
  AP-800 MultiAgentCycleCertificationService (capability detection)
  AP-794/AP-796 FindingSlicePlannerService (slice planning available)
  StewardshipBranchMergeGovernorService (merge-truth: main must advance)
  AreaFocusCandidateQuarantineService (transient-aware duplicate guard)
  ProductMode read-model (bounded projection window)
do_not_create:
  parallel runtime, loop, provider, merge, or branch deletion
```

## Non-Negotiable Safety

- Read-only. Never runs the loop, a provider, a merge, a commit, a deploy, a
  secret read or any destructive operation.
- Branch cleanup is a **PLAN only** — it lists candidate branches and whether they
  are merged into `main`; it never deletes anything and never touches unrelated
  human changes.
- `blocked` is never dressed as `ready`. A skipped probe is a warning, not a pass.
- May run a short smoke / dry-run / single read-only validation, never 10 cycles.

## Output

Schema: `atlas.stewardship.ten_cycle_readiness.v1`

- `status`: `ready` | `partial` | `blocked`.
- `readiness_id`, `area`, `focus`, `checked_at`.
- `repo_state`, `branch_state`, `provider_state`, `budget_state`, `backlog_state`,
  `multi_agent_state`, `merge_truth_state`, `product_mode_state`, `memory_state`,
  `kill_switch_state`, `lease_lock_state`, `validation_state`.
- `gates`: each gate with `ok`, `hard` (hard vs soft) and `detail`.
- `required_commands`, `proof_commands`.
- `recommended_command_for_10_cycle_run` (or an explicit DO NOT RUN when not ready).
- `blockers[]`, `warnings[]`, `cleanup_plan[]`.
- deterministic `report_hash` (excludes `checked_at`).
- `claim_policy`: read-only, runs nothing, deletes nothing.

## Gates

Hard gates (any failure → `blocked`):

| Gate | Meaning |
|---|---|
| `repo_clean_or_known_dirty` | git status is readable and dirty paths are classified |
| `no_uncommitted_ap_substrate` | no uncommitted AP runtime/service/test code |
| `provider_timeout_minimum_ok` | inner provider timeout ≥ 300s for dev and cursor |
| `merge_truth_guard_present` | merge governor advances-main guard present |
| `finding_slice_planner_available` | AP-794/AP-796 slice planner present |
| `duplicate_finding_guard_available` | AP-790 seen-finding + transient quarantine guard |
| `multi_agent_lane_contracts_available` | AP-797 lane orchestrator present |
| `judge_repair_available` | AP-798 judge + AP-799 repair present |
| `provider_routing_available_or_honest_degraded` | a provider is available (when probed) |
| `kill_switch_available` | AP-790 kill-switch + pause controls present |
| `no_open_leases` | no open repo merge lease |
| `no_stale_lock` | no held/stale loop lock |
| `product_mode_projection_memory_safe` | bounded read-model window (no OOM) when probed |

Soft gates (failure → warning / proof-command, status `partial`):

| Gate | Meaning |
|---|---|
| `per_run_budget_ok` | runner exposes a per-run merge budget + ledger |
| `max_merges_not_cumulative` | `--max-merges` counts this run, not the cumulative total |
| `branch_cleanup_plan_available` | a cleanup plan was computed (needs `--include-branch-audit`) |
| `docs_health_ok` | `atlas:engineering:knowledge docs-health` passed (proof command) |
| `architecture_validate_ok` | `atlas:ai:architecture-validate` passed (proof command) |

## Real-Cycle Criteria Enforced Downstream

AP-805 only certifies *readiness*. The 10-cycle run itself counts a cycle as real
only under the existing rules it reuses: provider really invoked (AP-793 facts),
sandbox/worktree materialized, owner-flow completed, focused validation ran,
inbox/evidence emitted, merge governor evaluated, and — when merged —
`main_before != main_after` (AP-793 merge truth, AP-800 certification). A blocked
cycle is recorded honestly and never counted as success.

## Isolation Note (L1 vs L2)

The current substrate is **L1 git worktree isolation**: agents run on the host
inside an isolated worktree with scope-guarded file edits. **L2 OS/process
isolation** (containers / AP-793 future sandbox providers) is a hardening upgrade,
NOT a blocker for the 10-cycle run. AP-805 surfaces this as an explicit
`isolation_warning` field rather than a false sense of full isolation.

## CLI

```bash
php artisan atlas:software-company-stewardship ten-cycle-readiness \
  --area=agentic_engineering_os --focus=dev_forge --json
# Options: --strict --allow-cleanup-plan --include-product-mode
#          --include-provider-probe --include-branch-audit
```

`--strict` exits non-zero when status is not `ready`.

## Acceptance

- Read-only; runs nothing destructive; branch cleanup is a plan, never an action.
- `ready` only when every hard gate passes and there are no warnings.
- `blocked` when a provider is unavailable with no fallback, a merge lease is open,
  a loop lock is held/stale, Product Mode would OOM, or the AP-795..AP-800
  multi-agent capabilities are absent.
- L1 worktree isolation is reported as a warning; missing L2 process isolation is
  not a blocker.
- `cleanup_plan` lists stale branches with merged/unmerged status and never deletes.
- `recommended_command_for_10_cycle_run` carries safe bounded flags when ready,
  including `--multi-agent-workcell`; a 10-cycle proof for this AP must exercise
  the AP-801/AP-797 lane workcell, not the legacy single-agent loop.
- `report_hash` is deterministic for identical input (excludes `checked_at`).
