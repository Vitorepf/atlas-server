---
id: AP-756-area-focus-branch-sandbox-materializer-contract
type: architecture_proposal
title: AP-756 Area Focus Branch Sandbox Materializer Contract
status: accepted
owner: programming
created_at: 2026-05-27
summary: Materializes an AP-726 Area Focus branch sandbox into an isolated local git branch/worktree only after an explicit operator sandbox receipt. AP-756 reuses AP-726/AP-747/AP-749/AP-758/AP-759 owners, writes only an append-only JSONL sandbox record, and does not run Dev/Forge, invoke providers, apply fixes, mutate the source worktree, merge, deploy, push externally, access secrets or perform destructive changes.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
  - docs/engineering-knowledge-base/atlas-area-stewardship-layer.md
  - docs/ap/AP-715-software-company-stewardship-stack-contract.md
  - docs/ap/AP-726-area-focus-branch-sandbox-preflight-handoff-contract.md
  - docs/ap/AP-747-area-focus-dev-forge-release-contract.md
  - docs/ap/AP-749-owner-specific-dev-forge-queue-consumption-gate-contract.md
  - docs/ap/AP-758-owner-runtime-execution-adapter-contract.md
  - docs/ap/AP-759-owner-sandbox-runtime-runner-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxMaterializerService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxHandoffService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxPreflightService.php
  - app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxMaterializerServiceTest.php
requires_evidence: true
risk_level: critical
---
# AP-756 Area Focus Branch Sandbox Materializer Contract

## Decision

AP-726 prepares branch sandbox metadata. AP-747 can release that handoff into
owner queues. AP-756 is the missing physical boundary between "branch plan" and
"isolated local worktree exists".

AP-756 may create exactly one local git branch/worktree pair for a ready AP-726
handoff, but only with an explicit operator sandbox receipt.

It is deliberately not an executor. It does not call Atlas Dev, Forge,
providers, merge, deploy, push externally, access secrets, apply patches or run
the work order.

## Anti-Duplication Resolution

Feature placement and ACRUI report same-owner overlap with Area Focus, Product
Mode, Area Stewardship and Dev/Forge release surfaces. That overlap is correct.
AP-756 reuses the existing chain:

```text
AP-726 branch sandbox handoff
-> AP-756 operator-receipted branch/worktree materialization
-> AP-757 sandbox binding inside AP-749
-> AP-747/AP-749 owner release and consumption gates
-> AP-758 owner runtime execution adapter
-> AP-759 owner sandbox runtime runner when operator-authorized
-> Atlas Dev / Forge owned execution
-> AP-750 owner runtime result bridge
```

AP-756 does not create a Stewardship OS, branch manager, Dev path, Forge path,
scheduler, Product Mode ledger or provider path.

## Required Receipt

The input receipt must include:

```text
decision = materialize_sandbox | approve_branch_sandbox
operator_actor
target_handoff_hash | target_hash
```

Optional fields:

```text
sandbox_id
sandbox_receipt_id
receipt_hash
```

## Boundary

AP-756 may:

- validate an AP-726 ready handoff;
- validate a safe branch name with prefix `area-focus/` or `atlas/area-focus/`;
- resolve a git repository root and base ref;
- create an isolated local worktree using `git worktree add -b`;
- append an idempotent JSONL sandbox record;
- list and replay materialized sandbox records;
- safely clean up a previously materialized sandbox: it removes only the
  isolated worktree (which always lives inside the controlled worktrees root)
  via `git worktree remove`, may optionally delete the sandbox branch, and
  appends an idempotent JSONL cleanup event that folds a `cleaned` lifecycle
  state onto the original record. Cleanup refuses to remove a dirty worktree
  without `--allow-dirty-removal` and refuses to delete a branch with unmerged
  commits without `--allow-unmerged-branch-delete`.

AP-756 must not:

- execute the work order;
- invoke Atlas Dev, Forge or any provider;
- apply a fix, patch, commit, merge, deploy or external push;
- touch secrets or perform destructive changes;
- mutate the source worktree contents;
- run `git reset`/`git checkout` or any destructive history rewrite during
  cleanup, or discard user changes outside the isolated sandbox worktree;
