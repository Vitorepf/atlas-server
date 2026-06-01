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
  - docs/engineering-knowledge-base/self-construction/agent-control-plane-contract-part-01.md
  - docs/engineering-knowledge-base/self-construction/agent-control-plane-contract-part-02.md
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
human_name: Atlas Self-Construction - Agent Control Plane Contract
canonical_name: Atlas Self-Construction - Agent Control Plane Contract
technical_name: atlas-self-construction-agent-control-plane-contract
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md
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
evidence_refs:
  - symbol: AtlasSelfConstructionReadinessService
  - command: atlas:ai:self-construction:shell-placeholder
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

## Regras Para IA

Agentes devem tratar este contrato como limite de seguranca: sem provider start, sem dispatch, sem ledger write e sem self-programming fora dos gates assinados.

## Escopo De Implementacao

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

## Anchors Da Cadeia Profunda

- expose automatic dispatch scheduler one-shot tick Codex real invoker post-start signed real invoker release gate as read-only signed release bridge.
- expose automatic dispatch scheduler one-shot tick Codex real invoker post-start implementation boundary gate as read-only implementation boundary bridge.
- expose automatic dispatch scheduler one-shot tick Codex real invoker post-start executor plan gate as read-only executor plan bridge.
- expose automatic dispatch scheduler one-shot tick Codex real invoker post-start executor fresh release gate as read-only fresh release bridge.
- expose automatic dispatch scheduler one-shot tick Codex real invoker post-start executor enablement gate as read-only executor enablement bridge.
- expose automatic dispatch scheduler one-shot tick Codex real invoker post-start supervised start activation gate as read-only supervised start activation bridge.
- expose automatic dispatch scheduler one-shot tick Codex real invoker post-start guarded process start executor gate as read-only guarded process start bridge.
- expose automatic dispatch scheduler one-shot tick Codex real invoker post-start final process start authorization gate as read-only final process start authorization bridge.
- expose automatic dispatch scheduler one-shot tick Codex real invoker post-start actual process start rehearsal gate as read-only process start rehearsal bridge.
- expose automatic dispatch scheduler one-shot tick Codex real invoker post-start process start envelope gate as read-only process start envelope bridge.
- expose automatic dispatch scheduler one-shot tick Codex real invoker post-start start execution gate as read-only start execution bridge.
- expose automatic dispatch scheduler one-shot tick Codex real invoker post-start process starter readiness gate as read-only process starter readiness bridge.
- expose automatic dispatch scheduler one-shot tick Codex real invoker post-start manual start executor receipt as read-only manual start receipt bridge.
- expose automatic dispatch scheduler one-shot tick Codex real invoker post-start operator start handoff as read-only operator handoff bridge.
- expose automatic dispatch scheduler one-shot tick Codex real invoker post-start receipt contract as read-only receipt contract bridge.
- expose automatic dispatch scheduler one-shot tick Codex real invoker post-start evidence receipt as read-only evidence receipt bridge.
- expose automatic dispatch scheduler one-shot tick Codex real invoker post-start evidence acceptance bridge as read-only evidence acceptance bridge.
- expose automatic dispatch scheduler one-shot tick Codex real invoker post-start liveness monitor as read-only liveness monitor bridge.
- expose automatic dispatch scheduler one-shot tick Codex real invoker post-start dispatch release gate as read-only dispatch release bridge.
- expose automatic dispatch scheduler one-shot tick Codex real invoker post-start signed dispatch authorization gate as read-only signed dispatch authorization bridge.
- expose automatic dispatch scheduler one-shot tick Codex real invoker post-start dispatch executor handoff as read-only dispatch executor handoff bridge.
- expose automatic dispatch scheduler one-shot tick Codex real invoker post-start dispatch receipt-use executor as read-only dispatch receipt-use bridge.
- expose automatic dispatch scheduler one-shot tick Codex real invoker post-start provider start driver gate as read-only provider start driver bridge.
- expose automatic dispatch scheduler one-shot tick Codex real invoker post-start adapter invocation boundary gate as read-only adapter invocation boundary bridge.
- expose automatic dispatch scheduler one-shot tick Codex real invoker post-start adapter execution guard gate as read-only adapter execution guard bridge.
- expose automatic dispatch scheduler one-shot tick Codex real invoker post-start provider execution contract gate as read-only provider execution contract bridge.
- expose automatic dispatch scheduler one-shot tick Codex real invoker post-start process start release gate as read-only process start release bridge.
- expose automatic dispatch scheduler one-shot tick Codex real invoker post-start supervised start executor gate as read-only supervised start executor bridge.
- expose automatic dispatch scheduler one-shot tick Codex real invoker post-start process spawn enablement gate as read-only process spawn enablement bridge.
- expose automatic dispatch scheduler one-shot tick Codex real invoker post-start final process spawn executor gate as read-only final process spawn executor bridge.
- expose automatic dispatch scheduler one-shot tick Codex real invoker post-start external process runtime gate as read-only external process runtime bridge.
- expose automatic dispatch scheduler one-shot tick Codex real invoker post-start process invocation authorization gate as read-only process invocation authorization bridge.
- expose automatic dispatch scheduler one-shot tick Codex real invoker post-start external process invoker dry-run gate as read-only external process invoker dry-run bridge.
- expose automatic dispatch scheduler one-shot tick Codex real invoker post-start real invoker release preflight gate as read-only real invoker release preflight bridge.

## Agent Control Plane Chain Integrity Certification v1

Schema: `atlas.self_construction.agent_control_plane_chain_integrity_certification.v1`.

This certification audits deep-chain anchors, command options, readiness methods, invoker classes, runtime-safety flags, documentation coverage and cycle integrity without authorizing provider start, dispatch, token spend, adapter execution, ledger writes or self-programming.

## Agent Control Plane Deterministic Chain Replay

Schema: `atlas.self_construction.agent_control_plane_deterministic_chain_replay.v1`.

The replay rebuilds the chain proof from canonical slices, edges, runtime-safety flags and documentation anchors in read-only mode. It exists to prove that chain integrity is reproducible without starting providers, spawning processes, spending tokens, writing ledger entries or mutating the next required slice.

## Detalhes Extraidos

Comandos, capacidades runtime, simuladores, filas, journals e superfícies de prontidão foram movidos para recortes filhos para manter este contrato legível no modal humano e na cartografia.

- `docs/engineering-knowledge-base/self-construction/agent-control-plane-contract-part-01.md` — Canonical command surface ate Agent Control Plane Certification Observatory v1.
- `docs/engineering-knowledge-base/self-construction/agent-control-plane-contract-part-02.md` — Agent Control Plane Runtime Pilot Simulator v1 ate Schema.
