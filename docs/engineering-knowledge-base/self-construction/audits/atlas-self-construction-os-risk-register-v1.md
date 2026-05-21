---
id: atlas-self-construction-os-risk-register-v1
type: engineering_knowledge
title: Atlas Self-Construction OS Risk Register v1
status: active
category: audit
priority: 80
summary: Risk register listing the 15 named risks the Self-Construction OS faces today, with severity, likelihood, signal, mitigation, owner handoff and current status.
tags:
  - atlas-ai
  - self-construction
  - audit
  - risk
capabilities:
  - self_construction_atlas_self_construction_os_risk_register_v1
  - risk_register
decisions:
  - Every risk must declare a signal an operator can observe BEFORE damage.
  - Risks must be mitigated at design time, not after a first real dispatch.
maintenance:
  - Update when a risk fires, when a mitigation lands, or when a new risk is identified during a read-only audit.
related_paths:
  - docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md
  - docs/engineering-knowledge-base/self-construction/self-programming-safety-contract.md
  - docs/engineering-knowledge-base/self-construction/audits/atlas-self-construction-os-gap-audit-v1.md
  - docs/engineering-knowledge-base/self-construction/audits/atlas-agent-control-plane-runtime-readiness-gap-matrix-v1.md
  - docs/engineering-knowledge-base/self-construction/audits/atlas-self-construction-os-open-questions-v1.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 520
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-self-construction-os-risk-register-v1
graph_title: Atlas Self-Construction OS Risk Register v1
graph_world: atlas
graph_layer: gear
graph_kind: runbook
graph_parent: atlas-ai-self-construction-os
graph_status: active
graph_source: repo
human_name: Atlas Self-Construction OS Risk Register v1
canonical_name: Atlas Self-Construction OS Risk Register v1
technical_name: atlas-self-construction-os-risk-register-v1
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/self-construction/audits/atlas-self-construction-os-risk-register-v1.md
repo_paths:
  - docs/engineering-knowledge-base/self-construction/audits/atlas-self-construction-os-risk-register-v1.md
allowed_changes:
  - Atualizar quando um risco disparar, uma mitigacao aterrissar ou um novo risco for identificado em auditoria.
forbidden_changes:
  - Declarar risco mitigado sem evidencia ou sem citar o gate verde que prova a mitigacao.
depends_on:
  - atlas-ai-documentation-operating-system
flows_to:
  - atlas-cartography
unlocks:
  - ai-safe-implementation-context
governs:
  - self-construction
evidence:
  - docs/engineering-knowledge-base/self-construction/audits/atlas-self-construction-os-risk-register-v1.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
visual_tags:
  - gear
  - risk
  - self-construction
ai_entrypoints:
  - Antes de propor runtime, valide cada risco pelo signal listado.
ai_usage_notes:
  - Risk_id e estavel; severity/likelihood/status podem mudar.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - Promover slice com risco ativo e sem mitigacao.
observability_signals:
  - runtime_safety_all_false=true mantido em todas projecoes
next_actions:
  - Revisar status apos cada slice promovida.
---
# Atlas Self-Construction OS Risk Register v1

Severity legend: critical / high / medium / low.
Likelihood legend: high / medium / low.
Status legend: open / mitigated_by_design / mitigated_by_runtime / accepted / monitoring.

## SC-OS-R-001 — False Green Certification

- title: Certification workbench reports green while runtime is broken.
- severity: high
- likelihood: medium
- affected area: Chain Integrity Certification, Deterministic Replay, Promotion Gate, Baseline.
- signal: replay diff status flips from `unchanged` to `regressed` after a quartet was edited; or coverage report grade drops; or scenario simulator detection rate < 1.0.
- mitigation: keep Mutation Guard active around every macro-sprint; require Promotion Gate `passed` AND Replay Diff `improved|passed` AND Mutation Guard `guard_passed=true` together; never trust a single signal.
- owner/handoff: backend SelfConstruction Claude (current); human operator owns the final ack.
- status: mitigated_by_design.

## SC-OS-R-002 — Pointer Regression

- title: `current_next_required_slice` moves backward after a macro-sprint.
- severity: high
- likelihood: low
- affected area: Agent Control Plane projection, Chain Integrity, Replay Diff.
- signal: `current_pointer != expected_pointer` in replay status, or `pointer_regression` scenario fires in simulator.
- mitigation: Promotion Gate must `require_no_regressions=true`; Mutation Guard treats pointer change as `forbidden_mutation`.
- owner/handoff: backend SelfConstruction Claude.
- status: mitigated_by_design.

## SC-OS-R-003 — Accidental Provider Call

