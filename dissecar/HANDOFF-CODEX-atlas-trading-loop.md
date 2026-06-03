# HANDOFF → Codex — Atlas Trading Strategy-Evolution Loop

You are taking over an in-flight, **running** piece of work on Atlas. Read this top-to-bottom once,
then read the two canonical docs in §1. Everything you need to continue is here. Date: 2026-06-03.

---

## 0. TL;DR — where things stand

A **trading strategy-evolution loop** was built, adversarially hardened, and is **running right now**
on the operator's Mac, propose-only, searching for a profitable BTC/USDT strategy on real history. It
is the first non-engineering transfer of the proven Atlas Evolution Loop. **56 tests green.** An
independent adversarial audit found and closed a critical fake-green flaw before launch. As of this
handoff: **~4,765 search rounds done, 0 certified (honest), best Deflated Sharpe seen 0.946** (just
under the 0.95 bar — the gate is correctly refusing to certify an overfit edge).

**Your job is to continue this WITHOUT breaking its honesty.** The single most important rule:
**the loop must never be able to fool itself, and it must never touch real money.**

---

## 1. READ THESE FIRST (canonical docs — they are comprehensive and self-contained)

1. `docs/engineering-knowledge-base/domains/finance/strategy-evolution-loop-charter.md`
   — the finance/trading loop: invariants, the honest metric, all commands, the strategy schema,
   architecture, how to run/monitor/stop, the critical lesson, how to extend.
