# Control plane and task fabric

The Control Plane decides what evolves, when, at what risk, budget, scope, and mode. It owns the task-packet queue, leases, claims, and certification. It never writes code. Task Fabric converts approved architecture into conflict-free, self-sufficient packets with scope, dependencies, gates, rollback, and evidence. It never sets final priority or verifies merit. Together they are the compile-and-admit stage between the decide organs and the execute organs.

## Purpose

To turn a criticized architecture contract into a queue of atomic, conflict-free, self-sufficient packets, and to admit each cycle's work only when scope, risk, budget, and mode allow. This is where the simplicity law and the autonomy invariants are enforced before any worker ever sees a packet.

## Key abstractions

| Abstraction | Role |
|---|---|
| Control Plane | Chooses the next safe government action and bounds each cycle by scope, risk, budget, mode |
| Next action selector | Pure precedence-based selector: repair, create packets, schedule, verify, merge, sync, or hold |
| Scope risk budget gate | Bounds a cycle by owner scope, risk class, task count, cost budget, project lane, and rollback readiness |
| Autonomy mode | `off`, `observe`, or `execute`; observe never executes, off never acts |
| Task packet | The atomic execution unit: objective, allowed files, forbidden files, dependencies, risk class, gates, rollback, evidence |
| Packet spec validator | Preflight that rejects packets missing objective, acceptance, evidence, rollback, or with broad directories or non-Atlas-native ownership |
| Architecture contract compiler | Pure compiler that turns an approved contract into atomic packet-spec drafts |
| Dependency ladder | Topological sort of packets into buildable waves with explicit `depends_on` edges |
| Autonomy invariants | The five boolean fields every packet must carry to stay Atlas-native |
| AgentControlPlane family | The durable queue, lease, claim, and certification runtime |

## How it works

### Control Plane admission

The Control Plane runs two pure gates before any work is scheduled. `AtlasSelfConstructionNextActionSelector` chooses the next safe action by deterministic precedence:

| Precedence | Condition | Action |
|---|---|---|
| 0 | autonomy mode is `off` | hold_position |
| 1 | scope gate not allowed | hold_position |
| 2 | critical organs missing or blocked | repair_organs |
| 3 | knowledge_sync degraded but rest ready | run_knowledge_sync |
| 4 | autonomy mode is `observe` | hold_position |
| 5 | open verifications exist | verify_candidates |
| 6 | candidates ready to promote | prepare_merge |
| 7 | tasks pending workers | schedule_workers |
| 8 | backlog has acceptance work but no tasks | create_task_packets |
| 9 | otherwise | hold_position |

The selector never enqueues, dispatches, executes, merges, or writes ledgers. It emits facts only.

`AtlasSelfConstructionScopeRiskBudgetGate` bounds each cycle by owner scope, risk class, task count, and cost budget. It blocks when scope falls outside the project lane's `allowed_scope_roots`, when a forbidden organ is touched, when task or cost budget is empty, or when a high or hardest risk class lacks `rollback_ready`. The output is `allowed`, `blockers`, `normalized_scope`, `max_tasks`, `max_cost_units`, and `risk_floor`.

The autonomy mode (`AtlasSelfConstructionAutonomyModePolicy`) is `off`, `observe`, or `execute`. Observe mode runs the perception and decide organs but never executes; it is the safe mode for proving the pipeline. Off mode holds position no matter what.

### Task Fabric compilation

Task Fabric turns an approved architecture contract into atomic packet-spec drafts. `AtlasTaskFabricArchitectureContractCompiler` is pure: it never enqueues, dispatches, shells, gits, or mutates storage. It takes a contract (`contract_id`, `owner_scope`, `capability_gap`, `candidate_files`, `acceptance_seed`, `evidence_seed`, `risk_class`) and emits one draft per impl file, paired with its adjacent test file when one exists.

The compiler rejects four autonomy regressions before any draft is emitted:

- broad directory entries in `candidate_files` (no `.`, trailing `/`)
- empty `acceptance_seed` or `evidence_seed`
- empty `owner_scope`
- `capability_gap` wording that conflicts with Atlas-native ownership, matching the forbidden phrases `external provider owns`, `human operator owns final runtime`, and `external_provider_runtime`

Each draft carries a deterministic `spec_hash` (sha256 over the canonical contract fields).

### Packet spec validation

`AtlasTaskFabricPacketSpecValidator` is the preflight that validates a draft packet spec before it reaches the queue. It enforces the simplicity law and the autonomy invariants directly. The required topology is `shared_local_main_with_scope_lock` and the required simplicity contract is `atlas_native`. A packet is rejected when:

- `objective` is missing
- `allowed_files` is missing or contains a broad directory (no basename `.`)
- `scope_in` is missing
- `acceptance_criteria` is missing or mentions no gate (no `phpunit`, `pint`, `pest`, `test`, `gate`, `verify`, or `assert`)
- `required_evidence` is missing
- `rollback_hint` is missing
- `workspace_policy.execution_topology` is not `shared_local_main_with_scope_lock` (the `workspace_policy:non_shared_main` blocker)
- `simplicity_contract` is not `atlas_native` (the `ownership:non_atlas_native` blocker)

The validator returns `self_sufficient` and a sorted `blockers` list. It never mutates the queue.

### The dependency ladder

`AtlasTaskFabricDependencyLadder` orders packet specs into buildable waves with explicit `depends_on` edges, so every consumer arrives in a later wave than every producer it depends on. It uses a Kahn-style topological sort and surfaces three blocker families instead of guessing:

- `cycle_detected:<id>` — a dependency cycle would never finish
- `missing_producer_for:<symbol>` — a consume edge has no producer in the input
- `allowed_files_conflict:<path>` — two packets in the same wave would write the same file

When blockers are present, waves and edges are still emitted for the consistent subgraph, but the caller is responsible for halting until blockers are resolved. Within-wave order is alphabetical for determinism.

### The autonomy invariants

Every packet and runtime plan must preserve five invariants. A packet that violates any of them is a malformed autonomy regression, even if its implementation would be technically simple:

| Invariant | Required value | Enforced by |
|---|---|---|
| `final_runtime_owner` | `atlas_native` | Packet spec validator (`REQUIRED_SIMPLICITY`) and contract compiler (`FORBIDDEN_OWNERSHIP_PHRASES`) |
| `steady_state_runtime_owner` | `atlas_server` | Packet spec validator and contract compiler |
| `operator_dependency_allowed` | `false` | Contract compiler |
| `human_dependency_allowed` | `false` | Contract compiler |
| `external_provider_dependency_allowed` | `false` | Contract compiler |

The packet spec validator enforces the topology invariant (`shared_local_main_with_scope_lock`) so that worktree, sandbox, or branch isolation can never become the default execution model.

### The AgentControlPlane runtime

The durable queue, lease, claim, and certification runtime lives in the root of `app/Services/Ai/SelfConstruction/` as the `AgentControlPlane*` family. These are the large, older services that persist the task-packet queue, leases, claims, dispatch receipts, cost events, work products, and multi-agent loop certification. The per-organ Control Plane subdirectory (`app/Services/Ai/SelfConstruction/ControlPlane/`) holds the pure policy gates described above; the root family holds the durable runtime.

| File | Role |
|---|---|
| `app/Services/Ai/SelfConstruction/AgentControlPlaneTaskQueueOrchestrator.php` | Orchestrates the task-packet queue and leases |
| `app/Services/Ai/SelfConstruction/AgentControlPlaneTaskPacketQueueRepository.php` | Durable task-packet queue store |
| `app/Services/Ai/SelfConstruction/AgentControlPlaneClaimLeaseRepository.php` | Conflict-free claim and lease repository |
| `app/Services/Ai/SelfConstruction/AgentControlPlaneMultiAgentLoopCertificationService.php` | Multi-agent loop certification (anti-fake completion) |
| `app/Services/Ai/SelfConstruction/AgentControlPlaneChainIntegrityAuditService.php` | Chain-integrity audit of the receipt and evidence chain |
| `app/Services/Ai/SelfConstruction/AgentControlPlaneTaskAutoReplenishmentService.php` | Keeps the queue stocked from the backlog |
| `app/Services/Ai/SelfConstruction/AgentControlPlaneOneShotWorkerPacketService.php` | One-shot worker packet service |
| `app/Services/Ai/SelfConstruction/AgentControlPlaneReleaseDossierService.php` | Release dossier service |

## Integration points

- Inputs come from the [decide organs](separation-of-powers.md): Cortex's world snapshot, Goal and Value's anti-proxy verdict, Strategy Council's ranked directions, and Architecture Council's criticized contracts.
- Outputs flow to [Maestro and the worker swarm](maestro-and-worker-swarm.md), which serve and execute the admitted packets.
- Certification and chain integrity feed the [Verification court and merge governor](verification-court-and-merge-governor.md).
- The autonomy mode and master switches are gated by the [fleet control plane](agent-governance-fleet.md) and the [constitution](constitution-and-earned-autonomy.md).
- Knowledge sync readiness is one of the next-action selector's precedence rules; see [Learning transfer and knowledge sync](learning-transfer-and-knowledge-sync.md).

## Key source files

| File | Role |
|---|---|
| `app/Services/Ai/SelfConstruction/ControlPlane/AtlasSelfConstructionNextActionSelector.php` | Pure next-action selector (precedence 0-9) |
| `app/Services/Ai/SelfConstruction/ControlPlane/AtlasSelfConstructionScopeRiskBudgetGate.php` | Scope, risk, budget, lane, rollback gate |
| `app/Services/Ai/SelfConstruction/ControlPlane/AtlasSelfConstructionAutonomyModePolicy.php` | off / observe / execute mode policy |
| `app/Services/Ai/SelfConstruction/ControlPlane/AtlasSelfConstructionOrganReadinessComposer.php` | Composes organ readiness for the selector |
| `app/Services/Ai/SelfConstruction/TaskFabric/AtlasTaskFabricArchitectureContractCompiler.php` | Pure contract-to-packet-spec compiler |
| `app/Services/Ai/SelfConstruction/TaskFabric/AtlasTaskFabricPacketSpecValidator.php` | Preflight packet spec validator |
| `app/Services/Ai/SelfConstruction/TaskFabric/AtlasTaskFabricDependencyLadder.php` | Topological dependency ladder |
| `app/Services/Ai/SelfConstruction/TaskFabric/AtlasTaskFabricGiveBackLearningIntegrator.php` | Integrates give-back learning into future packets |
| `app/Services/Ai/SelfConstruction/TaskFabric/AtlasTaskFabricRoadmapGapMiner.php` | Mines roadmap gaps |

## Related pages

- [Self-Construction OS and government](index.md) — the overview and 16-organ map
- [Separation of powers](separation-of-powers.md) — the decide organs that feed Task Fabric
- [Maestro and worker swarm](maestro-and-worker-swarm.md) — where admitted packets are served and executed
- [Constitution and earned autonomy](constitution-and-earned-autonomy.md) — the autonomy levels and trust ladder
- [Glossary](../../overview/glossary.md) — task packet, autonomy invariants, dependency ladder