- title: An adapter or executor invokes a real provider (Codex / Claude / Gemini) outside the signed release chain.
- severity: critical
- likelihood: medium
- affected area: Provider Adapter Execution Guard, Codex provider execution driver, post-start gates.
- signal: any runtime flag in `runtime_safety` block flips to `true` without a signed release; ledger event count > 0 with no signed dispatch; provider session count increases without a claim.
- mitigation: every provider path is receipt-bound, workspace-bound, budget-bound; execution guard is the single tripwire; runtime flags MUST stay false in projection; tests assert blocks at every layer.
- owner/handoff: backend SelfConstruction Claude.
- status: mitigated_by_design.

## SC-OS-R-004 — Token Spend Without Budget

- title: Real token spend occurs without a hard ceiling.
- severity: critical
- likelihood: medium
- affected area: Cost Import policy, Codex provider execution contract, budget guard.
- signal: cost event count rises without a budget envelope; provider response stored without a cost record; spending exceeds receipt budget hash.
- mitigation: every provider start must declare `budget` and `runtime_bounds`; cost import runtime not yet active, so any spend MUST be human-approved; budget guard must hard-stop the orchestrator.
- owner/handoff: backend SelfConstruction Claude; human operator for budget approval.
- status: open (no budget guard runtime).

## SC-OS-R-005 — Adapter Execution Before Guard

- title: Adapter executes before `AgentProviderAdapterExecutionGuard` has cleared the path.
- severity: critical
- likelihood: low
- affected area: Provider Adapter Execution Guard, adapter invocation boundary.
- signal: adapter invocation metadata present but no matching guard evidence; `adapter_execution_allowed=true` in any payload.
- mitigation: invariants `adapter_execution_allowed=false` and `adapter_invocation_allowed=false` must remain false through every projection; tests assert the guard is invoked before any adapter call.
- owner/handoff: backend SelfConstruction Claude.
- status: mitigated_by_design.

## SC-OS-R-006 — Dispatch Before Signed Receipt

- title: A dispatch executes against a `signed_pending` receipt that was never countersigned by a human.
- severity: critical
- likelihood: low
- affected area: dispatch executor, receipt-use writer, post-start signed dispatch authorization gate.
- signal: `dispatch_allowed=true` without `signed_real_invoker_release` evidence; `dispatch_receipts` count of `used` > 0 without matching human signature receipts.
- mitigation: the chain forces a signed gate before any dispatch executor handoff; receipt-use writer is atomic and idempotent by receipt hash.
- owner/handoff: backend SelfConstruction Claude; human operator for first signed dispatch.
- status: mitigated_by_design.

## SC-OS-R-007 — Process Start Before Final Authorization

- title: Atlas starts a provider process before the final-start authorization receipt exists.
- severity: critical
- likelihood: low
- affected area: post-start guarded process start executor, post-start final process start authorization gate.
- signal: `process_started=true` or `external_process_started=true` or `atlas_process_spawned=true` in any projection without final authorization evidence.
- mitigation: the contract states Atlas DOES NOT spawn — the operator does. Post-start evidence acceptance bridge requires attested external evidence; no path in projection turns the spawn flags true.
- owner/handoff: human operator (manual start); backend SelfConstruction Claude enforces invariants.
- status: mitigated_by_design.

## SC-OS-R-008 — Multi-Agent Write Conflict

- title: Two AI sessions write to overlapping files because scope locks are advisory, not enforced.
- severity: high
- likelihood: high
- affected area: Scope Lock Planner, Forge Workspace, durable reservation.
- signal: same path appears in two parallel claims; git status shows simultaneous edits in different corridors; merge conflicts on rebase.
- mitigation: today the mitigation is procedural — different Claudes work in different corridors (this audit pack confines itself to `audits/`). Real mitigation requires filesystem-level scope lock runtime + lease semantics.
- owner/handoff: human operator (procedural); backend SelfConstruction Claude for runtime.
- status: open.

## SC-OS-R-009 — Stale Lease

- title: A claim/lease outlives its session and blocks new work.
- severity: medium
- likelihood: high
- affected area: durable_reservation ledger, claim lease simulator, wakeup claim.
- signal: claim age > configured ttl; heartbeat absent; wakeup item stuck in `claimed`.
- mitigation: lease lifecycle contract declares expiry; runtime lease must reap stale entries on tick; today only simulator-level.
- owner/handoff: backend SelfConstruction Claude.
- status: open (runtime reaper missing).

## SC-OS-R-010 — Missing Continuation Summary

- title: A new session starts without recovering the prior session's continuation summary.
- severity: medium
- likelihood: medium
- affected area: Continuation Summary Builder, session bootstrap.
- signal: session bootstrap returns empty summary while prior reservation is `in_progress`.
- mitigation: continuation summary must be persisted at every step and recovered at session start; today builder is read-only.
- owner/handoff: backend SelfConstruction Claude.
- status: open.

