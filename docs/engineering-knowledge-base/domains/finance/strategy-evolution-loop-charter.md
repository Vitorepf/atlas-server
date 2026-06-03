---
id: atlas-finance-strategy-evolution-loop
type: engineering_knowledge
title: Atlas Finance Strategy-Evolution Loop
status: active
category: domains
priority: 95
summary: Canonical + operational guide for the finance/trading strategy-evolution loop — the Atlas Evolution Loop's first non-engineering transfer. It searches for a trading strategy on real history under an audited anti-overfit honesty gate (N-deflated out-of-sample Sharpe + PBO + governed holdout) and now runs as pre-registered research campaigns with null reports, champion quarantine, sequential confirmation queue, and scenario memory. Propose-only, never-merge, no real money, win-rate forbidden.
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
  - A near-certified champion that is blocked only by cross-campaign rediscovery is queued for the next independent confirmation campaign; this queue is sequential and never starts a parallel loop.
  - Strategy performance is scenario-specific: market, timeframe, family, and regime are recorded; never assume one strategy works for every asset/timeframe/regime.
  - Every campaign and scenario registry entry carries a governed `timeframe_profile`; 5m/15m/1mo hypotheses are registered as deferred research, not active roadmap items, until their data/cost/microstructure controls exist.
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
  - Feed a real exported freqtrade spot holdout report through the existing fail-closed adapter before using `--second-engine=freqtrade` in a live campaign.
  - Expand markets/timeframes sequentially, one focused campaign at a time after the BTCUSDT-1d benchmark campaign has a final verdict.
  - Add new strategy-family classes before running family-island campaigns.
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
  or API keys. Backtest (and optionally paper-forward *viewing*) only. The operational audit runs a
  static no-execution-surface scan over the finance strategy-loop code and scripts; broker/order/key
  signatures such as `ccxt`, `createOrder`, `apiKey`, `secretKey`, or `live_trading=allowed` fail
  the platform audit.
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

**Strategy families** — every implemented family is long-only spot, propose-only, costed on both
sides, and look-ahead-safe. Inert baseline is `risk_pct=0` (trades nothing ⇒ RED ⇒ satisfies the
revert-must-fail anti-fake). Current families:

| Family | Thesis | Core params |
|---|---|---|
| `trend-breakout-v1` | trend continuation after Donchian breakout | `entry_lookback`, `exit_lookback`, `atr_period`, `atr_mult`, `min_hold_bars` |
| `mean-reversion-v1` | buy statistically stretched spot dips, exit on revert/stop/timeout | `lookback`, `entry_z`, `exit_z`, `stop_loss_pct`, `max_hold_bars` |
| `momentum-v1` | buy sustained time-series strength, exit on momentum decay/stop/trail/timeout | `momentum_lookback`, `entry_momentum`, `exit_momentum`, `stop_loss_pct`, `trailing_stop_pct`, `max_hold_bars` |

`trend-breakout-v1` params:

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

`strategy-search` fails closed for unsupported family names rather than mislabeling one engine as
another. New families must add their own strategy class, parameter generator, tests, cross-campaign
signature buckets, and second-engine replay before entering a campaign.

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

`campaign.json` records symbol, interval, family, budget, seed, frozen costs, data hash, holdout
generation/max-generation/id/range/reuse limit, split policy, islands, Pareto objectives, second-engine mode, and
promotion criteria. The pre-registered budget also records `scenario_prior_trials`,
`scenario_max_candidates`, and `scenario_trial_accounting`, so weeks of fresh holdout campaigns cannot
reset the statistical cost of the exact scenario. `--dry-run-ledger` writes under `storage/framework/...`; `--no-ledger` writes nothing.
If `--max-rounds=0`, the campaign budget defaults to `--holdout-max-reuse`; there is no infinite
statistical budget. `--rounds` is only the current invocation's run limit, so a campaign can be
paused and resumed without converting the pause into a scientific null. When this default budget
reaches the validation holdout reuse limit, the verdict is holdout exhaustion, not family exhaustion,
so the scenario can continue on the next fresh validation generation.

