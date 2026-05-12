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
  - database/migrations/2026_05_12_010000_create_atlas_self_construction_agent_control_plane_tables.php
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 300
---

# Atlas Agent Control Plane Contract

Status: canonical read-only implementation contract.

Parent program: Atlas Self-Construction OS.

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

- show available, claimed, completed and withheld packets;
- show active provider sessions from claimed reservations;
- show completed runs from completed reservations;
- connect sessions to the Obras Shared Workspace / Forge Workspace;
- expose liveness from lease expiry;
- expose persistent runtime schema readiness for agent runs and heartbeats;
- inspect synced runtime runs for active, stale, expired and terminal liveness without mutating state;
- record controlled provider/model cost events for synced runs when runtime schema exists;
- record controlled work products for synced runs when runtime schema exists;
- expose the provider-neutral adapter invocation contract for Codex, Claude, Gemini and local runtime;
- project wakeup queue candidates for stale, expired, terminal and review-ready runs;
- materialize wakeup queue candidates into runtime wakeup items without scheduling jobs;
- select ready wakeup items for future resume without claiming or dispatching them;
- claim one ready wakeup item for governed manual/provider resume without dispatching providers or mutating packet state;
- build a read-only provider dispatch preflight from a claimed wakeup item without starting providers;
- generate a read-only signed dispatch receipt template without signing or starting providers;
- validate readiness to persist a future signed dispatch receipt without writing, signing or starting providers;
- define the durable dispatch receipt schema/model required before signed dispatch can ever be persisted;
- persist a validated signed dispatch receipt without starting providers or dispatching work;
- verify a signed dispatch receipt is valid for a future executor without starting providers or marking the receipt used;
- generate the provider dispatch executor contract template that binds receipt, packet, adapter, token policy, atomic receipt use, heartbeat, cost events and work products before any future provider start;
- verify that executor release remains blocked until persisted signed release authorization status, provider sandbox binding, atomic receipt-use writer and provider start driver exist;
- generate the read-only executor release authorization template with required signatures, evidence, denial conditions and one-shot execution limits without accepting signatures or approving dispatch;
- generate the unsigned executor release authorization receipt draft without accepting signatures, persisting authorization, marking receipts used or starting providers;
- generate the executor release authorization signature request without accepting signatures, validating signatures, persisting authorization or starting providers;
- generate the post-signature runbook that sequences hash checks, external signature validation, evidence checks, denial conditions and signed receipt template preparation without validating signatures or granting release;
- generate the signed receipt template shape without accepting signatures, validating signatures, persisting authorization, marking receipts used or starting providers;
- verify the signed receipt persistence prerequisites without accepting signatures, validating signatures, persisting authorization, marking receipts used or starting providers;
- generate the signed release authorization persistence template with future storage, idempotency and ledger fields without persisting authorization, marking receipts used, writing ledger events or starting providers;
- verify the authorization persistence prerequisites for storage, repository, external signature validation, append-only event writer, idempotency and atomic receipt-use writer without persisting authorization or writing ledger events;
- generate the authorization persistence writer contract template with service interface, required inputs, validations, forbidden behaviors and tests without creating writer files or writing authorization;
- verify the persistence writer implementation scope, allowed files, first changes, constraints and gates without creating writer files or writing authorization;
- generate the scoped persistence writer implementation packet with tasks, allowed files, non-goals, acceptance criteria, gates and stop conditions without creating writer files;
- persist signed executor release authorizations through the scoped writer service only when called by a future release path, using idempotency, expiry checks, external signature validation report hash, database transaction and append-only ledger event while never starting providers or dispatching work;
- inspect persisted executor release authorization state, selected receipt hash, expiry, pending release status and storage readiness without marking receipts used, writing ledger events, starting providers or dispatching work;
- generate the atomic dispatch receipt-use writer contract and preflight so a future executor can mark one signed dispatch receipt used exactly once before provider start, without marking receipts used yet;
- expose continuation commands for the next AI session;
- show which Paperclip-style control-plane primitives are absorbed;
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
