---
id: AP-801-multi-agent-live-cycle-executor
type: ap_contract
title: AP-801 Multi-Agent Live Cycle Executor Contract
status: active
implementation_state: implemented
owner: software_company_stewardship
summary: Integrator that composes the AP-795..AP-800 substrate into ONE governed multi-agent workcell cycle for a single finding/slice, wired into AP-786/AP-790 behind an explicit flag. AP-801 runs the lanes context_scout -> architect -> implementer -> reviewer -> repair_agent -> judge over a real (or injected fixture) owner-runtime result; it records a durable session per lane, judges integration, plans repair on failure and certifies the cycle read-only. It NEVER invokes a provider, opens a branch or merges - the real provider/owner execution stays in the AP-747..AP-750 owner-flow chain. It is not a new runtime, scheduler, provider router or Sandcastle clone.
related_paths:
  - docs/ap/AP-793-atlas-isolated-agent-execution-substrate-contract.md
  - docs/ap/AP-795-agent-execution-provider-port-session-store-contract.md
  - docs/ap/AP-796-finding-slice-planner-runtime-contract.md
  - docs/ap/AP-797-multi-agent-lane-orchestrator-contract.md
  - docs/ap/AP-798-integration-lane-judge-contract.md
  - docs/ap/AP-799-repair-agent-failure-capsule-contract.md
  - docs/ap/AP-800-multi-agent-cycle-certification-visibility-contract.md
  - docs/ap/AP-786-autonomous-evolution-session-contract.md
  - docs/ap/AP-790-reliable-24h-autonomous-loop-runner-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/AgentExecution/MultiAgentLiveCycleExecutorService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerService.php
  - app/Console/Commands/AtlasSoftwareCompanyAutonomousEvolutionSessionCommand.php
  - app/Console/Commands/AtlasSoftwareCompanyReliable24hLoopCommand.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/AgentExecution/MultiAgentLiveCycleExecutorServiceTest.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/MultiAgentWorkcellWiringTest.php
requires_evidence: true
risk_level: critical
---
# AP-801 Multi-Agent Live Cycle Executor Contract

## Authority

AP-801 is the integrator that turns the AP-793 Phase 4 multi-agent substrate
(AP-795..AP-800) into one **live** governed workcell cycle, and wires it into the
real AP-786 autonomous evolution session and AP-790 reliable loop runner.

Before AP-801 the substrate existed as isolated, well-tested services with no
single composition path and no wiring into the loop. AP-801 closes that gap:

```text
AP-796 FindingSlicePlannerService        decompose finding -> executable slice (gate)
AP-797 MultiAgentLaneOrchestratorService context_scout -> ... -> judge lane plan
AP-795 AgentExecutionProviderPortService normalize each lane's provider facts
AP-795 AgentExecutionSessionStoreService durable per-lane session receipts
AP-798 MultiAgentIntegrationJudgeService deterministic integration verdict
AP-799 MultiAgentRepairPlannerService    failure capsule + repair decision
AP-800 MultiAgentCycleCertificationService read-only cycle certification
```

AP-801 is **not** a new OS, scheduler, provider router, Dev/Forge runtime,
Sandcastle clone or parallel evidence ledger. It composes existing services and
the existing owner-flow result; it forks none of them.

## The Hard Honesty Rules

- AP-801 **never invokes a provider**, opens a branch, merges or mutates the repo.
  The real provider/owner execution happens in the AP-747 -> AP-756 -> AP-757 ->
  AP-749 -> AP-758 -> AP-759 -> AP-750 owner-flow chain inside AP-786. AP-801
  consumes that chain's result (`owner_runtime_result`).
- `provider_invoked=true` is set only from a REAL owner-runtime result
  (`provider_invoked` true and not simulated). Plans, deferrals and fixtures are
  never reported as a real provider run.
- If the owner runtime / provider is unavailable in execute mode, the status is
  `deferred`/`blocked` with a machine-readable blocker
  (`owner_runtime_unavailable`), never success.
- A broad/strategic/self-referential `factory_max` finding with no executable
  AP-796 slice blocks (`operator_or_architect_spec_required`); no lane runs.
- Production is certified only by AP-800 in `runtime_real` with real authority;
  `test_mode` (fixtures, the default) never certifies production.
- Test doubles / fixtures are unit-test inputs only; they never become a runtime
  claim. There is no test double wired at runtime.

## Lanes And Write Authority

The workcell runs the canonical AP-793/AP-797 lanes. Only the implementer (and a
conditional repair_agent) may write; reviewer and judge are read-only:

| Lane | Write authority | Role in AP-801 |
|---|---|---|
| `context_scout` | `read_only` | governance/planning lane (no provider) |
| `architect` | `spec_only` | governance/planning lane (no provider) |
| `implementer` | `worktree_write` | maps to the real owner-runtime provider run |
| `reviewer` | `read_only` | deterministic reviewer opinion (no LLM) |
| `repair_agent` | `repair_branch_write` | present only when validation/gate failed |
| `judge` | `read_only_no_merge` | AP-798 integration verdict |

Each lane is recorded as a durable session
(`atlas.agent_execution.session_store.v1`) keyed by the loop session id and the
lane, so `replay(session_id)` returns every lane of a cycle and
`latestByCycleId(cycle_id)` returns the most recent lane.

## Flow

