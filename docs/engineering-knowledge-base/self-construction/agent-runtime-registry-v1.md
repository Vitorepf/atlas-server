---
id: atlas-self-construction-agent-runtime-registry-v1
type: engineering_knowledge
title: Atlas Agent Control Plane - Agent Runtime Registry v1
status: active
category: architecture
priority: 93
summary: Persistent local Agent Control Plane layer that represents registered agents, capabilities, heartbeats, availability, task matching, load balancing, quarantine and handoff. Advisory posture only; this layer never dispatches, never calls providers and never spends tokens.
tags:
  - atlas-ai
  - self-construction
  - agent-control-plane
  - agent-registry
  - heartbeat
  - capability-matching
  - quarantine
  - handoff
capabilities:
  - agent_registry_persistence
  - agent_heartbeat_persistence
  - agent_capability_catalog
  - availability_planning
  - task_matching
  - load_balancing_policy
  - quarantine_management
  - handoff_planning
  - registry_certification
decisions:
  - Agent Runtime Registry is a submodule of the Agent Control Plane, not a replacement for Self-Programming OS.
  - Runtime boundary is a persistent-local projection over the canonical storage prefix; provider dispatch remains disabled.
  - Quarantine blocks availability; release requires reviewer + reason.
  - Handoff is advisory only; handoff_execution_allowed is always false in this layer.
  - Certification gates promotion of registry components; no promotion without green invariants.
maintenance:
  - Update when a new capability, status, kind, policy or invariant is added.
  - Do not edit agent-control-plane-contract.md from this doc.
  - Coordinate with task queue + claim/lease and workspace + work product Claudes via separate slices.
related_paths:
  - docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md
  - docs/engineering-knowledge-base/self-construction/atlas-agent-control-plane-runtime-pilot-map-v1.md
  - docs/engineering-knowledge-base/self-construction/atlas-agent-control-plane-safety-invariants-v1.md
  - docs/engineering-knowledge-base/self-construction/atlas-self-construction-os-operator-runbook-v1.md
  - app/Services/Ai/SelfConstruction/AgentRuntimeRegistryRepository.php
  - app/Services/Ai/SelfConstruction/AgentRuntimeRegistryCapabilityCatalog.php
  - app/Services/Ai/SelfConstruction/AgentRuntimeRegistryHeartbeatRepository.php
  - app/Services/Ai/SelfConstruction/AgentRuntimeRegistryAvailabilityPlanner.php
  - app/Services/Ai/SelfConstruction/AgentRuntimeRegistryTaskMatcher.php
  - app/Services/Ai/SelfConstruction/AgentRuntimeRegistryLoadBalancingPolicy.php
  - app/Services/Ai/SelfConstruction/AgentRuntimeRegistryQuarantineRepository.php
  - app/Services/Ai/SelfConstruction/AgentRuntimeRegistryHandoffProtocolBuilder.php
  - app/Services/Ai/SelfConstruction/AgentRuntimeRegistryOrchestrator.php
  - app/Services/Ai/SelfConstruction/AgentRuntimeRegistryCertificationService.php
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 520
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-self-construction-agent-runtime-registry-v1
graph_title: Atlas Agent Control Plane - Agent Runtime Registry v1
graph_world: atlas
graph_layer: gear
graph_kind: contract
graph_parent: atlas-self-construction-agent-control-plane-contract
graph_status: active
graph_source: repo
human_name: Atlas Agent Control Plane - Agent Runtime Registry v1
canonical_name: Atlas Agent Control Plane - Agent Runtime Registry v1
technical_name: atlas-self-construction-agent-runtime-registry-v1
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/self-construction/agent-runtime-registry-v1.md
repo_paths:
  - docs/engineering-knowledge-base/self-construction/agent-runtime-registry-v1.md
allowed_changes:
  - Atualizar quando um componente registry, capability, heartbeat ou invariant mudar com evidencia.
forbidden_changes:
  - Declarar runtime real, dispatch real ou self-programming sem evidencia e gates verdes.
depends_on:
  - atlas-self-construction-agent-control-plane-contract
flows_to:
  - atlas-cartography
  - atlas-code
unlocks:
  - ai-safe-implementation-context
governs:
  - self-construction
