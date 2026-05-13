---
id: atlas-self-construction-agent-control-plane-contract
type: engineering_knowledge
title: Atlas Self-Construction - Agent Control Plane Contract
status: active
category: architecture
priority: 100
summary: Canonical contract for the read-only Agent Control Plane projection that coordinates provider sessions, inferred agent runs, packet locks, liveness and continuation state before Self-Programming OS dispatch exists.
tags:
  - atlas-ai
  - self-construction
  - agent-control-plane
  - forge-workspace
  - multi-agent-orchestration
capabilities:
  - provider_session_state
  - agent_run_projection
  - packet_checkout_lock
  - run_liveness_projection
  - continuation_summary
decisions:
  - Agent Control Plane is a submodule of Atlas Self-Construction OS, not a replacement for Self-Programming OS.
  - The first implementation is a read-only projection over the durable reservation ledger and Forge Workspace state.
  - Provider dispatch remains disabled until runs, heartbeats, liveness, adapters, cost events and work products are durable runtime objects.
maintenance:
  - Read before changing provider sessions, agent runs, checkout locks, liveness, continuation summaries or automated provider dispatch.
  - Update when Agent Control Plane moves from projection to persistent runtime.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/obras/shared-workspace-and-forge.md
  - docs/engineering-knowledge-base/self-construction/paperclip-control-plane-benchmark.md
  - app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php
  - app/Console/Commands/AtlasAiSelfConstructionCommand.php
  - app/Models/AtlasSelfConstructionAgentRun.php
  - app/Models/AtlasSelfConstructionAgentHeartbeat.php
  - app/Models/AtlasSelfConstructionAgentSandboxBinding.php
  - app/Services/Ai/SelfConstruction/AgentProviderAdapterRegistry.php
  - app/Services/Ai/SelfConstruction/AgentProviderAdapterExecutionGuard.php
  - app/Services/Ai/SelfConstruction/AgentDispatchExecutorAdapterInvocationBoundary.php
  - database/migrations/2026_05_12_010000_create_atlas_self_construction_agent_control_plane_tables.php
  - database/migrations/2026_05_12_030000_create_atlas_self_construction_agent_sandbox_bindings_table.php
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 300
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-self-construction-agent-control-plane-contract
graph_title: Atlas Self-Construction - Agent Control Plane Contract
graph_world: atlas
graph_layer: gear
graph_kind: contract
graph_parent: atlas-ai-self-construction-os
graph_status: active
graph_source: repo
repo_paths:
  - docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md
allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.
forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.
depends_on:
  - atlas-ai-documentation-operating-system
flows_to:
  - atlas-cartography
  - atlas-code
unlocks:
  - ai-safe-implementation-context
governs:
  - self-construction
evidence:
  - docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
visual_tags:
  - gear
  - contract
  - self-construction
ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de implementar.
ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - Contexto desatualizado entre doc, codigo, teste e evidencia.
observability_signals:
  - docs-health status ok
next_actions:
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
---
# Atlas Agent Control Plane Contract
Status: canonical read-only implementation contract. Parent program: Atlas Self-Construction OS.
Canonical command:

