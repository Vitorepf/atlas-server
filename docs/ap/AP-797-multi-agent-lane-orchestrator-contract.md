---
id: AP-797-multi-agent-lane-orchestrator
type: ap_contract
title: AP-797 Multi-Agent Lane Orchestrator Contract
status: active
implementation_state: implemented
owner: software_company_stewardship
summary: Canonical contract for the AP-793 Phase 4 multi-agent lane orchestrator. AP-797 turns one AP-794 executable slice (or task packet) into a governed, deterministic lane plan with context_scout, architect, implementer, reviewer, repair_agent and judge lanes. Each lane carries its own write authority, input refs, output contract, budget, timeout, allowed/forbidden actions, receipt and lifecycle state. AP-797 only plans lanes and emits receipts; it never invokes a provider, never mutates a branch, never merges, never deep-scores the judge and never runs repair. It is a substrate mechanic under AP-793, not a fifth multi-agent layer.
related_paths:
  - docs/ap/AP-793-atlas-isolated-agent-execution-substrate-contract.md
  - docs/ap/AP-794-finding-slice-planner-contract.md
  - docs/ap/AP-786-autonomous-evolution-session-contract.md
  - docs/engineering-knowledge-base/atlas-multi-agent-unified-architecture.md
  - docs/engineering-knowledge-base/atlas-agentic-workcell-runtime.md
  - docs/engineering-knowledge-base/atlas-parallel-multi-agent-execution-spec.md
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - app/Services/Ai/SoftwareCompanyStewardship/AgentExecution/MultiAgentLaneOrchestratorService.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/AgentExecution/MultiAgentLaneOrchestratorServiceTest.php
  - docs/ap/AP-802-multi-agent-lane-execution-contract-hardening-contract.md
requires_evidence: true
risk_level: high
---
# AP-797 Multi-Agent Lane Orchestrator Contract

## Authority

AP-797 implements the **Multi-Agent Execution** section of AP-793 (Phase 4). It
is the orchestrator that turns a single bounded unit of work into a governed
multi-agent workcell with explicit lanes, instead of letting one agent do
everything in one undifferentiated pass.

AP-797 is a substrate mechanic. It is **not**:

- a new OS, scheduler, Dev runtime or Forge runtime;
- a fifth multi-agent layer (AAWR topology, Multi-Provider Profiles, Agent
  Control Plane and Forge Scheduler remain the four canonical layers of
  `atlas-multi-agent-unified-architecture.md`);
- a provider router or provider driver;
- a finding engine or slice planner (AP-794 owns decomposition);
- the judge scoring engine (a separate judge/integration service owns scoring);
- the repair execution engine (a separate repair agent service owns repair);
- Product Mode (a separate surface consumes the plan, AP-797 never writes it).

Anti-duplication decision:

```text
reuse_or_extend:
  AP-793 multi-agent lane definitions, write authority table, real-cycle facts
  AP-794 executable_slice schema as the input unit of work
  AP-756 worktree as the only place the implementer/repair lanes may write
  AP-786/AP-790 owner runtime as the eventual lane executor
do_not_create:
  parallel provider port / session store
  parallel slice planner
  parallel judge scoring engine
  parallel repair execution engine
  parallel Product Mode read model
  a fifth multi-agent orchestration layer
```

## Problem

AP-793 declares that several agents may work on one task only through lanes, and
that each lane needs its own receipt, budget and timeout. Before AP-797 there was
no service that produced that lane plan deterministically. Without it, "one agent
does everything" stays the only shape, write authority is implicit, and there is
no auditable contract for which lane may touch the worktree, which lane may only
read, and which lane may never merge.

AP-797 makes the lane plan an explicit, hashable, replayable artifact produced
**before** any provider is invoked.

## Position In The Substrate

```text
AP-748/AP-785 finding
  -> AP-794 executable_slice            (decomposition)
  -> AP-797 multi_agent_lane_plan       (this contract: lanes + receipts + state machine)
  -> AP-793 substrate facts             (provider port, sandbox, session store)
  -> AP-786/AP-790 owner execution      (lanes actually run here)
  -> AP-792 certification
```

AP-797 sits between AP-794 (what enters the substrate) and AP-786/AP-790 (where
lanes actually execute). It owns the **plan and the contract**, never the
execution.

## Schema

```text
atlas.agent_execution.multi_agent_lane_plan.v1
```

Top-level required fields:

| Field | Meaning |
|---|---|
| `schema_version` | `atlas.agent_execution.multi_agent_lane_plan.v1`. |
| `ap_contract` | `AP-797`. |
| `plan_id` | Deterministic plan id derived from the work unit + mode + lane shape. |
| `mode` | `plan_only` or `execution_ready`. |
| `work_unit` | Normalized identity of the source slice/task packet. |
| `lanes[]` | Ordered lanes (see lane schema). |
| `sequence[]` | Ordered list of `lane_id` defining execution order. |
| `repair` | Repair lane inclusion decision and trigger reason. |
| `shared_evidence_pack_ref` | The one evidence pack lanes may share. |
| `state_machine` | Lane and plan lifecycle states + allowed transitions. |
| `claim_policy` | Hard guarantees of this plan (see below). |
| `plan_hash` | Deterministic `sha256:` hash of the plan content. |
| `generated_at` | ISO-8601 timestamp (excluded from `plan_hash`). |

Each lane (`atlas.agent_execution.lane.v1`) carries:

| Field | Meaning |
|---|---|
| `lane_id` | Deterministic id for the lane within this plan. |
| `role` | One of the six canonical roles. |
| `sequence` | Order within the plan. |
| `write_authority` | Canonical authority for the role (see table). |
| `input_refs[]` | Refs the lane consumes (work unit refs + prior lane outputs). |
| `output_contract` | Schema name of the artifact the lane must produce. |
| `budget` | `max_runtime_seconds`, `max_actions` bounds. |
| `timeout_seconds` | Hard timeout for the lane. |
| `allowed_actions[]` | Actions the lane may perform. |
| `forbidden_actions[]` | Actions the lane may never perform. |
| `status` | Initial lifecycle state (`planned` or `ready`); never executing. |
| `receipt` | Per-lane receipt stub (id + lane hash) for later evidence. |

## Canonical Lanes And Write Authority

The six lanes and their write authority are fixed by AP-793 and reproduced here
as the authority for the orchestrator:

| Lane | Role | Write Authority |
|---|---|---|
| `context_scout` | discover files, tests, risks | `read_only` |
| `architect` | spec/SDD/TDD/BDD and slice plan | `spec_only` |
| `implementer` | modify allowed files | `worktree_write` |
| `reviewer` | inspect diff and evidence | `read_only` |
| `repair_agent` | fix a failed gate | `repair_branch_write` |
| `judge` | pick best candidate | `read_only_no_merge` |

## Minimal Sequence

The minimal plan is strictly sequential:

```text
context_scout -> architect -> implementer -> reviewer -> judge
```

`repair_agent` is conditional. It is inserted between `reviewer` and `judge`
**only** when:

- a validation failure is present (`validation_failure_present`); or
- a gate failure is present (`gate_failure_present`); or
- policy explicitly requires it (`policy_requires_repair`).

When the repair lane is present, the sequence becomes:

```text
context_scout -> architect -> implementer -> reviewer -> repair_agent -> judge
```

The repair lane never appears speculatively. A clean slice produces exactly five
lanes.

## Blocking Rules

AP-797 blocks (raises a guarded error with an explicit blocker code) when:

1. `implementer_allowed_files_required` — the implementer lane has no
   `allowed_files`. An implementer with no scope cannot write safely.
2. `judge_write_authority_forbidden` — the judge lane is given any write
   authority other than `read_only_no_merge`.
3. `read_only_lane_write_authority_violation` — any read-only lane
   (`context_scout`, `reviewer`, `judge`) is elevated to a write authority.
4. `forbidden_action_in_lane` — any lane's `allowed_actions` includes a globally
   forbidden action: `merge_to_main`, `deploy`, `delete_files`, `access_secrets`,
   `force_push`, `rebase` or `mutate_source_worktree`.
5. `work_unit_identity_required` — neither an `executable_slice` nor a
   `task_packet` with a stable id was provided.

Blocked plans are honest evidence. They are not silently downgraded into a
smaller plan.

## Determinism

The same input always produces the same `plan_id`, the same per-lane `lane_id`,
the same `lane_hash` values and the same `plan_hash`. The hash is computed over
the canonical JSON of the plan with `plan_hash` and `generated_at` excluded.
There is no randomness and no wall-clock value inside the hashed content.

## Claim Policy

Every plan declares these hard guarantees:

```text
no_provider_call      = true
no_branch_mutation    = true
no_merge              = true
no_secret_access      = true
no_repair_execution   = true
no_judge_scoring      = true
deterministic         = true
plan_only_when_plan_only_mode = true
```

`execution_ready` mode only changes the **initial lane/plan state** from
`planned` to `ready`. It still invokes nothing: actual dispatch belongs to
AP-786/AP-790 owner execution, governed by the provider port and Atlas Decide.

## State Machine

Lane lifecycle (the orchestrator only sets the initial state; later transitions
belong to the executor):

```text
planned -> ready -> dispatched -> running -> produced -> verified -> completed
                                       \-> failed -> blocked
```

Plan lifecycle:

```text
planned -> ready -> executing -> completed
                         \-> blocked -> failed
```

`plan_only` mode initializes every lane at `planned` and the plan at `planned`.
`execution_ready` mode initializes lanes at `ready` and the plan at `ready`.
AP-797 never advances a lane past its initial state.

## Safety Rules

- No provider call, no provider router, no provider driver.
- No branch creation, mutation, merge, deploy, rebase, force-push or secret
  access from the orchestrator.
- The implementer lane may write only inside an AP-756 worktree; the repair lane
  may write only inside a repair branch/worktree.
- Read-only lanes can never be elevated to write.
- The judge can never merge.
- Lanes share one evidence pack, never an uncontrolled mutable workspace.
- The plan is the only output; execution, judge scoring, repair and Product Mode
  belong to other services.

## Hardened By AP-802

This lane plan is **hardened** by AP-802
(`LaneExecutionContractService`). AP-797 emits the plan; AP-802 turns each lane
into a validated `atlas.agent_execution.lane_contract.v1` (role,
input_context_refs, allowed_actions, forbidden_actions, write_authority,
provider_plan, evidence_obligations, output_schema, status, blockers), enforces
the separation rules (canonical lanes/order, exactly one implementer write lane,
repair_agent gating, read-only reviewer/judge, an output schema per lane), binds
one durable AP-795 session per lane, and blocks the cycle when a lane receipt is
missing. See `docs/ap/AP-802-multi-agent-lane-execution-contract-hardening-contract.md`.

## Acceptance

- A clean slice produces a deterministic 5-lane plan
  (`context_scout -> architect -> implementer -> reviewer -> judge`).
- A validation/gate failure (or explicit policy) adds the `repair_agent` lane and
  only then.
- An implementer with no `allowed_files` blocks.
- A judge with any write authority blocks.
- Any lane requesting a forbidden action (merge/deploy/delete/secrets) blocks.
- The same input yields the same `plan_hash`.
- `plan_only` invokes nothing and emits the claim policy.
- AP-797 reuses the AP-793 lane definitions and AP-794 slice schema; it forks
  none of them and creates no fifth multi-agent layer.
