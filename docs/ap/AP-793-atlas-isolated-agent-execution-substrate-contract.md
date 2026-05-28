---
id: AP-793-atlas-isolated-agent-execution-substrate
type: ap_contract
title: AP-793 Atlas Isolated Agent Execution Substrate Contract
status: active
implementation_state: architecture_contract
owner: software_company_stewardship
summary: Canonical substrate contract for isolated, durable, multi-agent execution in Atlas. AP-793 absorbs the useful architecture pattern from Sandcastle-style systems (provider adapters, sandbox/worktree lifecycle, branch strategy, session capture, stream/result parsing and cleanup) without importing a parallel product/runtime. It governs AP-756, AP-759, AP-786, AP-790 and future multi-loop/multi-area execution so Atlas can run days, weeks, months and years without fake cycles, branch pollution or provider-specific shortcuts.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/ap/AP-756-area-focus-branch-sandbox-materializer-contract.md
  - docs/ap/AP-759-owner-sandbox-runtime-runner-contract.md
  - docs/ap/AP-786-autonomous-evolution-session-contract.md
  - docs/ap/AP-787-forge-owner-runtime-dispatch-bridge-contract.md
  - docs/ap/AP-788-forge-execution-authority-injection-contract.md
  - docs/ap/AP-790-reliable-24h-autonomous-loop-runner-contract.md
  - docs/ap/AP-791-autonomous-loop-inbox-merge-receipt-integrity-contract.md
  - docs/ap/AP-792-24h-loop-certification-harness-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxMaterializerService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOwnerSandboxRuntimeRunnerService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowExecutor.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Loop24hCertificationHarnessService.php
requires_evidence: true
risk_level: critical
---
# AP-793 Atlas Isolated Agent Execution Substrate Contract

## Authority

AP-793 defines the execution substrate Atlas needs before autonomous loops can
run for days, weeks, months or years. It is not a new OS, scheduler, provider
router, Dev runtime, Forge runtime or replacement for the Stewardship Stack.

The anti-duplication decision is explicit:

```text
reuse_or_extend:
  AP-756 branch/worktree materializer
  AP-759 owner sandbox runtime runner
  AP-786 autonomous evolution session
  AP-790 reliable 24h loop runner
  AP-792 certification harness
do_not_create:
  parallel Sandcastle clone
  parallel branch manager
  parallel provider router
  parallel evidence ledger
  direct provider loop
```

Sandcastle is useful as an architectural reference because it separates provider
adapters, sandbox providers, worktree lifecycle, branch strategy, session
storage and stream/result parsing. Atlas must absorb those boundaries, but keep
Atlas-specific authority above them: AAEOS, Atlas Decide, Atlas Dev, Atlas Forge,
Evidence, Product Mode, AP-756/AP-759/AP-786/AP-790 and operator sovereignty.

## The Problem AP-793 Solves

The current loop can produce real work, but it is still too easy for it to:

- count simulated/deferred/projected work as progress;
- create branch pollution without durable cleanup;
- repeat the same candidate after a timeout;
- overfit to one provider command shape;
- run one area while blocking other areas;
- merge-count without proving that `main` advanced;
- run a direct provider with an Atlas-shaped prompt and call it Forge;
- lose state after crash, timeout, rate limit or killed terminal;
- scale one loop before proving the substrate can coordinate many loops.

AP-793 turns those recurring failures into one substrate contract.

## Current Reality vs Target

This contract is intentionally ahead of the current runtime. The distinction is
mandatory so future agents do not confuse architecture with delivery.

| Capability | Current reality | Target |
|---|---|---|
| `agent_execution.provider_port.v1` | Not implemented as a formal port. Provider command paths exist in owner runtimes and provider drivers. | One normalized provider port for Cursor, Claude, Codex, Gemini and future providers. |
| `agent_execution.sandbox_provider.v1` | Not implemented as a formal port. Current autonomous work uses host git worktrees plus scope guards. | Worktree provider first, then container/VM providers for long-horizon unattended execution. |
| `agent_execution.worktree_lifecycle.v1` | Lifecycle primitives exist across AP-756 and branch lifecycle services, but vocabulary is not unified. | Durable append-only lifecycle shared by every loop. |
| `agent_execution.branch_strategy.v1` | Candidate branches, integration lanes and merge governor exist as separate services. | One branch strategy contract for candidate, integration, repair and future parallel-universe branches. |
| `agent_execution.session_store.v1` | Provider/session facts are partially preserved in AP-786/AP-790 receipts. | Durable provider session store with resume, stream, usage, timeout and prompt/context hashes. |
| `agent_execution.result_parser.v1` | Result interpretation exists inside owner flow, loop receipts and merge governance. | One parser contract that prevents magic-text completion and fake success. |

