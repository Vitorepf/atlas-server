---
id: AP-787-forge-owner-runtime-dispatch-bridge-contract
type: ap_contract
title: AP-787 Forge Owner Runtime Dispatch Bridge Contract
status: active
summary: Lets AP-786 autonomous-evolution-session run owner=forge through the REAL Atlas Forge/Obra owner runtime - an allowlisted AP-759 command (e.g. atlas:forge:runtime-dispatch) inside the AP-756 worktree - instead of blocking blindly or falling back to a direct provider driver. It never fabricates an Obra, never calls AtlasForgeProviderInvocationDriverRouter, and never claims completion for a plan: a runtime-dispatch plan with no real changed files is reported as PLANNED, and missing Obra/topology/live-decision block with precise machine-readable reasons before any execution claim.
owner: programming
related_paths:
  - docs/ap/AP-786-autonomous-evolution-session-contract.md
  - docs/ap/AP-759-owner-sandbox-runtime-runner-contract.md
  - docs/ap/AP-750-owner-runtime-result-bridge-contract.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/atlas-forge-provider-topology-and-fallback-v1.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ForgeRuntimeInputPolicy.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/ForgeOwnerRuntimeDispatchPlanner.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/ForgeOwnerRuntimeDispatchBridge.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowExecutor.php
  - app/Console/Commands/AtlasForgeRuntimeDispatchCommand.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ForgeRuntimeInputPolicyTest.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowExecutorTest.php
---
# AP-787 Forge Owner Runtime Dispatch Bridge Contract

## Authority

AP-787 unblocks `owner=forge` in the AP-786 owner flow without ever reintroducing
the rejected "model + prompt" path. Forge work must flow through the Atlas-owned
chain exactly like atlas_dev:

```text
Area Focus -> AP-786 -> AP-747 release -> AP-748 outcome -> AP-749 consumption
-> AP-758 adapter -> AP-759 owner sandbox runtime runner (allowlisted Forge command
   inside the AP-756 worktree) -> AP-750 result bridge -> AP-769/AP-774 merge governance
```

`Ap786OwnerFlowExecutor` delegates the forge command construction to
`ForgeOwnerRuntimeDispatchBridge` (`ForgeOwnerRuntimeDispatchPlanner` seam). The
provider driver (`AtlasForgeProviderInvocationDriverRouter`) is NEVER called, and
`--allow-direct-provider-driver` is NEVER used.

## What counts as REAL Forge execution

A forge cycle is `owner_flow_completed` (mergeable) ONLY when ALL of:

1. a real, operator/loop-supplied governed **Obra UUID** was provided (never fabricated);
2. a **live provider topology** and a **live Forge decision** were present;
3. AP-759 ran an **allowlisted Forge command** inside the AP-756 worktree;
4. the owner runtime returned a **real result with allowed changed files**;
5. tests/validation are present and **AP-750** bridged the owner_result;
6. **AP-769/AP-774** merge governance then evaluated the branch.

## What does NOT count (must never be reported as completed)

- `atlas:forge:runtime-dispatch` only prepares a governed dispatch plan
  (`--execution-mode=prepare_dispatch_plan`, "NUNCA chama provider externo").
  A successful run of it with **no real changed files** is reported as
  `owner_flow_forge_planned` (blocker `forge_runtime_dispatch_planned_only`),
  recorded honestly via AP-750 as a **partial** result, and merge is withheld.
- Any forge path that would call the provider driver router directly.
- A fabricated/placeholder/zero Obra UUID.
- A plan, simulation, or "prepared" state presented as execution.

## Precise machine-readable blockers (before any execution claim)

| Blocker | Meaning |
|---|---|
| `forge_obra_required` | No `forge_obra`/`obra_id` supplied. AP-787 does not fabricate an Obra. |
| `forge_obra_invalid` | `forge_obra` is not a valid Obra UUID (or is fake/placeholder/zero). |
| `forge_live_topology_required` | No `forge_live_topology.status=live`. |
| `forge_live_decision_required` | No `forge_live_decision` (decision + operator_actor). |
| `forge_provider_authorization_required` | `provider-invoke` mode without `forge_provider_authorization`. |
| `forge_budget_approval_required` | `provider-invoke` mode without `forge_budget_approved`. |
| `forge_parallel_durable_inputs_required` | `parallel-durable` mode without `forge_tickets`/`forge_agents`. |
| `forge_runtime_dispatch_planned_only` | runtime-dispatch produced a plan with no real changed files. |

## Inputs (threaded from the AP-786 session to the bridge)

`forge_obra`/`obra_id`, `forge_live_topology` (`{status: live}`),
`forge_live_decision` (`{decision, operator_actor}`), `forge_dispatch_mode`
(`forge_runtime_dispatch` default | `forge_parallel_durable` | `forge_provider_invoke`),
`forge_role` (canonical role), and for provider-backed execution
`forge_provider_authorization` + `forge_budget_approved` (+ `forge_tickets`/`forge_agents`
for parallel-durable).

`ForgeRuntimeInputPolicy` is the shared input policy for AP-787 and AP-789:
it validates real Obra UUID shape/fake guards and normalizes `forge_role` using
`AtlasForgeProviderTopologyService::CANONICAL_ROLES`. Do not duplicate local
role or Obra validators in the dispatch bridge.

## Allowlisted commands (AP-759)

- `atlas:forge:runtime-dispatch --obra=<uuid> --role=<role> --json --strict` (plan-only, default).
- `atlas:forge:parallel-durable --tickets=<json> --agents=<json> --json`.
- `atlas:forge:provider-invoke --obra=<uuid> --mode=execute --confirm-provider-call --confirm-budget --confirm-runtime-dispatch --json` (PROVIDER_COMMAND; requires authority + budget).

`atlas:code:forge-fast-path` and `atlas:forge:live-execute` are NOT in the AP-759
allowlist, so an execution-completing forge run through AP-759 currently requires
`provider-invoke` with explicit provider + budget authorization. Until that
authority is supplied, autonomous forge honestly resolves to **planned** (via
runtime-dispatch) or **blocked**, never completed.

## Anti-fake guarantees

- Never calls `AtlasForgeProviderInvocationDriverRouter`.
- Never uses `--allow-direct-provider-driver`.
- Never marks a cycle completed when Forge only planned.
- A plan-only command (runtime-dispatch) can never become completed without real changed files.
- All blockers are emitted before any execution claim and never reach merge governance.

## Evidence

- `tests/Unit/.../OwnerFlow/Ap786OwnerFlowExecutorTest.php`:
  forge blocks with precise reason when Obra/topology/decision missing; forge
  proceeds with minimal inputs using the allowlisted runtime-dispatch command and
  never the provider router; runtime-dispatch plan-only is PLANNED not completed;
  forge completed with changed files bridges the owner_result to AP-750;
  atlas_dev regression keeps using `atlas:dev:senior-loop:run`.
- `tests/Unit/.../ForgeRuntimeInputPolicyTest.php`:
  shared AP-787/AP-789 input policy rejects fake/placeholder/zero Obra IDs and
  normalizes `forge_role` from the topology canonical roles.
- `tests/Unit/.../AutonomousEvolutionSessionServiceTest.php`:
  session routes owner=forge through the owner flow and holds merge when planned;
  direct provider stays a gated legacy diagnostic path.
