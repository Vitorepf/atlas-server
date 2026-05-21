---
id: atlas-agent-control-plane-runtime-readiness-gap-matrix-v1
type: engineering_knowledge
title: Atlas Agent Control Plane Runtime Readiness Gap Matrix v1
status: active
category: audit
priority: 80
summary: Block-by-block matrix showing which Agent Control Plane capabilities have contract, certification, dry-run, runtime, persistence, UI and tests, and the next safe step for each.
tags:
  - atlas-ai
  - self-construction
  - agent-control-plane
  - audit
capabilities:
  - self_construction_atlas_agent_control_plane_runtime_readiness_gap_matrix_v1
  - runtime_readiness_audit
decisions:
  - The matrix is observational; it does not promote any row to runtime.
  - A row is runtime ONLY when a signed promotion gate, a passing replay diff and a real evidence event back it.
maintenance:
  - Replace this file with v2 when the chain advances past the current pointer.
related_paths:
  - docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md
  - docs/engineering-knowledge-base/self-construction/audits/atlas-self-construction-os-gap-audit-v1.md
  - docs/engineering-knowledge-base/self-construction/audits/atlas-self-construction-os-risk-register-v1.md
  - docs/engineering-knowledge-base/self-construction/audits/atlas-self-construction-os-open-questions-v1.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 520
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-agent-control-plane-runtime-readiness-gap-matrix-v1
graph_title: Atlas Agent Control Plane Runtime Readiness Gap Matrix v1
graph_world: atlas
graph_layer: gear
graph_kind: runbook
graph_parent: atlas-ai-self-construction-os
graph_status: active
graph_source: repo
human_name: Atlas Agent Control Plane Runtime Readiness Gap Matrix v1
canonical_name: Atlas Agent Control Plane Runtime Readiness Gap Matrix v1
technical_name: atlas-agent-control-plane-runtime-readiness-gap-matrix-v1
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/self-construction/audits/atlas-agent-control-plane-runtime-readiness-gap-matrix-v1.md
repo_paths:
  - docs/engineering-knowledge-base/self-construction/audits/atlas-agent-control-plane-runtime-readiness-gap-matrix-v1.md
allowed_changes:
  - Atualizar somente em uma nova rodada de auditoria read-only.
forbidden_changes:
  - Promover qualquer linha a Runtime sem evidencia verificavel + replay diff verde + promotion gate.
depends_on:
  - atlas-ai-documentation-operating-system
flows_to:
  - atlas-cartography
unlocks:
  - ai-safe-implementation-context
governs:
  - self-construction
evidence:
  - docs/engineering-knowledge-base/self-construction/audits/atlas-agent-control-plane-runtime-readiness-gap-matrix-v1.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
visual_tags:
  - gear
  - audit
  - self-construction
ai_entrypoints:
  - Use a matriz como check operacional antes de qualquer plan que envolva runtime.
ai_usage_notes:
  - Quando uma linha aparece como Y na coluna Runtime, exige link para evidencia signed.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - Promover linha Y na Runtime sem evidencia.
observability_signals:
  - replay_diff_status improved/regressed
next_actions:
  - Atualizar matriz quando uma slice avancar; nao antes.
---
# Atlas Agent Control Plane Runtime Readiness Gap Matrix v1

Cell legend:

- Y — surface exists today, observed via read-only command or canonical doc.
- N — surface does not exist.
- partial — declared with quartet/contract/preflight/implementation-packet/status but no real runtime/persistence/UI/tests at end-to-end level.

The "Runtime" column means: a real runtime mutation occurs in production paths under a signed receipt, with idempotency and rollback. Every Runtime cell is N or partial. The matrix is the canonical place to spot that.

## Matrix