Global holdout reuse is tracked in `storage/atlas/finance/holdouts/registry.json`, so a reused
holdout cannot silently become fresh by opening a new campaign. Campaign reports also append a
governed knowledge event to `storage/atlas/finance/research-evidence-ledger.jsonl`.

`storage/atlas/finance/scenario-registry.json` records the scenario roadmap, latest campaign,
latest verdict, research history, explicit data/cost hashes, best-observed candidate profiles, and
family-exhaustion marker per `symbol-interval-family`.
Roadmap entries are full scenarios (`symbol + interval + strategy_family`), so the system can learn
that a strategy works for one asset/timeframe/family but not another. Best-observed candidates are
research evidence only, not executable signals. New campaigns can warm-start their first elite pool
from those prior best-observed candidates, but only for the exact same `symbol + interval + family +
feature_set`; this speeds exploration across fresh holdout generations without weakening the new
campaign's N penalty, holdout, or quarantine gates. It explicitly states the one-active-campaign policy
and the no-universal-strategy policy. The command also takes a process lock at
`storage/atlas/finance/strategy-search.lock`; a second simultaneous loop is refused.

`php artisan atlas:finance:strategy-campaign-runner` is the sequencer. Without `--continuous`, it runs
one campaign: pending champion confirmation first, otherwise the next full scenario. With
`--continuous --max-campaigns=0` it keeps selecting the next eligible campaign, still one at a time.
Zero-candidate holdout exhaustion is `INCONCLUSIVE`, not `NULL_*`; evidence-bearing
`NULL_HOLDOUT_EXHAUSTED` also retries that same scenario while fresh validation
`holdout_generation`s remain. Terminal conclusions are not reopened by campaign id. The runner delegates to `strategy-search`, so the same global lock
forbids parallel finance loops, and missing market data returns `market_data_missing`.

`atlas:finance:strategy-loop-audit` defaults to the active scientific target: it prefers a
`running` campaign with the freshest `ledger.jsonl` before falling back to paused or terminal
campaign metadata. This keeps monitoring attached to the live campaign rather than the last
metadata file touched by a completed report. Runtime audit also requires the user LaunchAgent to run
at load, keep the job alive while `storage/atlas/finance/STOP` is absent, and invoke
`scripts/finance/strategy-loop-launchd-guard.sh` every 60 seconds. The guard currently focuses the
live operator loop on `BTCUSDT` / `1d` while still allowing the in-scenario roadmap families to run
sequentially. It respects `STOP`, refuses duplicate runners, and relaunches the continuous sequential
runner if the process dies.

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

`--confirmation-holdout-frac` must be strictly smaller than `--holdout-frac`, and all three
regions (scoring, validation holdout, confirmation holdout) must contain bars. Degenerate split
configuration is rejected before campaign creation; the loop never aliases validation and
confirmation holdouts silently.

Holdout reuse is counted globally in `holdouts/registry.json`; campaign round number is not added
again to that global count. This prevents premature exhaustion while still blocking certification
once the real global `reuse_count >= max_reuse`.

`--holdout-generation=0` uses the latest validation slice before the reserved confirmation holdout.
Higher generations walk the validation slice backward, keeping BTCUSDT-1d on fresh validation ground
without pretending a spent holdout is fresh.

A candidate that clears the round honesty gate is only **promoted**. The quarantine gate then checks:

```text
round pass
→ cumulative campaign-N penalty
→ reserved confirmation holdout policy
→ confirmation holdout Sharpe/trades gate
→ timeframe-governed cost stress (at least 2x; 3x for high-frequency intraday once activated)
→ neighborhood robustness
→ second-engine divergence gate
→ cross-campaign rediscovery
→ certified_for_review, otherwise promoted_pending_quarantine
```

