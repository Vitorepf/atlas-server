---
id: atlas-evolution-loop-runtime
type: engineering_knowledge
title: Atlas Evolution Loop Runtime Engine
status: active
category: autonomous-evolution
priority: 99
summary: The proven AtlasEvolution* runtime — the autonomous, propose-only "third product" (distinct from Dev, Forge, AND from AAEL the portfolio governor). A scenario explorer drives a generator (a provider, or pure code) to produce candidates; a FROZEN JUDGE re-scores each against a frozen acceptance contract; the best is certified-for-review and NEVER merged. A durable campaign supervisor runs it for a wall-clock budget with crash recovery, kill/pause, and a 3-layer never-merge invariant. Any AI can instantiate the loop for a NEW purpose by providing a base_workspace + an acceptance contract.
tags:
  - atlas-ai
  - evolution-loop
  - autonomous-evolution
  - propose-only
  - frozen-judge
  - anti-fake
capabilities:
  - autonomous_scenario_exploration
  - frozen_judge_acceptance
  - durable_campaign_runtime
  - propose_only_certification
decisions:
  - The loop is a distinct third product (autonomous, propose-only) — NOT Dev, NOT Forge, and NOT AAEL (the portfolio governor, atlas:aael).
  - The frozen judge is the single source of "did this honestly improve" — the loop may never edit the judge, the tests/harness, or the metric.
  - Provider-agnostic by construction; the default driver invokes the provider directly on the isolated workspace via the Forge router; a generator can also be pure code (e.g. the finance search).
  - Propose-only / never-merge is enforced at three layers (DB CHECK + trigger + Eloquent guard); the loop has no merge-to-main path.
  - Reuse AP-790 safety PATTERNS (lock lease, kill/pause, ledger, responsive sleep) but never its fixture core or merge-capable session.
maintenance:
  - Update when the acceptance contract, explorer, drivers, durable schema, or campaign supervisor change.
related_paths:
  - app/Services/Ai/AutonomousEvolution/AtlasEvolutionScenarioExplorer.php
  - app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php
  - app/Services/Ai/AutonomousEvolution/WorkspaceProviderLoopExecutionDriver.php
  - app/Services/Ai/AutonomousEvolution/TimeBoundedLoopExecutionDriver.php
  - app/Services/Ai/AutonomousEvolution/Campaign/AtlasLoopCampaignSupervisor.php
  - app/Services/Ai/AutonomousEvolution/Persistence/AtlasLoopStore.php
  - app/Console/Commands/AtlasLoopCampaignCommand.php
  - app/Console/Commands/AtlasLoopGrindTaskCommand.php
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
owner: atlas-ai
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-evolution-loop-runtime
graph_title: Atlas Evolution Loop Runtime Engine
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-ai-canonical-architecture-index
graph_status: active
graph_source: repo
human_name: Atlas Evolution Loop Runtime Engine
canonical_name: Atlas Evolution Loop Runtime Engine
technical_name: atlas-evolution-loop-runtime
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-evolution-loop-runtime.md
repo_paths:
  - docs/engineering-knowledge-base/atlas-evolution-loop-runtime.md
  - app/Services/Ai/AutonomousEvolution
  - app/Console/Commands/AtlasLoopCampaignCommand.php
allowed_changes:
  - Extend the acceptance contract or drivers when a new generator/domain needs it, keeping the guards intact.
  - Add new loop domains by supplying a base_workspace + acceptance (no engine fork).
forbidden_changes:
  - Never give the loop a merge-to-main path or remove a never-merge layer.
  - Never let a candidate edit the judge, the frozen paths, the metric, or the costs.
  - Never hardcode a provider; keep the driver provider-agnostic.
  - Never weaken the four Goodhart guards (tamper, scope, re-proof, diff-earned).
depends_on:
  - atlas-ai-self-construction-os
  - atlas-forge
flows_to:
  - atlas-control-plane
unlocks:
  - autonomous-propose-only-evolution
governs:
  - atlas-finance-strategy-evolution-loop
evidence:
  - tests/Feature/Loop
  - tests/Unit/Ai/AutonomousEvolution
evidence_refs:
  - symbol: AtlasEvolutionFrozenJudge
  - command: atlas:loop:campaign