| Block | Contract | Certification | Dry-run | Runtime | Persistence | UI | Tests | Risks | Next Step |
|---|---|---|---|---|---|---|---|---|---|
| Task Packet | Y | Y (chain integrity references packet shape) | Y (Task Packet Builder service) | N | partial (durable reservation packets exist; not yet a real signed-task-packet runtime) | N | partial (focused tests on builder shape) | scope drift, packet hash mismatch | freeze schema, then write real packet signer |
| Claim/Lease | Y | Y (chain integrity slice for receipt/lease) | Y (Claim Lease Simulator service) | N | partial (durable_reservation_ledger writes exist for AP reservations; competing-claim runtime not active) | N | partial | stale lease, double claim | row-locked claim runtime under signed receipt |
| Scope Lock | Y | Y | Y (Scope Lock Planner service) | N | N | N | partial | forbidden-file write, unknown-file write | filesystem-level scope guard before runtime |
| Evidence Ledger | Y | Y | Y (Evidence Ledger Dry Run service) | N (no automated writes) | partial (manual writers exist; automation disabled) | N | partial | ledger drift, missing receipts | guarded runtime invoker that writes ledger inside same transaction as state change |
| Continuation Summary | Y | Y | Y (Continuation Summary Builder) | N (no auto-resume) | N | N | partial | session amnesia, stale resume | persist summary, recover at session start |
| Work Product Manifest | Y | Y | Y (Work Product Manifest Planner) | N | N | N | partial | artifact loss, missing manifest | workspace scan + hash + register |
| Cost Import | Y | Y | Y (Cost Import Dry Run) | N | N (no automated cost writes) | N | partial | unbounded spend, double counting | provider billing reader + idempotency key |
| Multi-Agent Planning | Y | Y | Y (Multi-Agent Parallelism Planner) | N | N | N | partial | write conflict, deadlock | runtime orchestrator after lease + scope-lock runtime |
| Runtime Pilot Orchestrator | Y | Y | partial (quartet only) | N | N | N | partial | false green, premature dispatch | signed-task to single bounded run |
| Runtime Pilot Certification | Y | Y | partial (quartet only) | N | N | N | partial | certification of fake run | replay diff + mutation guard on real run |
| Real Dispatch | Y (post-start chain) | Y (chain integrity 34 slices) | Y (every slice exposes contract/preflight/packet/status) | N | partial (signed_pending receipts can be written; receipts not consumed by real dispatch) | N | partial (per-slice tests) | accidental provider call, unsigned dispatch | first signed dispatch authorization + receipt-use exactly once |
| Provider/Adapter Execution | Y | Y | Y (execution guard blocks adapter call) | N (`adapter_execution_runtime` in not_yet_runtime_capable) | N | N | partial | provider call without guard, budget bypass | route every provider call through execution guard + budget |
| Process Start | Y (operator-side manual start) | Y | Y (rehearsal, envelope, gate) | N (`process_started=false`) | partial (rehearsal/envelope metadata recorded) | N (no operator UI) | partial | uncontrolled spawn, lost PID | external operator start + attested evidence acceptance (current model) |
| Kill Switch | Y (declared in safety contract) | partial | partial | N | N | N | N (no end-to-end kill test) | dispatch cannot be revoked | revoke gate that flips runtime flags to false and writes ledger event |
| Rollback | Y (per-slice attestations require rollback hash) | partial | partial | N | N | N | partial | irreversible change, lost work | rollback executor under signed receipt |
| Budget Guard | Y (budget bounds in execution contract) | partial | partial | N | N | N | partial | unbounded token spend | hard ceiling + per-run watchdog |
| Work Product Collection | Y | Y | Y (Manifest Planner) | N (`automatic_work_product_collection_runtime` in not_yet_runtime_capable) | N | N | partial | artifact drop, missing hash | scan + hash + register at session close |
| End-to-End Runtime | Y (chain integrity covers 34 slices) | Y | Y (every slice ends in status projection) | N | partial (no end-to-end mutation) | N | partial | claim-to-completion gap | first signed pilot run with full evidence chain |
| UI Operator Surface | partial (atlas-desktop work in another corridor, not inspected here) | N | N | N | N | partial | N | operator blind to claim/lease/scope/kill | a panel that shows claim, lease, scope, evidence, kill switch, budget |
| Forge / SelfImprovement Integration | partial (declared in roadmap, not in current pointer) | N | N | N | N | N | N | activation collision, duplicate scope | integration AFTER first signed real dispatch lands |

