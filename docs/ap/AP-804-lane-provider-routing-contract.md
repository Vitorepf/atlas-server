---
id: AP-804-lane-provider-routing
type: ap_contract
title: AP-804 Multi-Agent Lane Provider Routing Contract
status: active
implementation_state: implemented
owner: software_company_stewardship
summary: Canonical contract for routing a provider/model PER multi-agent Stewardship lane (AP-797), instead of locking the loop to one hardcoded Cursor/Claude/Codex account. Each lane gets an explicit, auditable, substitutable provider plan with desired capabilities, a preferred provider, a fallback chain, the selected provider/model/profile, auth mode, availability and an honest reason/blocker. Atlas Decide (provider topology) is authoritative when present; otherwise the plan degrades honestly to a deferred plan carrying an explicit blocker — never a silent fallback, never a false provider_invoked. Implemented by LaneProviderRoutingService, embedded into the AP-797 lane plan and surfaced by AP-800 certification / Product Mode.
related_paths:
  - docs/ap/AP-793-atlas-isolated-agent-execution-substrate-contract.md
  - docs/ap/AP-795-agent-execution-provider-port-session-store-contract.md
  - docs/ap/AP-797-multi-agent-lane-orchestrator-contract.md
  - docs/ap/AP-800-multi-agent-cycle-certification-visibility-contract.md
  - docs/engineering-knowledge-base/atlas-multi-agent-unified-architecture.md
  - app/Services/Ai/SoftwareCompanyStewardship/AgentExecution/LaneProviderRoutingService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AgentExecution/MultiAgentLaneOrchestratorService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AgentExecution/AgentExecutionProviderPortService.php
  - app/Services/Ai/AtlasDecideService.php
  - app/Services/Ai/Programming/AtlasForgeProviderTopologyService.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/AgentExecution/LaneProviderRoutingServiceTest.php
requires_evidence: true
risk_level: high
---
# AP-804 Multi-Agent Lane Provider Routing Contract

## Authority

AP-804 routes a provider and model **per lane** in the AP-797 multi-agent
Stewardship cycle. The loop must never be locked to one fixed Cursor/Claude/Codex
account: `context_scout` can use a cheap/fast model, `architect` a stronger one,
`implementer` a provider with tool/write support, `reviewer`/`judge` a critical
model, and `repair_agent` the implementer's provider or a specialized fallback.

AP-804 is **not** a provider router/driver, a provider invoker, a new Atlas Decide
or a credentials store. It only produces a *plan*. Actual invocation stays with
the AP-786 owner-flow, normalized by AP-795. It is a substrate mechanic under
AP-793, not a fifth multi-agent layer.

Anti-duplication:

```text
reuse_or_extend:
  AtlasDecideService / AtlasForgeProviderTopologyService -> provider availability + role assignments
  AP-795 AgentExecutionProviderPortService -> normalizes the REAL invocation facts later
  AP-797 MultiAgentLaneOrchestratorService -> embeds the per-lane provider plan
  AP-800 MultiAgentCycleCertificationService -> shows provider-per-lane, blocks a lane with no plan
do_not_create:
  parallel provider router / driver
  parallel Atlas Decide
  a credentials/secret store
```

## Schema

```text
atlas.agent_execution.lane_provider_plan.v1          (full document)
atlas.agent_execution.lane_provider_plan_entry.v1    (one lane)
```

Each lane entry carries:

| Field | Meaning |
|---|---|
| `lane_id` | The AP-797 lane id. |
| `role` | Lane role (`context_scout`/`architect`/`implementer`/`reviewer`/`judge`/`repair_agent`). |
| `tier` | Capability tier the role maps to. |
| `desired_capabilities` | Capabilities the lane needs (drives provider eligibility). |
| `preferred_provider` | First choice (Atlas Decide assignment, else tier policy head). |
| `fallback_chain` | Ordered fallbacks (capability-qualified, never a single account-as-truth). |
| `selected_provider` | The chosen available provider, or `null` when blocked. |
| `selected_model` | Concrete model id when known (redacted), else `null`. |
| `selected_profile` | Model profile alias when the concrete id is unknown (`fast`/`premium`/`default`). |
| `auth_mode` | `account` / `session` / `api` / `unknown` — a label, never a secret. |
| `availability` | `available` / `unavailable` / `unknown`. |
| `fallback_applied` | True when the preferred provider was unavailable. |
| `reason` | Human-readable, redacted explanation of the selection/fallback/degradation. |
| `blocker` | `blocked_provider_unavailable`, `atlas_decide_unavailable`, or `null`. |
| `invocation_state` | `planned` or `deferred` — **never** `real` (this only plans). |
| `provider_invoked` | Always `false`. |
| `routing_source` | `atlas_decide_topology` / `injected_topology` / `degraded_default_no_atlas_decide`. |
| `entry_hash` | Deterministic hash of the entry. |

