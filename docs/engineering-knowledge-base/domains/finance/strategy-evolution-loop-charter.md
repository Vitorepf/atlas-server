---
id: atlas-finance-strategy-evolution-loop
type: engineering_knowledge
title: Atlas Finance Strategy-Evolution Loop
status: active
category: domains
priority: 95
summary: Canonical + operational guide for the finance/trading strategy-evolution loop — the Atlas Evolution Loop's first non-engineering transfer. It searches for a trading strategy on real history under an audited anti-overfit honesty gate (N-deflated out-of-sample Sharpe + PBO + governed holdout) and now runs as pre-registered research campaigns with null reports and champion quarantine. Propose-only, never-merge, no real money, win-rate forbidden.
tags:
  - atlas-ai
  - domains
  - finance
  - trading
  - evolution-loop
  - propose-only
  - anti-overfit
capabilities:
  - finance_strategy_evolution
  - honest_backtest
  - deflated_sharpe_gate
  - propose_only_search
decisions:
  - Propose-only / never-merge / no-real-money are HARD invariants; the loop never trades and has no broker path.
  - Win-rate is FORBIDDEN as an objective; the metric is N-deflated out-of-sample Sharpe after costs, gated by PBO and a sealed holdout.
  - The trial count N fed to the Deflated Sharpe is the TRUE number of candidates searched (anti-Goodhart); an analytic variance floor makes N always bite even when siblings cluster.
  - Strategy search now runs as scientific campaigns: each campaign owns its ledger, pre-registers budget/seed/costs/data hash/holdout, and ends in CERTIFIED or NULL_*.
  - A round-level pass only promotes a champion into quarantine; certification for review requires campaign-level N, a reserved confirmation holdout, 2x cost stress, neighborhood robustness, an independent engine gate, and cross-campaign rediscovery.
  - Strategy performance is scenario-specific: market, timeframe, family, and regime are recorded; never assume one strategy works for every asset/timeframe/regime.
  - Only one finance strategy-search loop may run at a time; additional starts fail on the global lock.
  - The fast in-process search (atlas:finance:strategy-search) is the workhorse for parameter optimization; the LLM-driven loop is for strategy-family ideation only.
  - Costs (fees/slippage) are FROZEN by the harness; a candidate strategy can never reduce them.
maintenance:
  - Update when the strategy family, honest-metric thresholds, engines, or data layer change.
related_paths:
  - app/Console/Commands/AtlasFinanceStrategySearchCommand.php
  - app/Console/Commands/AtlasFinanceStrategyBacktestCommand.php
  - app/Console/Commands/AtlasFinanceStrategyEvolveCommand.php
  - app/Console/Commands/AtlasFinanceStrategyLoopCommand.php
  - app/Services/Ai/Finance/StrategyLoop/MarketDataCache.php
  - app/Services/Ai/Finance/StrategyLoop/Strategy/TrendBreakoutStrategy.php
  - app/Services/Ai/Finance/StrategyLoop/Metrics/HonestMetrics.php
  - app/Services/Ai/Finance/StrategyLoop/TradingHonestyGate.php
  - app/Services/Ai/Finance/StrategyLoop/Campaign
  - tests/Unit/Ai/Finance/StrategyLoop
owner: atlas-ai
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-finance-strategy-evolution-loop
graph_title: Atlas Finance Strategy-Evolution Loop
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-ai-finance-domain
graph_status: active
graph_source: repo
human_name: Atlas Finance Strategy-Evolution Loop
canonical_name: Atlas Finance Strategy-Evolution Loop
technical_name: atlas-finance-strategy-evolution-loop
cartography_type: module
canonical_source: docs/engineering-knowledge-base/domains/finance/strategy-evolution-loop-charter.md
repo_paths:
  - docs/engineering-knowledge-base/domains/finance/strategy-evolution-loop-charter.md
  - app/Services/Ai/Finance/StrategyLoop
  - app/Console/Commands/AtlasFinanceStrategySearchCommand.php
allowed_changes:
  - Add markets, strategy families, or honest-metric refinements that keep the invariants.
  - Tighten (never loosen) the anti-overfit gate.