Therefore AP-793 is currently a **substrate contract with real primitives below
it**, not six completed runtime ports. A production claim must say which AP-793
ports are implemented, which are wrappers over existing primitives and which are
still target architecture.

## Existing Primitive Mapping

AP-793 must wrap and normalize existing Atlas primitives before creating any new
service. This table is the anti-duplication map.

| AP-793 port / concern | Existing primitive to reuse or extend |
|---|---|
| Work intake and finding discovery | `AreaFocusDeepFindingEngineService`, `AgenticEngineeringOsFindingEngineService`, AP-748/AP-785. |
| Finding selection and loop continuity | `Reliable24hLoopRunnerService`, `AutonomousEvolutionSessionService`, AP-786/AP-790. |
| Finding decomposition | AP-794 Finding Slice Planner, extending deep finding and priority outputs. |
| Worktree materialization | `AreaFocusBranchSandboxMaterializerService`, AP-756. |
| Branch lifecycle | `StewardshipBranchLifecycleRegistryService`, AP-770/AP-773 lineage. |
| Integration lane | `StewardshipIntegrationLaneService`, `StewardshipIntegrationLanePromotionService`. |
| Merge queue and merge truth | `StewardshipMergeQueueService`, `StewardshipBranchMergeGovernorService`, AP-769/AP-774/AP-791. |
| Repo merge serialization | `StewardshipRepoMergeLeaseService`. |
| Owner runtime execution | `Ap786OwnerFlowExecutor`, `StewardshipOwnerSandboxRuntimeRunnerService`, AP-759. |
| Forge owner dispatch | AP-787/AP-788 and the existing Forge runtime dispatch bridge. |
| Evidence, inbox and Product Mode | AP-750/AP-765/AP-791 and Product Mode read models. |
| Certification | `Loop24hCertificationHarnessService`, AP-792. |

If a future implementation creates a new provider port, sandbox port or session
store, it must be an adapter around these primitives unless a fresh
anti-duplicate gate explicitly proves the primitive is insufficient.

## Canonical Model

```text
Loop Supervisor (AP-790 / future multi-loop runner)
  -> Work Intake + Priority (AP-748/AP-785)
  -> Area/Repo/Path Reservation
  -> Sandbox Plan
  -> Worktree Materialization (AP-756)
  -> Provider/Owner Authority (Atlas Decide + AP-758/AP-759/AP-787/AP-788)
  -> Agent Execution Session
  -> Stream/Result Parser
  -> Diff + Evidence Capture
  -> Validation + Repair
  -> Owner Result Bridge (AP-750/AP-765)
  -> Merge Queue + Merge Governor
  -> Cleanup + Learning
  -> Next Cycle
```

The substrate owns mechanics. It never owns product intent, provider choice,
architecture, evidence acceptance, merge policy or operator approval.

## Required Ports

### 1. Agent Provider Port

Every provider used by Atlas must expose the same governed port:

```text
atlas.agent_execution.provider_port.v1
```

Required fields:

| Field | Meaning |
|---|---|
| `provider_id` | `cursor_cli`, `claude_cli`, `codex_cli`, `gemini_cli`, etc. |
| `model_family` | Model chosen by Atlas Decide or operator receipt. |
| `command_argv` | Argv array only; no shell string. |
| `working_directory` | AP-756 worktree or approved sandbox path. |
| `session_id` | Provider session id when available. |
| `resume_token` | Resume handle when provider supports continuation. |
| `stream_events` | Normalized text/tool/error/usage events. |
| `usage` | Tokens, cost proxy, duration and limit state when available. |
| `auth_mode` | Local account, API key, local model or blocked. |
| `permission_mode` | Exact tool/sandbox permission mode. |
| `exit_status` | Process status, timeout or signal. |

Provider-specific UX can vary; the port cannot.

### 2. Sandbox Provider Port

Atlas supports multiple sandbox kinds without letting any one become authority:

```text
atlas.agent_execution.sandbox_provider.v1
```

Initial required provider:

```text
local_git_worktree
```

Future providers may include container, VM, remote sandbox or mobile/device
simulator, but only when they satisfy the same contract: isolated filesystem,
controlled secrets, path ownership, cleanup, resource limits and evidence.

`no_sandbox` is forbidden for autonomous loops. A human may run diagnostics in
the source worktree, but those runs cannot count as autonomous real cycles.

### Isolation Honesty

The current AP-790/AP-786 loop uses a git worktree and file-scope governance. It
does not yet provide OS/process isolation equivalent to Docker, Podman, Vercel
Firecracker or another container/VM boundary. A provider invoked with broad host
permissions inside a worktree is **host-scoped**, not OS-isolated.

Certification levels:

