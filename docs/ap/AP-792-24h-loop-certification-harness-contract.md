---
title: AP-792 End-to-End 24h Loop Certification Harness Contract
status: active
implementation_state: implemented
requires_evidence: true
owner: software_company_stewardship
---

# AP-792 End-to-End 24h Loop Certification Harness Contract

## Authority

AP-792 is the read-only certification harness that proves — or honestly blocks —
the Atlas Software Company Stewardship 24h autonomous loop before it is left
running. It is **not** the loop and it does **not** re-implement it. It composes
and certifies the existing owners: AP-786 autonomous evolution session, AP-787
Forge owner runtime dispatch bridge, AP-788 Forge execution authority injection,
the branch sandbox materializer, branch merge governor, owner-flow runner,
robust Forge quality contract and AP-786 Product Mode visibility.

It must run even when AP-789 (Forge live authority), AP-790 and AP-791 are not
yet merged: missing capabilities are detected and reported as `partial` with the
exact missing capability list — never a false `passed`.

AP-792 must certify AP-793 as the substrate floor. A 24h loop is not production
certified unless evidence proves the AP-793 real-cycle facts: provider invoked
through authority, sandbox/worktree materialized, owner runtime chain completed,
product diff or explicit no-progress result captured, focused validation ran,
inbox/evidence emitted, merge governor evaluated and `main` advanced when a
merge is claimed.

## Non-Negotiable Safety

- Read-only by default. No provider call, no branch, no commit, no merge, no
  deploy, no secret access, no destructive operation.
- It never runs the real 24h loop. It exercises read-only dry-run/replay probes
  and inspects real recorded evidence.
- All file IO uses temp dirs / sandbox fixtures only.

## Certification Modes & Real Authority (mandatory honesty)

The harness distinguishes two modes and never confuses them:

- **`test_mode`** (default, fixtures / test doubles): a CONTRACT self-test. It can
  prove the loop's invariants hold for representative shapes, but it **never
  certifies production**. `production_certified` is always `false`; the top status
  is at most `partial` (or `blocked` if an invariant fails).
- **`runtime_real`** (`--use-real-services`): certifies a scenario as
  production-ready **only** when it is evaluated against REAL recorded loop
  evidence carrying real authority: real Obra/work-packet, real Decision Receipt,
  real Atlas Decide provider topology, real AWIS workspace and real Evidence
  ledger refs.

Hard rule: **never report `passed` for a scenario if any component is
mock/simulated or missing.** The correct status is `partial` or `blocked` with an
explicit `missing_real_authority` list. A scenario is production-`passed` only
when `evaluated_against == runtime_real`, `missing_real_authority == []`, required
capabilities exist and invariants hold. There is no code path to a fabricated or
fixture-derived production pass.

Real-authority components checked: `real_obra`, `real_decision_receipt`,
`real_provider_topology`, `real_awis_workspace`, `real_evidence_ledger`.

## Capability Matrix

Capabilities are detected by probing canonical class names (`class_exists`),
overridable via input for tests and for wiring AP-789/790/791 when they land.

Required (must exist to certify the loop at all):

| Capability | Class |
|---|---|
| `ap786_loop_session` | `AutonomousEvolutionSessionService` |
| `ap786_cycle_certification` | `Ap786RealCycleCertificationService` |
| `branch_sandbox_materializer` | `AreaFocusBranchSandboxMaterializer` |
| `branch_merge_governor` | `StewardshipBranchMergeGovernor` |
| `owner_flow_runner` | `OwnerFlow\Ap786OwnerFlowRunner` |
| `robust_forge_quality_contract` | `Ap786RobustForgeQualityContractService` |
| `forge_owner_runtime_dispatch_bridge` (AP-787) | `OwnerFlow\ForgeOwnerRuntimeDispatchBridge` |
| `loop_receipt_integrity` (AP-791) | `AutonomousLoopReceiptIntegrityService` |
| `product_mode_visibility` | `ProductModeOperationalInboxReadModelService` |
| `isolated_agent_execution_substrate` (AP-793 contract evidence) | `docs/ap/AP-793-atlas-isolated-agent-execution-substrate-contract.md` |