Cost stress is not decorative: `timeframe_policy.cost_stress_multiplier` is recorded in
`promotion_criteria` and applied during quarantine (daily/4h = 2x; high-frequency = 3x once activated).

The default second engine is `python-replay`, an external Python process that replays implemented
strategy families only for champions in quarantine. `independent-replay` remains available as a PHP
clean-room replay for `trend-breakout-v1`. `freqtrade` is supported as a pinned external holdout-report adapter via
`--second-engine=freqtrade --freqtrade-report=<json>`; missing, invalid, or incomplete reports fail
closed. The pinned freqtrade report must declare `engine=freqtrade`, `trading_mode=spot`,
`propose_only=true`, `live_trading=forbidden`, and must match the campaign's symbol, interval,
strategy family, data hash, holdout id, and cost-profile hash before its metrics are accepted. All
second-engine paths compare metrics only and never emit orders or touch a broker.
`--second-engine=none` is allowed only for `--no-ledger` smoke or `--dry-run-ledger` experiments;
real governed campaigns reject it before campaign creation, and `strategy-loop-audit` also rejects it
because a scientific campaign must have a real independent replay path.
The divergence gate compares trade count, annualized Sharpe, max drawdown, optional net/total
return, optional exposure, optional sampled equity curve, and explicit holdout pass/fail. If a
`freqtrade` campaign is audited, the operational audit validates the pinned report's metadata and
metrics through the adapter; file existence alone is not enough.

Cross-campaign rediscovery uses a coarse parameter-region signature stored in
`storage/atlas/finance/candidate-rediscovery-ledger.jsonl`. A champion in campaign B must have been
found independently by at least one prior campaign with the same symbol, timeframe, family, and
coarse parameter region. Same-campaign repeats do not count.

If a champion passes quarantine except for `cross_campaign_rediscovery_required`, it is written to
`storage/atlas/finance/confirmation-queue.json` with a deterministic next campaign id, fresh seed,
candidate signature, and a ready-to-run `atlas:finance:strategy-search` command. Inspect the next
request with `php artisan atlas:finance:strategy-confirmation-next --json`; claim it only when the
active loop has stopped. This is a sequential campaign plan, not parallel execution.

## Cenarios, Regimes E Estrategia Nao Universal

The loop records scenario-specific performance instead of searching for one universal magic strategy.
Every campaign is scoped by `symbol`, `interval`, and `strategy_family`; every scored holdout includes
regime metrics (`bull`, `bear`, `lateral`, `high_volatility`, `low_volatility`). Reports preserve
asset/timeframe differences, and the registry keeps a research-only family matrix per exact scenario.

Each campaign records `timeframe_profile`/`timeframe_policy`: `1d` is the daily benchmark, `4h` is
intraday swing when data exists, and `5m`/`15m` plus `1mo` stay deferred until data, spread, slippage,
and history-depth controls exist. It also records `feature_set`: `price_only_v1` is the only active
default; deferred activation order is regime/liquidity, derivatives, cross-asset, orderbook, on-chain,
then late/experimental news only after AP/data manifest/anti-lookahead controls exist.

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
| `atlas:finance:strategy-campaign-runner` | sequential orchestrator | run one pending confirmation/roadmap scenario, or continuous one-at-a-time campaign sequence | campaign-length |
| `atlas:finance:strategy-loop-audit` | read-only verifier | prove campaign-platform invariants: one-active policy, propose-only, real second engine, scenario roadmap, holdouts, ledger, optional runtime process check | instant |
| `atlas:finance:strategy-adversarial-audit` | dry adversary | tries bad states: unsupported family, terminal campaign, exhausted holdout, zero-candidate null closure, missing/divergent second engine, wrong freqtrade report, family-signature confusion | instant |
| `atlas:finance:strategy-scientific-readiness-audit` / `strategy-plan-completion-audit` | readiness verifiers | aggregate audits and map final-plan items to current evidence | instant |
| `atlas:finance:strategy-confirmation-next` | queue reader | inspect/claim the next sequential champion confirmation campaign | instant |
| `atlas:finance:strategy-backtest` | frozen scorer (one candidate) | scoring/debugging one `strategy.json` | instant |
| `atlas:finance:strategy-evolve` | LLM provider proposes N scenarios | strategy-family *ideation* | slow (min/scenario) |
| `atlas:finance:strategy-loop` | repeats `strategy-evolve` | LLM-driven continuous loop | slow (≈hours/round) |