required_tests:
  - "vendor/bin/phpunit tests/Unit/Ai/AutonomousEvolution tests/Feature/Loop"
requires_evidence: true
risk_level: high
line_limit: 600
next_actions:
  - Soak-test a multi-hour campaign; exercise non-hermes providers through the driver.
  - Finish framework-coupled (materialized) target grinding; then DB-stateful targets.
---

> ⚠️ **DEFINIÇÃO CANÔNICA DO LOOP — leia primeiro: `docs/loop-canonical-definition.md` + memórias `loop-*`.** Este doc descreve IMPLEMENTAÇÃO / ESTADO / HISTÓRICO; parte do framing aqui (refactor / ciclomática / landing-rate / best-of-N / proxy) é o **ALVO ERRADO**. O Loop = evolução autônoma **exponencial** de features REAIS do Atlas (entender escopo → projeção frontier + crítica cross-model → multi-agente → teste → wiring), **nunca faxina / proxy / one-shot**. Objetivo final: ser o ÚNICO que evolui o Atlas 24/7 sozinho.

# Atlas Evolution Loop Runtime Engine

> Status: **canonical · BUILT + PROVEN**. This documents the `AtlasEvolution*` engine + `atlas:loop:*` runtime that powers BOTH the engineering evolution loop AND the [Finance Strategy-Evolution Loop](domains/finance/strategy-evolution-loop-charter.md). Self-contained; if it conflicts with code, fix the doc.

## Resumo

The Atlas Evolution Loop is the autonomous, **propose-only** runtime that grinds ONE opportunity to
a certified-for-review result. A scenario explorer drives a generator to produce N candidates in
isolated workspaces; a **frozen judge** re-scores each against a frozen acceptance contract and
picks the best; a durable campaign supervisor runs this for a wall-clock budget with crash recovery,
kill/pause, and a **3-layer never-merge** invariant. It explores many solutions, keeps only what a
frozen, tamper-proof judge certifies, and **never merges**. Any AI can put it to work on a new
problem by supplying a `base_workspace` + an `acceptance` contract — no engine fork.

## Papel no Atlas

It is the **third autonomous product**, beside (not inside) the other two:
- **Atlas Dev** — interactive, human-in-command engineering (providers as an engine).
- **Atlas Forge** — heavy, human-supervised, multi-day obras.

The loop runs with no human for a budget, orchestrating providers/code as muscle, and accumulates
propose-only certified artifacts. It reduces human load without uncontrolled self-programming: the
human reviews the certified proposals; the loop never writes to main.

## Onde Se Encaixa

Critical disambiguation — **this engine is NOT AAEL.** `AAEL` /
`AtlasAutonomousEvolutionLoopService` / `atlas:aael` is the *portfolio governor* that decides WHICH
opportunities to evolve ([atlas-autonomous-evolution-loop](atlas-autonomous-evolution-loop.md)). This
`AtlasEvolution*` engine is the thing that GRINDS one opportunity to a propose-only result. **AAEL
chooses; this loop executes.** Do not conflate them or merge their docs.

```text
Self-Improvement / AAEL portfolio  → selects a target
→ THIS ENGINE grinds it (explore → frozen judge → certify) → propose-only artifact
→ human reviews / Control Plane exposes status
```

It consumes Self-Construction governance for Atlas-building-Atlas work and the Forge provider router
as muscle; it never re-implements Dev or Forge.

## Contratos

Everything the loop does for a target is defined by ONE frozen `acceptance` array — the single
interface you implement to put the loop to work:

```php
$acceptance = [
    'commands'         => ['<shell cmd that scores the candidate and exits 0/1>'],
    'allowed_globs'    => ['<glob>'],   // the ONLY paths a candidate may change
    'frozen_globs'     => ['<glob>'],   // paths a candidate may NEVER touch (tests/harness/metric)
    'metric_kind'      => 'gate' | 'minimize' | 'maximize',
    'metric_pattern'   => '/ATLAS_METRIC=([-0-9.]+)/',  // scraped from stdout for minimize/maximize
    'revert_recheck'   => true,   // anti-fake: reverting the diff MUST go RED
    'strict_untracked' => true,   // census ignores .gitignore/.git/info/exclude (no hidden siblings)
    'timeout_seconds'  => 240,
];
```