forbidden_changes:
  - Never wire live trading, broker keys, real money, or order execution.
  - Never use win-rate as a selection objective.
  - Never let a candidate edit the backtest harness, the market data, or the costs.
  - Never weaken the deflated-Sharpe variance floor, the holdout significance gate, or the sibling-diversity requirement.
depends_on:
  - atlas-evolution-loop-runtime
  - atlas-ai-finance-domain
flows_to:
  - atlas-control-plane
unlocks:
  - finance-strategy-evolution
governs:
  - finance-strategy-search
evidence:
  - tests/Unit/Ai/Finance/StrategyLoop
evidence_refs:
  - symbol: TradingHonestyGate
  - command: atlas:finance:strategy-search
required_tests:
  - "vendor/bin/phpunit tests/Unit/Ai/Finance/StrategyLoop"
requires_evidence: true
risk_level: high
line_limit: 700
next_actions:
  - Add a fully external freqtrade backend beside the independent replay backend.
  - Run a second independent campaign sequentially when a champion is promoted, so the cross-campaign gate can be satisfied without parallel loops.
  - Expand markets/timeframes sequentially, one focused campaign at a time.
---
# Atlas Finance Strategy-Evolution Loop

> Status: **canonical · BUILT + RUNNING** · Classification: **SENSITIVE** · Domain: `finance` · Flow: `finance.strategy_evolution`
> Engine: reuses the [Atlas Evolution Loop runtime](../../atlas-evolution-loop-runtime.md) — candidate = `strategy.json`, frozen judge = the backtest. Self-contained; if it conflicts with code, fix the doc.

## Resumo

The first transfer of the Atlas Evolution Loop out of engineering into **finance/trading**. It
autonomously searches for a profitable trading strategy by evolving a small parameter file and
scoring every candidate against **real market history** under a frozen, **anti-overfit honesty
harness**. Same loop that grinds code — the candidate is a strategy instead of a patch, the frozen
judge is a backtest instead of a test suite. **The honest output is usually "no candidate survived"
— that is the system working, not failing.** A certified strategy is rare and earned; it is a
*reviewable proposal*, never an order.

## Papel no Atlas

It proves that the Evolution Loop generalizes beyond engineering, and gives Atlas a governed way to
do quantitative strategy research without ever touching money. The human role is to review certified
proposals; the loop itself only researches and proposes. It lives inside the existing Finance domain
(`finance.strategy_evolution` flow) under that domain's review-only charter.

## Onde Se Encaixa

```text
Atlas Evolution Loop engine  →  (this) finance instance: candidate = strategy.json, judge = backtest
→ honesty gate (deflate by N + PBO + sealed holdout)  →  propose-only proposal  →  human review
```