evidence:
  - docs/engineering-knowledge-base/self-construction/agent-runtime-registry-v1.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan test tests/Feature/Ai/SelfConstruction/AgentRuntimeRegistryRepositoryTest.php tests/Feature/Ai/SelfConstruction/AgentRuntimeRegistryCapabilityCatalogTest.php tests/Feature/Ai/SelfConstruction/AgentRuntimeRegistryHeartbeatRepositoryTest.php tests/Feature/Ai/SelfConstruction/AgentRuntimeRegistryAvailabilityPlannerTest.php tests/Feature/Ai/SelfConstruction/AgentRuntimeRegistryTaskMatcherTest.php tests/Feature/Ai/SelfConstruction/AgentRuntimeRegistryLoadBalancingPolicyTest.php tests/Feature/Ai/SelfConstruction/AgentRuntimeRegistryQuarantineRepositoryTest.php tests/Feature/Ai/SelfConstruction/AgentRuntimeRegistryHandoffProtocolBuilderTest.php tests/Feature/Ai/SelfConstruction/AgentRuntimeRegistryOrchestratorTest.php tests/Feature/Ai/SelfConstruction/AgentRuntimeRegistryCertificationServiceTest.php"
requires_evidence: true
risk_level: high
visual_tags:
  - gear
  - contract
  - agent-runtime-registry
ai_entrypoints:
  - Leia Resumo, Componentes, Storage, Runtime Safety e Limites antes de implementar consumidor.
ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - Confundir assignment_plan com dispatch real.
  - Bypassar quarantine para destravar slice.
observability_signals:
  - docs-health status ok
  - certification batch invariants_all_true true
next_actions:
  - Manter doc sincronizado com runtime-pilot-map e safety-invariants.
---
# Atlas Agent Control Plane - Agent Runtime Registry v1

The Agent Runtime Registry is the persistent-local Agent Control Plane
layer that represents which agents exist, what they can do, whether
they are alive, whether they can take a packet, which packet is the
best advisory match, in what order candidates rank, whether they are
quarantined and how a handoff envelope is shaped.

It is a sibling of, not a replacement for, the canonical
`agent-control-plane-contract.md`. It does not dispatch. It does not
call providers. It does not spend tokens. It does not write the
evidence ledger. It does not enable self-programming.

## 1. Components

| Component | Class | Schema | Posture |
|-----------|-------|--------|---------|
| Agent Registry | `AgentRuntimeRegistryRepository` | `atlas.self_construction.agent_runtime_registry.v1` | persistent-local |
| Capability Catalog | `AgentRuntimeRegistryCapabilityCatalog` | `atlas.self_construction.agent_runtime_registry_capability_catalog.v1` | read-only |
| Heartbeat Ledger | `AgentRuntimeRegistryHeartbeatRepository` | `atlas.self_construction.agent_runtime_registry_heartbeat.v1` | persistent-local |
| Availability Planner | `AgentRuntimeRegistryAvailabilityPlanner` | `atlas.self_construction.agent_runtime_registry_availability_plan.v1` | read-only |
| Task Matcher | `AgentRuntimeRegistryTaskMatcher` | `atlas.self_construction.agent_runtime_registry_task_match.v1` | read-only |
| Load Balancing Policy | `AgentRuntimeRegistryLoadBalancingPolicy` | `atlas.self_construction.agent_runtime_registry_load_balancing_policy.v1` | read-only |
| Quarantine Ledger | `AgentRuntimeRegistryQuarantineRepository` | `atlas.self_construction.agent_runtime_registry_quarantine.v1` | persistent-local |
| Handoff Protocol Builder | `AgentRuntimeRegistryHandoffProtocolBuilder` | `atlas.self_construction.agent_runtime_registry_handoff_protocol.v1` | read-only |
| Orchestrator | `AgentRuntimeRegistryOrchestrator` | `atlas.self_construction.agent_runtime_registry_orchestrator.v1` | read-only |
| Certification | `AgentRuntimeRegistryCertificationService` | `atlas.self_construction.agent_runtime_registry_certification.v1` | read-only gate |

None of these dispatches a real provider call. The Orchestrator
composes the others into one advisory plan. The Certification gates
whether the layer is ready to be consumed by the next slice.

## 2. Storage layout

The registry uses three local storage prefixes on the default `local`
disk.

```text
atlas/self-construction/agent-control-plane/agent-registry/
atlas/self-construction/agent-control-plane/agent-heartbeats/
atlas/self-construction/agent-control-plane/agent-quarantine/
```