Key services + schemas:
- `AtlasEvolutionScenarioExplorer::explore($task, ?$scenarios)` → `{scenarios_explored,
  scenarios_accepted, winner, attempts[]}` (`atlas.evolution.scenario_exploration.v1`).
- `AtlasEvolutionFrozenJudge::score($workspace, $acceptance)` → verdict
  (`atlas.evolution.frozen_judge_verdict.v1`).

**The four Goodhart guards (in the judge, non-negotiable):**
1. **TAMPER** — any changed file matching `frozen_globs` ⇒ reject.
2. **SCOPE** — any changed file outside `allowed_globs` ⇒ reject (`strict_untracked` closes the
   `.git/info/exclude` hiding hole for opted-in flows).
3. **RE-PROOF** — the judge re-runs `commands` itself; it never trusts the generator's self-report.
4. **DIFF-EARNED** (anti-fake, `revert_recheck`) — after acceptance passes, the judge stashes the
   candidate's diff and re-runs; it MUST go RED. A green that survives the revert is fake ⇒ reject.
   Catches state-seeded fakes (a DB row, /tmp marker) because the stash is workspace-only.

## Fluxo

```text
discover a target → generate a RED task (acceptance the baseline fails)
→ EXPLORE N scenarios (a generator produces a candidate in each isolated workspace)
→ FROZEN JUDGE re-scores each candidate against the frozen acceptance
→ pick the winner (best metric; ties → smallest diff)
→ [optional domain post-gate consuming scenarios_explored as the true trial count N]
→ certify-for-review (propose-only, never merged) → loop-back → repeat
```

## Regras para IA

