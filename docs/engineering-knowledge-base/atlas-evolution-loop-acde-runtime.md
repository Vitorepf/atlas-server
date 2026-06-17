---
id: atlas-evolution-loop-acde-runtime
type: engineering_knowledge
title: Atlas Evolution Loop — ACDE Runtime & Arbor-Graft
status: active
category: autonomous-evolution
priority: 98
summary: The CANONICAL owner map for the ACDE (Atlas Compounding Delivery Engine) runtime that grew on top of the AtlasEvolution* loop since 2026-06. Quality comes from the FLOW, not the LLM — the out-of-process FROZEN JUDGE + SEMANTIC IMPLEMENTATION CERTIFIER prove every win, the LLM is swappable fuel. Maps the eight families (discovery/supply, grind/cert, escalation conductor, obra large-work, auto-merge governance, the Arbor idea-tree graft, compounding/learning, governance flags) to their anchor files, and records the 2026-06-17 power-up (Arbor-graft armed, DI dead-wiring closed, contract-swap guard, obra day-2 verified). Child of atlas-evolution-loop-runtime; the loop is the engine that BUILDS Atlas, so this map is load-bearing for all downstream self-improvement.
tags:
  - atlas-ai
  - evolution-loop
  - acde
  - arbor-graft
  - frozen-judge
  - obra
  - compounding
capabilities:
  - frozen_judge_acceptance
  - semantic_implementation_certification
  - autonomous_escalation_conductor
  - obra_large_work_delivery
  - governed_auto_merge
  - idea_tree_compounding
decisions:
  - Quality is a property of the FLOW (deterministic out-of-process cert), not the model — the LLM is swappable fuel; prove the moat on the weakest engine (MiniMax) so a stronger engine is a clean multiplier.
  - The Arbor idea-tree (hypothesis tree, constraints-block, SELECT re-rank, insight-backprop, failure-supply) is ADVISORY — walled off from every cert/merge/trust class by AtlasLoopAdvisoryFirewallTest; it may never gate.
  - The ORIGINATION ceiling (greenfield decomposition origination + deep semantic correctness) is model-bound; closing it deterministically is Goodhart-forbidden. The EXTRACTION ceiling (flow multipliers) is what Atlas builds.
  - Every new lever ships flag-default-OFF + byte-identical-OFF; arm in .env after measuring, never by changing the config default.
  - Loop-internal files are pétreo (AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS) — the loop may never edit its own judge/harness/metric.
maintenance:
  - Update when an ACDE family gains a service, when a governance flag is armed, or when the honest ceiling table changes. Status must stay resolved-evidence — never round an effective grade up.
owner: atlas-ai
graph_id: atlas-evolution-loop-acde-runtime
graph_title: Atlas Evolution Loop — ACDE Runtime & Arbor-Graft
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-evolution-loop-runtime
graph_status: active
graph_source: repo
human_name: Atlas Evolution Loop — ACDE Runtime & Arbor-Graft
canonical_name: Atlas Evolution Loop — ACDE Runtime & Arbor-Graft
technical_name: atlas-evolution-loop-acde-runtime
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-evolution-loop-acde-runtime.md
related_paths:
  - app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php
  - app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php
  - app/Services/Ai/AutonomousEvolution/AtlasLoopSemanticImplementationCertifier.php
  - app/Services/Ai/AutonomousEvolution/AtlasLoopProposalPromotionGate.php
  - app/Services/Ai/AutonomousEvolution/AtlasLoopObraAutoMergeService.php
  - app/Services/Ai/AutonomousEvolution/AtlasLoopObraExecutionAdapter.php
  - app/Services/Ai/AutonomousEvolution/AtlasLoopAutonomousConductor.php
  - app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopQueueRefiller.php
  - app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopBackService.php
  - app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopHypothesisTreeProducer.php
  - app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopConstraintsBlockAssembler.php
  - app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopInsightBackpropService.php
  - app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopFailureHypothesisProducer.php
  - app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopIdeaTreeAccessor.php
  - app/Services/Ai/AutonomousEvolution/Campaign/AtlasLoopCampaignSupervisor.php
  - tests/Feature/Architecture/AtlasLoopAdvisoryFirewallTest.php
---

# Atlas Evolution Loop — ACDE Runtime & Arbor-Graft

> Child of [Atlas Evolution Loop Runtime](atlas-evolution-loop-runtime.md). That doc owns the original
> propose-only loop (explorer → frozen judge → never-merge). THIS doc owns the **ACDE** layer that grew on
> top of it since 2026-06: certified delivery, autonomous escalation, large-obra, governed auto-merge to
> main, and the Arbor idea-tree graft. Read both before extending the loop.

## 1. Thesis (why this engine exists)

ACDE = **Atlas Compounding Delivery Engine**. The bet: certified quality is a property of the **flow**, not
the model. The out-of-process **frozen judge** + **semantic implementation certifier** re-prove every claim
against a human-frozen contract, so the LLM underneath is swappable fuel. Prove the moat on the *weakest*
engine (MiniMax via Hermes) and a stronger engine becomes a clean multiplier (the N×M antifragile equation).

## 2. The honest ceiling (do not round)

- **EXTRACTION ceiling** — what the flow/structure can multiply (decomposition support, boundary-oracle,
  compounding, fan-out, outcome-ledger, cross-domain transfer, flow unification). **Buildable.**
- **ORIGINATION ceiling** — greenfield decomposition origination + deep semantic correctness judgement.
  **Model-bound.** Closing it deterministically is Goodhart-forbidden; a goal with no human-frozen fixture
  degrades to structural-only and correctness there stays model-bound. State this honestly, never fake it.

## 3. Family map (anchor files)