| Level | Meaning | Allowed claim |
|---|---|---|
| `L0_diagnostic_host` | Direct source worktree or no autonomous sandbox. | Diagnostics only. |
| `L1_worktree_scoped` | Isolated git worktree, branch, file reservations, scope guard, evidence and merge governor. | Real cycle, supervised or short-horizon. |
| `L2_process_isolated` | Worktree plus container/VM/process boundary, controlled secrets, resource limits and cleanup. | Long-horizon unattended loop candidate. |
| `L3_remote_or_ephemeral_isolated` | Stronger disposable sandbox such as remote VM/microVM with artifact sync. | Multi-day/multi-area production autonomy candidate. |

Until `L2_process_isolated` exists, Atlas may run real worktree-scoped cycles,
but must not claim month-scale unattended safety.

### 3. Worktree Lifecycle Port

AP-756 remains the source of truth for git worktree materialization. AP-793 adds
the lifecycle vocabulary all loops must use:

```text
planned -> reserved -> materialized -> executing -> completed|failed|blocked
  -> merged|review_required|quarantined -> cleaned|retained_for_debug
```

Every state transition must be append-only and idempotent. A loop may resume from
the last durable transition after crash.

### 4. Branch Strategy Port

Branch strategy is explicit:

| Strategy | Use |
|---|---|
| `candidate_branch` | Normal AP-786 cycle branch. |
| `integration_lane` | Promote several proven small branches under merge governor. |
| `parallel_universe` | Future best-of-N execution; each universe isolated. |
| `repair_branch` | Bounded repair attempt after failed validation. |

Merges count only when `main_before != main_after` and the post-merge hash is the
actual current `main` hash.

### 5. Session Store Port

Long-running autonomy needs durable provider sessions:

```text
atlas.agent_execution.session_store.v1
```

It records provider session ids, stdout/stderr excerpts, normalized stream
events, usage, timeout, retry/recovery state, prompt hash, context hash, command
argv hash and worktree hash. It must never store raw secrets.

### 6. Result Parser Port

Completion is never a magic phrase. A cycle is complete only when the parser sees:

- provider command finished or timed out with explicit status;
- product diff or explicit no-progress result;
- validation result;
- owner result bridge;
- inbox/evidence result;
- merge governor result.

Text such as `COMPLETE` is advisory only and cannot certify success.

## Multi-Agent Execution

AP-793 allows several agents on one task only through lanes:

| Lane | Role | Write Authority |
|---|---|---|
| `context_scout` | discover files, tests, risks | read-only |
| `architect` | spec/SDD/TDD/BDD and slice plan | docs/spec only unless approved |
| `implementer` | modify allowed files | AP-756 worktree only |
| `reviewer` | inspect diff and evidence | read-only |
| `repair_agent` | fix failed gate | repair branch/worktree only |
| `judge` | pick best candidate | read-only, no merge |

Each lane has its own receipt, budget and timeout. Lanes may share an evidence
pack, not an uncontrolled mutable workspace. The integration lane is the only
place where outputs are composed, and it is governed by merge policy.

## Multi-Loop / Multi-Area Execution

To run Atlas Dev, Forge, Memory, BlackInk and other areas at the same time, the
substrate must coordinate at four scopes:

| Scope | Lock / Reservation |
|---|---|
| `global` | kill switch, provider budget, disk budget, CPU budget. |
| `repo` | merge lease, git maintenance, main refresh. |
| `area` | area focus policy, WIP limit, priority queue. |
| `path` | file/module reservation to prevent conflicting writes. |

Multiple loops may run concurrently only when their path reservations do not
conflict and repo merge lease remains serialized. Provider budget is shared
across loops so one noisy area cannot starve the rest.

## Long-Horizon Durability

For days/weeks/months/years, every loop must prove:

- append-only run ledger;
- restart from ledger after crash;
- stale lock recovery;
- timeout classification without permanent false quarantine;
- budget reset semantics per run and cumulative lifetime counters;
- cleanup of merged/failed/abandoned worktrees;
- branch retention policy for debug;
- disk pressure protection;
- provider limit/backoff/fallback state;
- inbox and evidence emissions before merge;
- Product Mode visibility for every state.

## Real Cycle Certification

A cycle is real only if all required facts are true:

```text
provider_invoked = true
provider_authority = atlas_decide_or_operator_receipt
sandbox_kind = local_git_worktree_or_stronger
worktree_materialized = true
owner_runtime_chain = AP-747 -> AP-756 -> AP-757 -> AP-749 -> AP-758 -> AP-759 -> AP-750
product_diff_exists = true
focused_validation_ran = true
inbox_item_emitted = true
evidence_refs_present = true
merge_governor_evaluated = true
if merged: main_before != main_after
```

If any fact is absent, status is `partial`, `blocked`, `diagnostic` or
`no_progress`; it is not a successful autonomous cycle.

