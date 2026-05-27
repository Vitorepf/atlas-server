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
- AP-747/AP-756/AP-757/AP-749/AP-758/AP-759/AP-750 owner-flow chain for real
  Atlas Dev/Forge execution.
- Native Obra / work-packet semantics from Forge Continuum OS.
- Self-Directed Evolution / SDD packet before implementation.
- TDD test contract and BDD acceptance contract before provider execution.
- Atlas Decide provider topology, AAWR/multi-agent workcell and role assignment.
- Universal Gates, Programming Governance, deterministic validation suite and
  repair loop with failed-gate capsule.
- Evidence Ledger, Decision Receipts, replay/reproduction packet and rollback
  instructions before merge.
- `cursor_cli` governed provider driver using local Cursor login only as an
  explicitly authorized legacy diagnostic path; it must never be claimed as full
  Atlas Forge or Atlas Dev execution by itself.
- AP-765 Evidence/Product Mode/Inbox result bridge.
- AP-769/AP-774 merge governor and merge autonomy policy.

## Required Behavior

For `area_id=agentic_engineering_os` and `focus=dev_forge`, a session may run
one or more cycles:

1. scan the area for bugs, gaps, risks and improvements;
2. rank candidates by largest real advancement and robustness;
3. materialize an isolated branch/worktree;
4. pass the owner-flow integrity gate before any provider execution;
5. prove the robust Obra/Forge quality contract before execution:
   SDD/spec packet, TDD contract, BDD acceptance contract, provider topology,
   multi-agent role plan, universal gates, validation suite, repair-loop policy,
   Evidence/Decision Receipts and replay packet;
6. invoke providers only through Atlas Dev/Forge owner authority, or block with
   `full_atlas_forge_flow_required`;
7. use the direct provider driver only when `allow_direct_provider_driver=true`
   and label it as legacy diagnostic, not full Forge;
8. commit only scoped sandbox changes;
9. emit an operator Inbox item with what was found, what changed, why it matters,
   evidence and rollback;
10. evaluate merge eligibility with AP-769/AP-774;
11. fast-forward merge to `main` only when the policy proves the branch eligible;
12. pull/update `main` before the next cycle when configured;
13. record an append-only session receipt.

## Robust Flow Contract

AP-786 is not allowed to optimize for "many small commits" or "provider did
something." The autonomous loop must optimize for the software factory becoming
more powerful, faster, safer and more compounding.

Every real execution must prove these capabilities:

| Capability | Meaning |
|---|---|
| `native_obra_or_work_packet` | Work is a governed Obra/work packet, not a loose prompt. |
| `self_directed_spec_or_sdd_packet` | The system writes or selects the spec/SDD before implementation. |
| `tdd_test_contract` | Tests are declared before the implementation path runs. |
| `bdd_acceptance_contract` | User-visible acceptance behavior is explicit. |
| `atlas_decide_provider_topology` | Provider/model/role choice comes from Atlas Decide, not a hardcoded shortcut. |
| `aawr_or_multi_agent_workcell` | Context scout, architect, implementer, reviewer and repair roles are explicit. |
| `universal_gates_and_programming_governance` | Universal gates and programming governance run before result claim. |
| `deterministic_validation_suite` | Validation is executable and replayable. |
| `repair_loop_with_failed_gate_capsule` | Failed gates feed repair attempts with captured failure context. |
| `evidence_ledger_and_decision_receipts` | Result is evidenced by receipts, not chat narration. |
| `replay_or_reproduction_packet` | The work can be replayed or reproduced later. |
| `ap769_ap774_merge_governance` | Merge is governed, ff-only when eligible, and never hidden. |

If any capability is absent, the cycle must block or downgrade itself to
diagnostic evidence. It must not produce a commit that claims autonomous Atlas
Forge execution.

## Non-Negotiable Safety

- No direct provider call outside Atlas provider drivers.
- No claim of "full Atlas Forge" or "full Atlas Dev" when the execution path is
  only a provider driver plus Atlas-shaped prompt.
- Execute mode blocks by default with `full_atlas_forge_flow_required` until the
  owner-flow chain AP-747 -> AP-756 -> AP-757 -> AP-749 -> AP-758 -> AP-759 ->
  AP-750 is the authority for provider execution.
- A provider cannot be the architecture. Providers are executors inside Obra,
  SDD/TDD/BDD, Atlas Decide, multi-agent choreography, gates, repair, Evidence
  and replay.
- `--allow-direct-provider-driver` is legacy diagnostic only and must not be used
  for benchmark, superiority or autonomous factory claims.
- No merge without AP-769/AP-774 eligibility.
- No rebase, force-push, deploy, secret access or destructive operation.
- No broad code auto-merge unless the change is declared `bugfix` or `cleanup`,
  validation passed, file count is within policy and `allow_code_auto_merge=true`.
- `code_or_mixed` remains human-review only.
- Inbox is emitted before merge attempt so the operator can audit what happened.
- Failed provider execution, empty diff, validation failure or dirty base stops
  the cycle and records the blocker.
- Code auto-merge must run at least one focused PHP test suite for the touched
  factory runtime. `git diff --check` and `docs-health` are not sufficient for
  AP-786 code changes.

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
  --validation-command="git diff --check" \
  --validation-command="php artisan test tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionServiceTest.php" \
  --json
```

Legacy diagnostic, not full Forge:

```bash
php artisan atlas:software-company-stewardship:autonomous-evolution-session \
  --area=agentic_engineering_os \
  --focus=dev_forge \
  --cycles=1 \
  --execute \
  --allow-direct-provider-driver \
  --json
```

Any report produced with `direct_provider_driver_allowed=true` must be treated
as diagnostic evidence only. It cannot support a claim that Atlas Forge or Atlas
Dev were evaluated through their full native flow.

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
- The execution path proves the robust flow contract before provider execution.
- Legacy Cursor CLI diagnostic request includes `decision_receipt_id`,
  `decision_receipt_hash`, `allowed_files`, forbidden paths, workspace and model.
- Execute mode without explicit legacy direct-driver allowance blocks before
  sandbox/provider invocation and emits `flow_integrity_gate.required_chain` and
  `flow_integrity_gate.required_robust_flow_capabilities`.
- The session can be replayed from JSONL.
- Every merge is ff-only and AP-769 governed.
- AP-786 self-hardening changes prove the focused
  `AutonomousEvolutionSessionServiceTest` suite is green before merge.