```bash
php artisan atlas:ai:self-construction --agent-control-plane --json
php artisan atlas:ai:self-construction --agent-control-plane-runtime-schema-preflight --json
php artisan atlas:ai:self-construction --agent-adapter-contract --json
php artisan atlas:ai:self-construction --agent-provider-adapter-invocation-runtime-policy --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-provider-process-supervision-policy --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-cost-import-policy --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-work-product-collection-policy --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-policy --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-runtime-execution-gate --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-dry-run-tick --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-writer-contract --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-writer-preflight --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-release-template --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-release-receipt-draft --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-release-receipt-validation-preflight --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-release-receipt-persistence-contract --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-release-receipt-persistence-writer-preflight --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-release-receipt-persistence-writer-implementation-packet --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-release-receipt-persistence-status --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-mutating-writer-release-preflight --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-mutating-writer-contract --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-mutating-writer-preflight --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-mutating-writer-implementation-packet --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-mutating-writer-status --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-guarded-runtime-invocation-contract --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-guarded-runtime-invocation-preflight --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-guarded-runtime-invocation-implementation-packet --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-guarded-runtime-invocation-status --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-dispatch-receipt-use-release-contract --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-dispatch-receipt-use-preflight --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-dispatch-receipt-use-implementation-packet --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-dispatch-receipt-use-status --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-provider-start-driver-release-contract --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-provider-start-driver-preflight --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-provider-start-driver-implementation-packet --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-provider-start-driver-status --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-adapter-invocation-boundary-release-contract --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-adapter-invocation-boundary-preflight --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-adapter-invocation-boundary-implementation-packet --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-adapter-invocation-boundary-status --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-provider-adapter-execution-guard-release-contract --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-provider-adapter-execution-guard-preflight --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-provider-adapter-execution-guard-implementation-packet --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-provider-adapter-execution-guard-status --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-provider-specific-execution-contract-release --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-provider-specific-execution-contract-preflight --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-provider-specific-execution-contract-implementation-packet --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-provider-specific-execution-contract-status --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-codex-process-start-release-contract --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-codex-process-start-release-preflight --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-codex-process-start-release-implementation-packet --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-automatic-dispatch-scheduler-one-shot-tick-codex-process-start-release-status --actor=<actor> --session=<session> --json
# Runtime families: --agent-{run-sync,heartbeat,run-liveness,run-liveness-write,cost-event,work-product,wakeup-queue,wakeup-write,wakeup-scheduler,wakeup-claim}
# Dispatch families: --agent-{dispatch-preflight,dispatch-receipt-template,dispatch-receipt-validation-preflight,dispatch-receipt-write,dispatch-executor-preflight,dispatch-executor-contract-template}
# Executor families: --agent-dispatch-executor-{release-*,receipt-use-writer-*,sandbox-binding-*,provider-start-driver-*,adapter-invocation-boundary-*}
# Provider families: --agent-provider-adapter-{registry-*,execution-guard-*} and --agent-codex-{provider-execution-*,process-start-release-*,supervised-start-executor-*,process-spawn-*}
```

## Purpose
Atlas Agent Control Plane is the operational layer that lets Atlas coordinate multiple AI implementation sessions without loose chat, duplicated scope or invisible state.
It is not the Self-Programming OS yet. It is the control layer inside Self-Construction OS that projects:

- packet queue state;
- durable packet checkout locks;
- active provider sessions;
- inferred agent runs;
- Forge Workspace status;
- liveness and lease state;
- continuation summary;
- next runtime slices needed for full automation.

## Current Capability
The current implementation is a read-only projection backed by existing durable reservation state.
It can:

- show queue, claims, provider sessions, completed runs and Forge Workspace state;
- expose persistent runtime schema readiness, run liveness, liveness materialization, cost events, work products and wakeups without dispatching providers;
- build and persist signed dispatch receipts, release authorizations, receipt-use records and sandbox bindings only through scoped writers with idempotency, expiry, row locks, scope hashes and append-only evidence;
- expose provider start driver, adapter registry, adapter invocation boundary and adapter execution guard contracts so every provider path is receipt-bound, workspace-bound, budget-bound and process-start disabled by default;
- expose the Codex-only staged chain and provider process supervision policy through provider execution, process release, supervised start, process spawn and real-invoker gates while preserving no-spawn, no-token-spend, kill switch, replay guard and evidence-bridge requirements;
- expose automatic cost import policy as a read-only normalization and guard contract; no billing API read, live log parsing or automatic cost write is allowed in this slice;
- expose automatic work product collection policy as a read-only artifact normalization and guard contract; no workspace scan, provider stream read or automatic artifact write is allowed in this slice;
- expose automatic dispatch scheduler policy as a read-only selection and guard contract; no wakeup claim, receipt write, receipt use or provider start is allowed in this slice;
- expose automatic dispatch scheduler runtime execution gate as a read-only one-shot dry-run release contract; no scheduler tick runs until a future signed dry-run tick release exists;
- expose automatic dispatch scheduler dry-run tick as a read-only tick simulation; it selects the next candidate and dispatch envelope preview without claiming wakeups, writing receipts, using receipts, starting providers or spending tokens;
- expose automatic dispatch scheduler one-shot tick writer contract as the bounded mutation contract for a future signed tick; it permits at most one wakeup claim and one signed pending dispatch receipt only after a separate fresh release, and still forbids receipt use, provider start, adapter invocation and self-programming;
- expose automatic dispatch scheduler one-shot tick writer preflight as a read-only release-condition evaluator; it checks candidate freshness, release hash shape and dispatch envelope integrity without persisting release receipts, claiming wakeups, writing dispatch receipts or starting providers;
- expose automatic dispatch scheduler one-shot tick release template as an unsigned, non-persisted release payload for a future human/operator signature; it does not accept signatures, persist release receipts, claim wakeups, write dispatch receipts, invoke adapters, start providers or enable self-programming;
- expose automatic dispatch scheduler one-shot tick release receipt draft as an unsigned, non-persisted receipt draft; it shapes the future signed approval object and validation checklist without accepting signatures, persisting release receipts, claiming wakeups, writing dispatch receipts, invoking adapters, starting providers or enabling self-programming;
- expose automatic dispatch scheduler one-shot tick release receipt validation preflight as a read-only validator for the draft; it checks signature, candidate, envelope, scope and one-shot invariants before any future persistence contract, while still forbidding signature acceptance, release receipt persistence, wakeup claim, dispatch receipt write, adapter invocation, provider start and self-programming;
- expose automatic dispatch scheduler one-shot tick release receipt persistence contract as a read-only contract for a future idempotent release receipt writer; it defines source hashes, idempotency keys, locks, evidence and storage target while still forbidding receipt persistence in this slice, wakeup claim, dispatch receipt write, adapter invocation, provider start and self-programming;
- expose automatic dispatch scheduler one-shot tick release receipt persistence writer preflight as a read-only writer readiness gate; it verifies contract, model, table, required columns, lock policy and implementation requirements while still forbidding signature acceptance, release receipt persistence, wakeup claim, dispatch receipt write, adapter invocation, provider start and self-programming;
- expose automatic dispatch scheduler one-shot tick release receipt persistence writer implementation packet as the scoped work order for the future writer service; it names allowed files, tasks, acceptance criteria, stop conditions and required gates while still forbidding signature acceptance, receipt persistence in the projection, wakeup claim, dispatch receipt write, adapter invocation, provider start and self-programming;
- expose automatic dispatch scheduler one-shot tick release receipt persistence status as the read-only service projection; it confirms the writer class, storage, ledger readiness, persisted release receipt count and next slice while still forbidding signature acceptance, receipt persistence in the projection, wakeup claim, dispatch receipt write, adapter invocation, provider start and self-programming;
- expose automatic dispatch scheduler one-shot tick mutating writer release preflight as the read-only release gate before any future mutating scheduler tick; it verifies a persisted release receipt against the current dry-run candidate, wakeup identity, dispatch envelope hash, expiry, decision and safety flags while still forbidding wakeup claim, dispatch receipt write, receipt use, adapter invocation, provider start and self-programming;
- expose automatic dispatch scheduler one-shot tick mutating writer contract as the future bounded mutation contract: after release preflight is ready, it may claim at most one queued wakeup, write at most one signed pending dispatch receipt and append evidence, while still forbidding receipt use, provider start, adapter invocation, token spend and self-programming;
- expose automatic dispatch scheduler one-shot tick mutating writer preflight as the read-only implementation prerequisite check; it verifies storage, models, columns, atomic sequence, rollback guard and no-provider/no-self-programming constraints before any future implementation packet can create the mutating writer;
- expose automatic dispatch scheduler one-shot tick mutating writer implementation packet as the scoped work order for the future service; it names allowed files, tasks, acceptance criteria, gates, stop conditions and rollback expectations for claiming one wakeup and writing one dispatch receipt while still forbidding receipt use, provider start and self-programming in this projection;
- implement automatic dispatch scheduler one-shot tick mutating writer service as the first bounded runtime mutation in this scheduler chain; the service requires a fresh persisted release receipt, recomputes release/contract/preflight hashes, locks the selected queued wakeup, claims exactly one wakeup, writes exactly one `signed_pending_dispatch` receipt, appends evidence in the same transaction, is idempotent by receipt hash and rolls back claim/receipt when evidence fails;
- expose automatic dispatch scheduler one-shot tick mutating writer status as a read-only projection over the service; it reports writer readiness, claimed wakeup count, pending dispatch receipt count and the next guarded runtime invocation contract while still forbidding status projection writes, receipt use, provider start, adapter invocation, token spend and self-programming;
- expose automatic dispatch scheduler one-shot tick guarded runtime invocation contract as the official future entrypoint into the mutating writer; it defines required signed input, at-most-one writer call, idempotency by receipt hash, output shape and prohibitions against receipt use, provider start, adapter invocation, token spend and self-programming;
- expose automatic dispatch scheduler one-shot tick guarded runtime invocation preflight as the read-only readiness gate for that future entrypoint; it verifies the mutating writer service, canonical method, input contract, idempotency policy and no-provider guard before any invoker implementation;
- expose automatic dispatch scheduler one-shot tick guarded runtime invocation implementation packet as the scoped work order for the future invoker service; it names allowed files, tasks, acceptance criteria, gates and stop conditions, while still forbidding invoker execution in the projection;
- implement automatic dispatch scheduler one-shot tick guarded runtime invoker service as the official runtime boundary into the mutating writer; it validates signed input shape, calls the mutating writer exactly once, returns the writer result, preserves idempotency by receipt hash and still forbids dispatch receipt use, provider start, adapter invocation, token spend and self-programming;
- expose automatic dispatch scheduler one-shot tick guarded runtime invocation status as a read-only projection over the invoker service; it reports invoker readiness, canonical method, writer readiness, pending dispatch receipt count and the next dispatch receipt-use release contract while still forbidding status projection writes, writer invocation, receipt use, provider start, adapter invocation, token spend and self-programming;
- expose automatic dispatch scheduler one-shot tick dispatch receipt-use release contract as the read-only contract for the next boundary after a signed pending dispatch receipt exists; it names `AgentDispatchExecutorReceiptUseWriter::markReceiptUsedAtomically`, required hashes, idempotency by provider start attempt, handoff policy and the rule that receipt use is not provider start;
- expose automatic dispatch scheduler one-shot tick dispatch receipt-use preflight as the read-only readiness gate for the future receipt-use invoker; it verifies the guarded runtime invocation service, receipt storage, ledger storage, generic receipt-use writer and no-provider/no-adapter/no-token constraints before any implementation packet can create the invoker;
- expose automatic dispatch scheduler one-shot tick dispatch receipt-use implementation packet as the scoped work order for the future invoker service; it allows only a receipt-use invoker, tests, command/readiness wiring and documentation, and still forbids marking receipt used in the projection, provider start, adapter invocation, token spend and self-programming;
- implement automatic dispatch scheduler one-shot tick dispatch receipt-use invoker service as the official bridge from scheduler-generated signed pending dispatch receipts into the generic atomic receipt-use writer; it validates input shape, delegates once to `AgentDispatchExecutorReceiptUseWriter`, records idempotent receipt use and still forbids provider start, adapter invocation, token spend and self-programming;
- expose automatic dispatch scheduler one-shot tick dispatch receipt-use status as a read-only projection over the invoker; it reports pending and used receipt counts, latest used receipt metadata, writer/invoker readiness and the next provider start driver release contract while still forbidding status projection writes or provider start;
- expose automatic dispatch scheduler one-shot tick provider start driver release contract as the read-only contract after receipt use; it names `AgentDispatchExecutorProviderStartDriver::startProviderOnce`, required receipt/release/sandbox fields, idempotency by provider start attempt, the pre-start guarded run contract and the rule that this driver does not spawn an external provider process;
- expose automatic dispatch scheduler one-shot tick provider start driver preflight as the read-only readiness gate for the future scheduler-specific invoker; it verifies receipt-use readiness, generic provider start driver readiness, storage tables, canonical method and no-provider/no-adapter/no-token constraints before any implementation packet can create the invoker;
- expose automatic dispatch scheduler one-shot tick provider start driver implementation packet as the scoped work order for the future invoker service; it allows only a scheduler provider-start invoker, tests, command/readiness wiring and documentation, and still forbids calling the driver in the projection, spawning providers, invoking adapters, spending tokens or enabling self-programming;
- implement automatic dispatch scheduler one-shot tick provider start driver invoker service as the official scheduler bridge into the generic provider start driver; it validates input shape, delegates once to `AgentDispatchExecutorProviderStartDriver`, creates/reuses the pre-start guarded run and heartbeat and still forbids external provider process start, adapter invocation, token spend and self-programming;
- expose automatic dispatch scheduler one-shot tick provider start driver status as a read-only projection over the invoker; it reports invoker readiness, generic driver readiness, pre-start guarded run counts, latest run metadata and the next adapter invocation boundary release contract while still forbidding status projection writes or provider starts;
- expose automatic dispatch scheduler one-shot tick adapter invocation boundary release contract as the read-only contract after provider pre-start guard; it names `AgentDispatchExecutorAdapterInvocationBoundary::prepareInvocation`, requires provider start attempt, command, cwd and context hashes, and states that adapter boundary preparation is metadata preparation, not adapter execution;
- expose automatic dispatch scheduler one-shot tick adapter invocation boundary preflight as the read-only readiness gate for the future scheduler-specific boundary invoker; it verifies provider-start readiness, generic adapter boundary readiness, storage tables, canonical method and no-provider/no-adapter/no-token constraints before any implementation packet can create the invoker;
- expose automatic dispatch scheduler one-shot tick adapter invocation boundary implementation packet as the scoped work order for the future invoker service; it allows only a scheduler adapter-boundary invoker, tests, command/readiness wiring and documentation, and still forbids calling adapters, starting providers, spending tokens or enabling self-programming in the projection;
- implement automatic dispatch scheduler one-shot tick adapter invocation boundary invoker service as the official scheduler bridge into the generic adapter invocation boundary; it validates input shape and context hashes, delegates once to `AgentDispatchExecutorAdapterInvocationBoundary`, moves the pre-start guarded run to `adapter_invocation_prepared`, appends evidence and still forbids adapter execution, provider process start, token spend and self-programming;
- expose automatic dispatch scheduler one-shot tick adapter invocation boundary status as a read-only projection over the invoker; it reports invoker readiness, generic boundary readiness, prepared run counts, latest adapter invocation metadata and the next provider adapter execution guard release contract while still forbidding status projection writes, adapter calls, provider starts or token spend;
- expose automatic dispatch scheduler one-shot tick provider adapter execution guard release contract as the read-only contract after adapter boundary preparation; it names `AgentProviderAdapterExecutionGuard::blockUntilProviderSpecificContract`, requires execution guard identity plus adapter invocation identity, and states that the guard is a blocking tripwire, not provider-specific execution;
- expose automatic dispatch scheduler one-shot tick provider adapter execution guard preflight as the read-only readiness gate for the future scheduler-specific guard invoker; it verifies adapter-boundary readiness, generic execution guard readiness, storage tables, canonical method and no-provider/no-adapter/no-token constraints before any implementation packet can create the invoker;
- expose automatic dispatch scheduler one-shot tick provider adapter execution guard implementation packet as the scoped work order for the future invoker service; it allows only a scheduler guard invoker, tests, command/readiness wiring and documentation, and still forbids starting providers, calling adapters, spending tokens or enabling self-programming in the projection;
- implement automatic dispatch scheduler one-shot tick provider adapter execution guard invoker service as the official scheduler bridge into the generic provider adapter execution guard; it validates input shape, delegates once to `AgentProviderAdapterExecutionGuard`, records the provider-specific execution block and still forbids adapter execution, provider process start, token spend and self-programming;
- expose automatic dispatch scheduler one-shot tick provider adapter execution guard status as a read-only projection over the invoker; it reports invoker readiness, generic guard readiness, guarded run counts, latest blocking metadata and the next provider-specific execution contract release while still forbidding status projection writes, adapter calls, provider starts or token spend;
- expose automatic dispatch scheduler one-shot tick provider-specific execution contract release as the read-only Codex-specific contract after the execution guard; it names `AgentCodexProviderExecutionDriver::prepareCodexExecution`, requires guard identity, adapter invocation identity, explicit context hashes, sandbox `cwd`, budget and liveness bounds, and states that provider-specific execution contract preparation is not process start;
- expose automatic dispatch scheduler one-shot tick provider-specific execution contract preflight as the read-only readiness gate for the future scheduler-specific Codex contract invoker; it verifies execution-guard readiness, Codex driver readiness, sandbox binding storage, ledger storage, canonical method and no-Codex/no-token/no-self-programming constraints before any implementation packet can create or call the invoker;
- expose automatic dispatch scheduler one-shot tick provider-specific execution contract implementation packet as the scoped work order for the Codex provider execution contract invoker; it allows only the Codex scheduler invoker, tests, command/readiness wiring and documentation, and still forbids Codex process start, Codex app/CLI calls, token spend, packet completion and self-programming;
- implement automatic dispatch scheduler one-shot tick Codex provider execution contract invoker service as the official scheduler bridge into the generic Codex provider execution driver; it validates input shape, hashes, budget and provider identity, delegates once to `AgentCodexProviderExecutionDriver`, records `codex_provider_execution` metadata and evidence, and still forbids Codex process start, adapter execution, token spend and self-programming;
- expose automatic dispatch scheduler one-shot tick provider-specific execution contract status as a read-only projection over the Codex invoker; it reports invoker readiness, generic Codex driver readiness, sandbox binding readiness, prepared run counts, latest Codex execution metadata and the next Codex process start release contract while still forbidding status projection writes, Codex calls, provider starts or token spend;
- expose automatic dispatch scheduler one-shot tick Codex process start release contract as the read-only release contract after Codex provider execution preparation; it names `AgentCodexProcessStartReleaseGate::authorizeCodexProcessStart`, requires operator release receipt hash and Codex execution contract hash, and states that process start release is authorization for a later supervised path, not process start;
- expose automatic dispatch scheduler one-shot tick Codex process start release preflight as the read-only readiness gate for the scheduler-specific release invoker; it verifies provider-specific execution readiness, generic process-start release gate readiness, storage tables, canonical method and no-Codex/no-token/no-self-programming constraints before any implementation packet can create or call the invoker;
- expose automatic dispatch scheduler one-shot tick Codex process start release implementation packet as the scoped work order for the release invoker; it allows only the scheduler release invoker, tests, command/readiness wiring and documentation, and still forbids Codex process start, Codex app/CLI calls, token spend, run terminal state, packet completion and self-programming;
- implement automatic dispatch scheduler one-shot tick Codex process start release invoker service as the official scheduler bridge into the generic process start release gate; it validates release input and hashes, delegates once to `AgentCodexProcessStartReleaseGate`, records `codex_process_start_release` metadata and evidence, and still forbids Codex process start, adapter execution, token spend and self-programming;
- expose automatic dispatch scheduler one-shot tick Codex process start release status as a read-only projection over the release invoker; it reports invoker readiness, generic release gate readiness, released run counts, latest release metadata and the next supervised start executor release contract while still forbidding status projection writes, Codex calls, provider starts or token spend;
- expose automatic dispatch scheduler one-shot tick Codex supervised start executor release contract as the read-only contract after process start release authorization; it names `AgentCodexSupervisedStartExecutor::prepareSupervisedStart`, requires supervised start id plus sanitizer, ready-probe and rollback hashes, and states that supervised start preparation is not process spawn;
- expose automatic dispatch scheduler one-shot tick Codex supervised start executor preflight as the read-only readiness gate for the scheduler-specific supervised start invoker; it verifies process-start release readiness, generic supervised executor readiness, storage tables, canonical method and no-spawn/no-token/no-self-programming constraints before any implementation packet can create or call the invoker;
- expose automatic dispatch scheduler one-shot tick Codex supervised start executor implementation packet as the scoped work order for the supervised start invoker; it allows only the scheduler supervised-start invoker, tests, command/readiness wiring and documentation, and still forbids Codex process spawn, Codex app/CLI calls, token spend, run terminal state, packet completion and self-programming;
- implement automatic dispatch scheduler one-shot tick Codex supervised start executor invoker service as the official scheduler bridge into the generic supervised start executor; it validates supervision input and hashes, delegates once to `AgentCodexSupervisedStartExecutor`, records `codex_supervised_start` metadata and evidence, and still forbids Codex process spawn, adapter execution, token spend and self-programming;
- expose automatic dispatch scheduler one-shot tick Codex supervised start executor status as a read-only projection over the supervised start invoker; it reports invoker readiness, generic executor readiness, prepared supervised-start run counts, latest supervised metadata and the next process spawn enablement contract while still forbidding status projection writes, Codex calls, provider starts or token spend;
- expose automatic dispatch scheduler one-shot tick Codex process spawn enablement contract as the read-only contract after supervised start preparation; it names `AgentCodexProcessSpawnEnablementGate::enableCodexProcessSpawn`, requires operator spawn receipt hash and supervised start contract hash, and states that spawn enablement is authorization for a later final spawn executor, not process spawn;
- expose automatic dispatch scheduler one-shot tick Codex process spawn enablement preflight as the read-only readiness gate for the scheduler-specific spawn enablement invoker; it verifies supervised-start readiness, generic spawn enablement gate readiness, storage tables, canonical method and no-spawn/no-token/no-self-programming constraints before any implementation packet can create or call the invoker;
- expose automatic dispatch scheduler one-shot tick Codex process spawn enablement implementation packet as the scoped work order for the spawn enablement invoker; it allows only the scheduler spawn-enablement invoker, tests, command/readiness wiring and documentation, and still forbids Codex process spawn, Codex app/CLI calls, token spend, run terminal state, packet completion and self-programming;
- implement automatic dispatch scheduler one-shot tick Codex process spawn enablement invoker service as the official scheduler bridge into the generic spawn enablement gate; it validates spawn enablement input and hashes, delegates once to `AgentCodexProcessSpawnEnablementGate`, records `codex_process_spawn_enablement` metadata and evidence, and still forbids Codex process spawn, adapter execution, token spend and self-programming;
- expose automatic dispatch scheduler one-shot tick Codex process spawn enablement status as a read-only projection over the spawn enablement invoker; it reports invoker readiness, generic gate readiness, spawn-enabled run counts, latest enablement metadata and the next final process spawn executor contract while still forbidding status projection writes, Codex calls, provider starts or token spend;
- expose automatic dispatch scheduler one-shot tick Codex final process spawn executor contract as the read-only contract after spawn enablement; it names `AgentCodexProcessSpawnExecutor::prepareProcessSpawn`, requires final spawn receipt, supervision plan, stdout/stderr sink and liveness probe hashes, and states that final spawn executor preparation is not external process runtime;
- expose automatic dispatch scheduler one-shot tick Codex final process spawn executor preflight as the read-only readiness gate for the scheduler-specific final spawn invoker; it verifies spawn enablement readiness, generic spawn executor readiness, storage tables, canonical method and no-runtime/no-token/no-self-programming constraints before any implementation packet can create or call the invoker;
- expose automatic dispatch scheduler one-shot tick Codex final process spawn executor implementation packet as the scoped work order for the final spawn invoker; it allows only the scheduler final-spawn invoker, tests, command/readiness wiring and documentation, and still forbids Codex process start, Codex app/CLI calls, subprocess spawn, token spend, run terminal state, packet completion and self-programming;
- implement automatic dispatch scheduler one-shot tick Codex final process spawn executor invoker service as the official scheduler bridge into the generic process spawn executor; it validates final spawn input and hashes, delegates once to `AgentCodexProcessSpawnExecutor`, records `codex_process_spawn_executor` metadata and evidence, and still forbids external process runtime, adapter execution, token spend and self-programming;
- expose automatic dispatch scheduler one-shot tick Codex final process spawn executor status as a read-only projection over the final spawn invoker; it reports invoker readiness, generic executor readiness, prepared executor run counts, latest executor metadata and the next external process runtime driver contract while still forbidding status projection writes, Codex calls, provider starts or token spend;
- expose automatic dispatch scheduler one-shot tick Codex external process runtime driver contract as the read-only contract after final process spawn executor preparation; it names `AgentCodexExternalProcessRuntimeDriver::prepareExternalRuntime`, requires runtime receipt, process command, environment contract and termination policy hashes, and states that runtime-driver preparation is not process invocation;
- expose automatic dispatch scheduler one-shot tick Codex external process runtime driver preflight as the read-only readiness gate for the scheduler-specific runtime driver invoker; it verifies final process spawn executor readiness, generic runtime driver readiness, storage tables, canonical method and no-invocation/no-token/no-self-programming constraints before any implementation packet can create or call the invoker;
- expose automatic dispatch scheduler one-shot tick Codex external process runtime driver implementation packet as the scoped work order for the runtime driver invoker; it allows only the scheduler runtime-driver invoker, tests, command/readiness wiring and documentation, and still forbids Codex process invocation, Codex app/CLI calls, subprocess spawn, token spend, run terminal state, packet completion and self-programming;
- implement automatic dispatch scheduler one-shot tick Codex external process runtime driver invoker service as the official scheduler bridge into the generic external runtime driver; it validates runtime driver input and hashes, delegates once to `AgentCodexExternalProcessRuntimeDriver`, records `codex_external_process_runtime_driver` metadata and evidence, and still forbids process invocation, adapter execution, token spend and self-programming;
- expose automatic dispatch scheduler one-shot tick Codex external process runtime driver status as a read-only projection over the runtime driver invoker; it reports invoker readiness, generic driver readiness, prepared runtime-driver run counts, latest runtime metadata and the next process invocation authorization contract while still forbidding status projection writes, Codex calls, provider starts or token spend;
- require every post-start gate to prove liveness through accepted `post_start_evidence_acceptance_bridge_id`; raw liveness cannot bypass the receipt/evidence chain.