| Family | What it does | Anchor |
|---|---|---|
| Grind + cert | one task → scenarios → frozen judge → semantic cert → kept winners | `AtlasLoopTaskGrinder`, `AtlasEvolutionFrozenJudge`, `AtlasLoopSemanticImplementationCertifier` |
| Discovery / work-supply | targets → RED-verified tasks; results→sources self-feed (the #1 bottleneck) | `AtlasLoopQueueRefiller`, `AtlasLoopBackService` |
| Escalation conductor | a no-winner round escalates STRUCTURALLY (best_of_n→repair→decompose→escalate) | `AtlasLoopAutonomousConductor` |
| Obra (large work) | multi-node DAG executed in an isolated worktree; net-diff cert across nodes | `AtlasLoopObraExecutionAdapter` |
| Auto-merge governance | governed crossing to main; obra crossing with trust-ladder interlock | `AtlasLoopProposalPromotionGate`, `AtlasLoopObraAutoMergeService` |
| Arbor idea-tree graft | competing-sibling hypothesis tree + constraints-block + SELECT + insight-backprop + failure-supply (ADVISORY) | `AtlasLoopHypothesisTreeProducer`, `AtlasLoopConstraintsBlockAssembler`, `AtlasLoopIdeaTreeAccessor`, `AtlasLoopInsightBackpropService`, `AtlasLoopFailureHypothesisProducer` |
| Campaign runtime | durable 24h supervisor: crash recovery, kill/pause, keepalive, drift restart | `AtlasLoopCampaignSupervisor` |
| Advisory firewall | proves no cert/merge/trust class references any advisory tree field | `tests/Feature/Architecture/AtlasLoopAdvisoryFirewallTest.php` |

> This is the curated load-bearing set, not an exhaustive census. ~160 services shipped under
> `app/Services/Ai/AutonomousEvolution/**` since 2026-06-09; group new ones into the family above and add the
> keystone file here rather than listing every leaf.

## 4. The Arbor graft (idea-tree)

Arbor's substrate is a tree of competing siblings under a parent — distinct hypotheses for one goal, pruned
if they fail, harvested if they win, with a distilled lesson propagating up the path-to-root. Atlas's
discovery was FLAT (one target = one file). The graft adds: a hypothesis-tree producer (materializes the K
sampled readings as sibling nodes), a constraints-block assembler (pruned lessons + findings + tree shape
into the ideate prompt), a SELECT re-rank within a priority band, insight-backprop (a node's terminal lesson
folds up the tree), and failure-supply (a metric-miss fans out N orthogonal alternative directions). **All
ADVISORY** — `AtlasLoopAdvisoryFirewallTest` proves none of it is referenced by any gate. The tree columns
live on `atlas_loop_targets` (migration `2026_06_17_000400`).

## 5. Power-up record — 2026-06-17 (Waves 1-4)

- **Wave 1 (dead-wiring):** audit CLEAN. The `?Type=null`-autowired-but-not-bound bug class (a nullable ctor
  dep that silently disables a feature) was closed for `AtlasLoopQueueRefiller` (args 14-16: SELECT /
  constraints-block / tree-producer) and `AtlasLoopBackService` (tree trio) via explicit binds in
  `AppServiceProvider`. The grinder/obra-adapter/conductor are lazy-functional (`?? new` / `?? app()`), NOT
  dead — do not add churn binds to them.
- **Wave 2 (Arbor armed):** the graft flags were read in code but never declared in `config/atlas.php` (so
  `.env` was inert) — declared + the live DB migrated + the advisory flags armed.
- **Wave 3:** `comprehension_samples=2` (required for the divergence path to materialize ≥2 readings) +
  hub-first multi-file shaping; `red_reason_gate` already on.
- **Wave 4:** #8 contract-swap guard (`reprove()` asserts the persisted acceptance contract hashes to the
  grind-time fingerprint via `AtlasEvolutionFrozenJudge::acceptanceHash()`; armed after 150/150 live
  proposals hash-matched) + #7 cross-node consumer-cert default for ≥2-file obra + obra day-2 fixes verified
  (trust-ladder interlock + clean-tree precondition + exclusive merge-lock, frozen by
  `AtlasLoopObraAutoMergeServiceTest`).
- **Verification:** byte-identical-OFF for everything armed; live-Postgres smoke confirmed the advisory paths
  round-trip on the real schema; the affected test surface is green.

## 6. Governance flags (where the dials live)

All ACDE flags read `config('atlas.loop.*')` (declared in `config/atlas.php`, armed via `.env`,
default-OFF). Load-bearing live dials: `auto_merge_to_main` (`atlas.ai.loop.*`), `obra_auto_merge_enabled` +
`obra_auto_merge_require_trust`, `conductor_escalation_enabled`, `idea_tree_enabled`, `failure_supply_enabled`,
`reprove_hash_assert_enabled`, `obra_cross_node_cert_default`, `planning_enabled` (OFF — the obra planner is
dormant until fixtures are seeded). The compounding-recall arm (`atlas.semantic_memory.compounding_recall_*`)
surfaces promoted learnings into the live recall.

## 7. Hard rules for anyone extending the loop

1. Quality from the flow — never weaken the frozen judge / semantic cert; never edit the acceptance_hash
   composition (it invalidates every persisted hash).
2. Flag-default-OFF + byte-identical-OFF; arm in `.env` after measuring, never flip a config default.
3. ADVISORY stays advisory — keep `AtlasLoopAdvisoryFirewallTest` green.
4. Anti-refragmentation — reuse the existing table/service; do not rebuild a parallel one.
5. Pétreo — the loop never edits its own judge/harness/metric (`FORBIDDEN_SELF_TARGETS`).
6. Honest status only — resolved-evidence, never a rounded effective grade.