## Notes

- The "Y" cells in Certification reflect the certification workbench (Chain Integrity, Replay, Snapshot Store, Replay Diff, Promotion Gate, Baseline, Scenario Simulator, Release Dossier, Mutation Guard, Observatory). They prove the chain SHAPE is honest, not that runtime exists.
- The "Y" cells in Dry-run reflect quartet surfaces (contract/preflight/implementation packet/service/status) that return read-only payloads. Dry-run is NOT runtime.
- Every "partial" must be read as "scaffolded but not end-to-end."
- The Runtime column intentionally has no Y rows in this audit window.

## Test Surface Snapshot

The repo contains focused tests per slice (visible in `tests/Feature/Ai/AtlasAiSelfConstruction*`), and new test files appeared during the audit window inside the other Claudes' corridor (certification baseline, coverage report, evidence query, fuzz harness, mutation guard, scenario corpus, scenario simulator, status batch, multi-snapshot comparison, release dossier exporter, release dossier). Those tests prove the quartet shape and certification workbench correctness, NOT real runtime.

## Current Pointer

- `current_next_required_slice`: `activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_receipt_contract`
- `next_safe_macro_batch` (from deterministic replay): `reentry_into_post_start_evidence_corridor`
- `not_yet_runtime_capable` (4): adapter_execution_runtime, automatic_cost_import_runtime, automatic_work_product_collection_runtime, automatic_dispatch_scheduler_codex_real_invoker_post_start_receipt_contract_runtime.

## Closing Note

If any row in the Runtime column flips to "Y" in a future revision, that revision MUST cite:

- the signed promotion gate id;
- the replay diff status (`improved` or `passed`);
- the release dossier hash;
- the mutation guard `guard_passed=true`;
- the first real evidence event id (ledger or external attestation).

Without all five, the row stays N.

## Resumo

Matriz observacional bloco-a-bloco do estado runtime do Agent Control Plane na data da auditoria.

## Papel no Atlas

E o mapa rapido de leitura para decidir se uma fatia esta segura para sair de read-only.

## Onde Se Encaixa

Companion do Gap Audit; consumido por Risk Register e Open Questions.

## Contratos

Reflete o estado das capabilities declaradas no `agent-control-plane-contract.md` na data da auditoria; nao reescreve nem promove nenhuma.

## Fluxo

Leitor escolhe um bloco -> le linha completa -> aplica regra dos 5 itens da Closing Note antes de promover.

## Regras para IA

Nunca renderizar a matriz com linhas Y em Runtime sem citar os 5 artefatos listados na Closing Note.

## Escopo de Implementacao

Mudancas em `docs/engineering-knowledge-base/self-construction/audits/`.

## Dependencias

Depende do Gap Audit companion e dos outputs read-only do agent-control-plane.

## Evidencias

Outputs sumarizados das commands em 2026-05-14; per-slice tests existentes no diretorio `tests/Feature/Ai`.

## Riscos

Risco: leitor tratar partial como runtime, ou Y de Certification como Y de Runtime. A Closing Note existe para impedir isso.

## Exemplos

Linha "Real Dispatch" mostra Contract=Y, Certification=Y, Dry-run=Y, Runtime=N: contrato e prova de chain integrity nao equivalem a dispatch real.

## Proximas Acoes

- Avancar uma linha por vez, com promotion gate verde.
- Nao tocar Forge/SelfImprovement integration antes do primeiro real dispatch.