```text
finding (+ optional slice_plan / executable_slice)
  -> AP-796 resolve executable slice           (block if broad factory_max unsliced)
  -> AP-797 orchestrate lane plan              (repair lane only if validation/gate failed)
  -> AP-795 record one session per lane        (implementer = real owner-runtime facts)
  -> provider availability gate                (execute + no owner runtime -> blocked)
  -> AP-798 judge integration                  (accepted / repair_required / rejected / ...)
  -> AP-799 plan repair                         (when judge did not cleanly accept)
  -> AP-800 certify cycle (read-only)          (test_mode never certifies production)
  -> final receipt
```

## Input

`MultiAgentLiveCycleExecutorService::execute(array $input)`:

| Field | Meaning |
|---|---|
| `finding` | Selected finding (used to slice when no slice/slice_plan is supplied). |
| `executable_slice` | Optional explicit AP-794 slice (AP-786 balanced cycle). |
| `slice_plan` | Optional pre-computed AP-796 plan (AP-786 factory_max cycle). |
| `owner_runtime_result` | The real (or injected fixture) owner-flow/provider result the implementer lane composes. Absent + execute -> blocked. |
| `execute` | `true` => execution_ready; `false` => plan_only (nothing runs). |
| `scope_profile` | `balanced` or `factory_max` (broad-finding gate). |
| `area_id`, `focus`, `session_id`, `cycle_id` | Loop correlation. |
| `use_real_services` | AP-800 certification mode flag (only true under real authority). |

## Output

Schema: `atlas.agent_execution.multi_agent_live_cycle.v1`

Required receipt fields:

```text
status            blocked | planned | deferred | repair_required | rejected |
                  operator_review_required | accepted_pending_merge_governor
selected_finding  finding id / title / kind
slice_id          executable slice id
lane_count        number of lane sessions
lane_sessions[]   per-lane { lane_id, role, write_authority, invocation_state,
                  provider_invoked, agent_session_id, session_hash, status }
provider_invoked  true only for a real owner-runtime invocation
write_lane        the implementer lane id (only default write lane)
judge_decision    AP-798 status + reason + judgement_id + all_gates_passed
repair_decision   AP-799 classification + decision + repair_allowed (or null)
merge_eligible    judge accepted AND a real provider invocation occurred
production_certified  AP-800 verdict (only ever true in runtime_real)
blockers[]        machine-readable blockers
```

## Wiring (implemented)

- **Config**: `atlas.software_company_stewardship.multi_agent_workcell`
  (env `ATLAS_STEWARDSHIP_MULTI_AGENT_WORKCELL`), default `false`.
- **CLI**: `--multi-agent-workcell` on
  `atlas:software-company-stewardship:autonomous-evolution-session` and on
  `atlas:software-company-stewardship:reliable-24h-loop` (forwarded to AP-786).
- **AP-786** (`AutonomousEvolutionSessionService`): when the flag is on, each
  EXECUTED cycle is projected through the executor using the cycle's own finding,
  AP-796 slice plan and owner-runtime facts; the workcell receipt is attached to
  the cycle and a summary to the session payload under `multi_agent_workcell`.
  Dry-run / pre-provider-blocked cycles are marked `not_executed` (no false
  provider claim). Flag off => the legacy single-pass flow is unchanged (no
  `multi_agent_workcell` key). The projection is defensive (wrapped) so a
  substrate failure can never break the AP-786 session.
- **AP-790** (`Reliable24hLoopRunnerService`): forwards `multi_agent_workcell` to
  the wrapped AP-786 session unchanged; it adds no provider call of its own.

## Run A Multi-Agent Cycle

```bash
php artisan atlas:software-company-stewardship:autonomous-evolution-session \
  --area=agentic_engineering_os --focus=dev_forge --scope-profile=factory_max \
  --cycles=1 --execute --multi-agent-workcell \
  --validation-command="git diff --check" \
  --validation-command="php artisan test tests/Unit/Ai/SoftwareCompanyStewardship/AgentExecution/MultiAgentLiveCycleExecutorServiceTest.php" \
  --record --json
```

The cycle is a real multi-agent cycle only when the wrapped AP-786 owner-flow
chain actually invoked a provider under Atlas authority and the AP-800
certification carries the real-cycle facts. Without that, the workcell reports
`deferred`/`blocked` with the exact blocker.

## Safety Rules

- No provider call, branch, merge, deploy or secret access from AP-801.
- No real-cycle claim without a real owner-runtime provider invocation.
- No production certification outside AP-800 `runtime_real` with real authority.
- No new runtime, scheduler, provider router, evidence ledger or Sandcastle clone.
- Reviewer and judge lanes never write; only the implementer (and conditional
  repair_agent) hold write authority.
- Repair runs only when the judge did not cleanly accept (validation/gate
  failure), and AP-799 still governs budget and the no-repeat rule.

## Acceptance

- A happy path with an injected owner-runtime result yields
  `accepted_pending_merge_governor`, `provider_invoked=true`, five lanes, an
  accepted judge verdict and `merge_eligible=true`.
- Execute mode with no owner runtime is `blocked` (`owner_runtime_unavailable`),
  never success.
- A broad `factory_max` finding with no executable slice is `blocked`
  (`operator_or_architect_spec_required`) with zero lanes.
- A validation failure produces an AP-799 repair plan and a `repair_agent` lane.
- Reviewer and judge lanes carry read-only authority; only the implementer lane
  is a write lane and a real provider invocation.
- `test_mode` never certifies production, even on the happy path.
- Each lane records a distinct durable session; `replay`/`latestByCycleId` work.
- The flag off preserves the legacy AP-786 flow with no `multi_agent_workcell`.