Optional / expected (AP-789/AP-790 — `partial` when absent, real integration
certified when present). AP-791 is required as `loop_receipt_integrity` because a
24h loop without auditable receipts is not certifiable:

| Capability | AP | Detected via candidate classes |
|---|---|---|
| `forge_live_authority` | AP-789 | Forge live authority bootstrap class |
| `loop_resume_ledger` | AP-790 | `Reliable24hLoopRunnerService` append-only ledger |
| `loop_kill_switch` | AP-790 | `Reliable24hLoopRunnerService` kill-switch/pause controls |

## Scenarios

Each scenario emits machine-readable evidence (`invariants`, `evidence`,
`required_capabilities`, `optional_capabilities`, `missing_capabilities`,
`status`).

1. `dry_run_selection_factory_max` — dry-run selects a finding under
   `factory_max`; no branch/provider/merge.
2. `forge_missing_authority_honest_block` — without full owner-flow authority the
   cycle blocks honestly: `provider_called=false`, `merge_performed=false`.
3. `forge_planned_not_completed` — a planned Forge path is not "completed" and is
   not merged.
4. `atlas_dev_executed_owner_flow_completed` — an Atlas Dev execution completes
   the owner-flow and certifies as a real cycle.
5. `validation_failed_no_merge_inbox_receipt` — failed validation blocks merge
   and still emits an inbox item / receipt.
6. `merge_eligible_ff_only_receipt` — an eligible branch produces a ff-only merge
   receipt under AP-769/AP-774.
7. `duplicate_finding_skipped_review_locked` — a duplicate/already-reviewed
   finding is skipped or review-locked.
8. `crash_restart_resume_ledger` — a recorded session is replayable after a
   crash/restart (baseline replay; AP-790 ledger upgrades it).
9. `kill_switch_clean_stop` — an active kill switch stops the loop cleanly with
   no new cycle (AP-791 upgrades it).
10. `product_mode_visibility_operational_item` — Product Mode's Operational Inbox
    surfaces the autonomous cycle as an operator item.

## CLI

```bash
php artisan atlas:software-company-stewardship:certify-24h-loop --json
php artisan atlas:software-company-stewardship:certify-24h-loop --strict
php artisan atlas:software-company-stewardship:certify-24h-loop --scenario=forge_missing_authority_honest_block --json
php artisan atlas:software-company-stewardship:certify-24h-loop --use-real-services --json
```

- `--json` machine-readable report.
- `--strict` exits non-zero on `partial` or `blocked`.
- `--scenario=` certify a single scenario.
- `--use-real-services` adds live read-only probes (dry-run selection, real cycle
  certification, session replay, Product Mode projection); still no execute, no
  merge.

## Output

Schema: `atlas.software_company_stewardship.loop_24h_certification.v1`

- `status`: `passed` | `partial` | `blocked`.
- `certification_mode`: `test_mode` | `runtime_real`.
- `production_certified`: bool (only ever `true` in `runtime_real`).
- `capabilities`: detected matrix with present/missing.
- `missing_capabilities` / `missing_real_authority`: exact lists.
- `scenarios`: per-scenario machine-readable evidence, each with
  `evaluated_against` (`fake_fixture` | `runtime_real` | `runtime_real_no_evidence`),
  `contract_self_test`, `real_authority`, `missing_real_authority`, `invariants`.
- deterministic `report_hash` (excludes `generated_at`).
- `claim_policy`: read-only, no provider/branch/merge/24h-run, `fixtures_certify_production=false`, `false_pass_possible=false`.

## Acceptance

- `production_certified` is `true` only in `runtime_real` with every required
  capability present and every scenario `passed` against real authority.
- `test_mode` never certifies production (`production_certified=false`).
- A scenario is `passed` only when evaluated against `runtime_real` evidence with
  no `missing_real_authority`; otherwise `partial`/`blocked`. Never a false pass.
- `partial` lists the exact missing capabilities and missing real-authority.
- `--strict` exits non-zero on `partial`/`blocked`.
- Every scenario carries machine-readable evidence.
- Detects AP-789/790/791 when present; reports `partial` (not false pass) when absent.
- Runs read-only; never runs the 24h loop and never merges.