## SC-OS-R-011 — Evidence Ledger Drift

- title: Evidence Ledger drifts from the state it claims to attest (ledger event without state change, or state change without ledger event).
- severity: high
- likelihood: medium
- affected area: Evidence Ledger Dry Run, signed dispatch receipt writer, post-start evidence receipt writer.
- signal: ledger event count vs. claim count vs. receipt count diverge; replay diff reports mismatch in `evidence_index`.
- mitigation: every state change is wrapped in a transaction that also writes the ledger event; today dry-run only. Mutation Guard treats `ledger_event_count_changed` as `forbidden_mutation` outside the allowed path.
- owner/handoff: backend SelfConstruction Claude.
- status: open (no runtime ledger writes yet).

## SC-OS-R-012 — Work Product Loss

- title: Artifacts produced by an agent run are lost because no automated collector exists.
- severity: medium
- likelihood: high
- affected area: Work Product Manifest Planner, workspace scan.
- signal: completed_runs > 0 but persistent_work_products = 0; manifest does not contain artifacts seen in workspace.
- mitigation: workspace scan + hash + register at session close; today planner is read-only and counters confirm 0 work products today.
- owner/handoff: backend SelfConstruction Claude.
- status: open.

## SC-OS-R-013 — Cost Import Mismatch

- title: Imported cost events do not match real provider billing.
- severity: high
- likelihood: medium
- affected area: Cost Import Dry Run, provider billing reader.
- signal: cost event amount diverges from provider invoice; double-counted events; cost event without dispatch receipt.
- mitigation: idempotency key per provider event; reconciliation report; today dry-run only.
- owner/handoff: backend SelfConstruction Claude; human operator for reconciliation.
- status: open.

## SC-OS-R-014 — Cross-Claude File Conflict

- title: Concurrent Claude sessions edit the same file in different corridors.
- severity: high
- likelihood: high
- affected area: filesystem, git working tree, this audit pack.
- signal: git status shows simultaneous modifications by two corridors; rebase conflict on merge.
- mitigation: corridor discipline — each Claude declares its allowed paths up front; this audit pack writes ONLY inside `docs/engineering-knowledge-base/self-construction/audits/`; other Claudes are confined to their corridors per intake.
- owner/handoff: human operator.
- status: mitigated_by_procedure (this audit run); open at the runtime level.

## SC-OS-R-015 — Self-Programming Premature Activation

- title: Self-programming is enabled before docs/spec/gates/rollback are real.
- severity: critical
- likelihood: low (today), high (if anyone shortcuts the safety contract).
- affected area: self-programming-safety-contract, autonomy shrink rule, forbidden mutations list.
- signal: `self_programming_allowed=true` appears in any projection without the required preconditions; receipt scope omits rollback_strategy; allowed_files list includes high-risk surfaces (provider/model policy, auth, memory).
- mitigation: contract requires every precondition; autonomy shrink rule applies when context is weak; Risk Register and Open Questions companions must be consulted before any promotion.
- owner/handoff: human operator (final ack).
- status: mitigated_by_design.

## Cross-Cutting Observation

Every risk above has the same "ultimate signal": at least one `runtime_safety` flag flips to `true` without the matching signed-release evidence chain. That single observable is the canonical alarm. Operators should bind that alarm to a UI strip in the future Atlas Desktop surface; today, it is monitored through projection JSON only.

## Resumo

Lista canonica de 15 riscos do Self-Construction OS na data 2026-05-14 com severity, likelihood, signal, mitigacao, owner e status.

## Papel no Atlas

E a fonte unica para discutir runtime sem cair em otimismo cego.

## Onde Se Encaixa

Companion do Gap Audit e da Matrix; consumido pelas Open Questions.

## Contratos

Nao altera contratos; aponta para `agent-control-plane-contract.md` e `self-programming-safety-contract.md` como fontes.

## Fluxo

Operador planeja runtime -> consulta Risk Register -> exige mitigacao + signal + ownership antes de promover.

## Regras para IA

Nunca declarar um risco mitigated_by_runtime sem citar o gate verde + replay diff + receipt assinado.

## Escopo de Implementacao

Mudancas em `docs/engineering-knowledge-base/self-construction/audits/`.

## Dependencias

Depende dos contratos do Self-Construction OS e do output read-only do agent-control-plane.

## Evidencias

Outputs sumarizados das commands em 2026-05-14; auditoria nao escreveu ledger nem snapshots.

## Riscos

Meta-risco: este registro envelhecer sem ser atualizado quando uma slice avancar.

## Exemplos

Risco SC-OS-R-003 (accidental provider call) tem signal claro: qualquer flag de runtime_safety vira true sem release assinado.

## Proximas Acoes

- Revisar status apos cada slice promovida.
- Atualizar mitigations quando a runtime correspondente for entregue.
