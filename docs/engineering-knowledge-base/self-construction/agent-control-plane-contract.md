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
