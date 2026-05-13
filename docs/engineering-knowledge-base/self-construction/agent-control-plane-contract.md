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
---

# Atlas Agent Control Plane Contract
Status: canonical read-only implementation contract. Parent program: Atlas Self-Construction OS.

Canonical command:

```bash
php artisan atlas:ai:self-construction --agent-control-plane --json
php artisan atlas:ai:self-construction --agent-run-sync --json
php artisan atlas:ai:self-construction --agent-heartbeat --actor=<actor> --session=<session> --packet=<packet> --json
php artisan atlas:ai:self-construction --agent-run-liveness --json
php artisan atlas:ai:self-construction --agent-cost-event --actor=<actor> --session=<session> --packet=<packet> --model=<model> --input-tokens=<n> --output-tokens=<n> --cost-usd=<amount> --json
php artisan atlas:ai:self-construction --agent-work-product --actor=<actor> --session=<session> --packet=<packet> --artifact-type=<type> --artifact-path=<path> --artifact-hash=<sha256> --summary=<summary> --json
php artisan atlas:ai:self-construction --agent-adapter-contract --json
php artisan atlas:ai:self-construction --agent-wakeup-queue --json
php artisan atlas:ai:self-construction --agent-wakeup-write --json
php artisan atlas:ai:self-construction --agent-wakeup-scheduler --json
php artisan atlas:ai:self-construction --agent-wakeup-claim --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-dispatch-preflight --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-dispatch-receipt-template --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-dispatch-receipt-validation-preflight --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-dispatch-receipt-write --actor=<actor> --session=<session> --decision=<decision> --signed-by=<identity> --receipt-hash=<sha256> --dispatch-envelope-hash=<sha256> --expires-at=<timestamp> --json
php artisan atlas:ai:self-construction --agent-dispatch-executor-preflight --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-dispatch-executor-contract-template --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-dispatch-executor-release-preflight --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-dispatch-executor-release-authorization-template --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-dispatch-executor-release-authorization-receipt-draft --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-dispatch-executor-release-authorization-signature-request --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-dispatch-executor-release-authorization-post-signature-runbook --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-dispatch-executor-release-authorization-signed-receipt-template --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-dispatch-executor-release-authorization-signed-receipt-preflight --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-dispatch-executor-release-authorization-persistence-template --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-dispatch-executor-release-authorization-persistence-preflight --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-dispatch-executor-release-authorization-persistence-writer-contract-template --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-dispatch-executor-release-authorization-persistence-writer-implementation-preflight --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-dispatch-executor-release-authorization-persistence-writer-implementation-packet --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-dispatch-executor-release-authorization-persistence-status --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-dispatch-executor-receipt-use-writer-contract-template --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-dispatch-executor-receipt-use-writer-preflight --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-dispatch-executor-receipt-use-writer-implementation-packet --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-dispatch-executor-sandbox-binding-contract-template --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-dispatch-executor-sandbox-binding-preflight --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-dispatch-executor-sandbox-binding-implementation-packet --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-dispatch-executor-provider-start-driver-contract-template --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-dispatch-executor-provider-start-driver-preflight --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-dispatch-executor-provider-start-driver-implementation-packet --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-dispatch-executor-adapter-invocation-boundary-contract-template --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-dispatch-executor-adapter-invocation-boundary-preflight --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-dispatch-executor-adapter-invocation-boundary-implementation-packet --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-provider-adapter-registry-contract-template --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-provider-adapter-registry-preflight --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-provider-adapter-registry-implementation-packet --actor=<actor> --session=<session> --json
php artisan atlas:ai:self-construction --agent-{provider-adapter-execution-guard,codex-provider-execution,codex-process-start-release,codex-supervised-start-executor,codex-process-spawn-enablement,codex-process-spawn-executor,codex-external-process-runtime-driver,codex-external-process-invocation-authorization,codex-external-process-invoker-dry-run,codex-real-invoker-release-preflight,codex-signed-real-invoker-release-gate,codex-real-invoker-implementation-boundary,codex-real-invoker-executor-plan,codex-real-invoker-executor-fresh-release-gate,codex-real-invoker-executor-enablement-gate,codex-real-invoker-supervised-start-activation-gate,codex-real-invoker-guarded-process-start-executor,codex-real-invoker-final-process-start-authorization-gate,codex-real-invoker-actual-process-start-rehearsal-executor,codex-real-invoker-process-start-envelope-builder,codex-real-invoker-start-execution-gate,codex-real-invoker-process-starter-readiness-gate,codex-real-invoker-manual-start-executor-receipt-writer,codex-real-invoker-operator-start-handoff-builder,codex-real-invoker-post-start-receipt-contract-builder,codex-real-invoker-post-start-evidence-receipt-writer,codex-real-invoker-post-start-liveness-monitor,codex-real-invoker-post-start-dispatch-release-gate,codex-real-invoker-post-start-signed-dispatch-authorization-gate}-{contract-template,preflight,implementation-packet} --actor=<actor> --session=<session> --json
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

- show available, claimed, completed and withheld packets, active provider sessions from claims and completed runs from completed reservations;
- connect sessions to the Obras Shared Workspace / Forge Workspace;
- expose liveness from lease expiry, persistent runtime schema readiness for agent runs/heartbeats and synced run liveness without mutating state;
- record controlled provider/model cost events and work products for synced runs when runtime schema exists;
- expose the provider-neutral adapter invocation contract for Codex, Claude, Gemini and local runtime;
- project, materialize, select and claim wakeup queue items without scheduling jobs, dispatching providers or mutating packet state;
- build dispatch preflight, signed dispatch receipt template, validation preflight and durable receipt schema/model before signed dispatch persistence;
- persist a validated signed dispatch receipt without starting providers or dispatching work;
- verify a signed dispatch receipt is valid for a future executor without starting providers or marking the receipt used;
- generate the provider dispatch executor contract template that binds receipt, packet, adapter, token policy, atomic receipt use, heartbeat, cost events and work products before any future provider start;
- verify that executor release remains blocked until persisted signed release authorization status, provider sandbox binding, atomic receipt-use writer and provider start driver exist;
- generate executor release authorization template, receipt draft, signature request, post-signature runbook, signed receipt template/preflight and persistence template/preflight without accepting signatures, persisting authorization, marking receipts used or starting providers;
- generate authorization persistence writer contract, implementation preflight and implementation packet without creating writer files or writing authorization;
- persist signed executor release authorizations through the scoped writer service only when called by a future release path, using idempotency, expiry checks, external signature validation report hash, database transaction and append-only ledger event while never starting providers or dispatching work;
- inspect persisted executor release authorization state, selected receipt hash, expiry, pending release status and storage readiness without marking receipts used, writing ledger events, starting providers or dispatching work;
- generate the atomic dispatch receipt-use writer contract, preflight and implementation packet so a future executor can mark one signed dispatch receipt used exactly once before provider start, without marking receipts used yet;
- mark a signed dispatch receipt used through the scoped receipt-use writer service only when called by a future release path, with row locking, idempotency, expiry checks, packet/provider guards and append-only ledger event while never starting providers or dispatching work;
- generate the provider sandbox/worktree binding contract, preflight and implementation packet so every future provider start is tied to one receipt, one packet scope, one workspace identity and one non-overlapping worktree before any provider can run;
- bind a future provider executor to one workspace/worktree through the scoped sandbox binding writer service only when called by a future release path, using one active binding per receipt, workspace-root guards, scope hashes, hot-scope rejection and append-only ledger event while never creating worktrees, marking receipts used or starting providers;
- generate the provider start driver contract, preflight and implementation packet with mandatory used-receipt, active-binding, run-state, heartbeat, budget and scoped-context guards while never invoking an adapter from the command surface;
- generate the provider adapter registry contract, preflight and implementation packet so Codex, Claude, Gemini, local shell and HTTP adapters are declared as provider-specific descriptors with process start and token spend disabled by default;
- generate the provider adapter execution guard contract, preflight and implementation packet so prepared adapter invocations are explicitly blocked until provider-specific execution contracts exist;
- generate the Codex provider execution, process start release and supervised start executor contract/preflight/packet chain, and prepare a Codex execution envelope through the scoped driver, so the first provider path is Codex-only and still unable to start Codex or spend tokens;
- prepare the adapter invocation boundary through a scoped service requiring a pre-start guarded run, pre-start heartbeat, explicit context pack hash, explicit continuation summary hash, adapter/command/cwd match and append-only ledger event while never spawning a provider process, calling Codex/Claude/Gemini/local/HTTP adapters or spending tokens;
- prepare the Codex real-invoker post-start dispatch release, signed dispatch authorization, dispatch executor handoff, receipt-use executor, provider start driver gate, adapter invocation boundary gate, adapter execution guard gate, provider execution contract gate and process start release gate chain after external liveness, binding signed receipt hashes, human signature hash, dispatch window, replay guard, kill switch, executor packet, workspace hash, scope lock, active sandbox, provider start projection and pre-start heartbeat while still forbidding process start, provider calls, adapter execution, token spend, supervised start execution and dispatch;
- expose continuation commands for the next AI session and show which Paperclip-style control-plane primitives are absorbed;
- show what is still missing before provider dispatch can become automatic.

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

Required top-level objects:

- `control_plane`
- `control_plane_hash`
- `non_execution_guarantees`
- `human_summary`

Required `control_plane` fields:

- `control_plane_id`
- `canonical_name`
- `parent_program`
- `workspace_id`
- `workspace_name`
- `maturity`
- `current_capability`
- `not_yet_runtime_capable`
- `paperclip_patterns_absorbed`
- `source_of_truth`
- `counts`
- `readiness`
- `provider_sessions`
- `agent_runs`
- `execution_workspaces`
- `liveness`
- `continuation_summary`
- `next_build_slices`
- `invariants`

## Source Of Truth

The projection must be derived from canonical Self-Construction surfaces:

- `--packet-queue`
- `--parallel-session-plan`
- `--multi-session-readiness-gate`
- `--forge-workspace-status`
- `--reservation-status`

No independent state store is allowed in this projection. Until the database-backed runtime exists, the local reservation ledger remains the durable source of claim state.

## Paperclip Patterns Absorbed

The Agent Control Plane must explicitly carry these patterns:

- Agent Runtime State
- Heartbeat Run shape
- Checkout Lock
- Execution Workspace
- Activity Log
- Approvals as Objects
- Adapter Abstraction
- Run Liveness Projection
- Continuation Summary

The implementation may project some of these before it can persist them. Projected capability must be labeled honestly.

## Runtime Gaps

The Control Plane is not complete until these become durable runtime objects:

- liveness state writer;
- scheduler dispatch execution;
- signed dispatch receipt persistence and validation;
- automatic cost import from provider adapters;
- provider adapter invocation runtime;
- automatic work product collection from provider adapters.

## Invariants

- No provider session without a packet claim.
- One active reservation per packet.
- One active writer per allowed file scope.
- Completed packets need evidence hash before integration review.
- Loose provider-to-provider chat is not source of truth.
- The control-plane projection does not dispatch agents.
- The control-plane projection does not enable self-programming.

## Operator Flow

For a new AI implementation session:

```bash
php artisan atlas:ai:self-construction --agent-control-plane --json
php artisan atlas:ai:self-construction --packet-queue --json
php artisan atlas:ai:self-construction --agent-start-packet --actor=<actor> --session=<session> --json
```

The session must then:

1. read the scoped start packet;
2. validate scope;
3. touch only allowed files;
4. run required gates;
5. return evidence;
6. complete or release the reservation.

## Promotion Path

This contract is the bridge from Self-Construction OS to Self-Programming OS.

Promotion requires:

1. persistent `agent_runs`;
2. persistent `heartbeat_runs`;
3. controlled reservation-ledger-to-run sync;
4. heartbeat writer;
5. run liveness detector;
6. liveness state writer after signed policy;
7. controlled cost event writer;
8. controlled work product registry;
9. provider-neutral adapter invocation contract;
10. adapter runtime for Codex, Claude, Gemini, local shell and HTTP;
11. automatic cost import and budget policy;
12. automatic work product collection from provider adapters;
13. approval objects for sensitive actions;
14. wakeup queue for long-running unattended work;
15. controlled wakeup writer;
16. read-only wakeup scheduler;
17. controlled wakeup claim without provider dispatch;
18. read-only dispatch preflight from claimed wakeup items;
19. signed dispatch receipt template;
20. signed dispatch receipt validation preflight;
21. durable signed dispatch receipt schema/model;
22. signed dispatch receipt persistence writer;
23. dispatch executor preflight;
24. controlled provider executor;
25. scheduler dispatch after signed policy.

Only after those pieces exist can Atlas move from "coordinate providers" to "orchestrate providers".