**Default to `atlas:finance:strategy-search`.** Param tuning is optimizer work; a heavyweight LLM
agent per scenario is the wrong tool (an 8-scenario evolve round can exceed an hour). The LLM engines
are for *inventing new families*, not tuning numbers.

- `strategy-search` options: `--symbol=BTCUSDT --interval=1d --family=trend-breakout-v1|mean-reversion-v1|momentum-v1 --candidates=600 --rounds=0 --max-rounds=0 --max-seconds=0 --seed=<int> --campaign-id=<id> --ledger=<path> --holdout-generation=0 --islands=conservative,aggressive,robustness --second-engine=python-replay|independent-replay|freqtrade|none --freqtrade-report=<json> --cross-campaign-confirmations=1 --no-ledger --dry-run-ledger --sleep=2 --kill-switch=<path>` (frozen `--fee-bps=10 --slippage-bps=5`; timeframe policy supplies default `--min-trades`, `--holdout-min-trades`, and `--holdout-max-reuse`; gate also has `--max-dd=0.6 --holdout-frac=0.25 --confirmation-holdout-frac=0.10 --confirmation-holdout-max-reuse=1`; deferred intervals require `--allow-deferred-timeframe`). `--rounds` limits this invocation; `--max-rounds` is the campaign's pre-registered statistical budget and defaults to holdout reuse. `--second-engine=none` is smoke-only and fails the operational audit. Stop: `touch storage/atlas/finance/STOP`.
- `strategy-backtest`: `--strategy=path --region=scoring|holdout|full --json` → `ATLAS_METRIC=` + `ATLAS_TRADING_REPORT={...}`; exit 0 only if the sanity gate passes. Costs are overridden here, so a candidate cannot zero its fees.
- `strategy-evolve --dry-run` proves the inert baseline is RED without calling a provider.

**Architecture (the pieces):**

