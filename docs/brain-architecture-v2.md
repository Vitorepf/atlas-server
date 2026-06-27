# External Brain v2 — Architecture Map

This is the canonical map of the external-brain organs as of the 24h autonomous run (L1–L25). The brain is a flag-gated, pétreo-floored, payload-rich READ over scope state — it ORIGINATES candidate evolutions and EMITS the payload that a pasted-AI consumes via the worker prompt. Author≠judge: the brain authors specs; the gates vet; the brain never edits its own perception, priorizadores or audit batteries.

## Organ map

### Foundations (pre-L1)
- `AtlasBrainMasterSwitch` — `ATLAS_BRAIN_MASTER_ENABLED` env flag. Operator-only; brain CANNOT self-enable.
- `AtlasBrainScopeRegistry` — pétreo data registry mapping `scope` → `{slug, roots, docs_roots, meta_harness}`.
- `AtlasBrainDoneSetLedger` — append-only per-scope NDJSON; sticky dedup by target_path.
- `AtlasBrainScopeDryProbe` — the only STOP signal; never the model self-judging.
- `AtlasBrainEvolutionLevelClassifier` — classifies an objective as proxy / real evolution.
- `AtlasBrainEvolutionDocAuthor` — writes the journal section under `docs/loop-evolution-journal/{scope}.md`.
- `AtlasBrainSeedQualityGate` — promotes 3 inspector advisories (vague, not_runnable, proxy) to BLOCKING at the seed boundary.
- `AtlasBrainCycleProgressVerdict` — anti-Goodhart cycle-progress check.

### L1 — `AtlasBrainStructuralSignalDigest`
Reads the comprehension model and emits top-K `{orphans, clone_clusters, doc_stated_gaps}`. Surfaces multi-file leverage signals previously invisible to the brain.

### L2 — `AtlasBrainPortfolioRouter`
Maps the dominant signal class to a portfolio path (orphans→comprehension-deepening, clones→pattern-design, gaps→frontier-harvest). Priority: orphans > clones > gaps.

### L3 — `AtlasBrainGateAdversarialAuditor`
Frozen battery of 10 canonical attacks (empty/missing fields, bare dir, pétreo target, vague, not_runnable, coverage_mismatch, permanent_human_dependency). Reports inspector holes.

### L4 — `AtlasBrainFrontierSourceRegistry`
Per-scope append-only NDJSON of curated frontier candidates ({title, url, summary, source, captured_at}). NO network fetch — operator/cron appends; brain reads top-K newest-first.

### L5 — `AtlasBrainCompoundingDigest`
Tail-window summary of done-set: `{by_status, success_streak, top_actions}`. Counts only; no scalar.

### L6 — `AtlasBrainMetricSnapshot`
Declares 5 measurable targets with direction: `orphan_count` / `clone_cluster_count` / `doc_stated_gap_count` / `recent_refusal_count` (minimize); `recent_served_streak` (maximize).

### L7 — `AtlasBrainSpecSimulationTwin`
Runs N candidate specs through the LIVE inspector; ranks best-first (passes_clean > advisory_only > blocked); returns `winner` (or null).

### L8 — `AtlasBrainLeverageBrief`
Consolidates all signals into ONE `{action_hint, evidence, rationale}`. Rule cascade (first-match):
  -1. **gate_health.holes > 0** ⇒ `fix_gate_regression` (foundational; trumps everything)
   0. **perseveration_streak ≥ 3** (same prior action_hint) ⇒ `escalate_perseveration`
   1. **recent_refusal_count > 3** ⇒ `rotate_path`
   2. **drafted_candidates non-empty** ⇒ `use_drafted_candidate`
   3. **recommended_path** set ⇒ `use_routed_path`
   4. **success_streak ≥ 3** ⇒ `compound`
   5. **frontier_candidates non-empty** ⇒ `harvest_frontier`
   6. else ⇒ `originate_fresh`

### L9 — `AtlasBrainOrphanSpecDrafter`
Maps an `App\X\Y\Bar` FQCN → a complete, inspector-passing spec (target path + mirrored test path + runnable acceptance + tests_or_gates_result evidence). Fail-closed on path traversal / non-App\\ FQCN.

### L10 — drafted_candidates wired into payload (brain:next)
Top-3 orphans → drafter → filter null → `scope_signals.drafted_candidates[]`.

### L11–L17 — leverage_brief rule additions
L11: `use_drafted_candidate` rule.
L12: seed-gate adversarial auditor parallel to L3.
L13: worker prompt mentions `scope_signals` consumption.
L14: brief recorded to Reflexion stream as time series.
L15: prior_briefs surfaced on refused/abstain payload.
L16: prior_briefs symmetry (also on served).
L17: perseveration rule (active).

### L18 — Drafts + simulator composed
`AtlasBrainSpecSimulationTwin` runs over `drafted_candidates` → `scope_signals.recommended_draft` (winner task_packet_id).

### L19 — Worker prompt references `recommended_draft`.

### L20 — Adversarial battery +1 attack (`permanent_human_dependency`).

### L21 — `gate_health` in scope_signals
Runtime audit hole counts inside every brain:next payload.

### L22 — `fix_gate_regression` becomes top brief rule.

### L23 — `atlas:brain:state` command
Read-only snapshot: master switch, scope, done-set tail counts, reflection tail count, gate_health (L24), last_brief (L25).

## Payload shape (brain:next, scope_signal_digest_enabled=true)