Each prefix contains a `registry.json` or `index.json`, per-agent
files, and a `.lock` file used for coarse advisory locking under
`withLock`. No other storage location is permitted by this layer.

## 3. Agent record shape

`register()` accepts a payload that is normalized into a canonical
record:

- `agent_id`: lowercase ASCII slug-ish, 2-128 chars, matching
  `^[A-Za-z0-9][A-Za-z0-9._\-]{1,127}$`.
- `schema_version`: registry schema constant.
- `label`: human-readable label.
- `kind`: `codex | claude | human_operator | local_worker | dry_run_agent`.
- `status`: `registered | available | busy | stale | quarantined | disabled | unregistered`.
- `capabilities[]`: normalized (trim + lowercase + dedupe + sort).
- `surfaces[]`: normalized string list.
- `max_parallel_tasks`: integer, minimum 1.
- `current_task_count`: integer, clamped to `<= max_parallel_tasks`.
- `heartbeat_required`: bool, default true.
- `lease_supported`: bool.
- `workspace_isolation_supported`: bool.
- `cost_meter_supported`: bool.
- `continuation_summary_supported`: bool.
- `evidence_required`: bool, default true.
- `runtime_execution_allowed`: always false.
- `provider_call_allowed`: always false.
- `token_spend_allowed`: always false.
- `dispatch_allowed`: always false.
- `self_programming_allowed`: always false.
- `receipts[]`: append-only local receipts.
- `history[]`: append-only event log.

`register()` is idempotent: re-registering an identical signature
returns `idempotent: true`. Re-registering with a different signature
updates the record and records a `status_changed` history event.

## 4. Capability catalog

Canonical capabilities (in `AgentRuntimeRegistryCapabilityCatalog::CAPABILITIES`):

- `code_review`
- `code_edit`
- `test_runner`
- `docs_writer`
- `self_construction_readiness`
- `task_packet_handling`
- `claim_lease_handling`
- `workspace_planning`
- `workspace_isolation`
- `evidence_collection`
- `continuation_summary`
- `cost_reporting`
- `human_approval`
- `dry_run_only`

`normalizeCapabilities` lowercases, trims, dedupes and sorts. `match`
returns `matched`, `missing`, `extra`, `match_score`, and
`match_status` of `matched | partial | missing`.

## 5. Heartbeats

Heartbeats are recorded only when supplied by the caller or a test.
The repository never pings real agents and never starts a process.

A heartbeat carries:
- `heartbeat_id`, `agent_id`, `observed_at`, `recorded_at`, `status`.
- `current_task_count`, `max_parallel_tasks` (clamped).
- `active_lease_ids[]`, `current_workspace_ids[]`.
- `last_continuation_summary_hash`.
- Runtime flags always false.

`staleAgents()` and `heartbeatStatus()` use a TTL (default 90 seconds)
to mark agents as fresh or stale.

## 6. Availability

`AvailabilityPlanner::plan(agents, heartbeats, options)` returns:

- `available_agents[]` (status eligible, fresh heartbeat, capacity not
  full, capabilities match, workspace/lease requirements met, not
  quarantined).
- `unavailable_agents[]` with explicit `reasons[]`.
- `stale_agents[]`.
- `capacity_summary` (total/used/free slots).
- `availability_hash` (sha256, stable for the same input).

Quarantine is passed as `options.quarantined_agents` so the planner is
pure; the orchestrator queries the quarantine ledger for the live set.

## 7. Task matching

`TaskMatcher::match(taskPacket, agents, options)` returns:

- `candidate_agents[]`, `rejected_agents[]` with explicit
  `rejections[]`.
- `best_candidate`, `matching_policy`, `capability_match`,
  `load_match`, `risk_match`.
- `match_hash` (sha256, stable for the same input).
- `dispatch_allowed: false`.

Rejection reasons include `missing_capabilities`, `capacity_full`,
`workspace_isolation_required`, `lease_support_required`,
`human_approval_required_for_high_risk`,
`dry_run_agent_requires_dry_run_only_task`,
`dry_run_only_capability_requires_dry_run_only_task`.

High/critical risk packets require an agent with the `human_approval`
capability. `dry_run_agent` kind only accepts `dry_run_only: true`
packets.

## 8. Load balancing

