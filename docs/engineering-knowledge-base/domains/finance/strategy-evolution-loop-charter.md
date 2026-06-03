# Finance · Strategy-Evolution Loop — Charter

> Status: canonical · Classification: **SENSITIVE** · Domain: `finance` · Flow: `finance.strategy_evolution`
> Consumes the proven Atlas Evolution Loop engine (`app/Services/Ai/AutonomousEvolution/*`) verbatim.

## What this is

The first transfer of the Atlas Evolution Loop out of engineering and into **finance/trading**.
The loop autonomously searches for a profitable trading strategy by evolving a small params
file (`strategy.json`) and scoring each candidate against **real market history** under a
frozen, anti-overfit honesty harness. It is the same engine that grinds code — the candidate
is a strategy instead of a patch, and the frozen judge is a backtest instead of a test suite.

## Hard invariants (non-negotiable)

- **Propose-only. Never-merge.** A certified strategy is a *reviewable proposal*, never an
  order. The loop has no path to a live broker and never will from this flow.
- **No real money. Ever.** No live keys, no order routing, no funded wallets, no smart
  contracts. Backtest + (optionally, later) paper-forward viewing only. A green paper
  dashboard is never the metric.
- **Research/backtest only** — inherits the finance domain charter (`market_execution_forbidden`,
  `analysis_review_only`, "Live trading bloqueado"). The 24h autonomy is scoped strictly to
  research; research ≠ execution.
- **Win-rate is forbidden as an objective.** It is the textbook trading fake-green. The
  objective is risk-adjusted return after costs, corrected for how hard the loop searched.
- **The data + harness are frozen.** The loop edits only `strategy.json`. It can tune WHAT it
  trades, never HOW it is scored. (Enforced by the frozen judge's scope/tamper guards.)
- **Honest null is a valid, expected outcome.** "No candidate survived the gates" is the
  system working — never something to paper over with a weaker bar.

## The honest metric (the operator's "pesos e processos")

Backtest on real Binance daily history (frozen, point-in-time). GATE then MAXIMIZE:

1. **Sanity GATE** (acceptance exit 0/1): enough realized trades, ≥3y of history, drawdown
   within bound, finite Sharpe. An inert baseline (`risk_pct=0` ⇒ 0 trades) fails the gate —
   so reverting any candidate to baseline goes RED, satisfying the loop's diff-earned anti-fake.
2. **MAXIMIZE** annualized out-of-sample Sharpe (after fees + slippage). Scraped as
   `ATLAS_METRIC=` from the backtest.
3. **Honesty gate** (post-selection, where the winner is certified or nulled):
   - **Deflated Sharpe Ratio ≥ 0.95**, with trials **N injected from `scenarios_explored`** —
     searching wider raises the bar (anti-Goodhart). *This is the #1 risk, closed here.*
   - **PBO (CSCV) ≤ 0.2** across the sibling strategies — the in-sample winner must not be an
     out-of-sample loser.
   - **Sealed holdout stays positive** — the most recent slice the loop's metric never saw.

The ">80% acerto" target is honestly reframed: not win-rate, but **positive risk-adjusted
out-of-sample after costs, DSR > 0.95, PBO < 0.2** — survival, not hit-rate.

## First market

CRYPTO — Binance spot **BTC/USDT daily**, long-only trend/breakout. The only market clearing
all no-money gates (free ~8y history, open API, no regulatory friction). Long-only on spot
defuses funding/borrow/survivorship so the loop proves the HONESTY HARNESS first, not a fragile
edge. Other markets/strategies are later slices, never a loosening of these invariants.

## Surfaces

- `atlas:finance:strategy-backtest` — the frozen acceptance scorer (one candidate → ATLAS_METRIC).
- `atlas:finance:strategy-evolve` — drives the proven engine for N scenarios, applies the
  honesty gate, persists a propose-only proposal (or an honest null) to
  `storage/atlas/finance/proposals/`.

## Provider

Provider-agnostic (hermes default, free). The loop never hardcodes a provider; the strategy
search is judged solely by the frozen backtest, not by which engine proposed the candidate.