It cannot:
- launch providers;
- dispatch Codex, Claude, Gemini or local runtimes;
- dispatch a claimed wakeup item into a provider automatically;
- write ledger events;
- mark dispatch receipts used;
- approve completion;
- enable self-programming;
- replace human approval for sensitive actions.

## Schema
The JSON payload must use:
```text
schema_version: atlas.self_construction_agent_control_plane.v1
status: agent_control_plane_ready
mode: read_only_agent_control_plane_projection
execution_allowed: false
dispatch_allowed: false
ledger_write_allowed: false
```

Required top-level objects: `control_plane`, `control_plane_hash`, `non_execution_guarantees`, `human_summary`.
Required `control_plane` fields: `control_plane_id`, `canonical_name`, `parent_program`, `workspace_id`, `workspace_name`, `maturity`, `current_capability`, `not_yet_runtime_capable`, `paperclip_patterns_absorbed`, `source_of_truth`, `counts`, `readiness`, `provider_sessions`, `agent_runs`, `execution_workspaces`, `liveness`, `continuation_summary`, `next_build_slices`, `invariants`, optional `runtime_contracts_available`.

## Resumo

Contrato canonico do Agent Control Plane para coordenar sessoes de provider, claims, liveness, evidencia e continuacao sem liberar dispatch autonomo.

