---
id: AP-759-owner-sandbox-runtime-runner
type: ap_contract
title: AP-759 Owner Sandbox Runtime Runner Contract
status: active
owner: programming
summary: Executes an explicitly approved Atlas Dev or Forge owner CLI command inside the AP-756 materialized sandbox after AP-758 projection, then emits an AP-750-compatible owner result. AP-759 is the first real owner command runner in the Stewardship Stack, but it creates no OS, runtime, provider path, branch, worktree, merge, deploy, external push, secret access or destructive action.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-area-stewardship-layer.md
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/ap/AP-749-owner-specific-dev-forge-queue-consumption-gate-contract.md
  - docs/ap/AP-756-area-focus-branch-sandbox-materializer-contract.md
  - docs/ap/AP-757-owner-queue-sandbox-binding-contract.md
  - docs/ap/AP-758-owner-runtime-execution-adapter-contract.md
  - docs/ap/AP-760-product-mode-owner-sandbox-runtime-visibility-contract.md
  - docs/ap/AP-750-owner-runtime-result-bridge-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOwnerSandboxRuntimeRunnerService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOwnerRuntimeResultBridgeService.php
  - app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOwnerSandboxRuntimeRunnerServiceTest.php
requires_evidence: true
risk_level: critical
---
# AP-759 Owner Sandbox Runtime Runner Contract

## Decision

AP-759 is the governed execution boundary after AP-758.

```text
AP-749 ready owner queue consumption
-> AP-756 materialized branch/worktree sandbox
-> AP-757 sandbox binding
-> AP-758 owner runtime execution adapter/projection
-> AP-759 approved owner command runs inside the AP-756 worktree
-> AP-750 owner runtime result bridge
```

AP-758 proves that the owner handoff is shaped correctly. AP-759 performs the
first concrete owner command execution, but only by calling existing allowlisted
owner CLIs inside the AP-756 worktree and only after an explicit operator command
receipt. AP-760 is the Product Mode visibility layer for AP-759; AP-759 remains
the execution boundary.

AP-759 is not a Dev runtime, Forge runtime, provider driver, new scheduler,
branch materializer, merge engine or deployment engine.

## Required Inputs

AP-759 requires:

- AP-758 report or record with status `ready_for_ap750_result_bridge` or
  `owner_runtime_execution_recorded`;
- AP-758 sandbox check proving the AP-756 worktree exists and matches the
  recorded hash;
- target owner `atlas_dev` or `forge`;
- explicit runtime command receipt with decision
  `execute_owner_runtime_in_sandbox`, `run_owner_runtime_command` or
  `invoke_owner_runtime_command`;
- `operator_actor`;
- command as an argv array, not a shell string;
- Product Mode kill switch off;
- `allow_runtime_command_execution=true` before execute mode;
- provider and budget approvals when the command may invoke a provider.

## Allowlist

The command must be a direct PHP artisan argv array:

```text
[php, artisan, <allowlisted-owner-command>, ...args]
```

`artisan` may be either the literal `artisan` inside the AP-756 worktree or the
canonical `base_path()/artisan` entrypoint. The latter is allowed so git
worktrees without `vendor/` can still run the owner CLI while all target
workspace mutation remains inside the AP-756 worktree.

Allowed owners:

| Owner | Commands |
|---|---|
| `atlas_dev` | `atlas:dev:run-worker`, `atlas:dev:senior-loop:run`, `atlas:programming:console` |
| `forge` | `atlas:forge:provider-invoke`, `atlas:forge:runtime-dispatch`, `atlas:forge:parallel-durable`, `atlas:programming:console` |

Shell metacharacters, shell strings, arbitrary binaries and non-owner commands
are blocked.

Provider-capable commands (`atlas:dev:run-worker`,
`atlas:dev:senior-loop:run`, `atlas:forge:provider-invoke`) require
`provider_execution_authorized=true` and `budget_approved=true`.
`atlas:forge:provider-invoke --mode=execute` also
requires `--confirm-provider-call`, `--confirm-budget` and
`--confirm-runtime-dispatch`.

For Atlas Dev owner execution, `--provider-choice` and `--composer-model` are
runtime authority inputs, not cosmetic CLI labels. The owner command must carry
them into Atlas Dev planning so the emitted task contract has the matching
`provider_lock`. A command that requests `cursor_cli` must execute through the
governed Cursor CLI path and must not silently fall back to `claude_cli/sonnet`.
Because Cursor mutates the sandbox worktree directly, Atlas Dev derives the
post-run git diff, skips patch re-application, and still runs ScopeGuard,
verification and completion gates before AP-750 can bridge the result.