| File | Role |
|---|---|
| `Finance/StrategyLoop/MarketDataCache.php` | reads the frozen Binance CSV; point-in-time; µs→ms normalize |
| `Finance/StrategyLoop/Strategy/TrendBreakoutStrategy.php` | the look-ahead-safe strategy engine, costs applied |
| `Finance/StrategyLoop/Strategy/MeanReversionStrategy.php` | long-only spot mean-reversion family with dip entry, revert/stop/timeout exits, costs applied |
| `Finance/StrategyLoop/Strategy/MomentumStrategy.php` | long-only spot momentum family with strength entry, decay/stop/trail/timeout exits, costs applied |
| `Finance/StrategyLoop/Metrics/HonestMetrics.php` | Sharpe, maxDD, **Deflated Sharpe** (variance-floored), **PBO/CSCV** |
| `Finance/StrategyLoop/TradingHonestyGate.php` | the post-selection gate: N-deflation + PBO + holdout + diversity |
| `Finance/StrategyLoop/Campaign/StrategyCampaignStore.php` | campaign layout, pre-registration, data/cost/holdout manifests, isolated ledgers |
| `Finance/StrategyLoop/Campaign/StrategyCampaignReporter.php` | turns campaign ledgers into `CERTIFIED` / `NULL_*` verdicts |
| `Finance/StrategyLoop/Campaign/ChampionQuarantine.php` | prevents round winners from becoming proposals before hardening gates pass |
| `Finance/StrategyLoop/Campaign/StrategyRobustnessChecks.php` | 2x cost stress + neighborhood robustness checks |
| `Finance/StrategyLoop/Campaign/SecondEngineDivergenceGate.php` | independent-engine comparison gate; fails closed while unavailable and rejects metric/holdout divergence |
| `Finance/StrategyLoop/Campaign/ExternalPythonTrendBreakoutReplay.php` | external Python second-engine process for implemented families |
| `Finance/StrategyLoop/Campaign/IndependentTrendBreakoutReplay.php` | clean-room second replay for current family |
| `Finance/StrategyLoop/Campaign/FreqtradeSecondEngineAdapter.php` | pinned freqtrade JSON report adapter; fail-closed external second-engine path |
| `scripts/finance/strategy_second_engine_replay.py` | no-network/no-broker external replay implementation |
| `Finance/StrategyLoop/Campaign/HoldoutRegistry.php` | cross-campaign holdout reuse registry |
| `Finance/StrategyLoop/Campaign/MarketRegimeAnalyzer.php` | bull/bear/lateral/high-vol/low-vol report segmentation |
| `Finance/StrategyLoop/Campaign/StrategyParetoSelector.php` | internal multi-objective island/Pareto selection |
| `Finance/StrategyLoop/Campaign/StrategyResearchEvidenceLedger.php` | governed campaign knowledge event ledger |
| `Finance/StrategyLoop/Campaign/StrategyCandidateSignature.php` | coarse parameter-region signature for rediscovery |
| `Finance/StrategyLoop/Campaign/CandidateRediscoveryLedger.php` | promoted candidate rediscovery ledger |
| `Finance/StrategyLoop/Campaign/CrossCampaignRediscoveryGate.php` | hard gate requiring independent rediscovery |
| `Finance/StrategyLoop/Campaign/StrategyConfirmationQueue.php` | sequential queue for near-certified champions needing independent rediscovery |
| `Finance/StrategyLoop/Campaign/StrategyScenarioRegistry.php` | sequential market/timeframe/family registry |
| `Finance/StrategyLoop/Campaign/StrategyLoop{Operational,Adversarial,ScientificReadiness}Audit.php` | read-only, adversarial, and end-to-end readiness audits for the campaign platform |
| `Finance/StrategyLoop/Campaign/StrategyNoExecutionSurfaceAudit.php` | static guard for no broker, no keys, no order routing, no live-money path |
| `Console/Commands/AtlasFinanceStrategySearchCommand.php` | the fast in-process search loop |
| `Console/Commands/AtlasFinanceStrategyCampaignRunnerCommand.php` | one-campaign sequential runner: confirmation queue first, then roadmap |
| `Console/Commands/AtlasFinanceStrategy{Loop,Adversarial,ScientificReadiness,PlanCompletion}AuditCommand.php` | audit commands; runtime, adversarial, readiness, and plan-completion proof surfaces |
| `Console/Commands/AtlasFinanceStrategyConfirmationNextCommand.php` | reads/claims the next sequential confirmation campaign request |
| `Console/Commands/AtlasFinanceStrategyBacktestCommand.php` | the frozen acceptance scorer |
| `Console/Commands/AtlasFinanceStrategy{Evolve,Loop}Command.php` | the LLM-driven engines |

**Data layer:** frozen CSV caches under `storage/atlas/finance/market-data/`, fetched by
`storage/atlas/finance/fetch-btc-history.sh` from `data.binance.vision` (free). The initial
sequential crypto roadmap is cached locally: `BTCUSDT-1d`, `ETHUSDT-1d`, `SOLUSDT-1d`,
`BTCUSDT-4h`, and `ETHUSDT-4h`. These are frozen inputs; the loop reads them, never edits them.
Testnet/paper feeds are NEVER backtest input.

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
campaign rediscovery is required, near-certified champions enter a sequential confirmation queue,
the external Python replay agrees inside tolerance for implemented families, the freqtrade report adapter fails closed or
reads pinned scenario/data/cost-matched metrics, scenario registry records one-active-campaign/no-universal-strategy policy
plus family-exhausted knowledge, regime reports are preserved, and null reports become governed
evidence. Runtime evidence now lives in
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

