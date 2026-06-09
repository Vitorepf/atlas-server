---
id: AP-789-forge-live-decide-awis-authority-bootstrap-contract
type: ap_contract
title: AP-789 Forge Live Decide + AWIS Authority Bootstrap Contract
status: active
summary: Derives, for a REAL governed Obra, the live authority AP-787/AP-788 need to dispatch the Forge owner runtime - real provider topology, real live Atlas Decide decision receipt (id/hash), and real AWIS workspace readiness (execution gate + handoff pack) - and injects it into the autonomous-evolution-session via --bootstrap-forge-authority. Authority is REAL or it blocks: it never fabricates an Obra or operator authorization, never simulates a decision receipt, never uses a direct provider driver, and never emits status=ready from synthetic/operator-supplied shape. partial/blocked is the correct result when real authority does not yet exist.
owner: programming
related_paths:
  - docs/ap/AP-787-forge-owner-runtime-dispatch-bridge-contract.md
  - docs/ap/AP-788-forge-execution-authority-injection-contract.md
  - docs/engineering-knowledge-base/atlas-forge-provider-topology-and-fallback-v1.md
  - docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ForgeRuntimeInputPolicy.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ForgeLiveAuthorityBootstrapService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ForgeAuthority/ForgeProviderTopologyPort.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ForgeAuthority/ForgeLiveDecideReceiptPort.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ForgeAuthority/AwisExecutionGatePort.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ForgeAuthority/AwisHandoffPackPort.php
  - app/Services/Ai/Programming/AtlasForgeProviderTopologyService.php
  - app/Services/Ai/AtlasDecideService.php
  - app/Services/Ai/WorkspaceIntelligence/AtlasWorkspaceIntelligenceExecutionGateService.php
  - app/Services/Ai/WorkspaceIntelligence/AtlasWorkspaceHandoffPackService.php
  - app/Console/Commands/AtlasSoftwareCompanyAutonomousEvolutionSessionCommand.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ForgeRuntimeInputPolicyTest.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ForgeLiveAuthorityBootstrapServiceTest.php
---
# AP-789 Forge Live Decide + AWIS Authority Bootstrap Contract

## Authority

AP-789 prepares the live authority that AP-787 (Forge owner runtime dispatch) and
AP-788 (Forge execution authority injection) require, so the AP-786 owner flow can
reach the Forge owner runtime for a REAL Obra. It composes only real services
behind ports:

```text
--bootstrap-forge-authority --forge-obra=<real Obra>
-> AtlasForgeProviderTopologyService  (real provider topology, live receipt)
-> AtlasDecideService                 (real live decision receipt id/hash)
-> AtlasWorkspaceIntelligenceExecutionGateService + AtlasWorkspaceHandoffPackService (real AWIS readiness)
-> forge_inputs (forge_live_topology + forge_live_decision) injected into AP-787/AP-788
```

It never calls `AtlasForgeProviderInvocationDriverRouter` and never uses
`--allow-direct-provider-driver`.

## Runtime authority is REAL or it blocks

`status=ready` is produced ONLY from real derivation:

1. a real, persisted governed Obra (resolved by the topology service / AtlasProject);
2. a real provider topology with `runtime_dispatch_allowed=true`;
3. a real live Atlas Decide decision receipt with an id (and hash);
4. real AWIS workspace readiness — execution gate allowed AND a ready handoff pack;
5. real evidence refs (Obra, provider_topology_id, atlas_decide_receipt, AWIS readiness).

`status=ready` can NEVER be emitted from synthetic shape, operator-supplied JSON,
mocks, or test doubles. `partial` means real topology + real live decision exist
(forge_inputs can drive an AP-787 governed dispatch/plan) but AWIS still gates real
mutative execution. `blocked` means topology or live decision is missing.

## Test doubles are forbidden in runtime

Mocks / Fakes / TestDoubles may exist ONLY inside unit tests, must be named
`Fake*`/`TestDouble*`, and must never cross into runtime, this canonical doc, or
`claim_policy` as authority. Runtime always binds the real services
(`AppServiceProvider`): the `ForgeAuthority\*Port` interfaces are seams whose only
runtime implementations are the real Atlas services.

## Precise machine-readable blockers (never a false positive)

| Blocker | Meaning |
|---|---|
| `forge_obra_required` | No `--forge-obra` supplied; AP-789 never fabricates an Obra. |
| `forge_obra_invalid` | `--forge-obra` is not a valid Obra UUID (or fake/placeholder/zero). |
| `forge_obra_not_found` | The Obra is not a persisted governed AtlasProject. |
| `forge_live_topology_unavailable` | Real provider topology is not runtime-dispatch-allowed. |
| `forge_operator_actor_required` | No operator actor; AP-789 never fabricates operator authorization. |
| `live_decide_receipt_required` | No real live Atlas Decide receipt; AP-789 never simulates one. |
| `awis_execution_gate_blocked` (+ `awis_gate:<reason>`) | Real AWIS execution gate not allowed. |
| `workspace_handoff_pack_blocked` | Real AWIS handoff pack not ready. |
| `forge_topology_probe_failed` / `awis_probe_failed` | A real probe errored; resolve before bootstrap. |

Every blocked/partial result carries `next_actions` describing the real step
required (e.g. certify the AWIS workspace, restore live provider topology, produce
a live Atlas Decide receipt).

`ForgeRuntimeInputPolicy` is the shared input policy for AP-789 and AP-787:
it validates real Obra UUID shape/fake guards and normalizes `forge_role` using
`AtlasForgeProviderTopologyService::CANONICAL_ROLES`. Do not duplicate local
role or Obra validators in the authority bootstrap.

## CLI

```bash
php artisan atlas:software-company-stewardship:autonomous-evolution-session \
  --area=agentic_engineering_os --focus=dev_forge --scope-profile=factory_max \
  --bootstrap-forge-authority --forge-obra=<real Obra UUID> --forge-operator-actor=<operator> --json
```

When `--bootstrap-forge-authority` is set and `--forge-obra` is supplied, the command
calls the bootstrap and injects `forge_live_topology`/`forge_live_decision` into the
session ONLY when they were really derived. The `forge_authority_bootstrap` block in
the report carries status/blockers/next_actions/evidence_refs (never the decision
contents).

## Evidence

- `tests/Unit/.../AreaFocusLoop/ForgeLiveAuthorityBootstrapServiceTest.php`:
  blocks without/with invalid Obra; blocks when Obra not persisted; requires
  operator actor; blocks when no live decide receipt; AWIS blocked surfaces as a
  blocker (status partial, never ready); ready only from real topology + decision +
  AWIS; CLI `--bootstrap-forge-authority` injects the real live fields. The Fake*
  ports are confined to that test.
- `tests/Unit/.../AreaFocusLoop/ForgeRuntimeInputPolicyTest.php`:
  shared AP-789/AP-787 input policy rejects fake/placeholder/zero Obra IDs and
  normalizes `forge_role` from the topology canonical roles.
