---
id: atlas-tool-runtime-contracts
type: engineering_knowledge
title: Atlas Tool Runtime Contracts
status: active
category: architecture
priority: 98
summary: Focused contract for Super Tool Runtime registry, policy, tiers, authority matrix and external agent boundaries.
tags:
  - atlas
  - tools
  - contracts
capabilities:
  - tool_registry
  - tool_policy_engine
  - tool_authority_matrix
decisions:
  - Every tool enters through the registry before recurring automation.
  - Tool execution must be governed by tier, risk, privacy, sandbox and approvals.
  - External coding agents are executors inside Atlas, never replacement control-planes.
maintenance:
  - Keep policy and authority changes here.
related_paths:
  - docs/engineering-knowledge-base/super-tool-runtime-core.md
  - docs/engineering-knowledge-base/tool-runtime/evidence-gates.md
  - app/Services/Tools/AtlasToolRegistryService.php
  - app/Services/Tools/AtlasToolPolicyEngine.php
  - app/Services/Tools/AtlasToolAuthorityMatrixService.php
---

# Atlas Tool Runtime Contracts

## Core Tables

- `atlas_tool_definitions`;
- `atlas_tool_installations`;
- `atlas_tool_policies`;
- `atlas_tool_runs`;
- `atlas_tool_artifacts`;
- `atlas_tool_findings`.

## Registry Contract

Every tool definition declares:

- slug, category and capabilities;
- execution tier and expected cost;
- default trigger and recommended surface;
- authority group and authority role;
- timeout, network posture, sandbox posture and failure policy;
- safe command recipes when executable.

Missing optional binaries produce `missing` or `skipped` with reason. They do not
silently disappear and do not become mandatory paid dependencies.

## Evidence Persistence Contract

Tool evidence writes are all-or-nothing. `AtlasToolEvidenceStore` requires
`atlas_tool_definitions`, `atlas_tool_runs`, `atlas_tool_artifacts` and
`atlas_tool_findings` before recording external tool evidence. If any required
table is unavailable, it fails closed and writes no partial run, artifact,
finding or ledger event.

Run, artifact and finding persistence share one transaction. Ledger projection is
recorded only after the local evidence graph exists, and ledger failures do not
turn partial evidence into success.

Run metadata includes the tool definition's category, type, execution tier,
expected cost, default trigger, authority group and authority role. `context`
metadata may add recipe or execution-origin fields, but it does not create a new
authority model outside the registry.

Each run metadata also carries `receipt_schema_version`,
`summary_hash`, `normalized_result_hash` and `evidence_receipt_hash`. The ledger
event reuses those hashes so gates can compare persisted evidence with replayed
ToolEvidenceRecorded events without exposing raw command output or workspace
paths.

Evidence export surfaces must expose these hashes as an explicit `receipt`
block. The receipt is pointer/hash only: it may include `tool_run_id`,
`tool_slug`, `workspace_hash`, `command_hash`, `summary_hash`,
`normalized_result_hash` and `evidence_receipt_hash`, but it must not expose raw
commands, raw output or workspace paths. Provider dispatch and runtime policy
mutation remain closed in exported evidence receipts.

Each run also carries `atlas.tool_action_runtime.contract.v1`. This contract
states that persisted evidence is `evidence_recording`, not execution authority:
raw command/output/workspace remain hidden, provider dispatch, policy mutation
and Agent Control Plane dispatch stay false, and operator approval is required
before any external execution path can use that evidence.

## Policy Contract

`AtlasToolPolicyEngine` emits auditable decisions:

- `allowed`;
- `denied`;
- `requires_approval`;
- `skipped`.

Inputs include tier, risk, network, cost, sandbox, privacy, task type,
provider-safe requirement, approval status and workspace/global overrides.

## Tier Contract

| Tier | Use | Blocks? |
|---|---|---|
| T0 interactive | symbol lookup, rg, cheap context | No by itself |
| T1 local fast | format/lint/typecheck/diff secret scan | Yes for scoped direct errors |
| T2 PR/review | full static/security/API/a11y/architecture scans | Yes by severity/policy |
| T3 release/nightly | mutation, license, deep vulnerability/performance | Yes for release/nightly |

T3 must not block interactive flow. T0 must not write without approval.

## Authority Matrix

Overlapping tools need a declared authority group:

- primary tools define default blocking severity;
- complementary tools add evidence and may elevate when more precise;
- fallbacks prevent total blind spots;
- duplicate findings are correlated for gate counting, never deleted from evidence.

Waivers attach to findings/fingerprints, not to entire tools.

## External Agent Boundary

Aider, Continue, OpenHands, Serena/MCP with writes and similar agents are tool
executors inside Atlas.

They receive Atlas context and constraints, run in worktree/sandbox, produce
patch/artifacts, and are validated by Atlas tests/gates/evidence. They never mark
work resolved, pick providers, bypass secrets policy or become the source of
truth.