It consumes the [engine runtime](../../atlas-evolution-loop-runtime.md) verbatim and the
[Finance domain](../finance.md) charter (`market_execution_forbidden`, `analysis_review_only`, "Live
trading bloqueado"). Autonomy is scoped strictly to research/backtest — research ≠ execution.

## Contratos

**Hard invariants (never violate):**
- **Propose-only. Never-merge. No real money. EVER.** No live broker, order routing, funded wallet,
  or API keys. Backtest (and optionally paper-forward *viewing*) only.
- **Win-rate is forbidden as an objective** (the textbook trading fake-green).
- **The data + harness are frozen.** A candidate may tune only `strategy.json` — never the costs,
  the scorer, or the data.
- **Honest null is valid and expected.** Never paper over it with a weaker bar.

**The honest metric — two stages.** A per-candidate sanity GATE (the backtest's exit 0/1), then a
post-selection honesty gate on the round's best:

Sanity GATE (scoring region): `n_trades ≥ 20`, `max_dd ≤ 0.6`, `years ≥ 3`, Sharpe finite. The
MAXIMIZE target among passers is the annualized OOS Sharpe after costs (`ATLAS_METRIC=`).

Honesty gate (`TradingHonestyGate`) — certify only if ALL hold:

| Check | Threshold | Catches |
|---|---|---|
| **Deflated Sharpe** (Bailey & López de Prado) | `≥ 0.95`, trials `N` = candidates searched this round | luck from wide search (anti-Goodhart) |
| **PBO via CSCV** | `< 0.2` | in-sample winner being an OOS loser |
| **Sealed holdout** | annualized Sharpe `≥ 0.5` over `≥ 10` trades, region never seen by the metric | a backtest edge that evaporates on fresh ground |
| **Sibling diversity** | `≥ 3` passing siblings, `≥ 2` distinct | a degenerate set where the stats are meaningless |

The ">80% acerto" goal is honestly reframed: **not win-rate**, but *positive risk-adjusted OOS after
costs, DSR > 0.95, PBO < 0.2* — survival, not hit-rate.

**The strategy (`strategy.json`)** — a long-only spot trend/breakout family. Inert baseline is
`risk_pct=0` (trades nothing ⇒ RED ⇒ satisfies the revert-must-fail anti-fake). Tunable params:

| Param | Range | Meaning |
|---|---|---|
| `regime_period` | 0 or 20–300 | long only when `close > SMA(regime_period)`; 0 disables |
| `entry_lookback` | 5–100 | enter on breakout above prior-N-bar high |
| `exit_lookback` | 0 or 5–100 | exit on breakdown below prior-N-bar low; 0 disables |
| `atr_period` | 5–50 | ATR window for trailing stop + sizing |
| `atr_mult` | 1.0–8.0 | trailing-stop distance in ATRs |
| `risk_pct` | 0.05–0.5 | equity fraction risked per trade (`0` = inert baseline) |
| `min_hold_bars` | 0–20 | minimum hold (reduces churn/costs) |
| `fee_bps`, `slippage_bps` | — | **FROZEN** by the harness; values in the file are ignored |

Look-ahead-safe by construction: decide on `close[t]`, fill at `open[t+1]`, windows bounded by `t`
(proven by prefix-invariance).

## Fluxo

```text
sample/mutate a strategy → backtest on real history (after costs, no look-ahead)
→ sanity gate → collect passing siblings → HONESTY GATE (round N + PBO + holdout)
→ campaign-level N penalty → champion quarantine OR honest null
→ proposal_for_review only if quarantine passes every gate
```

## Campanhas Cientificas

New searches are no longer a single endless ledger by default. `atlas:finance:strategy-search` opens
a pre-registered campaign:

```text
storage/atlas/finance/campaigns/<campaign_id>/
  campaign.json
  ledger.jsonl
  workers/
  proposals/
  null-report.json
  holdout-ledger.jsonl
  data-manifest.json
```

`campaign.json` records the symbol, interval, family, max rounds/seconds, candidates per round,
seed, frozen costs, data hash, holdout id/range/reuse limit, parameter islands, Pareto objectives,
second-engine mode, and promotion criteria before the loop runs. `--dry-run-ledger` writes the same
structure under `storage/framework/...` for tests/smoke runs; `--no-ledger` writes nothing.

Global holdout reuse is tracked in `storage/atlas/finance/holdouts/registry.json`, so a reused
holdout cannot silently become fresh by opening a new campaign. Campaign reports also append a
governed knowledge event to `storage/atlas/finance/research-evidence-ledger.jsonl`.

`storage/atlas/finance/scenario-registry.json` records the scenario roadmap and latest campaign per
`symbol-interval-family`. It explicitly states the one-active-campaign policy and the no-universal-
strategy policy. The command also takes a process lock at `storage/atlas/finance/strategy-search.lock`;
a second simultaneous loop is refused.

The old long-running `storage/atlas/finance/search-ledger.jsonl` was captured as a legacy research
snapshot under `storage/atlas/finance/campaigns/BTCUSDT-1d-trend-breakout-v1-legacy/` with
`legacy-null-report.json`. Its verdict is `NULL_HOLDOUT_EXHAUSTED`: thousands of rounds reused the
same holdout, so the honest conclusion is research null / exhausted holdout, not failure.

## Holdout + Quarantine

Holdout is now a consumable resource with states: `FRESH`, `ACTIVE`, `EXHAUSTED`,
`RESERVED_FOR_CONFIRMATION`. A spent holdout can support a null research report, but it cannot
certify a strong proposal. Each campaign has two slices:

- `holdout`: validation/research holdout used by the round and campaign honesty gate.
- `confirmation_holdout`: final reserved slice used only when a champion enters quarantine.

A candidate that clears the round honesty gate is only **promoted**. The quarantine gate then checks:

```text
round pass
→ cumulative campaign-N penalty
→ reserved confirmation holdout policy
→ confirmation holdout Sharpe/trades gate
→ 2x cost stress
→ neighborhood robustness
→ second-engine divergence gate
→ cross-campaign rediscovery
→ certified_for_review, otherwise promoted_pending_quarantine
```

The default second engine is `independent-replay`, a separate clean-room replay of the current
strategy family. `freqtrade` remains the preferred external backend once configured; selecting it
without configuration fails closed.

Cross-campaign rediscovery uses a coarse parameter-region signature stored in
`storage/atlas/finance/candidate-rediscovery-ledger.jsonl`. A champion in campaign B must have been
found independently by at least one prior campaign with the same symbol, timeframe, family, and
coarse parameter region. Same-campaign repeats do not count.

## Cenarios, Regimes E Estrategia Nao Universal

The loop records performance by scenario instead of searching for one universal magic strategy.
Every campaign is scoped by `symbol`, `interval`, and `strategy_family`, and every scored holdout
includes regime metrics:

- bull
- bear
- lateral
- high volatility
- low volatility

This matters because a strategy may work on `BTCUSDT-1d` and fail on `ETHUSDT-1d`, or work on daily
bars and fail on 15-minute bars. The report preserves that distinction; regime data explains where a
candidate behaves well, but never weakens certification.

## Regras para IA

- Never add a live-trading / broker / money path. Ever.
- Never optimize win-rate; never let a candidate touch costs/data/harness.
- Never weaken the gate. If you change the metric, add a test AND re-run an independent adversarial
  audit (your own tests can give false confidence — see Riscos).
- Treat an honest null as success. A certified proposal is a hypothesis for a human, not a trade.

## Escopo de Implementacao

**Commands (and when to use which):**

| Command | Engine | Use for | Speed |
|---|---|---|---|
| `atlas:finance:strategy-search` | **fast, in-process** search | **the workhorse** — param optimization, run for hours | ~hundreds/sec |
| `atlas:finance:strategy-backtest` | frozen scorer (one candidate) | scoring/debugging one `strategy.json` | instant |
| `atlas:finance:strategy-evolve` | LLM provider proposes N scenarios | strategy-family *ideation* | slow (min/scenario) |
| `atlas:finance:strategy-loop` | repeats `strategy-evolve` | LLM-driven continuous loop | slow (≈hours/round) |

**Default to `atlas:finance:strategy-search`.** Param tuning is optimizer work; a heavyweight LLM
agent per scenario is the wrong tool (an 8-scenario evolve round can exceed an hour). The LLM engines
are for *inventing new families*, not tuning numbers.

- `strategy-search` options: `--symbol=BTCUSDT --interval=1d --family=trend-breakout-v1 --candidates=600 --rounds=0 --max-rounds=0 --max-seconds=0 --seed=<int> --campaign-id=<id> --ledger=<path> --islands=conservative,aggressive,robustness --second-engine=independent-replay|freqtrade|none --cross-campaign-confirmations=1 --no-ledger --dry-run-ledger --sleep=2 --kill-switch=<path>` (frozen `--fee-bps=10 --slippage-bps=5`, gate `--min-trades=20 --max-dd=0.6 --holdout-frac=0.25 --confirmation-holdout-frac=0.10 --holdout-max-reuse=1000 --confirmation-holdout-max-reuse=1`). Stop: `touch storage/atlas/finance/STOP`.
- `strategy-backtest`: `--strategy=path --region=scoring|holdout|full --json` → `ATLAS_METRIC=` + `ATLAS_TRADING_REPORT={...}`; exit 0 only if the sanity gate passes. Costs are overridden here, so a candidate cannot zero its fees.
- `strategy-evolve --dry-run` proves the inert baseline is RED without calling a provider.

**Architecture (the pieces):**

| File | Role |
|---|---|
| `Finance/StrategyLoop/MarketDataCache.php` | reads the frozen Binance CSV; point-in-time; µs→ms normalize |
| `Finance/StrategyLoop/Strategy/TrendBreakoutStrategy.php` | the look-ahead-safe strategy engine, costs applied |
| `Finance/StrategyLoop/Metrics/HonestMetrics.php` | Sharpe, maxDD, **Deflated Sharpe** (variance-floored), **PBO/CSCV** |
| `Finance/StrategyLoop/TradingHonestyGate.php` | the post-selection gate: N-deflation + PBO + holdout + diversity |
| `Finance/StrategyLoop/Campaign/StrategyCampaignStore.php` | campaign layout, pre-registration, data/cost/holdout manifests, isolated ledgers |
| `Finance/StrategyLoop/Campaign/StrategyCampaignReporter.php` | turns campaign ledgers into `CERTIFIED` / `NULL_*` verdicts |
| `Finance/StrategyLoop/Campaign/ChampionQuarantine.php` | prevents round winners from becoming proposals before hardening gates pass |
| `Finance/StrategyLoop/Campaign/StrategyRobustnessChecks.php` | 2x cost stress + neighborhood robustness checks |
| `Finance/StrategyLoop/Campaign/SecondEngineDivergenceGate.php` | independent-engine comparison gate; fails closed while unavailable |
| `Finance/StrategyLoop/Campaign/IndependentTrendBreakoutReplay.php` | clean-room second replay for current family |
| `Finance/StrategyLoop/Campaign/HoldoutRegistry.php` | cross-campaign holdout reuse registry |
| `Finance/StrategyLoop/Campaign/MarketRegimeAnalyzer.php` | bull/bear/lateral/high-vol/low-vol report segmentation |
| `Finance/StrategyLoop/Campaign/StrategyParetoSelector.php` | internal multi-objective island/Pareto selection |
| `Finance/StrategyLoop/Campaign/StrategyResearchEvidenceLedger.php` | governed campaign knowledge event ledger |
| `Finance/StrategyLoop/Campaign/StrategyCandidateSignature.php` | coarse parameter-region signature for rediscovery |
| `Finance/StrategyLoop/Campaign/CandidateRediscoveryLedger.php` | promoted candidate rediscovery ledger |
| `Finance/StrategyLoop/Campaign/CrossCampaignRediscoveryGate.php` | hard gate requiring independent rediscovery |
| `Finance/StrategyLoop/Campaign/StrategyScenarioRegistry.php` | sequential market/timeframe/family registry |
| `Console/Commands/AtlasFinanceStrategySearchCommand.php` | the fast in-process search loop |
| `Console/Commands/AtlasFinanceStrategyBacktestCommand.php` | the frozen acceptance scorer |
| `Console/Commands/AtlasFinanceStrategy{Evolve,Loop}Command.php` | the LLM-driven engines |

**Data layer:** `storage/atlas/finance/market-data/BTCUSDT-1d.csv` — ~3073 real daily bars
(2018→2026), fetched by `storage/atlas/finance/fetch-btc-history.sh` from `data.binance.vision`
(free). A frozen input; the loop reads it, never edits it. Testnet/paper feeds are NEVER backtest
input.

## Dependencias

- The [Atlas Evolution Loop runtime](../../atlas-evolution-loop-runtime.md) (explorer + frozen judge + acceptance contract) — for the LLM engines.
- The frozen market-data cache (Binance daily CSV) — the only data source.
- `HonestMetrics` (the DSR/PBO math) and `TradingHonestyGate` (the certification gate).
- The [Finance domain](../finance.md) review-only charter and gates.

## Evidencias

`vendor/bin/phpunit tests/Unit/Ai/Finance/StrategyLoop` proves: look-ahead impossible
(data + strategy prefix-invariance), inert baseline trades zero, DSR falls with N (incl. the floored
zero-variance regime), PBO low/high cases, numeric clamps, the gate keystone (the SAME clustered
winner certifies at N=2, rejected at N=500), holdout significance, sibling diversity, and the
degenerate-moment fail-safe. It also proves campaign ledgers stay isolated, smoke `--no-ledger`
does not write, holdout `EXHAUSTED` blocks certification, cumulative campaign-N can reject a round
pass, confirmation holdout must pass, 2x cost stress and neighborhood robustness fail fragile
champions, the independent replay agrees inside tolerance, Pareto keeps robust tradeoffs, cross-
campaign rediscovery is required, scenario registry records one-active-campaign/no-universal-
strategy policy, regime reports are preserved, and null reports become governed evidence. Runtime evidence now lives in
`storage/atlas/finance/campaigns/<id>/`; the legacy single `search-ledger.jsonl` remains historical
evidence only.

## Riscos

**THE CRITICAL LESSON (do not reintroduce).** An adversarial audit caught a critical flaw before
launch: the Deflated Sharpe's `SR0 = sqrt(varSharpe)·(N-terms)`, so when the passing siblings CLUSTER
(a converging search's natural state) or are singleton/tied, `varSharpe→0 → SR0→0 → N inert` — a
strategy with *zero* OOS edge certified at any N. Unit tests gave false confidence by hand-feeding a
variance the real loop never produces. **Fixes (keep them):** analytic variance floor
`effVar = max(empirical, (1 + 0.5·sr²)/nObs)` (Lo 2002) so N always deflates; per-period units
(annualized vs per-period was a 365× bug); holdout significance (was a `≥0` sign test); sibling
diversity; numeric NaN/INF clamps; a non-positive DSR denominator fails safe (reject, never certify).
**Rule: never trust your own honesty tests without an independent adversary.**

**Other limitations:** single market/family by current data availability (proves the harness, not a
guaranteed edge); campaign-N + holdout registry + cross-campaign rediscovery harden cross-round
multiple testing; external `freqtrade` is not configured yet, so the operational second engine is
the clean-room independent replay.

## Exemplos

```bash
# 0. one-time: fetch the frozen real history (only if the cache is missing)
bash storage/atlas/finance/fetch-btc-history.sh BTCUSDT 1d 2018 2026 5

# 1. score one candidate
php artisan atlas:finance:strategy-backtest --strategy=/tmp/s.json --region=scoring   # ATLAS_METRIC=...

# 2. run the fast search for hours (propose-only)
php artisan atlas:finance:strategy-search --candidates=600 --sleep=2

# 2b. run a pre-registered dry campaign smoke without touching the real campaign ledger
php artisan atlas:finance:strategy-search --campaign-id=smoke --rounds=1 --candidates=10 --seed=123 --dry-run-ledger --sleep=0

# 2c. smoke with no writes at all
php artisan atlas:finance:strategy-search --rounds=1 --candidates=10 --no-ledger --sleep=0

# 3. stop after the current round
touch storage/atlas/finance/STOP

# 4. watch progress + read certified proposals
tail -f storage/atlas/finance/campaigns/<campaign_id>/ledger.jsonl
cat storage/atlas/finance/campaigns/<campaign_id>/null-report.json
```

A v2 ledger row adds `campaign_id`, `worker_id`, `seed`, `campaign_trials`, `winner_island`,
`promoted`, validation/confirmation holdout status/reuse, regime metrics, data/cost hashes, and
quarantine details. A typical healthy run is **mostly
`certified:false`** with reasons like `deflated_sharpe_too_low` / `holdout_not_positive` — the gate
refusing overfit edges. To run it autonomously: launch detached, health-check every ~10 min (process
alive? campaign ledger growing? mostly null?), never stop early.

## Proximas Acoes

- Wire external `freqtrade` spot beside the current independent replay backend.
- When a champion is promoted, launch the next confirmation campaign sequentially with a new seed
  and fresh/reserved holdout; do not run it in parallel.
- Extend to `ETHUSDT-1d`, `SOLUSDT-1d`, then `BTCUSDT-4h`/`ETHUSDT-4h`; avoid 15m/5m, leverage,
  perps/funding, and survivorship-biased top-coin selection until regime reporting is in place.
  **Only ever tighten the gate, never loosen it.**
