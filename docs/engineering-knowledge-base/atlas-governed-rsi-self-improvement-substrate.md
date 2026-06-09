---
id: atlas-governed-rsi-self-improvement-substrate
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Governed RSI — Self-Improvement of the Loop's Own Machinery
slug: atlas-governed-rsi-self-improvement-substrate
status: future
implementation_state: future_spec_no_runtime_yet
category: agentic-engineering
priority: 96
summary: >
  Canonical design for GOVERNED Recursive Self-Improvement (RSI): letting the
  autonomous-evolution loop improve its OWN machinery (gates, planners, judges,
  ledgers, the loop itself) WITHOUT ever weakening a sacred gate and WITHOUT ever
  auto-canonizing. Safety is built FIRST: an Immutable Invariant Registry, a
  proposal-only + human-gated promotion path, and a proof that the RSI path cannot
  weaken an invariant nor auto-promote. Only then is capability added:
  ComponentValueLedger (value-per-token per component), SelfTargetSelector
  (which of its own components to improve next), a self-improvement proposal
  pipeline that REUSES the Pilar 2 frontier armor + curation inbox, and a meta
  measured-or-reverted closure (a merged self-improvement that did NOT raise the
  target component's value-per-token is git-reverted). Composes with what is on
  main; introduces NO new provider, NO new OS, NO new authority to merge or canonize.
tags: [atlas-ai, software-company, rsi, self-improvement, invariant-registry, measured-or-reverted, proposal-only, provider-proof, no-scaffold]
capabilities: [immutable_invariant_registry, governed_rsi_proposal_pipeline, component_value_ledger, meta_measured_or_reverted, self_target_selection]
decisions:
  - Build-Safety BEFORE Build-RSI. The Immutable Invariant Registry, proposal-only gating and the cannot-weaken proof ship and stay green BEFORE any self-targeting capability is wired.
  - The RSI path is PROPOSAL-ONLY and HUMAN-GATED. It can never auto-apply a change to its own machinery, never merge, never canonize, never call an operator-promotion receipt builder.
  - Any self-improvement diff that touches OR weakens a sacred gate is AUTO-REJECTED by the Invariant Registry before it can reach the curation inbox. The registry protects itself (self-referential immutability).
  - RSI reuses the existing Pilar 2 frontier armor chain (I1..I9) and the curation inbox; it does NOT fork a parallel generator, gate stack or promotion authority.
  - Meta measured-or-reverted is MANDATORY: a merged self-improvement whose target component did not provably raise value-per-token is git-reverted (never git reset --hard), mirroring FoundryEvolutionOutcomeMaterializerService.
  - Self-improvement targeting consumes ONLY the append-only ComponentValueLedger; it never invents a component, never fabricates a value, never asks a provider to rank components.
maintenance:
  - Update BEFORE adding a new sacred invariant, changing the registry's immutability proof, or changing the meta measured-or-reverted contract.
  - Block when an agent tries to (a) make the RSI path write canon/merge, (b) add an eligibility/override path around the registry, (c) let a self-improvement diff edit the registry or a sacred gate, or (d) ship Build-RSI before Build-Safety is green.
risk_level: high
owner: agentic_engineering_os/dev_forge
authority_class: planner
graph_id: atlas-governed-rsi-self-improvement-substrate
graph_title: Atlas Governed RSI Substrate
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-frontier-evolution-foundry
graph_status: future
graph_source: repo
related_paths:
  - docs/engineering-knowledge-base/atlas-frontier-evolution-foundry.md
  - docs/engineering-knowledge-base/atlas-earned-autonomy-adversarial-immune-system.md
  - app/Services/Ai/Foundry/Rsi/RsiInvariantGuardService.php
repo_paths:
  - app/Services/Ai/Foundry/Rsi
  - app/Services/Ai/Rsi
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Rsi
  - tests/Unit/Ai/Foundry/Rsi
  - tests/Unit/Ai/Rsi
allowed_changes:
  - Refine RSI invariant registry, proposal-only routing, component value measurement and measured-or-reverted closure.
  - Update evidence links when RSI service paths or proof tests move.
forbidden_changes:
  - Do not give RSI merge, canonization or provider authority.
  - Do not weaken sacred gates, the invariant registry, provider-proof or measured-or-reverted.
  - Do not start Build-RSI capability before the cannot-weaken proof is green.
depends_on:
  - atlas-frontier-evolution-foundry
  - atlas-software-company-stewardship-stack
flows_to:
  - atlas-earned-autonomy-adversarial-immune-system
unlocks:
  - governed_self_improvement_proposals
governs:
  - rsi_invariant_registry
  - rsi_invariant_guard
  - rsi_component_value_ledger
evidence:
  - app/Services/Ai/Foundry/Rsi/RsiInvariantGuardService.php
  - app/Services/Ai/Foundry/Rsi/ImmutableInvariantRegistryService.php
  - tests/Unit/Ai/Foundry/Rsi/RsiCannotWeakenInvariantTest.php
required_tests:
  - php artisan test tests/Unit/Ai/Foundry/Rsi/RsiCannotWeakenInvariantTest.php tests/Unit/Ai/Rsi
requires_evidence: true
next_actions:
  - Keep proposal-only and human-gated promotion as the only allowed RSI path.
  - Re-run the RSI proof suite before changing registry, guard, value ledger or outcome materializer behavior.
---

# Atlas Governed RSI — Self-Improvement of the Loop's Own Machinery

> RSI is the most dangerous capability the loop can have: it lets the system
> change the very code that decides what is safe. This document is therefore
> ordered SAFETY-FIRST. The Immutable Invariant Registry, the proposal-only +
> human-gated promotion path, and the proof that RSI cannot weaken an invariant
> or auto-canonize are built and proven GREEN **before** any self-targeting
> capability exists. Build-Safety precedes Build-RSI. This ordering is a hard
> rule, not a preference.

## Resumo

Governed RSI defines how the loop may propose improvements to its own machinery while
remaining proposal-only, human-gated and measured-or-reverted.

## Papel no Atlas

It is the safety substrate for recursive self-improvement: invariant registry first,
guard first, curation inbox second, and no new merge/canon/provider authority.

## Onde Se Encaixa

It composes the Frontier Evolution Foundry, the stewardship loop, the invariant guard
services and the RSI value/outcome services without creating a parallel runtime.

## Contratos

The immutable invariant registry, fail-closed RSI guard, proposal-only inbox routing,
component value ledger and meta measured-or-reverted closure are the contracts.

## Fluxo

Target selection -> self-improvement proposal -> invariant guard -> existing frontier
armor -> curation inbox -> human owner-flow -> measured-or-reverted outcome.

## Regras para IA

Never treat RSI as permission to edit sacred gates, merge automatically, canonize
automatically or call a provider from the RSI machinery itself.

## Escopo de Implementacao

This is a future-governance substrate with real proof/service anchors; runtime
promotion remains gated by the explicit build order and human review.

## Dependencias

Depends on the Frontier Foundry armor, self-directed curation inbox, metric ledger,
foundry outcome materializer and operator-governed owner flow.

## Evidencias

Evidence lives in the RSI guard/registry services, the RSI value/outcome services and
the proof tests listed in frontmatter.

## Riscos

Main risk is self-modification escaping its safety case: weakening a sacred invariant,
fabricating value, or turning proposal-only review into hidden execution.

## Exemplos

A proposal that edits a sacred guard file is rejected before the inbox; a promoted
self-improvement that fails its post-merge value metric is reverted, not celebrated.

## Proximas Acoes

Keep the cannot-weaken proof green, then wire only reversible slices that preserve
proposal-only review and measured-or-reverted closure.

## 0. Scope, thesis and what RSI is NOT

**Thesis.** The loop today improves the *product* (the agentic engineering OS it
stewards) via the AFEF / Pilar 2 frontier pipeline. Governed RSI extends the
exact same governed motion to the loop's OWN machinery — the gates, planners,
judges, ledgers, selectors and the autonomous-evolution session itself — so the
system can compound on its own quality. It does this WITHOUT acquiring any new
power: RSI proposes, a human promotes, reality measures, and a regression is
reverted.

**RSI is NOT:**

- not a new provider, model, or OS;
- not an auto-applying optimizer — it never edits its own machinery without an
  explicit human promotion receipt;
- not a parallel gate stack — it reuses the on-main frontier armor (I1..I9) and
  the curation inbox;
- not a new authority to merge or canonize — it has strictly fewer powers than
  the product loop, never more;
- not allowed to touch a sacred gate. A diff that would weaken provider-proof,
  no-scaffold, measured-or-reverted, the adversarial proof panel, honest-stop,
  proposal-only gating, or the Invariant Registry itself is auto-rejected at the
  boundary.

**Composition (what is already on main, reused not duplicated):**

| On-main component | Path | RSI reuses it for |
| --- | --- | --- |
| `FrontierGenerationOrchestratorService` | `Foundry/Frontier/` | The proposal-only, double-config-guarded generation motion (RSI runs behind its OWN second flag on top). |
| Frontier armor gates `I1..I9` | `Foundry/Frontier/Armor/` | Evidence-bound, dedup, drift, decomposer, judge-panel, metric+rollback armor — applied unchanged to self-improvement proposals. |
| `FoundryEvolutionOutcomeMaterializerService` | `Foundry/Frontier/Outcome/` | The measured-or-reverted closing authority (`consolidate` only on real-green measure; else `git revert`). RSI's meta-closure mirrors it. |
| `FoundryCompoundingAuditService` | `Foundry/Frontier/Outcome/` | Read-only fold proving net capability gain per cycle. RSI's component audit mirrors it. |
| `FrontierMetricRollbackGate` | `Foundry/Frontier/Armor/` | Falsifiable `{operator, baseline, threshold}` + complete rollback contract. RSI proposals MUST carry one. |
| `SelfDirectedEvolutionCurationInboxService` | `SelfDirectedEvolution/` | Operator review/approve/veto, promotion routed to the canonical owner. RSI proposals land here, never auto-approved. |
| `AdversarialProofPanelService` | `AreaFocusLoop/` | Fail-closed pre-merge refutation panel. A self-improvement that touches loop machinery gets an EXTRA verifier (V5, below). |
| `MetricLedgerService` | `AreaFocusLoop/PlanExecution/` | Deterministic, real-command, no-provider outcome measurement. The ComponentValueLedger's measure seam mirrors its port shape. |
| `FoundryExhaustionRarityGateService` | `Foundry/` | I8 eligibility (measured exhaustion only). RSI is gated behind it too — RSI is a last resort, after product backlog is honestly exhausted. |

---

## PART A — BUILD-SAFETY (ships and stays green FIRST)

### A.1 The Immutable Invariant Registry

A single canonical, append-only-then-frozen list of SACRED INVARIANTS. It is the
spine of RSI safety: every self-improvement proposal is checked against it
BEFORE it can enter the curation inbox, and any proposal that touches or weakens
an invariant is auto-rejected.

**Service:** `App\Services\Ai\Rsi\ImmutableInvariantRegistryService`
**Schema:** `atlas.rsi.invariant_registry.v1`

The registry is a frozen `final` map. Each entry:

```
{
  invariant_id:        string,   // e.g. 'provider_proof', 'no_scaffold'
  title:               string,
  guards_files:        list<glob>,// canonical paths/globs whose weakening is forbidden
  forbidden_change:    string,    // human-readable description of the weakening
  protected_tokens:    list<string>, // code tokens whose REMOVAL/relaxation signals weakening
  severity:            'sacred',   // sacred entries can never be auto-touched
}
```

**The sacred set (initial, frozen):**

| invariant_id | guards (illustrative) | what RSI may never weaken |
| --- | --- | --- |
| `provider_proof` (SEC-001) | `Ap786OwnerFlowExecutor`, merge governor, provider-proof verifiers | the requirement that a real provider/owner-flow proof precedes merge; no direct-provider claim |
| `no_scaffold` | `FinalDeliveryQualityGateService`, `AdversarialProofPanelService::INCOMPLETENESS_MARKERS` | the ban on TODO/FIXME/placeholder/not-implemented in shipped product code |
| `measured_or_reverted` | `FoundryEvolutionOutcomeMaterializerService`, `MetricLedgerService` | `consolidate` only on real-green measure; revert via `git revert`, never `git reset --hard` |
| `adversarial_proof_panel` | `AdversarialProofPanelService` | the fail-closed pre-merge panel; never weaken default-skeptical verifiers |
| `honest_stop` | AP-806 honest-stop / anti-inertia path | the honest `backlog_exhausted` stop; never fabricate work to stay busy |
| `proposal_only_gating` | `FrontierGenerationOrchestratorService` double config guard | the two persistent config-backed guards; no eligibility/override path |
| `invariant_registry` | the registry service + this doc | the registry ITSELF — it is self-protecting |

**Self-protection (the critical property).** `invariant_registry` is itself a
sacred invariant whose `guards_files` include the registry service file. Any
self-improvement diff that edits the registry, adds an override path, or removes
an entry is auto-rejected. The registry can only be extended by a HUMAN editing
this canonical doc and the frozen map directly, outside the RSI path — never by
the loop.

The registry is `final`, has NO setter, NO config flag, NO injection seam that
can add/remove/relax an entry at runtime. Tests construct it as-is and assert the
sacred set; there is no "test override" that production could reach.

### A.2 The Invariant Guard (the rejection boundary)

**Service:** `App\Services\Ai\Rsi\RsiInvariantGuardService`
**Schema:** `atlas.rsi.invariant_guard_verdict.v1`

Pure, deterministic, no provider, no writes. Input: a self-improvement proposal
carrying its **declared touched files** and a **proposed diff** (unified). It
returns `admit:false` with a machine-readable `reject_reason` when ANY of:

- `touches_sacred_guard_file` — a touched path matches any sacred entry's
  `guards_files` glob (RSI may not edit a sacred gate's file at all);
- `weakens_protected_token` — the diff REMOVES or relaxes a `protected_token`
  occurrence inside a guarded file (e.g. deletes a `git revert` and inserts
  `git reset --hard`, deletes an `INCOMPLETENESS_MARKER`, removes a fail-closed
  `return false`);
- `touches_invariant_registry` — the diff touches the registry service or this
  doc;
- `adds_eligibility_override` — the diff introduces a new config key / arg / seam
  that could flip a gate guard (matches the `proposal_only_gating` protected
  tokens).

Fail-CLOSED: if the proposal does not declare its touched files, or the diff
cannot be parsed, the guard rejects (`undeclared_touch`). There is no "allow on
ambiguity" branch.

### A.3 Proposal-only + human-gated promotion (no new authority)

The RSI path's ONLY writes are exactly those the frontier orchestrator already
permits: (a) an in-memory curation-inbox projection, (b) an append-only
drop-reason JSONL, (c) an append-only prior-proposal JSONL. It NEVER writes
canon/code, NEVER merges, NEVER executes, NEVER auto-approves, NEVER calls a
promotion-receipt builder.

Promotion is HUMAN-GATED through the existing
`SelfDirectedEvolutionCurationInboxService`: an approved item is ROUTED to the
canonical owner (`agentic_engineering_os/dev_forge`) as a normal governed work
item — it enters the SAME owner-flow (provider-proof, no-scaffold, adversarial
proof panel, measured-or-reverted) as any product change. RSI gets no shortcut.

Double-flag activation (RSI is strictly more guarded than product frontier):

1. `atlas.software_company_stewardship.frontier_mode` (existing) must be on; AND
2. `atlas.rsi.self_improvement_mode` (NEW, default false, `env('ATLAS_RSI_MODE', false)`)
   — read ONLY from persistent config, no transient arg can flip it; AND
3. `FoundryExhaustionRarityGateService::decide()` must return `eligible`
   (measured product-backlog exhaustion) — RSI is a LAST RESORT, never a
   parallel always-on track.

### A.4 The cannot-weaken / cannot-auto-canonize PROOF (the deliverable gate)

Before any Build-RSI capability is wired, an END-TO-END test
(`RsiCannotWeakenInvariantTest`) MUST be green, driving the REAL guard +
orchestrator path with deterministic fake seams (no provider spend):

- **P1 cannot weaken** — feed the pipeline a self-improvement proposal whose diff
  swaps `git revert` for `git reset --hard` inside the materializer; assert it is
  rejected with `weakens_protected_token` and NEVER reaches the inbox.
- **P2 cannot edit the registry** — feed a proposal whose diff edits
  `ImmutableInvariantRegistryService`; assert `touches_invariant_registry`
  rejection.
- **P3 cannot add an override** — feed a proposal adding a `--force-eligible`
  arg around a gate guard; assert `adds_eligibility_override` rejection.
- **P4 cannot auto-canonize** — drive a self-improvement proposal that survives
  every armor gate; assert the terminal state is `pending_operator_review` in the
  inbox, that NO canon/code file was written, NO merge occurred, and NO
  promotion-receipt builder was invoked (spy the seams).
- **P5 fail-closed** — feed an undeclared-touch / unparseable-diff proposal;
  assert rejection, never admission.

Only when P1..P5 are green on main does Part B begin.

---

## PART B — BUILD-RSI (capability, built on top of green safety)

### B.1 ComponentValueLedger — value-per-token per component

**Service:** `App\Services\Ai\Rsi\ComponentValueLedgerService`
**Schema:** `atlas.rsi.component_value_ledger.v1` (append-only JSONL)

Records, per loop component (e.g. `frontier_judge_panel`, `fleet_planner`,
`metric_ledger`, `adversarial_proof_panel`), a deterministic **value-per-token**
read derived from REAL existing signals only — never invented:

```
{
  component_id:     string,
  window_id:        string,         // cycle window the read covers
  value_signal:     float,          // from GroundTruthValueAdapter (real, below)
  token_cost:       float,          // real provider/token spend attributed to the component
  value_per_token:  float,          // value_signal / max(token_cost, epsilon)
  source_refs:      list<string>,   // ledger lines this fold consumed (provenance)
  measured_at:      iso8601,
  hash:             sha256,         // MissionCanonicalHash over canonical counts
}
```

Strictly a read-model fold (mirrors `FoundryCompoundingAuditService`): ZERO
writes outside its own JSONL, ZERO provider, ZERO canonization. An absent signal
folds to a null/zeroed deterministic read (real-or-blocked: no data => no
invented value). Two folds of the same ledgers are byte-identical.

### B.2 GroundTruthValueAdapter — the real-signal boundary

**Service:** `App\Services\Ai\Rsi\GroundTruthValueAdapterService`
**Schema:** `atlas.rsi.ground_truth_value.v1`

The single, explicit boundary where component "value" comes from REALITY, not
opinion. It reads ONLY signals already produced by the live loop and recorded in
append-only ledgers:

- proven-vs-merged ratio from `FoundryCompoundingAuditService` (proven capability
  delta);
- revert rate from `evolution_outcomes.jsonl` (a component that causes reverts has
  negative value);
- metric-outcome-met rate from `MetricLedgerService` JSONL;
- pre-merge refutation rate from `AdversarialProofPanelService` verdicts;
- token/provider spend attributed per component from the existing performance
  ledger.

Live-signal boundary (explicit): the adapter NEVER asks a provider to score a
component. It folds existing numbers. If a signal is missing the value is null
(blocked), never fabricated. This is the ONLY place that maps raw loop telemetry
into a value scalar; everything downstream consumes the ledger, not raw signals.

### B.3 SelfTargetSelector — which component to improve next

**Service:** `App\Services\Ai\Rsi\SelfTargetSelectorService`
**Schema:** `atlas.rsi.self_target.v1`

Deterministic selection: read the latest `value_per_token` per component from the
ComponentValueLedger, exclude any component whose files intersect a sacred
invariant's `guards_files` (those are off-limits to RSI by construction), and
select the LOWEST value-per-token component with sufficient signal (>= window_n
reads) as the next self-improvement target. Insufficient signal =>
`no_eligible_target` (honest skip, never a guessed target). No provider; the
selector ranks numbers the ledger already holds.

### B.4 The self-improvement proposal pipeline (reuses Pilar 2 frontier)

**Service:** `App\Services\Ai\Rsi\RsiProposalPipelineService`

Orchestrates, behind the Part-A double flag + I8 eligibility:

1. `SelfTargetSelector` picks a target component (or honest skip);
2. the Pilar 2 frontier generator (via `FrontierGeneratorPort`, fake-seamed in
   tests) emits self-improvement proposals scoped to that component, each
   carrying a falsifiable `success_metric` whose `metric_id` is the target's
   `value_per_token` and a complete `rollback`;
3. **`RsiInvariantGuardService` runs FIRST** — any proposal touching/weakening a
   sacred gate is dropped at the boundary (Part A);
4. survivors run the UNCHANGED frontier armor chain `I1 -> I6 -> I3 -> I7 -> I9 -> I2`;
5. survivors of armor become curation-inbox items
   (`SelfDirectedEvolutionCurationInboxService`), status
   `pending_operator_review`. Nothing is written to canon/code, nothing merges.

The pipeline adds NO generator, NO gate, NO promotion authority — it inserts the
Invariant Guard ahead of the existing armor and routes to the existing inbox.

### B.5 Meta measured-or-reverted (the closing authority)

**Service:** `App\Services\Ai\Rsi\RsiOutcomeMaterializerService`
**Schema:** `atlas.rsi.evolution_outcome.v1` (mirrors the Foundry outcome)

After a human-promoted self-improvement has been MERGED through the normal
owner-flow (proven by a real `autonomous_loop_cycle_receipt.v1` with
`lifecycle_state===merged` and a non-empty `merge_hash`), this service decides
CONSOLIDATE vs REVERT using the meta-metric:

- re-read the target component's `value_per_token` from the ComponentValueLedger
  AFTER the merge (via the real measure seam, mirroring `MetricLedgerService`);
- `consolidate` ONLY when `post_value_per_token >= baseline_value_per_token + target_delta`
  on the SAME property the `FrontierMetricRollbackGate` falsified (I9 property
  binding — same `success_metric` block, never an inferred metric);
- otherwise `action='reverted'`, `refuted_by_reality=true`, `post_value`
  null-on-block (never fabricated), invoke `GitRevertPort` (`git revert --no-edit`,
  NEVER `git reset --hard`), transition the component's roadmap entry
  `implemented -> reverted`.

This is the meta-loop's teeth: **a self-improvement that did not provably raise
the target component's value-per-token is reverted.** A merge alone is
`implemented`; only a real-green meta-measure is `proven`.

---

## Schemas (canonical)

| Schema | Owner service | Kind |
| --- | --- | --- |
| `atlas.rsi.invariant_registry.v1` | ImmutableInvariantRegistryService | frozen map |
| `atlas.rsi.invariant_guard_verdict.v1` | RsiInvariantGuardService | verdict |
| `atlas.rsi.component_value_ledger.v1` | ComponentValueLedgerService | append-only JSONL |
| `atlas.rsi.ground_truth_value.v1` | GroundTruthValueAdapterService | read fold |
| `atlas.rsi.self_target.v1` | SelfTargetSelectorService | selection |
| `atlas.rsi.evolution_outcome.v1` | RsiOutcomeMaterializerService | append-only JSONL |
| `atlas.rsi.compounding_audit.v1` | (read-model fold, mirrors Foundry) | read fold |

All hashes via `MissionCanonicalHash` (sha256 over canonical fields) for
byte-identical replays. All shape validation via `FoundrySchemas::validateShape`.

## Governance

- **Build order is hard:** A.1 -> A.2 -> A.3 -> A.4 (proof green) -> B.1 -> B.2 ->
  B.3 -> B.4 -> B.5. Skipping into Part B before A.4 is green is forbidden.
- **Powers:** RSI has strictly fewer powers than the product loop. It can propose
  and measure; it can never merge, canonize, or promote without a human.
- **Registry edits** happen only by a human editing this doc + the frozen map,
  outside the RSI path.
- **Activation** requires both config flags + I8 eligibility; default off.

## Observability

- Append-only JSONL per ledger under `storage/`, one event per fold/cycle, each
  hash-stamped.
- Drop-reason JSONL from the Invariant Guard and the armor chain (why a
  self-improvement was rejected).
- A read-only `atlas.rsi.compounding_audit.v1` fold proving net component
  value-per-token gain across cycles (mirrors `FoundryCompoundingAuditService`);
  a run that improved 1 of 10 components shows `proven_delta=+1`, never +10.

## Live-provider / live-signal boundary (explicit)

- **Live provider** is touched ONLY when a human-promoted self-improvement runs
  through the NORMAL owner-flow (same as any product change). The RSI machinery
  itself (registry, guard, ledger, selector, pipeline orchestration, materializer
  decision) NEVER calls a provider.
- **Live signal** enters ONLY at `GroundTruthValueAdapterService`, which folds
  existing append-only ledgers into a value scalar. No provider scores a
  component. Missing signal => null/blocked, never fabricated.
- Tests drive the REAL guard/pipeline/materializer paths with deterministic fake
  seams (fake generator, fake measurer, fake git port) — zero provider spend.

## Reversible build order (each slice is a reversible vertical cut)

1. **A.1 Registry** — frozen sacred map + self-protection; tests assert the set.
2. **A.2 Guard** — rejection boundary; tests assert each reject_reason fails closed.
3. **A.3 Proposal-only wiring** — double flag + inbox routing; no write/merge.
4. **A.4 Cannot-weaken proof** — P1..P5 green E2E. (GATE: Part B blocked until green.)
5. **B.1 ComponentValueLedger** — read fold, append-only JSONL.
6. **B.2 GroundTruthValueAdapter** — real-signal boundary fold.
7. **B.3 SelfTargetSelector** — deterministic lowest-value target.
8. **B.4 Proposal pipeline** — guard-first + reused armor + inbox.
9. **B.5 Meta measured-or-reverted** — consolidate-or-revert closing authority.

Each slice is independently revertible: drop the slice's service + its wiring;
the loop falls back to its current product-only behavior with no orphan.

## Filtro de 5 perguntas (proposta técnica)

1. **Multiplicador composto?** Sim — o loop passa a compor sobre a própria
   qualidade (value-per-token por componente), não otimiza ponto isolado.
2. **Antifrágil?** Sim — quanto mais sinal real de revert/refutação, melhor o
   alvo escolhido; chaos vira dado de seleção.
3. **Aproxima execução fim-a-fim?** Sim — fecha o ciclo de auto-construção
   governada sem novo provider nem nova autoridade.
4. **Destrava substituir função?** Sim — automatiza a engenharia da própria
   máquina, sob veto humano.
5. **Soberania local-first?** Sim — tudo roda local, sem dependência externa
   nova; nenhum sinal sensível sai da máquina.