- The loop is **propose-only**. Never add a merge path; never remove a never-merge layer.
- Never edit the judge, frozen paths, the metric, or the costs from within a candidate.
- Keep it provider-agnostic; never hardcode a provider name in the engine.
- Never weaken the four guards. If you change the acceptance/judge, add a test AND an independent
  adversarial audit — your own tests can give false confidence (see the finance loop's critical lesson).
- The loop ORCHESTRATES Dev/Forge/providers as muscle; never re-implement them inside it.
- Do not conflate this engine with AAEL; do not give it AAEL's portfolio responsibilities.

## Como Instanciar (the reusable recipe)

Any AI can put the loop to work on a NEW problem without forking the engine:

1. **Define the candidate** — the file(s) a generator edits (→ `allowed_globs`).
2. **Build an inert base_workspace** — a dir where the candidate FAILS the acceptance (RED), so
   `revert_recheck` is meaningful (revert → RED → diff earned).
3. **Write the frozen acceptance** — a `commands` entry that scores the candidate and prints
   `ATLAS_METRIC=` (maximize/minimize) or just exits 0/1 (gate). Keep the scorer + data OUTSIDE the
   workspace; set `frozen_globs`/`allowed_globs`/`strict_untracked`.
4. **Run it** — `app(AtlasEvolutionScenarioExplorer::class)->explore($task, $scenarios)`, with
   `$task = ['objective'=>..., 'base_workspace'=>$dir, 'acceptance'=>$acceptance, 'provider'=>null,
   'allowed_files'=>[...], 'keep_workspaces'=>true]`.
5. **(Optional) domain post-gate** — consume `result['scenarios_explored']` as the true trial count
   (the finance loop deflates the Sharpe by it).
6. **Persist propose-only** — write a certified-for-review artifact with `merged_to_main:false`.

**Worked example:** the [Finance Strategy-Evolution Loop](domains/finance/strategy-evolution-loop-charter.md)
— candidate = `strategy.json`, scorer = `atlas:finance:strategy-backtest`, post-gate =
`TradingHonestyGate`. Its fast variant swaps the provider generator for pure-code param search,
same judge, same contract.

## Drivers (the generator, provider-agnostic)

The scenario generator is a `LoopExecutionDriver`. Default bind (`AppServiceProvider`):
`WorkspaceProviderLoopExecutionDriver` (invokes the provider directly on the isolated workspace via
the canonical `AtlasForgeProviderInvocationDriverRouter` — codex/cursor/hermes/gemini/minimax,
command-allowlisted, SEC-003 changed-files), wrapped by `TimeBoundedLoopExecutionDriver` (per-attempt
wall-clock kill). Resolution order: per-task override → `config('atlas.loop.default_provider')` → ""
(let Atlas Decide pick). A generator can also be pure code (the finance search bypasses providers).

## Runtime Durável (the campaign supervisor)

For unattended runs the engine is wrapped in a durable supervisor (`atlas:loop:campaign`):
- Tables `atlas_loop_campaigns / tasks / proposals / explorations / targets`; a task payload is a
  restart-safe SNAPSHOT, so a crash mid-task is resumable.
- `AtlasLoopStore` — atomic claim (`FOR UPDATE SKIP LOCKED` + folded lease reclaim), idempotent
  enqueue/certify, `rebuildInFlight` (resume).
- `AtlasLoopCampaignSupervisor` — refill → claim → grind → stream-persist → loop-back, under file
  lock / kill / pause / heartbeat + crash recovery; `elapsed_seconds` persisted so pause doesn't burn
  budget and a crash resumes against the REMAINING budget.
- **Never-merge at 3 layers**: pgsql CHECK + plpgsql trigger + Eloquent `saving()` guard — all force
  `merged_to_main=false` + `status=certified_for_review`.
- Guards: `TimeBoundedLoopExecutionDriver`, `AtlasLoopResourceGate` (disk floor + orphan reaper).
  Parallel pool exists but ships config-gated OFF (serial is the proven default).

## Comandos

```bash
# durable autonomous campaign (engineering targets)
php artisan atlas:loop:campaign --base-workspace="$(pwd)" --max-seconds=3600 --scenarios=6  # runs to budget/kill
php artisan atlas:loop:campaign:status --json
php artisan atlas:loop:campaign:stop                 # graceful kill-switch ( --pause to pause )
php artisan atlas:loop:grind-task --task-id=<id> --json   # one durable task, standalone
# atlas:loop:evolve runs ONE explore-N-scenarios pass without the durable campaign

# finance instance (see its own doc) — fast pure-code generator:
php artisan atlas:finance:strategy-search --candidates=600
```

## Escopo de Implementacao

- **Engineering**: self-contained `app/Support`-style targets ground end-to-end (real provider,
  certified-for-review proposals). Framework-coupled (materialized) targets are foundation-proven;
  full autonomous wiring + DB-stateful targets are the next obras.
- **Finance**: the [Strategy-Evolution Loop](domains/finance/strategy-evolution-loop-charter.md) —
  the first non-engineering transfer, running propose-only.

## Evidencias

`vendor/bin/phpunit tests/Unit/Ai/AutonomousEvolution tests/Feature/Loop` — explorer, frozen judge,
the anti-fake diff-earned guard (incl. external-state-seeded fake-green), campaign supervisor (full
cycle / budget stop / crash-resume), store claim/lease, never-merge at the Eloquent layer, guards,
and the worker pool.

## Dependencias

- `AtlasForgeProviderInvocationDriverRouter` (the provider muscle) + the Forge provider keys.
- Atlas Self-Construction OS governance for Atlas-building-Atlas work.
- The durable `atlas_loop_*` pgsql schema for unattended campaigns.
- A generator: a provider (default) OR pure code (e.g. the finance search) — no hard provider dependency.

## Exemplos

- **Engineering**: a self-contained `app/Support` target → RED acceptance → explore N via hermes →
  frozen-judge winner → certified-for-review proposal (`merged_to_main:false`), re-proved out-of-process.
- **Finance**: candidate `strategy.json`, scorer `atlas:finance:strategy-backtest`, post-gate
  `TradingHonestyGate` deflating by `scenarios_explored`. See the finance loop doc.

## Riscos

- Multi-hour soak not yet proven; only hermes exercised through the new driver (others = a config swap).
- Framework-coupled grinding is foundation-proven but not yet fully autonomous; DB-stateful targets
  are a further slice. Self-contained autonomous grinding is fully proven and shippable.
- Cost cap is inert until the exec layer reports per-attempt cost.

## Proximas Acoes

- Soak-test a multi-hour campaign; exercise non-hermes providers through the driver.
- Finish framework-coupled (materialized) target grinding; then DB-stateful targets.
- Keep the loop wired into the Atlas Control Plane read model.