## Papel no Atlas

Este doc define a ponte governada entre Self-Construction OS e o futuro Self-Programming OS.

## Onde Se Encaixa

A peca vive abaixo de `atlas-ai-self-construction-os` e governa projecoes, contratos e gates de execucao de agentes.

## Contratos

O payload usa `atlas.self_construction_agent_control_plane.v1`, preserva `execution_allowed=false`, `dispatch_allowed=false` e exige ponte de evidencia aceita para liveness pos-start.

## Fluxo

O operador consulta control plane, fila de packets, start packet, executa escopo permitido, retorna evidencia e completa ou libera a reserva.

## Regras para IA

Agentes devem tratar este contrato como limite de seguranca: sem provider start, sem dispatch, sem ledger write e sem self-programming fora dos gates assinados.

## Escopo de Implementacao

Mudancas pertencem aos paths declarados no frontmatter e aos servicos/modelos de Self-Construction relacionados.

## Dependencias

Depende de Self-Construction OS, Forge Workspace, reservation ledger, provider adapter contracts e Evidence Ledger.

## Evidencias

Evidencia minima: `docs-health`, `architecture-validate`, testes de Self-Construction e hashes/receipts da cadeia post-start.

## Riscos

O risco principal e confundir projecao read-only com autorizacao de runtime real ou aceitar liveness solto sem evidence bridge.

## Exemplos

Use `php artisan atlas:ai:self-construction --agent-control-plane --json` para inspecionar estado sem executar providers.

## Proximas Acoes

Manter este contrato sincronizado com qualquer novo gate de provider start, evidence bridge, liveness monitor ou dispatch executor.
