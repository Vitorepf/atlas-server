---
id: AP-767-dev-forge-runtime-execution-bridge
type: ap_contract
title: AP-767 Dev/Forge Runtime Execution Bridge Contract
status: active
owner: programming
summary: Minimal operator-gated execution producer that closes the first complete Stewardship cycle. Consumes an already-approved handoff/finding/spec plus an existing (or test) branch sandbox, picks the atlas_dev or forge owner, and either plans the work (dry-run), runs a small allowlisted read-only + test "local deterministic owner task" inside the isolated worktree, or honestly reports provider_bridge_missing. It emits an execution_result shaped for AP-765 so Evidence/Product Mode/Portfolio can consume it. AP-767 invents no provider execution, creates no OS/runtime/branch/worktree, mutates nothing outside the sandbox, and never merges, deploys, pushes or touches secrets.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/ap/AP-749-owner-specific-dev-forge-queue-consumption-gate-contract.md
  - docs/ap/AP-756-area-focus-branch-sandbox-materializer-contract.md
  - docs/ap/AP-758-owner-runtime-execution-adapter-contract.md
  - docs/ap/AP-759-owner-sandbox-runtime-runner-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/DevForgeRuntimeExecutionBridgeService.php
  - app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/StewardshipEvolution/DevForgeRuntimeExecutionBridgeServiceTest.php
requires_evidence: true
risk_level: critical
---
# AP-767 Dev/Forge Runtime Execution Bridge Contract

## Decision

AP-767 is the **minimal first-cycle execution producer** for the Stewardship
Stack. The heavyweight, fully-governed owner runtime path already exists
(AP-747 → AP-748 → AP-756 → AP-757 → AP-749 → AP-758 → AP-759 → AP-750/AP-765),
but it requires the entire prerequisite chain plus several operator receipts
before a single owner command can run. AP-767 closes the *first complete cycle*
with a simple input so the loop becomes real:

```text
approved handoff/finding/spec + existing (or test) branch sandbox + owner + mode
-> AP-767 owner-specific consumption gate
-> capability-slot provider bridge (architect/executor/reviewer/certifier)
-> dry-run plan   OR   local deterministic owner task (read-only + tests)
-> execution_result
-> AP-765 StewardshipRuntimeResultBridgeService (Evidence / Product Mode / Portfolio)
```

AP-767 **composes** the existing owners; it does not duplicate them. Real
provider-backed code generation stays with the operator-gated AP-758/AP-759
owner runtimes. When no canonical provider runtime is wired for the capability
slots, AP-767 reports `provider_bridge_missing` with a clear contract instead of
faking a provider.

AP-767 is not a Dev runtime, Forge runtime, provider driver, scheduler, branch
materializer, merge engine or deployment engine. It is invoked by the operator
(or via the operator-gated `dev-forge-execute` CLI); the AP-766 Continuous
Stewardship Runner never auto-invokes it.

## Input

- `area_id` (canonical area, default `agentic_engineering_os`);
- `owner` = `atlas_dev` | `forge` (`atlas_forge` is normalised to `forge`);
- `mode` = `dry-run` | `execute` (default `dry-run`);
- source: `handoff_id` / `finding_id` / `spec_id` (or a structured `source` with
  `kind` + `id`), plus `allowed_files` and/or a `spec`;
- `sandbox`: a real AP-756 materializer record (with a nested `materialization`
  block) **or** a flat test descriptor `{sandbox_id, branch_name, worktree_path,
  base_ref, isolated, allowed_paths}`;
- optional: `provider_profiles` (capability-slot bindings), `budget`,
  `kill_switch`, `run_local_deterministic_task`, `test_commands`, `record_result`.

## Output

`execution_id`, `owner`, `sandbox_id`, `status`, `plan`, `commands_considered`,
`commands_executed`, `changed_files`, `test_commands`, `test_results`,
`evidence_refs`, `next_state`, `operator_review_required` (always `true`), plus
the integration extras `consumption_gate`, `provider_bridge`, `execution_result`
(AP-765-compatible), `claim_policy` and `source_ap_contracts`.

`status` is one of:

- `planned` — dry-run plan, no mutation, no execution;
- `executed_local_deterministic_task` — read-only + test proof ran in the sandbox;
- `provider_bridge_missing` — execute requested with scope but no bound provider
  runtime and no local task opt-in;
- `blocked_needs_operator_or_spec` — execute requested without `allowed_files`/spec;
- `blocked` — owner-specific consumption gate failed.

## Owner-specific consumption gate

The gate blocks unless:

- a sandbox descriptor is present (`sandbox_required`);
- the sandbox branch is **not** main/master/develop/HEAD
  (`sandbox_not_isolated_branch_on_main`) and the descriptor is marked isolated
  (`sandbox_not_isolated`) with a `worktree_path`;
- the sandbox declares `allowed_paths` (`allowed_paths_required`);
- no merge/deploy/push is requested (`merge_deploy_push_forbidden`);
- the Product Mode / per-area kill switch is off (`kill_switch_active`);
- the provider budget is not exhausted when an execute run would use it
  (`provider_budget_exhausted`).

## Safety invariants (claim policy)

- never invokes a provider; never fabricates provider output;
- runs only allowlisted read-only (`git status|diff|log|rev-parse|show|branch`)
  and test (`php artisan test`, `composer test`, `phpunit`, `pest`) commands,
  inside the isolated worktree, with shell metacharacters rejected;
- mutation requires explicit `allowed_files`/spec **and** a real provider runtime;
  the local deterministic task itself stays read-only + test;
- creates no branch/worktree; performs no merge/deploy/external push/secret
  access/destructive change; creates no parallel runtime or new OS;
- `operator_review_required` is always true; results feed AP-765 before any
  irreversible action.

## CLI

```bash
php artisan atlas:software-company-stewardship dev-forge-execute \
  --area=agentic_engineering_os --owner=atlas_dev \
  --handoff=<id> --sandbox=<id> --mode=dry-run --json
```

`--sandbox-descriptor-file` (or the AP-756 `--sandbox-record-file`) supplies the
real sandbox; `--allowed-file` (repeatable), `--run-local-task`,
`--record-bridge-result` and `--mode=execute` drive the execute path.

## Acceptance criteria

- Missing sandbox blocks; main/no-isolation blocks; kill switch blocks.
- `--mode=dry-run` produces a plan and `commands_considered` with no mutation,
  no execution and no recorded receipt.
- `--mode=execute` without `allowed_files`/spec returns
  `blocked_needs_operator_or_spec`; with scope but no provider/local task returns
  `provider_bridge_missing`.
- The local deterministic task runs only allowlisted read-only/test commands and
  writes an idempotent append-only receipt when requested.
- The emitted `execution_result` is accepted by AP-765
  `StewardshipRuntimeResultBridgeService` (`ready_for_operator_review`).
- Both `atlas_dev` and `forge` route to their respective owner runtime schemas.
- Claim policy proves no provider, no branch/worktree, no merge/deploy/push,
  no secrets, no destructive change, no parallel runtime, no new OS.