## Capability Tiers (default policy, overridable)

| Lane | Tier | Desired capabilities | Default provider preference |
|---|---|---|---|
| `context_scout` | `cheap_fast` | read_context, fast, low_cost, large_context | gemini_cli → claude_cli → codex_cli |
| `architect` | `strong_reasoning` | strong_reasoning, planning, spec_authoring | claude_cli → codex_cli → gemini_cli |
| `implementer` | `builder_write` | tool_use, file_write, code_edit, strong_reasoning | cursor_cli → claude_cli → codex_cli |
| `reviewer` | `critical_review` | critical_review, deterministic_judgement, reasoning | codex_cli → claude_cli → gemini_cli |
| `judge` | `critical_review` | (same as reviewer) | codex_cli → claude_cli → gemini_cli |
| `repair_agent` | `builder_write` | tool_use, file_write, code_edit | cursor_cli → claude_cli → codex_cli |

The preference list is a **capability policy with explicit fallback**, not a
single account declared as truth. Atlas Decide topology `role_assignments`
override the preferred head per role.

## How Atlas Decide Enters

When a normalized provider topology is supplied (mapped from
`AtlasForgeProviderTopologyService::topology()` / `AtlasDecideService`):

```text
provider_topology = {
  source: "atlas_decide_topology",
  providers: { <provider_id>: { capabilities[], models[], auth_mode, available|state } },
  role_assignments: { <forge_role>: { provider, model } }   // optional, authoritative
}
```

- `role_assignments` override the preferred provider per lane (lane role →
  topology role: architect/implementer → `primary_builder`, reviewer/judge →
  `critical_reviewer`, context_scout/repair_agent map 1:1).
- `providers[*].available` (or `state`) drives availability; the chain is walked
  until the first available, capability-qualified provider is found.
- If a provider in the chain is unavailable, `fallback_applied=true` and the
  `reason` names the fallback — **no silent fallback**.
- If no provider in the chain is available → `blocker=blocked_provider_unavailable`.

## Honest Degradation

When **no** topology is supplied and Atlas Decide is not consulted, the plan does
not pretend:

```text
selected_provider   = preferred (default policy head)
availability        = unknown
invocation_state    = deferred
blocker             = atlas_decide_unavailable
routing_source      = degraded_default_no_atlas_decide
```

The lane still has an explicit preferred provider and fallback chain (so it is
substitutable), but it is honestly marked deferred and unconfirmed.

## Safety Rules

- No provider call, router or driver. `provider_invoked` is always `false`.
- No silent fallback: every fallback and degradation has an explicit `reason`.
- No single account-as-truth: preferred + fallback_chain are capability policy.
- No secret in the plan: only whitelisted topology fields are read; `auth_mode`
  is a label; free text and model ids are passed through redaction.
- `auth_mode ∈ {account, session, api, unknown}`.
- Routing is per-lane, never only per-cycle.
- Deterministic: same input → same `plan_hash` / `entry_hash`.

## Integration

- **AP-797** (`MultiAgentLaneOrchestratorService`) embeds `provider_plan` on each
  lane and a plan-level `provider_routing` summary. It passes `provider_topology`
  through from its input. Routing is plan-only, so the orchestrator's
  `no_provider_call` claim still holds.
- **AP-795** (`AgentExecutionProviderPortService`) normalizes the REAL invocation
  facts after the owner-flow actually runs; AP-804 only plans which provider each
  lane *should* use.
- **AP-800** (`MultiAgentCycleCertificationService`) surfaces provider-per-lane
  and, when a cycle declares `provider_routing_required=true`, blocks with
  `lane_missing_provider_plan` if any required lane lacks a provider plan.

## Inspection

```bash
php artisan atlas:agent-execution:lane-provider-plan --json
# with an Atlas Decide / injected topology file:
php artisan atlas:agent-execution:lane-provider-plan --topology-file=storage/atlas-proof/topology.json --json
```

## Acceptance

- `architect` is routed to a strong profile when available.
- `context_scout` is routed to a cheap/fast profile when available.
- `implementer` requires write/tool capability and is never routed to a
  read-only provider.
- An unavailable preferred provider falls back to the next available one with an
  explicit fallback receipt.
- When no provider in the chain is available the lane blocks with
  `blocked_provider_unavailable`.
- `provider_invoked` is `false` and `invocation_state` is `planned`/`deferred`
  whenever the plan only planned.
- Secrets are never echoed; `auth_mode` is a label.
- The lane provider plan is deterministic.
- Certification blocks a `provider_routing_required` lane that has no provider
  plan.