`LoadBalancingPolicy::rank(candidates, options)` accepts a `policy` of
`least_loaded | capability_score | risk_first_human |
dry_run_preferred | stable_order`. The default is
`capability_score`. Invalid policies fall back to
`capability_score`.

Output includes `ranked_agents[]`, `selected_agent`, `policy`,
`ranking_reasons[]`, `ranking_hash` (sha256, stable for the same
input).

## 9. Quarantine

`QuarantineRepository` accepts one of the canonical reasons:
`stale_heartbeat`, `lease_violation`, `scope_violation`,
`missing_continuation_summary`, `evidence_failure`,
`operator_disabled`, `runtime_safety_violation`.

- `quarantine()` requires a reason code and a `declared_by` reviewer.
- `release()` requires a `reviewer` and a `reason` - there is no
  silent release.
- `isQuarantined()` returns the current boolean.
- `list()` supports filters `only_active`, `reason_code`, `limit`.

Receipts (`quarantine_declared`, `quarantine_released`) are recorded
locally with a stable sha256 hash. They are not the evidence ledger;
they are local audit only.

## 10. Handoff planning

`HandoffProtocolBuilder::build(fromAgent, toAgent, taskPacket, options)`
returns a handoff envelope. `handoff_execution_allowed` is always false
in this layer.

Blockers include:
- `from_agent_id_missing`, `to_agent_id_missing`,
  `task_packet_id_missing`.
- `missing_continuation_summary` (no continuation summary hash).
- `to_agent_missing_capabilities`.
- `missing_evidence_refs` when the packet requires evidence.
- `to_agent_lease_support_missing`,
  `to_agent_workspace_isolation_missing`.
- `to_agent_quarantined`.

Warnings include `handoff_to_same_agent` and `from_agent_quarantined`.
High/critical risk packets always set `operator_approval_required:
true`.

## 11. Orchestrator

`AgentRuntimeRegistryOrchestrator` composes the other components:

- `registerAndHeartbeat(agent, heartbeat)` registers and optionally
  records a heartbeat.
- `planAssignment(taskPacket, options)` builds availability + match +
  ranking plans and returns blockers/warnings.
- `planHandoff(fromAgentId, toAgentId, taskPacket, options)` builds a
  handoff plan using live registry/quarantine state.
- `health(options)` reports a single posture summary with stable
  `orchestrator_hash`.

The orchestrator never authorizes runtime. Its blockers include
`no_agents_registered`, `no_matching_candidates`, `no_available_agents`.
Its warnings include `quarantined_agents_present`.

## 12. Certification

`AgentRuntimeRegistryCertificationService::certify()` returns:

- `invariants[]` and `invariants_all_true`.
- `violation_count`, `warning_count`.
- `runtime_safety` with `runtime_safety_all_false: true`.
- `certification_hash` (sha256, stable).
- `next_action`.

Invariants include:
- `registry_repository_available`, `capability_catalog_available`,
  `heartbeat_repository_available`, `availability_planner_available`,
  `task_matcher_available`, `load_balancing_available`,
  `quarantine_repository_available`,
  `handoff_protocol_builder_available`, `orchestrator_available`.
- `register_idempotent`, `heartbeat_stale_detection`,
  `capability_match_detection`, `quarantine_blocks_availability`,
  `handoff_requires_continuation`.
- `runtime_safety:*_false` for every runtime flag and
  `no_dispatch_real`, `no_provider_call`, `no_token_spend`,
  `no_self_programming`.

The certification surface is the only gate that produces "ready"
verdicts for consumers that depend on this registry boundary.

## 13. Relation to Task Queue and Claim/Lease

This layer represents agents, not packets and not leases.

- The Task Queue is the persistent local store of task packets and
  status transitions (`AgentControlPlaneTaskPacketQueueRepository`).
- Claim/Lease lifecycle is owned by `AgentControlPlaneClaimLeaseRepository`
  and `AgentControlPlaneClaimLeaseSimulator`.
- The Multi-Agent Parallelism Planner composes claim/lease and lock
  graph for multiple agents.

The Agent Runtime Registry exposes `planAssignment()` and
`planHandoff()` outputs that those layers may consume to choose an
agent. It does not call them and it does not own their state.

Integration with Task Queue and Claim/Lease is intentionally separate.
This registry does not bundle packet storage, reservation ledger or lease
simulation into the agent registry boundary.