## Output Schemas

```text
atlas.software_company_stewardship.owner_sandbox_runtime_runner.v1
atlas.software_company_stewardship.owner_sandbox_runtime_run_record.v1
atlas.software_company_stewardship.ap759_owner_runtime_command.v1
atlas.software_company_stewardship.owner_runtime_result.v1
```

The embedded `owner_result` uses AP-750's
`atlas.software_company_stewardship.owner_runtime_result.v1` schema. AP-759 does
not mark the work accepted; AP-750 and the operator review surface own that.
AP-759 must inspect owner CLI JSON when present. `exit_code=0` is not sufficient
for success: if the embedded owner command reports `status=failed|blocked`,
non-empty blockers, or Atlas Dev `run_summary.completion_state=no_patch_needed`,
AP-759 emits `command_result.status=failed` and
`owner_result.result_status=failed` so AP-786 cannot attempt merge on an empty
or failed branch.

## CLI

Projection only:

```bash
php artisan atlas:software-company-stewardship owner-sandbox-runtime-run \
  --execution-file=<ap758.jsonl> \
  --owner-execution-id=<owner_execution_id> \
  --runtime-command-receipt-file=<ap759-command-receipt.json> \
  --json
```

Execute and record:

```bash
php artisan atlas:software-company-stewardship owner-sandbox-runtime-run \
  --execution-file=<ap758.jsonl> \
  --owner-execution-id=<owner_execution_id> \
  --runtime-command-receipt-file=<ap759-command-receipt.json> \
  --execute-owner-command \
  --record-owner-run \
  --json
```

Then bridge the emitted owner result:

```bash
php artisan atlas:software-company-stewardship owner-runtime-result-bridge \
  --consumption-file=<ap749.jsonl> \
  --result-file=<ap759-owner-result.json> \
  --record-result \
  --json
```

## Boundary

AP-759 may:

- read AP-758 reports or records;
- verify the AP-756/AP-757 sandbox chain;
- validate an explicit operator command receipt;
- execute an allowlisted owner CLI command inside the AP-756 worktree;
- capture stdout/stderr excerpts, exit code, duration and git status;
- emit an AP-750-compatible owner result for success or failure;
- append an idempotent JSONL run record when requested.

AP-759 must not:

- create a new OS, runtime, provider path or scheduler;
- choose arbitrary commands;
- run shell strings;
- create branch/worktree; AP-756 owns that;
- bypass Atlas Dev or Forge;
- merge, deploy, push externally, access secrets or perform destructive changes;
- mark a result accepted without AP-750 and operator review.

## Acceptance

- Blocks without AP-758.
- Blocks if AP-758 is not ready/recorded.
- Blocks without AP-756 worktree evidence.
- Blocks without explicit command receipt.
- Blocks non-allowlisted commands and shell metacharacters.
- Plans command by default without execution.
- Executes allowlisted Atlas Dev command inside AP-756 worktree only when
  execute mode and receipt authority are both present.
- Accepts `atlas:dev:senior-loop:run` as the canonical Atlas Dev live owner CLI
  for the first mutation proof, including `--create-fixture-workspace` when the
  operator wants the fixture created inside the AP-756 worktree.
- Autonomous AP-786 calls to `atlas:dev:senior-loop:run` must carry real
  worktree-scoped `--allowed-file=*` and `--validation-command=*` arguments from
  the selected finding. Fixture-only Senior Loop defaults are valid for smoke
  tests, but they must not govern Area Focus / 24h loop execution.
- Those `--allowed-file=*` arguments are the write-scope authority for the
  owner command. Atlas Dev discovery may include neighboring files as context,
  but it must not convert an explicitly bounded owner-runtime task into an R4
  Forge preview only because discovery found more related files.
- Autonomous AP-786 calls that request Cursor must carry
  `--provider-choice=cursor_cli --composer-model=composer-2.5-fast`, and the
  resulting Atlas Dev `provider_lock` must match those values.
- Emits AP-750-compatible owner result for completed and failed owner commands.
- Treats Atlas Dev JSON `no_patch_needed` as a failed/no-progress owner result,
  even when the process exits zero, and preserves provider-call evidence from
  `run_summary.provider_call`.
- Records append-only/idempotent JSONL without re-running duplicate run ids.
- AP-750 accepts the AP-759 owner result when identity, evidence and isolation
  checks pass.
- `php artisan test tests/Unit/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOwnerSandboxRuntimeRunnerServiceTest.php` passes.
