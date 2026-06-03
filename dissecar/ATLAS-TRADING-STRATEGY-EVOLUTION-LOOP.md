# Atlas Trading — Strategy-Evolution Loop · Blueprint + Rigorous Implementation Workflow

Status: **design, research-grounded (sources cited), propose-only / no-real-money.** This is the
finance-domain instantiation of the proven Atlas Evolution Loop. It reuses the loop engine
**verbatim** — no rebuild of the loop layer. Built 2026-06-03 from a 5-agent web-research workflow
(`wf_fda0264d`) + the existing Atlas Finance domain.

> The operator's #1 value is honesty over false-green. The single most important sentence in this
> doc: **a "> 80% win rate" target is the wrong, dangerous objective and is forbidden** — it is the
> textbook trading fake-green (90% right and still blow up). The honest target is **risk-adjusted,
> out-of-sample, after-cost** performance. Making the loop "believe TRUE" (operator's words) =
> the anti-overfit gates below, which are the literal isomorph of the loop's proven anti-fake guard.

## 0. The home: it is NOT built from scratch

Atlas already has a first-class **Finance domain** (`app/Services/Ai/Finance/`, delivered 2026-05-18)
with exactly the right bones, and `trading` is already classified **SENSITIVE** (live trading
hard-blocked). This design **registers under existing Atlas law**, it does not invent new constraints:

- `FinancePaperTradingSimulationService` — the no-money paper path (paper-only by contract).
- flow `finance.backtest_plan` — "hypothesis, dataset, **bias controls**, methodology review".
- gates `methodology_review` + **`market_execution_forbidden`** + `analysis_review_only`; `AtlasFinanceSafetyPolicy` classifies execution intent (buy/sell/rebalance/broker/transfer, EN+PT).
- Charter: **analysis/review-only; never place/modify/cancel/prepare market orders; never publish auto investment advice.**
- `atlas-domain-runtime-creation-gate.md`: trading = SENSITIVE (safety/sovereignty block + licensed human reviewer before promotion); "Live trading bloqueado" is a global invariant.

**The trading loop is a new flow `finance.strategy_evolution`** consuming the loop engine, living under
those gates. ONE governed extension is required + must be explicit: the domain charter says "background
execution disabled / low autonomy" — the loop's 24h autonomy is scoped **strictly to RESEARCH/BACKTEST**
(methodology-gated, propose-only, paper); it **never** executes, never auto-advises. Research ≠ execution.

## 1. The market (verified, June 2026)

> **The universe this picks FROM = the canonical markets catalog** `docs/engineering-knowledge-base/domains/finance/tradable-markets-universe.md`
> (13 families → ~118 categories, with a re-runnable completeness audit workflow `finance-markets-universe-audit`).
> That catalog is the "WHERE" (the exhaustive market reference); THIS doc is the "HOW" (the strategy-evolution engine).
> They are the two halves of the Finance/Trading area and connect at the loop's **Discovery/Sources stage (§5.1)**,
> which RANKS candidates from that catalog. This blueprint picks ONE entry (crypto) as the honest first slice — the
> loop later widens to the catalog's other families on the SAME engine (§6 Phase 8). Repo = canon for both; the
> Obsidian `09-financas/` area is the navigable projection.

**CRYPTO — centralized spot + USDT-perps on one top venue (Binance primary), starting with ONE liquid
spot pair (BTC/USDT).** It is the only market clearing all five no-money gates at once: open 24/7 REST+WS
API (no gatekeeping); FREE ~9yr bulk history (`data.binance.vision`, with CHECKSUMs); a free real-money-free
sandbox (testnets) **kept separate from the history**; near-zero account/regulatory friction (global signup,
no PDT, no hours); a native objective edge set (funding rate, spot-perp basis, cross-exchange).

- **First strategy family (after the harness is proven):** delta-neutral **funding-rate capture** (long spot
  / short perp). Second: spot-perp basis + cross-exchange stat-arb pairs. These are **carry / mean-reversion**
  edges — objective, hard to Goodhart-game — exactly what the frozen judge needs.
- **Rejected/deferred:** US equities (improving — PDT $25k minimum eliminated, SEC-approved, effective 2026-06-04,
  but broker rollout to Oct 2027; free data is IEX-only / non-representative) = **second domain**, stat-arb ports
  directly. FX **disqualified** for a US-rules bot (NFA hedging ban + FIFO Rule 2-43b break stat-arb/grid). Index
  futures = best sims but paid data + not 24/7 + funded account → later.
- **Honest magnitude (NOT a promise):** funding capture ≈ **5–15% APY** after fees in typical regimes, 20–40%
  only in high-vol windows, >100% only in short-lived extremes, **only if actively managed** — a rented carry
  yield that can invert to a cost after one liquidation cascade. Order-of-magnitude from practitioner sources;
  **must be re-derived by Atlas's own anti-overfit backtest, never trusted.**

## 2. The honest frozen metric (the "pesos e processos")

The trading judge is a faithful **superset of the code judge's exact `metric_kind` contract**
(`AtlasEvolutionFrozenJudge` GATE/MINIMIZE/MAXIMIZE). The acceptance command runs the backtest in the
scenario workspace, exits 0 only if every GATE passes, and prints **one scalar** the existing
`metric_pattern` regex scrapes: `ATLAS_METRIC=<deflated_oos_sharpe>`.

```
GATE( min_trades>=N  AND  regime_coverage(>=3yr: crash + chop + bull)  AND  max_drawdown<=X
      AND  PBO<=0.2  AND  DSR>0.95  AND  cross_engine_divergence<=tol )
THEN MAXIMIZE( deflated_OOS_Sharpe )      ;  ties -> simplest strategy (fewest params)
```

Four non-negotiable properties (all must hold): **(a) risk-adjusted** (a 90%-win-rate blow-up scores
badly — one fat left tail craters the ratio); **(b) out-of-sample** (rolling walk-forward / CPCV windows
the optimizer never saw + one sealed holdout opened once on the winner); **(c) after realistic costs**
(taker fee + ~2× historical spread + 100–200ms latency + funding/borrow, with a **2×-cost stress** that
must stay positive); **(d) drawdown-controlled** (a Calmar/max-DD GATE *before* the MAXIMIZE).
**Win-rate is forbidden as an objective anywhere in the judge.**

**Deflated Sharpe (Bailey–López de Prado)** is what makes the loop's N-parallel search honest: under true
Sharpe=0 the expected MAX Sharpe across N trials grows ~√(2 ln N), so the winner is compared to that noise
threshold SR0(N) — the metric gets **harder as the search widens** (the opposite of a gameable number).

## 3. The anti-overfit gates ≡ the loop's proven anti-fake (the isomorphism)

This is why the loop "believes TRUE". Each gate maps to a mechanism already proven on code:

| # | Trading gate | ≡ code-loop mechanism |
|---|---|---|
| 1 | **Point-in-time data clock** — no datum after decision-time t readable; fills at t+1 open | judge owns the data feed (RE-PROOF; loop can't reach around it) |
| 2 | **Survivorship-free universe** (delisted included; never today's roster) | trivially met by the first slice (continuously-listed majors) |
| 3 | **Purging + embargo** on every split (López de Prado) | frozen test integrity |
| 4 | **Costs in the metric, net by construction** + 2× stress | metric is authoritative, not self-reported |
| 5 | **√ market-impact + capacity cap** (≤1–5% of bar volume) | scope realism |
| 6 | **CPCV across regimes → score a LOWER quantile** (5th-pct/median), not the mean | rejects single-path luck |
| 7 | **Deflated Sharpe fed the TRUE N** = the explorer's `scenarios_explored` + MinTRL | anti-Goodhart (see §6 wiring gap) |
| 8 | **Cross-engine agreement** (freqtrade ∧ nautilus_trader within tol) | one number is never trusted |
| 9 | **Sealed one-shot holdout + paper-forward** (winner only, to PRINT not select; burned if peeked) | **≡ `diffEarned()` on fresh ground** |
| 10 | **Fail-closed + propose-only** (un-computable ⇒ REJECT; `merged_to_main:false`) | **≡ null=reject + never-merge** |

The keystone isomorphism: **PBO (CSCV)** is the literal twin of `diffEarned()`'s revert-must-fail —
IS-selection IS the "green", OOS-re-rank IS the "revert", and an edge that vanishes OOS is rejected the
same way a green-on-revert candidate is. `revert_recheck:true` stays on.

## 4. The no-money stack (free, verified, license-noted)

| Tool | Role | Free / caveat |
|---|---|---|
| **CCXT** (MIT) | data adapter over 100+ venues (public OHLCV/orderbook/funding, no key) | free; history gaps + shallow depth + rate-limits → cache aggressively |
| **data.binance.vision** | the FROZEN-METRIC input — bulk klines back to 2017 + CHECKSUMs | free, no account; bar-level only; **REAL mainnet history (the only valid backtest input)** |
| **freqtrade** (GPL) | fast scenario engine + crypto anti-overfit toolkit (**lookahead-analysis**, walk-forward, fees/slippage) | free; GPL → **drive as a separate process** (the LoopExecutionDriver pattern already shells out) |
| **nautilus_trader** (LGPL) | realism/confirmation engine (L2 fills, latency, costs) — the **2nd** cross-engine | free; a 2026 paper caught it double-charging fees → cross-check, don't trust blindly (that's *why* two engines) |
| **QuantStats** | engine-agnostic Sharpe/Sortino/Calmar from any returns series | free; DSR/PBO implemented on top (public formulas, small numpy/scipy) |
| **Binance/Bybit testnet** (faucet) | LIVE PAPER plumbing only — propose→paper handoff | free; **testnet PnL is NEVER backtest input** (thin/synthetic fills) — conflating it with the history is itself a fake-green |

## 5. Loop instantiation — verbatim reuse (no loop rebuild)

1. **Discovery** — rank (regime × asset × strategy-family) instead of code files (same `AtlasLoopTargetDiscoveryService` shape).
2. **Hypothesis** — `AtlasEvolutionTaskGenerator`'s RED-guard ports directly: a trading task is kept ONLY if its frozen acceptance is genuinely RED vs a null/baseline (buy-and-hold / zero-position) on IS — an edge already present before search = no real work, discarded like a born-green test. **Strategy = a small declarative params file** (entry/exit/sizing/risk) = the candidate "diff".
3. **Scenario exploration** — `AtlasEvolutionScenarioExplorer.explore()` UNCHANGED: N isolated git-baselined workspaces, deep search; `scenarios_explored` becomes the DSR trial-count N.
4. **Frozen judge** — `score()` UNCHANGED in mechanism: data layer = point-in-time cache (judge owns the clock); acceptance command = `run_backtest.py --params <f> --split oos` → `ATLAS_METRIC=<deflated_oos_sharpe>`; `metric_kind=MAXIMIZE`; `allowed_globs` = params file only; `frozen_globs` = data cache + harness + cost model (loop can NEVER edit the judge/data/costs).
5. **Anti-fake** — the acceptance command internally runs PBO/CSCV + purged walk-forward + cross-engine confirmation, exiting RED if the IS edge doesn't survive OOS — the literal isomorph of revert-must-fail.
6. **Winner** — `pickWinner()` UNCHANGED: strictly-better deflated-OOS-Sharpe; ties → smallest params (existing simplicity criterion).
7. **Loop-back + 24h supervisor** — `AtlasLoopBackService` + `AtlasLoopCampaignSupervisor` (budget, kill/pause, leases, parallel pool, dated ledger) UNCHANGED.
8. **Propose-only** — `AtlasEvolutionLoopRunner` UNCHANGED: `merged_to_main:false`; emits a certified backtested strategy + params + hash + deflated metric + N + holdout result. Optional paper-forward to TESTNET (faucet) as extra evidence — still zero real money, human decides (and per "Live trading bloqueado", live never happens).

## 6. The rigorous implementation workflow (9 phases, dependency-ordered, each buildable+verifiable)

- **Phase 0 — Register under the gate (no code).** place-feature + session-bootstrap; read the SENSITIVE-domain gate; freeze the invariant doc (propose-only, no broker live-keys, testnet/paper only). Verify: sensitive-domain checklist satisfied on paper.
- **Phase 1 — Point-in-time data layer (the judge's clock).** CCXT + binance-public-data → local cache keyed by (symbol, interval, as-of-t); a "bars up to t" reader that physically can't return t+1. Verify: a look-ahead unit test (request at t never returns t+1) + a CHECKSUM test. *(Small, no heavy deps — the concrete first build.)*
- **Phase 2 — Backtest driver as acceptance command.** `run_backtest.py`: load params → freqtrade IS/OOS over the PIT cache with fees+2×spread+slippage → `lookahead-analysis` → QuantStats OOS Sharpe → print `ATLAS_METRIC=` exit 0 only if clean. Verify: a known-overfit vs known-robust params pair scores robust higher; lookahead failure ⇒ non-zero exit.
- **Phase 3 — Deflated Sharpe + PBO + walk-forward inside the command.** purged-embargoed walk-forward + CPCV + DSR + PBO (small numpy/scipy). GATE(min_trades, regime≥3yr, max_DD, PBO≤0.2, DSR>0.95) then print deflated Sharpe; un-computable ⇒ non-zero (fail-closed). **THE ONE WIRING CHANGE:** the DSR trial-count N = the loop's real fan-out (`scenarios_explored`), but the judge scores ONE workspace blind to siblings → **inject N into the acceptance command as a per-scenario constraint/env from the explorer** (smallest route, preserves judge purity). Verify: N candidates over pure noise — deflated winner does NOT pass when N is fed truthfully, passes when N is faked (the regression proving the wire matters).
- **Phase 4 — The anti-fake isomorph (keystone).** `revert_recheck:true`; acceptance runs the PBO/CSCV re-rank + nautilus cross-engine, RED if IS edge vanishes OOS or divergence>tol. Verify (by direct analogy to `AtlasEvolutionAntiFakeGuardTest`): an IS-curve-fit whose edge vanishes OOS is REJECTED; genuine multi-regime OOS survival passes. **This proves the loop "believes TRUE".**
- **Phase 5 — Drive the UNCHANGED engine on one trading task (first-slice green run).** Author one metric-shaped trading task + `php artisan atlas:loop:evolve --task-file=<task>.json --scenarios=N`. Deliverable: a green `atlas.evolution.proposal.v1` with the deflated metric + N + the params diff; `merged_to_main:false`. *(The trading "smoke fixture".)*
- **Phase 6 — Sealed holdout + optional testnet paper-forward.** access-gated holdout tail + one-shot flag scored only on the winner (PRINT, never select; burn if peeked); optional testnet forward (faucet). Verify: an architecture test that the search path CANNOT read the holdout (contamination guard); assert no broker live-keys.
- **Phase 7 — Wrap in the 24h campaign + sync knowledge.** point `AtlasLoopCampaignSupervisor` at the trading queue UNCHANGED; `atlas engineering knowledge sync --prune` + `index-code --prune --workspace "$(pwd)"`. Verify: kill-switch halts; ledger append-only; restart resumes via leases.
- **Phase 8 (later, out of the first slice) — extend families/domains.** funding-capture (perp+short, funding/borrow/liquidation in the cost+DD gates) → equities lane (vectorbt/backtesting.py, Alpaca paper, IEX labeled non-representative) → CME micro futures. Each is a new task family on the SAME engine — never a loop rebuild. No forbidden vocab in identifiers.

## 7. The honest answer to ">80%"

Do **not** target/report a >80% win rate for a directional strategy — it teaches the loop martingale /
tight-TP-wide-stop structures that maximize hit-rate while accumulating the exact left tail that ruins
accounts. The honest, **more-demanding** reframe is consistency across independent OOS windows:
**"positive risk-adjusted out-of-sample performance in >80% of independent walk-forward / CPCV windows,
after realistic costs, Deflated Sharpe > 0.95, PBO < 0.2."** It is gameable-resistant precisely because a
single tail loss flips windows negative. Genuine high hit-rate lives ONLY in arbitrage / market-making,
where the edge is **execution infrastructure** (co-location/latency/fees), **not a backtestable rule** —
a bar backtest is structurally incapable of proving it, so the loop must never claim it from a backtest.

## 8. Honest residual (no false-green)

1. **Research/backtest/paper only.** Not investment advice, not a profitable strategy, not a P&L promise. Never auto-trades; no real money is ever wired ("Live trading bloqueado").
2. The design does **not** prove any profitable edge exists. The honest output may be **"no candidate survives the gates"** — that null result is the system *working*.
3. DSR+PBO are necessary, not sufficient: a clean backtest is still wrong if the DATA has survivorship/look-ahead/wash-trading. The data-cleaning layer is a separate fake-green the metric can't catch.
4. **THE #1 IMPLEMENTATION RISK (found by reading the code):** the judge is blind to sibling count → the DSR N must be injected from `scenarios_explored`. N=1 while thousands run silently defeats multiple-testing protection. Explicit Phase-3 deliverable, not a free reuse.
5. **Testnet ≠ backtest; paper ≠ live.** Testnet fills are non-representative (plumbing only); paper-forward still has an un-crossed sim→live gap. Market-making/HFT realism is out of scope and unclaimable here.
6. Cross-engine cost-model bugs are real and silent (nautilus double-charged fees); the agreement gate mitigates, doesn't eliminate — high-turnover candidates carry residual uncertainty.
7. **Holdout contamination** is an engineering property, not a metric guarantee — must be access-gated + one-shot + burn-on-peek, exactly as the code loop enforces never-merge separately from the judge.
8. Free-data terms / testnet / fees / region friction CHANGE — verified June 2026, re-verify at build time. Equities lane gated on PDT rollout + uses non-representative free data → deliberately second.

## 9. The concrete next build

**Phase 0 + Phase 1** (register the invariant + the point-in-time data layer with the look-ahead test) are
small, dependency-light, and the honest cold start — they build the *judge's clock* (the foundation every
gate stands on) before any backtest engine. The deliverable of the whole first slice is **not a profitable
strategy** — it is a green run of the **unchanged** loop runner over one trading task whose acceptance is a
real walk-forward backtest that **rejects an overfit candidate**: the trading equivalent of the proven smoke fixture.
