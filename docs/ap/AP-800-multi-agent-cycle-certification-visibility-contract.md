---
id: AP-800-multi-agent-cycle-certification-visibility
type: ap_contract
title: AP-800 Multi-Agent Cycle Certification and Product Mode Visibility Contract
status: active
implementation_state: implemented
requires_evidence: true
owner: software_company_stewardship
summary: Read-only certification harness and Product Mode read-model that proves a multi-agent-per-task cycle is real — or blocks honestly — by detecting capabilities and demanding evidence. AP-800 never runs providers or the lane runtimes (AP-795..AP-799); it certifies their composed output and projects operator visibility.
related_paths:
  - docs/ap/AP-792-24h-loop-certification-harness-contract.md
  - docs/ap/AP-793-atlas-isolated-agent-execution-substrate-contract.md
  - docs/ap/AP-794-finding-slice-planner-contract.md
  - docs/ap/AP-786-autonomous-evolution-session-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/AgentExecution/MultiAgentCycleCertificationService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Loop24hCertificationHarnessService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeOperationalInboxReadModelService.php
  - docs/ap/AP-802-multi-agent-lane-execution-contract-hardening-contract.md
---
# AP-800 Multi-Agent Cycle Certification and Product Mode Visibility Contract

## Authority

AP-800 is the read-only certification layer for the **multi-agent-per-task** cycle.
When AP-795 (provider port / session store), AP-796 (finding slice planner runtime),
AP-797 (lane orchestrator), AP-798 (integration judge) and AP-799 (repair planner)
are implemented, AP-800 proves the composed cycle is a real multi-agent cycle — or
blocks honestly with the exact missing capability and missing-evidence lists.

AP-800 is **not** any of those runtimes. It does not run providers, agents, lanes,
judges or repair. It detects capabilities by class/interface probing (overridable),
inspects a recorded cycle receipt or fixture, and emits a deterministic verdict plus
an operator-facing Product Mode projection.

AP-800 extends, and does not fork:

```text
reuse_or_extend:
  AP-792 24h loop certification harness (substrate-floor + scenario pattern)
  AP-793 isolated agent execution substrate facts
  AP-794 finding slice plan schema and blocking modes
  AP-786 autonomous evolution session receipts
  ProductMode operational inbox read-model (visibility surface)
do_not_create:
  parallel provider runtime, lane runtime, judge, repair planner or slice planner
  any path that can fabricate a production pass from fixtures
```

## Non-Negotiable Safety

- Read-only. No provider call, no agent run, no lane execution, no branch, no
  commit, no merge, no deploy, no secret access, no destructive operation.
- All IO is in-memory or temp/fixture only.
- `test_mode` can prove invariants on representative shapes but **never** certifies
  production (`production_certified` is always `false`).
- There is no code path from a fixture to a production pass. Mode is decided by how
  AP-800 is invoked, never by what the cycle fixture claims about itself.

## Certification Modes

- `test_mode` (default, fixtures / test doubles): a contract self-test. Top status
  is at most `partial` (or `blocked` if a hard invariant fails). `production_certified=false`.
- `runtime_real` (`use_real_services=true` against a real recorded cycle): certifies
  production only when the recorded cycle carries real authority and real substrate
  facts. A fixture cannot enter this mode by self-declaring `runtime_real`.

## Detected Capabilities

Probed via canonical class/interface names (overridable through
`capability_overrides` and `capability_probes`) or contract-doc evidence. Absent
capabilities are reported precisely — never a false pass.

| Capability | Source (AP) | Detection |
|---|---|---|
| `provider_port_session_store` | AP-795 | Agent provider port + session store class/interface |
| `finding_slice_planner` | AP-794/AP-796 | Slice planner class, or AP-794 contract doc evidence |
| `lane_orchestrator` | AP-797 | Lane orchestrator class/interface |
| `integration_judge` | AP-798 | Integration judge class/interface |
| `repair_planner` | AP-799 | Repair planner class/interface |
| `ap793_substrate_facts` | AP-793 | AP-793 facts present in the cycle evidence + contract doc |
| `ap792_harness` | AP-792 | `Loop24hCertificationHarnessService` present |

## Cycle Evidence Inspected

From the recorded cycle / fixture, AP-800 reads (never executes):

- substrate facts (AP-793): `provider_invoked`, `provider_authority`,
  `sandbox_kind`, `worktree_materialized`, `owner_runtime_chain`,
  `product_diff_exists`, `focused_validation`, `inbox_item`, `evidence_refs`,
  `merge_governance` (evaluated + `main_before != main_after` when merged);
- whether the provider was real vs simulated/mock/test-double;
- the finding breadth and `scope_profile` (to require an AP-794 slice plan for
  broad `factory_max` findings);
- the AP-794 slice plan (`decomposition_status = sliced`) when required;
- the lane plan: `context_scout`, `architect`, `implementer`, `reviewer`, `judge`,
  and `repair` (required when validation failed);
- the integration judge decision.

## Hard Blockers (status = blocked)