## Priority Contract

The substrate must not reward tiny churn. The priority engine should prefer work
that increases the factory's power:

1. execution substrate reliability;
2. owner runtime strength;
3. Atlas Decide/provider topology/fallback;
4. validation and repair loops;
5. evidence/replay/observability;
6. branch/worktree cleanup and recovery;
7. high-impact tests for critical runtime classes;
8. docs only when docs unblock runtime or stop future IA mistakes.

`factory_max` must decompose large findings into bounded slices. A provider
should never receive "make the factory better" as the executable prompt.

## Finding Decomposition Is Blocking

Large findings are not executable work. `factory_max` must pass every large,
strategic or self-referential finding through AP-794 Finding Slice Planner before
any provider invocation.

AP-794 is responsible for converting a high-level finding into one or more
bounded slices with:

- owner (`atlas_dev`, `forge`, `memory`, `stewardship`, etc.);
- risk level;
- allowed files and forbidden files;
- expected diff shape;
- validation command;
- evidence obligations;
- max duration and retry policy;
- provider fit and fallback hints;
- merge policy;
- stop condition when the finding cannot be sliced honestly.

If AP-794 cannot produce at least one bounded executable slice, the cycle must
block as `operator_or_architect_spec_required`. It must not downgrade the finding
into a fake small task or loop on starvation recovery.

## Safety Rules

- No direct provider loop can claim Atlas Dev or Forge execution.
- No source worktree mutation in autonomous mode.
- No shell-string execution.
- No provider bypass of Atlas Decide/operator receipt.
- No merge without AP-769/AP-774 and real `main` advancement.
- No repeated finding loops after timeout.
- No permanent quarantine for transient provider/runtime failures.
- No claim of 24h/7d readiness from fixture-only evidence.
- No uncontrolled branch namespace; every branch belongs to area/focus/cycle.
- No cleanup outside controlled worktree roots.

## Implementation Order

### Phase 1: Normalize Current Loop

- Add AP-793 fields to AP-786/AP-790 receipts.
- Make AP-792 certify AP-793 required facts.
- Ensure Product Mode shows AP-793 states: reserved, materialized, executing,
  blocked, merged, cleaned, quarantined.

Blocking gate before Phase 1 is complete:

```text
10 consecutive certified real cycles OR an explicit failure report proving the
missing AP-793 facts. Fixture-only evidence does not pass.
```

### Phase 1.5: Finding Slice Planner

- Create AP-794 as the contract for decomposing factory_max findings.
- Wire large findings through the planner before owner execution.
- Prove at least three high-ROI findings become bounded executable slices.

Blocking gate before Phase 2:

```text
factory_max cannot send unsliced large findings to a provider.
```

### Phase 2: Provider Port and Isolation Hardening

- Normalize stream/result/usage parsing for Cursor, Claude, Codex and Gemini.
- Store provider session/resume tokens when available.
- Enforce provider timeout/backoff/fallback classification.
- Promote from host-scoped worktree execution to at least one L2 process-isolated
  sandbox provider for unattended long-horizon runs.

Blocking gate before Phase 3:

```text
one loop runs 24h cleanly with AP-793 facts, honest blocked cycles, cleanup and
no false success; isolation level is reported explicitly.
```

### Phase 3: Multi-Loop Coordinator

- Add global/repo/area/path reservations.
- Allow multiple area loops in parallel with serialized merge lease.
- Add disk/provider-budget protection and fair scheduling.

Blocking gate before Phase 4:

```text
two loops run concurrently without path collision, provider starvation or merge
lease conflict; if they block, blockers are truthful and replayable.
```

### Phase 4: Multi-Agent Lanes

- Enable context_scout, architect, implementer, reviewer, repair_agent and judge
  as separate lanes under one task.
- Add integration lane and best-of-N future branch strategy.

Blocking gate before Phase 5:

```text
one task completes with at least architect + implementer + reviewer lanes,
separate receipts and one integration-lane decision.
```

### Phase 5: Long-Horizon Operations

- Run 10 certified consecutive real cycles.
- Run 24h with no fake success.
- Run 7d with recovery events.
- Graduate to month-scale retention, cleanup and learning policy.

## Acceptance

- Existing AP-756/AP-759/AP-786/AP-790/AP-792 remain the owner implementations;
  AP-793 does not fork them.
- ACRUI/feature-placement overlap is resolved as reuse/extend.
- Every real cycle report can be checked against AP-793 required facts.
- Multiple loops can be planned without conflicting path reservations.
- Provider-specific command shapes are hidden behind the provider port.
- Simulation/fixture/diagnostic runs can never certify production autonomy.
- A future Sandcastle-style external dependency can be added only as one
  sandbox/provider implementation behind these ports, never as Atlas authority.
