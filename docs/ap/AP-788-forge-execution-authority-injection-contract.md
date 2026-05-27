---
title: AP-788 Forge Execution Authority Injection Contract
status: active
implementation_state: implemented
requires_evidence: true
owner: software_company_stewardship
companion_of: AP-787-forge-owner-runtime-dispatch-bridge-contract
glossary: docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
---

# AP-788 Forge Execution Authority Injection Contract

## Authority

AP-787 (`ForgeOwnerRuntimeDispatchBridge`) can route `owner=forge` through the
real Atlas Forge/Obra owner-runtime path (AP-759 allowlisted command), but only
when it receives a real governed Obra, a live provider topology and a live Forge
decision. Until AP-788, `AutonomousEvolutionSessionService::run()` already
accepted those inputs (via `forgeInputs()`), but the
`atlas:software-company-stewardship:autonomous-evolution-session` CLI did not
expose them — so `owner=forge` could only ever block in real runs.

AP-788 is the **governed injection layer**: it lets the operator (or Codex, or a
launch script) pass real Forge execution authority into AP-786 from the CLI,
which forwards it unchanged to AP-787. It changes the command only; it does not
change AP-786/AP-787 core behavior. See the canonical glossary for Atlas Dev /
Forge naming (`docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md`).

- Command: `app/Console/Commands/AtlasSoftwareCompanyAutonomousEvolutionSessionCommand.php`
- Forwards to: `AutonomousEvolutionSessionService::run()` -> `forgeInputs()` -> `Ap786OwnerFlowRunner` -> AP-787 `ForgeOwnerRuntimeDispatchBridge`.

## Injected flags

| Flag | Maps to (run input key) | Meaning |
|---|---|---|
| `--forge-obra=<uuid>` | `forge_obra` | Real governed Obra UUID. Never fabricated; an invalid/fake UUID is rejected by AP-787 (`forge_obra_invalid`). |
| `--forge-live-topology-json=<json>` | `forge_live_topology` | Live provider topology object; AP-787 requires `status=live` (or `live=true`). |
| `--forge-live-decision-json=<json>` | `forge_live_decision` | Live Forge decision object; AP-787 requires `decision` + `operator_actor`. |
| `--forge-dispatch-mode=<mode>` | `forge_dispatch_mode` | `forge_runtime_dispatch` (default, plan-only) \| `forge_parallel_durable` \| `forge_provider_invoke`. |
| `--forge-role=<role>` | `forge_role` | Canonical role: primary_builder \| critical_reviewer \| context_scout \| repair_agent \| local_tool_runner. |
| `--forge-provider-authorization` | `forge_provider_authorization` | Explicit provider-execution authorization, required only for `forge_provider_invoke`. |
| `--forge-budget-approved` | `forge_budget_approved` | Explicit budget approval, required only for `forge_provider_invoke`. |

## Non-Negotiable Safety

- **No fabricated Obra.** AP-788 only forwards a UUID the operator supplied; if
  it is missing, AP-787 still blocks with `forge_obra_required`. AP-788 never
  invents one.
- **No direct provider routing.** AP-788 never touches
  `AtlasForgeProviderInvocationDriverRouter`. The path is always the AP-787
  owner-runtime dispatch (`provider_router_used=false`).
- **Invalid JSON fails fast and clearly.** A malformed
  `--forge-live-topology-json` / `--forge-live-decision-json` blocks the command
  before `run()` with `reason=forge_authority_json_invalid` naming the flag; no
  cycle is attempted.
- **Honest blocking is preserved.** With no/partial Forge authority, the cycle
  still blocks honestly (`forge_obra_required` / `forge_live_topology_required` /
  `forge_live_decision_required`). It is never upgraded to a fake completion.
- **Safe continuation.** With `--continue-on-blocked`, a Forge cycle that blocks
  for missing authority does not abort the session: the finding is review-locked
  and the loop advances to the next eligible finding without creating excessive
  branch/worktree churn (forge-authority blockers are not session-stop blockers).
- `--forge-provider-authorization` / `--forge-budget-approved` only matter for
  `forge_provider_invoke`; they are never sufficient on their own and never
  substitute for the Obra + live topology + live decision authority.

## Output

The command adds a `forge_authority` block to the AP-786 report so the operator
can see exactly what authority was injected (presence booleans, dispatch mode,
role) without leaking the decision contents, plus
`never_uses_direct_provider_router=true`.

## CLI

Real Forge run (plan-only runtime dispatch, the default and safest):

```bash
php artisan atlas:software-company-stewardship:autonomous-evolution-session \
  --area=agentic_engineering_os --focus=dev_forge --cycles=1 --execute \
  --forge-obra=<obra-uuid> \
  --forge-live-topology-json='{"status":"live","providers":[...]}' \
  --forge-live-decision-json='{"decision":"approve_forge_run","operator_actor":"vitor"}' \
  --forge-dispatch-mode=forge_runtime_dispatch \
  --forge-role=primary_builder \
  --record --json
```

Continue past a Forge cycle that lacks authority instead of aborting the session:

```bash
php artisan atlas:software-company-stewardship:autonomous-evolution-session \
  --area=agentic_engineering_os --cycles=3 --execute --continue-on-blocked --json
```

## Acceptance

- The CLI forwards `forge_obra` / `forge_live_topology` / `forge_live_decision`
  (and mode/role/auth/budget) into the session run, reaching `Ap786OwnerFlowRunner`.
- Invalid `--forge-*-json` blocks with a clear `forge_authority_json_invalid`
  error before any cycle runs.
- With no Forge authority the cycle still blocks honestly; with
  `--continue-on-blocked` the session advances to the next finding.
- With full authority, AP-787 returns the allowlisted `atlas:forge:runtime-dispatch`
  AP-759 command and never the provider router.
- The path never uses `AtlasForgeProviderInvocationDriverRouter`.