## 14. Assignment plan vs real dispatch

This is the most important distinction.

- An assignment plan is an advisory output of the orchestrator. It
  names a candidate agent, ranks candidates, lists blockers and
  warnings, and stamps a stable hash. It is read-only.
- A real dispatch creates state in the durable reservation ledger,
  consumes the agent's capacity, starts the agent's runtime path and
  may eventually consume tokens. That behavior is outside this layer.

Any consumer that treats an assignment plan as a dispatch is wrong.

## 15. Runtime safety

Every public surface of this layer returns the following flags as
false:

- `runtime_execution_allowed`
- `dispatch_allowed`
- `provider_call_allowed`
- `token_spend_allowed`
- `self_programming_allowed`
- `ledger_write_allowed`
- `handoff_execution_allowed` (handoff builder + orchestrator)

The certification surface returns
`runtime_safety_all_false: true` when every flag remains false,
which is the canonical signal that the layer is safe to operate.

## 16. Limits and non-goals

This layer does not:

- start any process;
- call Codex CLI, Codex app, Claude API or any other provider;
- spawn subprocesses;
- invoke adapters;
- enable adapter execution;
- dispatch a task to any real agent;
- write the evidence ledger;
- advance the next required slice;
- enable self-programming;
- declare Atlas Self-Construction OS complete.

If a consumer requires any of these behaviors, that consumer must open a
separate owner slice with its own decision receipt.

## 17. How to extend safely

To add a new capability:

- Append to `CAPABILITIES` in `AgentRuntimeRegistryCapabilityCatalog`.
- Add a description in the catalog's `describe()` helper.
- Add a certification check or scenario that exercises the new
  capability against the matcher and availability planner.

To add a new status:

- Append to `STATUSES` in `AgentRuntimeRegistryRepository`.
- Update the availability planner to treat the new status correctly
  (eligible, busy, stale, etc.).
- Add a test asserting the new status is honored.

To add a new policy:

- Append to `POLICIES` in `AgentRuntimeRegistryLoadBalancingPolicy`.
- Implement the comparator in `rank()`.
- Add a test asserting the new policy's ordering is stable.

Any extension requires a green certification batch before consumers
depend on it.

## Resumo

Camada Agent Runtime Registry: registry + capability catalog +
heartbeat + availability + task matching + load balancing + quarantine
+ handoff + orchestrator + certification. Persistent-local, dry-run,
nunca dispatches.

## Papel no Atlas

Submodulo do Agent Control Plane. Representa agentes e disponibilidade
para que Task Queue/Claim-Lease ou outro consumidor com owner proprio
possa decidir dispatch real sob gates separados.

## Onde Se Encaixa

Filho do Agent Control Plane Contract, irmao do Task Queue Repository,
do Claim/Lease Repository/Simulator e do Multi-Agent Parallelism
Planner.

## Contratos

Cada componente honra runtime_safety_all_false e nunca executa real.
Quarantine bloqueia availability. Handoff exige continuation summary.
Certification gateia promocao.

## Fluxo

register -> heartbeat -> availability -> match -> rank -> handoff
plan; certification valida cada surface antes de promover.

## Regras para IA

Nao tratar assignment_plan como dispatch. Nao bypassar quarantine. Nao
relaxar invariantes. Nao executar provider, processo ou self-programming.

## Escopo de Implementacao

Apenas arquivos com prefixo `AgentRuntimeRegistry*` em
`app/Services/Ai/SelfConstruction/` e
`tests/Feature/Ai/SelfConstruction/`, mais este doc.

## Dependencias

Storage local default, Agent Control Plane Contract, Self-Programming
Safety Contract, Self-Construction OS law.

## Evidencias

Receipts locais com sha256 stable hash, certification batch verde,
docs-health verde e testes AgentRuntimeRegistry verdes.

## Riscos

Confundir dry-run com runtime, vazar autorizacao em handoff, ignorar
quarantine, declarar promocao sem certification verde.

## Exemplos

Operador roda `register()` para um `dry_run_agent`, `heartbeat()`
fresh, `planAssignment()` para uma task `dry_run_only=true`, recebe
`assignment_plan` advisory com `dispatch_allowed=false`.

## Proximas Acoes

Manter sincronia com Task Queue/Claim-Lease/Workspace + Work Product
Manifest sob owners independentes; promover consumidores apenas com
Certification verde.