- `factory_max_broad_finding_without_slice_plan` — a broad/strategic/self-referential
  `factory_max` finding reached execution without an AP-794 `sliced` plan.
- `validation_failed_without_repair_lane` — validation failed and no repair
  lane/planner is present.
- `merge_claimed_without_main_advance` — a merge is claimed but `main` did not
  advance (no real new head). Mirrors the AP-793 merge-truth rule.
- `incomplete_substrate_facts` — the cycle claims completion but a required AP-793
  fact is missing.
- `multi_agent_claimed_without_required_lanes` — a multi-agent cycle is claimed but
  the required lanes are not all present.

A blocked plan is honest evidence, not a failure to hide.

## Production Pass Requirements (status = passed)

`production_certified` is `true` only when ALL hold:

- `certification_mode == runtime_real`;
- the provider was really invoked under real authority (not mock/simulated);
- all AP-793 substrate facts present;
- AP-794 slice plan present when the `factory_max` finding is broad;
- lane plan contains `context_scout`, `architect`, `implementer`, `reviewer`,
  `judge`;
- repair lane present when validation failed;
- integration judge decision present;
- evidence refs, inbox item, focused validation and merge-governance evaluation
  present;
- no missing required capability;
- no hard blocker.

Otherwise status is `partial` (capabilities or evidence missing, no hard violation)
or `blocked` (a hard invariant failed).

## Output

Schema: `atlas.agent_execution.multi_agent_cycle_certification.v1`

- `status`: `passed` | `partial` | `blocked`.
- `certification_mode`: `test_mode` | `runtime_real`.
- `production_certified`: bool (only ever `true` in `runtime_real`).
- `capabilities`: detected matrix (present / matched / candidates / missing).
- `missing_capabilities`: exact list.
- `substrate_facts`: AP-793 fact presence map.
- `lane_plan`: per-lane presence/status, missing lanes.
- `slice_plan`: required?, present?, decomposition status, slice count.
- `judge_decision`: present?, selected candidate, rationale present?.
- `repair`: required?, present?, status.
- `blockers`: hard blocker list (empty when none).
- `invariants`: machine-readable checks with pass/fail and reason.
- `product_mode_projection`: operator-facing visibility (see below).
- `next_operator_action`: one explicit next step.
- deterministic `report_hash` (excludes `generated_at`).
- `claim_policy`: read-only, no provider/agent/lane/merge, `fixtures_certify_production=false`,
  `false_pass_possible=false`.

## Product Mode Projection

Schema: `atlas.agent_execution.multi_agent_cycle_product_mode.v1`

Surfaces, for the operator, every fact needed to trust or reject a cycle:

- `lanes`: each of `context_scout`, `architect`, `implementer`, `reviewer`,
  `judge`, `repair` with present/status;
- `slice_plan`: required + status + slice count;
- `judge_decision`: present + selected candidate;
- `repair`: required + present + status;
- `missing_capabilities`: exact list;
- `cycle_real_or_blocked`: `real` | `partial` | `blocked`;
- `next_operator_action`: explicit next step.

## Wiring (no live-file edits in this contract)

AP-800 ships as an isolated read-model so it can land while AP-792/Product Mode are
being edited by other agents. Wiring is additive and detection-only:

- **AP-792 harness**: add a multi-agent scenario that calls
  `MultiAgentCycleCertificationService::certify()` and folds its `status` into the
  harness report. AP-800's `partial`/`blocked` must downgrade the harness, never the
  reverse.
- **Product Mode inbox** (`ProductModeOperationalInboxReadModelService`): include
  `product_mode_projection` as an operator item when a multi-agent cycle is recorded,
  so missing lanes / judge / repair / slice plan are visible with the next action.

These edits are intentionally left to the owning agents to avoid concurrent-edit
collisions; AP-800 stays consumable as a pure function in the meantime.

## Hardened By AP-802

The lane half of this certification is **hardened** by AP-802
(`LaneExecutionContractService`). Where AP-800 checks lane *presence* in a cycle
receipt, AP-802 proves each lane is a separated execution contract (role,
context, authority, provider plan, evidence obligations, output schema, receipt)
and blocks when a lane has no durable receipt (`lane_receipt_missing`). A cycle
that passes AP-800 lane presence but fails AP-802 lane-contract validation is not
a real multi-agent cycle. See
`docs/ap/AP-802-multi-agent-lane-execution-contract-hardening-contract.md`.

## Acceptance

- `MultiAgentCycleCertificationService` is read-only and side-effect free.
- A complete fixture is `passed` only in `runtime_real`; in `test_mode` it is
  `partial` and `production_certified=false`.
- Missing provider port → `partial` with the capability listed.
- Broad `factory_max` finding without an AP-794 slice plan → `blocked`.
- Validation failed without a repair planner/lane → `blocked`.
- `test_mode` never certifies production.
- `product_mode_projection` contains lanes and a `next_operator_action`.
- `report_hash` is deterministic for identical input (excludes `generated_at`).
- No code path fabricates a production pass from fixtures.