**Other limitations:** the active live campaign is still one scenario at a time and currently uses
daily/4h-ready scenarios as the mature roadmap. `mean-reversion-v1` and `momentum-v1` are implemented
and testable, but broader family/market conclusions require sequential campaigns. 5m/15m/1mo are
profiled and registered as deferred hypotheses, not active searches. Campaign-N + holdout registry +
cross-campaign rediscovery harden cross-round multiple testing; `freqtrade` is wired as a fail-closed
report adapter, but the operational default second engine is the external Python replay until a real
freqtrade holdout report is exported and pinned.

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

# 5. inspect the next sequential confirmation campaign, if a champion is near-certified
php artisan atlas:finance:strategy-confirmation-next --json

# 6. run exactly one sequential campaign from queue or full scenario roadmap (example smoke)
php artisan atlas:finance:strategy-campaign-runner --max-rounds=1 --candidates=10 --sleep=0 --dry-run-ledger

# 6b. focus a concrete family sequentially
php artisan atlas:finance:strategy-campaign-runner --family=momentum-v1 --max-rounds=1 --candidates=10 --sleep=0 --dry-run-ledger

# 6c. continuous scientific campaign mode: still one active campaign at a time
php artisan atlas:finance:strategy-campaign-runner --family=roadmap --continuous --max-campaigns=0 --candidates=600 --sleep=2

# 7. prove the platform shape without mutating state
php artisan atlas:finance:strategy-loop-audit --runtime --json

# 8. prove the gates fail closed under adversarial bad states
php artisan atlas:finance:strategy-adversarial-audit --json

```

A v2 ledger row adds `campaign_id`, `worker_id`, `seed`, `campaign_trials`, `scenario_trials`, `winner_island`,
`winner_strategy`, `winner_signature`, `promoted`, validation/confirmation holdout status/reuse,
regime metrics, data/cost hashes, and quarantine details. `deflated_sharpe` and `round_reasons`
describe the current round (`N=candidates_per_round`); `campaign_deflated_sharpe` and
`campaign_reasons` describe the cumulative campaign penalty (`N=campaign_trials`);
`scenario_deflated_sharpe` and `scenario_reasons` describe the exact scenario penalty across prior
campaigns for the same `symbol + interval + family + feature_set` (`N=scenario_trials`). A typical
healthy run is **mostly `certified:false`** with
reasons like `deflated_sharpe_too_low` / `holdout_not_positive` — the gate refusing overfit edges.
To run it autonomously: launch detached, health-check every ~10 min (process alive? campaign ledger
growing? mostly null?), never stop early.

Certified proposal files put the strictest available DSR in the top-level `honesty_report`: scenario
cumulative DSR when present, otherwise campaign DSR, with round-level DSR preserved separately as
`round_honesty_report`. The most conservative number is therefore the first-read value.

## Proximas Acoes

- Extend to `ETHUSDT-1d`, `SOLUSDT-1d`, then `BTCUSDT-4h`/`ETHUSDT-4h`; avoid 15m/5m, leverage,
  perps/funding, and survivorship-biased top-coin selection until data controls are in place.
- Feed a real exported `freqtrade` holdout report into `--freqtrade-report` before using
  `--second-engine=freqtrade` as a live campaign gate.
- Run `mean-reversion-v1` and `momentum-v1` campaigns sequentially after the current benchmark;
  add additional families only with strategy class + parameter generator + signature + external replay tests.
- **Only ever tighten the gate, never loosen it.**