- auto-approve AP-724 decisions, AP-747 releases, AP-749 consumption, AP-759
  owner command execution or AP-750 outcomes, including AP-758 owner runtime
  adapter start;
- create a new OS/runtime/executor.

## Schemas

```text
atlas.software_company_stewardship.area_focus_branch_sandbox_materializer.v1
atlas.software_company_stewardship.area_focus_branch_sandbox_materializer_record.v1
atlas.software_company_stewardship.area_focus_branch_sandbox_materializer_records.v1
atlas.software_company_stewardship.area_focus_branch_sandbox_materializer_cleanup.v1
```

## CLI

Dry-run materialization plan:

```text
php artisan atlas:software-company-stewardship area-focus-branch-sandbox-materialize \
  --preflight-file=<ap726.json> \
  --sandbox-receipt-file=<ap756-sandbox-receipt.json> \
  --repo-root=<repo> \
  --base-ref=HEAD \
  --json
```

Create the isolated branch/worktree and record it:

```text
php artisan atlas:software-company-stewardship area-focus-branch-sandbox-materialize \
  --preflight-file=<ap726.json> \
  --sandbox-receipt-file=<ap756-sandbox-receipt.json> \
  --repo-root=<repo> \
  --base-ref=HEAD \
  --materialize-sandbox \
  --json
```

List and replay records:

```text
php artisan atlas:software-company-stewardship area-focus-branch-sandboxes --json
php artisan atlas:software-company-stewardship area-focus-branch-sandbox-replay --sandbox-id=<id> --json
```

Dry-run cleanup plan (nothing is removed):

```text
php artisan atlas:software-company-stewardship area-focus-branch-sandbox-cleanup \
  --sandbox-id=<id> \
  --json
```

Remove the isolated worktree (and optionally the branch) safely:

```text
php artisan atlas:software-company-stewardship area-focus-branch-sandbox-cleanup \
  --sandbox-id=<id> \
  --remove-sandbox \
  --delete-branch \
  --json
# add --allow-dirty-removal to remove a worktree with uncommitted changes
# add --allow-unmerged-branch-delete to delete a branch with unmerged commits
```

## Status Semantics

| State | Meaning |
|---|---|
| `planned` | Receipt, handoff, repo and branch name are valid; no branch/worktree exists. For cleanup, this is the dry-run plan and nothing was removed. |
| `materialized` | Branch/worktree was created and JSONL record was appended. |
| `cleaned` | The isolated worktree was removed (branch optionally deleted) and an idempotent JSONL cleanup event was appended; the original record is retained. |
| `blocked` | Required receipt, target handoff, branch name, repo, base ref or git worktree operation failed, or a cleanup safety guard refused removal. |

## Acceptance

- Missing AP-726 preflight blocks.
- Missing explicit sandbox receipt blocks.
- Non-matching `target_handoff_hash` blocks.
- Unsafe branch names block before any git mutation.
- Dry-run validates repo/base-ref but does not create a branch/worktree.
- `--materialize-sandbox` creates a local isolated branch/worktree and records
  it idempotently.
- Re-running the same sandbox returns the existing record instead of creating
  another branch/worktree.
- Cleanup on an unknown sandbox blocks (`sandbox_record_not_found`).
- Dry-run cleanup never removes the worktree or branch.
- `--remove-sandbox` removes only the isolated worktree, appends an idempotent
  cleanup event, and folds a `cleaned` lifecycle state onto the record; a
  repeated cleanup returns the existing cleanup event.
- A dirty worktree blocks cleanup unless `--allow-dirty-removal` is given; a
  branch with unmerged commits blocks `--delete-branch` unless
  `--allow-unmerged-branch-delete` is given.
- Claim policy proves no provider, no Dev/Forge dispatch, no fix, no source
  worktree mutation, no merge/deploy/push/secrets/destructive action, no
  `git reset`/`git checkout`, no product code mutation and no new
  OS/runtime/executor.
