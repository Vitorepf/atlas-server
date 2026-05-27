---
title: AP-786 Autonomous Evolution Session Contract
status: active
implementation_state: implemented
requires_evidence: true
owner: software_company_stewardship
---

# AP-786 Autonomous Evolution Session Contract

## Authority

AP-786 is the governed "run the loop for real" session for the Atlas Software
Company Stewardship Stack. It is not a new OS and it does not replace Area Focus,
Self-Directed Evolution, Branch Sandbox, Dev/Forge, Evidence, Inbox, Product Mode
or Branch Merge Governor.

It composes:

- AP-748 deep finding scan.
- AP-785 priority engine.
- AP-756 branch sandbox materializer.
- `cursor_cli` governed provider driver using local Cursor login.
- AP-765 Evidence/Product Mode/Inbox result bridge.
- AP-769/AP-774 merge governor and merge autonomy policy.

## Required Behavior

For `area_id=agentic_engineering_os` and `focus=dev_forge`, a session may run
one or more cycles:

1. scan the area for bugs, gaps, risks and improvements;
2. rank candidates by largest real advancement and robustness;
3. materialize an isolated branch/worktree;
4. invoke Cursor CLI only through Atlas provider governance;
5. commit only scoped sandbox changes;
6. emit an operator Inbox item with what was found, what changed, why it matters,
   evidence and rollback;
7. evaluate merge eligibility with AP-769/AP-774;
8. fast-forward merge to `main` only when the policy proves the branch eligible;
9. pull/update `main` before the next cycle when configured;
10. record an append-only session receipt.

## Non-Negotiable Safety

- No direct provider call outside Atlas provider drivers.
- No merge without AP-769/AP-774 eligibility.
- No rebase, force-push, deploy, secret access or destructive operation.
- No broad code auto-merge unless the change is declared `bugfix` or `cleanup`,
  validation passed, file count is within policy and `allow_code_auto_merge=true`.
- `code_or_mixed` remains human-review only.
- Inbox is emitted before merge attempt so the operator can audit what happened.
- Failed provider execution, empty diff, validation failure or dirty base stops
  the cycle and records the blocker.

## CLI

```bash
php artisan atlas:software-company-stewardship:autonomous-evolution-session \
  --area=agentic_engineering_os \
  --focus=dev_forge \
  --cycles=3 \
  --execute \
  --auto-merge \
  --pull-main \
  --record \
  --json
```

## Output

Schema: `atlas.software_company_stewardship.autonomous_evolution_session.v1`

Each cycle includes:

- selected finding and priority report;
- sandbox id, branch and worktree;
- provider/model/auth/billing evidence;
- changed files, commit result and validation;
- Inbox/evidence/Product Mode bridge ids;
- merge governance result;
- pull/update result;
- final status and next action.

## Acceptance

- Dry-run does not create branch, call provider, commit or merge.
- Execute mode creates a real AP-756 sandbox before provider execution.
- Cursor CLI request includes `decision_receipt_id`, `decision_receipt_hash`,
  `allowed_files`, forbidden paths, workspace and model.
- The session can be replayed from JSONL.
- Every merge is ff-only and AP-769 governed.