2. `docs/engineering-knowledge-base/atlas-evolution-loop-runtime.md`
   — the underlying engine (the `AtlasEvolution*` runtime + the reusable acceptance contract +
   "Como Instanciar" recipe). NOTE: this is NOT `atlas-autonomous-evolution-loop.md` (that's AAEL,
   the portfolio governor — a different runtime; don't conflate them).

If anything below conflicts with code, the code wins — and fix the doc.

---

## 2. HARD INVARIANTS — NEVER violate these

- **Propose-only. Never-merge. NO REAL MONEY. EVER.** No live broker, order routing, funded wallet,
  API keys, or smart contracts. Backtest (and optionally paper-forward *viewing*) only. There is no
  code path to execution and you must never add one.
- **Win-rate is FORBIDDEN as an objective.** The metric is N-deflated out-of-sample Sharpe after
  costs, gated by PBO and a sealed holdout. (">80% acerto" was honestly reframed to ">80% of
  walk-forward windows positive / DSR>0.95 / PBO<0.2" — survival, not hit-rate.)
- **The data + harness + costs are FROZEN.** A candidate may tune only `strategy.json`. It can never
  change how it is scored, the fees/slippage, or the data.
- **Honest null is the EXPECTED, correct outcome.** Most rounds will not certify. Do NOT weaken any
  gate to force a "winner." If you change the metric, you MUST add a test AND re-run an independent
  adversarial audit — your own tests can give false confidence (this already happened once; see §6).
- **Atlas governance** (from `CLAUDE.md`, both repo root and `atlas-server/`): Atlas is the brain,
  providers (you, Codex) are the muscle. Forbidden vocabulary in code/docs: "Jarvis", "Rivals",
  "benchmark", "superiority", "concurrent". Before non-trivial code/architecture changes, consult the
  `atlas-open-brain` MCP (e.g. `atlas_memory_recall`) — don't trust blind grep or training memory.

---

## 3. What is RUNNING right now + how to control it

A background process is running the fast search loop:

```bash
php artisan atlas:finance:strategy-search --symbol=BTCUSDT --interval=1d --candidates=600 --sleep=2
```

- **Check it's alive:** `pgrep -fl strategy-search`
- **Watch progress:** `tail -f storage/atlas/finance/search-ledger.jsonl` (one JSON line per round)
- **Stop it (graceful, after current round):** `touch storage/atlas/finance/STOP`
- **Certified proposals (if any appear):** `storage/atlas/finance/proposals/*.json`

The operator wants it **left running for hours**, with a **quick health check ~every 10 minutes**
(process alive? ledger growing? mostly null is good; a certified proposal is rare + notable; a flood
of certifieds or all-errors is a red flag). Do NOT stop it early. "No winner yet" is success.

---

## 4. Current honest state (read before judging anything)

- ~4,765 rounds, **0 certified** — correct. Best Deflated Sharpe seen ≈ **0.946** (threshold 0.95),
  best holdout Sharpe seen ≈ 0.81. The search is right at the honesty boundary and honestly refuses.
- This is a real, useful finding: **daily BTC/USDT long-only trend-following, searched hard, has no
  edge that survives the multiple-testing + holdout correction (yet).** That null is the truth, not a
  bug. A richer strategy family / market / resolution may change it.
- The 2 files in `storage/atlas/finance/proposals/` are from the earlier slow LLM-engine smoke runs
  (honest nulls/rejects), not certified search winners.

---

## 5. Architecture (all PHP, self-contained, no Python)

| File | Role |
|---|---|
| `app/Services/Ai/Finance/StrategyLoop/MarketDataCache.php` | frozen Binance CSV reader; point-in-time; µs→ms normalize |
| `app/Services/Ai/Finance/StrategyLoop/Bar.php` | OHLCV value object (ms timestamps) |
| `app/Services/Ai/Finance/StrategyLoop/Strategy/TrendBreakoutStrategy.php` | look-ahead-safe long-only trend/breakout engine (decide close[t], fill open[t+1]) |
| `app/Services/Ai/Finance/StrategyLoop/Strategy/StrategyResult.php` | result value object |
| `app/Services/Ai/Finance/StrategyLoop/Metrics/HonestMetrics.php` | Sharpe, maxDD, **Deflated Sharpe (variance-floored)**, **PBO/CSCV**, normalCdf/probit |
| `app/Services/Ai/Finance/StrategyLoop/TradingHonestyGate.php` | post-selection gate: N-deflation + PBO + holdout + diversity |
| `app/Console/Commands/AtlasFinanceStrategySearchCommand.php` | **the workhorse** — fast in-process evolutionary search |
| `app/Console/Commands/AtlasFinanceStrategyBacktestCommand.php` | the frozen acceptance scorer (one candidate; freezes costs) |
| `app/Console/Commands/AtlasFinanceStrategyEvolveCommand.php` | LLM-engine: one explore-N-scenarios round + honesty gate |
| `app/Console/Commands/AtlasFinanceStrategyLoopCommand.php` | LLM-engine: repeats evolve rounds |
| `storage/atlas/finance/fetch-btc-history.sh` | fetches the frozen real history |
| `storage/atlas/finance/market-data/BTCUSDT-1d.csv` | the frozen data (~3073 daily bars 2018→2026) |

Shared engine touched this session: `app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php`
gained an opt-in `strict_untracked` flag (drops `--exclude-standard` in the changed-file census so a
candidate cannot hide siblings via `.git/info/exclude`; finance sets it, engineering path unchanged).

---

## 6. THE CRITICAL LESSON — do NOT reintroduce this

An adversarial audit (independent agents, reproduced through the real code) found a **critical**
fake-green: the Deflated Sharpe's `SR0 = sqrt(varSharpe) · (N-terms)`. When the passing siblings
CLUSTER (the natural state of a converging search) or are singleton/tied, `varSharpe → 0 → SR0 → 0 →
the trial count N becomes INERT` — and a strategy with **zero** out-of-sample edge certified at any N.
My own keystone test had passed only because it hand-fed a variance the real loop never produces.

**The fixes (all in place — keep them, never weaken them):**
1. Analytic variance floor (Lo 2002): `effVar = max(empiricalVarSharpe, (1 + 0.5·sr²)/nObs)` in
   `HonestMetrics::deflatedSharpe`, so N **always** deflates even at zero observed dispersion.
2. Per-period units: `collectSiblings` divides annualized `ATLAS_METRIC` by `sqrt(periodsPerYear)`
   before the gate takes variance (an annualized-vs-per-period 365× bug previously masked #1).
3. Holdout SIGNIFICANCE: `holdout_min_sharpe=0.5`, `holdout_min_trades=10` (was a `≥0` sign test).
4. Sibling DIVERSITY: gate rejects unless `≥3` siblings, `≥2` distinct; PBO boundary is `>=`.
5. Numeric: `pbo` returns 1.0 on a NaN cell; `maxDrawdown` returns 1.0 on non-finite equity.
6. A non-positive DSR denominator fails SAFE (reject, never certify).

**META-RULE:** never trust your own honesty tests without an independent adversary. After any change
to the metric/gate, re-run an adversarial audit (the operator can launch `/workflows`-style fan-out;
you can also write a fresh skeptic pass) and prove closure end-to-end through the REAL code.

---

## 7. How to verify (do this first to confirm you're on solid ground)

```bash
vendor/bin/phpunit tests/Unit/Ai/Finance/StrategyLoop        # 21 tests — the trading harness
vendor/bin/phpunit tests/Unit/Ai/AutonomousEvolution tests/Feature/Loop   # 35 tests — the engine
php artisan atlas:finance:strategy-search --rounds=1 --candidates=600     # one round, ~4s, prints a verdict
php artisan atlas:engineering:knowledge docs-health --json   # both loop docs are 0-blocking (others pre-exist)
```

---

## 8. The two engines (use the right one)

- **`atlas:finance:strategy-search`** — fast, in-process, pure-code param search. **The workhorse.**
  Hundreds of candidates/sec; `N` (=`--candidates`) is the true per-round trial count fed to the DSR.
- **`atlas:finance:strategy-evolve` / `:strategy-loop`** — LLM-provider engines (a provider edits
  `strategy.json`). Slow (~minutes/scenario). Use ONLY for inventing new strategy *families*, not
  tuning numbers. (Default provider = `hermes_cli`, free; provider-agnostic — never hardcode one.)

---

## 9. Open work / next steps (prioritized, all optional — the loop is shippable as-is)

1. **Cross-round honesty**: per-round `N` corrects within-round multiple-testing; running thousands
   of rounds is a softer uncorrected selection. Add cumulative-N and/or require multi-round
   confirmation (a strategy must certify in ≥2 independent rounds AND re-pass the holdout) before a
   proposal is surfaced. (Mitigated today by the per-round sealed holdout + human review + a recorded
   caveat in each proposal.)
2. **Cross-engine divergence gate**: add a second backtest engine (freqtrade or nautilus_trader) and
   reject a candidate if the two engines' returns diverge beyond a tolerance (catches engine bugs /
   look-ahead). Today there is one engine.
3. **More markets / families**: fetch other history into the cache (`--symbol/--interval`, set
   `periodsPerYear`); add new look-ahead-safe strategy classes + widen the search space. The honesty
   gate is family-agnostic. **Only ever tighten the gate.**
4. **Effective sample size**: the DSR uses daily-return nObs; consider per-trade ("per-bet") nObs for
   sparse strategies (infra already emits `trade_returns` in the backtest `--json`).
5. **Optional paper-forward viewer** (read-only, paper keys only, NEVER an input to the metric).

---

## 10. Git / uncommitted state

The work is **uncommitted** (the operator commits when they choose; do not commit/push unless asked).
New + modified files this session (verify with `git status`):
- NEW code: `app/Services/Ai/Finance/StrategyLoop/**` (6 files) + `app/Console/Commands/AtlasFinanceStrategy{Search,Backtest,Evolve,Loop}Command.php`.
- NEW tests: `tests/Unit/Ai/Finance/StrategyLoop/**` (4 files).
- MODIFIED: `app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php` (strict_untracked).
- NEW docs: `docs/engineering-knowledge-base/atlas-evolution-loop-runtime.md`; MODIFIED
  `docs/engineering-knowledge-base/domains/finance/strategy-evolution-loop-charter.md`.
- DATA + runtime: `storage/atlas/finance/{fetch-btc-history.sh, market-data/BTCUSDT-1d.csv,
  search-ledger.jsonl, proposals/}`.

After any docs/code change, sync the read-models (per `CLAUDE.md`):
`atlas engineering knowledge sync --prune` and
`atlas engineering knowledge index-code --prune --workspace "$(pwd)"` (the `--workspace` flag is
required; the bare form is AWIS-blocked).

---

## 11. Operating rules for you, Codex (the AI taking over)

- Confirm the loop is alive and healthy first (§3, §7). If it died, just relaunch the §3 command.
- Keep it running for hours; quick health check ~every 10 min; never stop early; honest null is fine.
- NEVER add a money/broker/execution path. NEVER optimize win-rate. NEVER let a candidate touch
  costs/data/harness. NEVER weaken a gate without an independent adversarial re-audit.
- The loop ORCHESTRATES providers/code as muscle and is PROPOSE-ONLY. It never merges.
- When you finish a unit of work: run the §7 tests, and if you changed docs/code, sync the read-models.
- A certified proposal is a hypothesis for the operator to review — never a trade.