```
status:           served | refused | abstain | already_done | dry | disabled | error
scope:            slug
journal:          docs path (served only)
packet:           {specs:{packets:[<task spec>]}}  (served only)
reason:           refusal reason  (refused/abstain only)
reflections:      [{kind, reflection}]  (refused/abstain; relevance-keyed)
prior_briefs:     [{kind, reflection}]  (every emit; newest leverage_brief notes)
scope_signals:
  orphans:                 list<FQCN>
  clone_clusters:          list<cluster_id>
  doc_stated_gaps:         list<gap text>
  k:                       digest top-K
  recommended_path:        portfolio path id  (router L2)
  recommended_path_reason: text
  signal_class:            orphans | clone_clusters | doc_stated_gaps
  frontier_candidates:     list<{title, url, summary, source, captured_at}>
  compounding:             {window, by_status, success_streak, top_actions}
  metrics:                 list<{id, value, direction, description}>
  gate_health:             {inspector_holes, seed_gate_holes}
  drafted_candidates:      list<spec>  (only when orphans present)
  recommended_draft:       task_packet_id  (simulator winner)
  leverage_brief:          {schema, action_hint, evidence, rationale}
```

## Pétreo floor

Every L1–L23 organ is in `AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS`. Operator-directed edits are allowed; the autonomous loop CANNOT edit any of: the inspector, the seed gate, the master switch, the scope registry, the comprehension model, the Reflexion stream, the causal effect gate, the structural signal digest, the portfolio router, the leverage brief, the adversarial auditors, the orphan spec drafter, the frontier source registry, the compounding/metric snapshots, the spec simulation twin, the state/next/seed/worker-prompt commands.

## Flag matrix

| Flag                              | Default | Effect when OFF                                  |
|-----------------------------------|---------|--------------------------------------------------|
| `ATLAS_BRAIN_MASTER_ENABLED`      | false   | brain:next + seed are byte-identical no-ops      |
| `atlas.brain.reflection_enabled`  | false   | Reflexion record/recall are no-ops               |
| `atlas.brain.causal_selector_enabled` | false | Pattern selector is byte-identical               |
| `atlas.brain.scope_signal_digest_enabled` | false | scope_signals key not emitted; payload byte-identical |

## What's deliberately NOT in this brain

- No network fetch (frontier source is pure-read).
- No provider call (no LLM in the brain itself; the BRAIN IS the pasted model).
- No auto-seed (drafter+simulator emit suggestions; brain still chooses; seed gate still vets).
- No self-modification of priorizadores, auditors, or perception (pétreo).

## Addendum (L26–L64): observability + recovery surface

The L1–L25 layer was the brain's PERCEPTION + DECISION engine. The L26–L64 wave added the OPERATOR SURFACE — read-only commands and recovery helpers that make the brain inspectable and self-repairing without enabling autonomous run.

### New commands
- `atlas:brain:state` — non-mutating snapshot (master switch, scope, done-set tail counts + ratio, reflection tail count, last_brief, frontier.count, gate_health {holes, attacks_tried}, paths {count, ids}, optional `--all` cohort summary, optional `--raw`).
- `atlas:brain:health-doctor` — first-aid checks emit `{severity, code, advice}` findings: `gate_regression` (critical), `master_switch_off` / `served_ratio_low` / `portfolio_paths_unexpected_count` / `portfolio_path_missing_executor` / `portfolio_canonical_paths_missing` (warn), `scope_signal_digest_dormant` / `reflection_empty` / `frontier_empty` (info). `--all` enumerates per-scope. `--raw` for log scrape.
- `atlas:brain:audit` — one-shot consolidated `{state, doctor, adversarial.{inspector,seed_gate}, gate_health_status:'airtight'|'regression', gate_health_total_holes}` for CI/dashboard.
- `atlas:brain:catalog` — dump `config('atlas.brain.paths')` with `--intent`/`--kind` filters + `--check` (exit 1 on portfolio drift).

### New organs
- `AtlasBrainPathCatalog` — single-source lookup over `config('atlas.brain.paths')` (`all`/`find`/`executorOrganFor`/`lensFor`/`byObjectiveKind`/`byIntent`). Pétreo.
- `AtlasBrainSpecRepairHints` — frozen `deficiency → concrete repair` table (16 keys). `repair()` returns hints + unknown bucket. Wired into `atlas:brain:seed`'s blocked emit. Pétreo.

### Brief enrichments
- `previous_action_hint` + `continuity ∈ {first|held|changed}` derived from prior_briefs[0].
- `rotate_path` rule rationale now cites `worst_refusal_streak` and `served_ratio_pct`.
- `use_routed_path` rule rationale now cites the path's `executor_organ` FQCN (delegated to the Path Catalog).
- New `worst_refusal_streak` + `served_ratio_pct` metrics in `AtlasBrainMetricSnapshot`.
- New `signal_strength` (0–3 axes count) in `AtlasBrainPortfolioRouter` output.

### Auditor battery growth
- Inspector battery now 11 attacks (added `permanent_human_dependency`, `test_evidence_without_test_in_allowed_files`).
- Seed-gate battery now 4 attacks (added `blind_orphan_wiring_proxy_promoted`).
- Both auditors expose `attacks_tried` alongside `holes` (anti-shrink contract).

## End-state goal

The 24h run delivered a brain that: (1) sees its scope's multi-file structural gaps, (2) routes by dominant signal, (3) drafts ready-to-seed candidates from orphans, (4) ranks them via the same gate they'll face, (5) recommends a single action via deterministic cascade with anti-perseveration + anti-regression safeguards, (6) records its own recommendation time series, (7) exposes a non-mutating snapshot for dashboards. All flag-gated OFF by default; turning the digest flag ON arms the rich payload without changing existing gates.
