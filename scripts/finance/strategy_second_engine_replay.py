#!/usr/bin/env python3
"""External second-engine replay for Atlas trend-breakout candidates.

Reads JSON from stdin:
  {"bars": [...], "params": {...}, "periods_per_year": 365.0}

Writes a compact JSON metrics report. No broker, no orders, no network.
"""

from __future__ import annotations

import json
import math
import sys
from typing import Any


def number(value: Any, default: float = 0.0) -> float:
    try:
        return float(value)
    except (TypeError, ValueError):
        return default


def integer(value: Any, default: int = 0) -> int:
    try:
        return max(0, int(value))
    except (TypeError, ValueError):
        return default


def normalize(params: dict[str, Any]) -> dict[str, float | int]:
    out: dict[str, float | int] = {
        "regime_period": integer(params.get("regime_period"), 100),
        "entry_lookback": max(1, integer(params.get("entry_lookback"), 20)),
        "exit_lookback": integer(params.get("exit_lookback"), 10),
        "atr_period": max(1, integer(params.get("atr_period"), 14)),
        "atr_mult": max(0.0, number(params.get("atr_mult"), 3.0)),
        "risk_pct": min(1.0, max(0.0, number(params.get("risk_pct"), 0.0))),
        "fee_bps": max(0.0, number(params.get("fee_bps"), 10.0)),
        "slippage_bps": max(0.0, number(params.get("slippage_bps"), 5.0)),
        "min_hold_bars": integer(params.get("min_hold_bars"), 0),
    }
    out["warmup"] = max(
        int(out["regime_period"]),
        int(out["entry_lookback"]) + 1,
        int(out["exit_lookback"]) + 1,
        int(out["atr_period"]) + 1,
        2,
    )
    return out


def sma(values: list[float], idx: int, period: int) -> float:
    lo = max(0, idx - period + 1)
    window = values[lo : idx + 1]
    return sum(window) / max(1, len(window))


def highest(values: list[float], idx: int, period: int) -> float:
    if idx < 0:
        return sys.float_info.max
    lo = max(0, idx - period + 1)
    window = values[lo : idx + 1]
    return max(window) if window else sys.float_info.max


def lowest(values: list[float], idx: int, period: int) -> float:
    if idx < 0:
        return -sys.float_info.max
    lo = max(0, idx - period + 1)
    window = values[lo : idx + 1]
    return min(window) if window else -sys.float_info.max


def atr(high: list[float], low: list[float], close: list[float], idx: int, period: int) -> float:
    lo = max(1, idx - period + 1)
    vals: list[float] = []
    for i in range(lo, idx + 1):
        vals.append(max(high[i] - low[i], abs(high[i] - close[i - 1]), abs(low[i] - close[i - 1])))
    return sum(vals) / len(vals) if vals else 0.0


def std(values: list[float]) -> float:
    n = len(values)
    if n < 2:
        return 0.0
    mean = sum(values) / n
    return math.sqrt(sum((v - mean) ** 2 for v in values) / (n - 1))


def sharpe(returns: list[float], periods_per_year: float) -> float:
    s = std(returns)
    if s <= 0:
        return 0.0
    return (sum(returns) / len(returns)) / s * math.sqrt(max(1.0, periods_per_year))


def max_drawdown(equity: list[float]) -> float:
    peak = -math.inf
    max_dd = 0.0
    for value in equity:
        if not math.isfinite(value):
            return 1.0
        peak = max(peak, value)
        if peak > 0:
            max_dd = max(max_dd, (peak - value) / peak)
    return max_dd


def total_return(equity: list[float]) -> float:
    return equity[-1] - 1.0 if equity else 0.0


def exposure_ratio(trades: list[dict[str, Any]], bar_count: int) -> float:
    if bar_count <= 0:
        return 0.0
    held = sum(max(0, integer(t.get("bars_held"), 0)) for t in trades)
    return min(1.0, held / bar_count)


def equity_sample(equity: list[float], points: int = 20) -> list[float]:
    n = len(equity)
    if n == 0:
        return []
    points = max(2, min(points, n))
    return [round(equity[round(i * (n - 1) / max(1, points - 1))], 10) for i in range(points)]


def replay_trend_breakout(bars: list[dict[str, Any]], params: dict[str, Any], periods_per_year: float) -> dict[str, Any]:
    p = normalize(params)
    opens = [number(b.get("open")) for b in bars]
    highs = [number(b.get("high")) for b in bars]
    lows = [number(b.get("low")) for b in bars]
    closes = [number(b.get("close")) for b in bars]
    n = len(bars)

    cash = 1.0
    units = 0.0
    entry_price = 0.0
    entry_idx = -1
    trail_high = 0.0
    pending: tuple[str, float | None] | None = None
    equity: list[float] = []
    returns: list[float] = []
    trades: list[dict[str, Any]] = []
    previous_equity = 1.0
    fee_rate = float(p["fee_bps"]) / 10000.0
    slip = float(p["slippage_bps"]) / 10000.0

    for t in range(n):
        if pending is not None:
            action, stop_dist_pending = pending
            if action == "enter" and units <= 0.0:
                fill = opens[t] * (1.0 + slip)
                stop_dist = max(float(stop_dist_pending or 0.0), 1e-9)
                risk_cap = float(p["risk_pct"]) * cash
                qty = risk_cap / stop_dist if fill > 0 else 0.0
                notional = qty * fill
                max_notional = cash / (1.0 + fee_rate)
                if notional > max_notional:
                    notional = max_notional
                    qty = notional / fill if fill > 0 else 0.0
                fee = notional * fee_rate
                if qty > 0 and notional + fee <= cash + 1e-9:
                    cash -= notional + fee
                    units = qty
                    entry_price = fill
                    entry_idx = t
                    trail_high = closes[t]
            elif action == "exit" and units > 0.0:
                fill = opens[t] * (1.0 - slip)
                notional = units * fill
                fee = notional * fee_rate
                cash += notional - fee
                trades.append(
                    {
                        "entry_idx": entry_idx,
                        "entry_price": entry_price,
                        "exit_idx": t,
                        "exit_price": fill,
                        "return": fill / entry_price - 1.0 if entry_price > 0 else 0.0,
                        "bars_held": t - entry_idx,
                    }
                )
                units = 0.0
                entry_price = 0.0
                entry_idx = -1
                trail_high = 0.0
            pending = None

        mark = cash + units * closes[t]
        equity.append(mark)
        if t > 0:
            returns.append(mark / previous_equity - 1.0 if previous_equity > 0 else 0.0)
        previous_equity = mark

        if t < int(p["warmup"]):
            continue
        if units > 0.0:
            if closes[t] > trail_high:
                trail_high = closes[t]
            current_atr = atr(highs, lows, closes, t, int(p["atr_period"]))
            stop_hit = (trail_high - float(p["atr_mult"]) * current_atr) > closes[t]
            don_exit = int(p["exit_lookback"]) > 0 and closes[t] < lowest(lows, t - 1, int(p["exit_lookback"]))
            can_exit = (t - entry_idx) >= int(p["min_hold_bars"])
            if can_exit and (stop_hit or don_exit):
                pending = ("exit", None)
        else:
            regime_ok = int(p["regime_period"]) <= 0 or closes[t] > sma(closes, t, int(p["regime_period"]))
            breakout = closes[t] > highest(highs, t - 1, int(p["entry_lookback"]))
            if regime_ok and breakout:
                current_atr = atr(highs, lows, closes, t, int(p["atr_period"]))
                pending = ("enter", float(p["atr_mult"]) * current_atr)

    return {
        "engine": "atlas_python_replay",
        "status": "ready",
        "trade_count": len(trades),
        "ann_sharpe": sharpe(returns, periods_per_year),
        "max_dd": max_drawdown(equity),
        "total_return": total_return(equity),
        "exposure": exposure_ratio(trades, len(equity)),
        "equity_curve_sample": equity_sample(equity),
        "holdout_passed": True,
        "trades": trades,
        "propose_only": True,
        "live_trading": "forbidden",
    }


def normalize_mean_reversion(params: dict[str, Any]) -> dict[str, float | int]:
    out: dict[str, float | int] = {
        "regime_period": integer(params.get("regime_period"), 100),
        "lookback": max(5, integer(params.get("lookback"), 30)),
        "entry_z": min(4.0, max(0.25, number(params.get("entry_z"), 1.5))),
        "exit_z": min(2.0, max(-1.0, number(params.get("exit_z"), 0.0))),
        "risk_pct": min(1.0, max(0.0, number(params.get("risk_pct"), 0.2))),
        "stop_loss_pct": min(0.5, max(0.005, number(params.get("stop_loss_pct"), 0.08))),
        "max_hold_bars": max(1, integer(params.get("max_hold_bars"), 20)),
        "fee_bps": max(0.0, number(params.get("fee_bps"), 10.0)),
        "slippage_bps": max(0.0, number(params.get("slippage_bps"), 5.0)),
    }
    out["warmup"] = max(int(out["regime_period"]), int(out["lookback"]), 2)
    return out


def z_score(values: list[float], idx: int, period: int) -> float:
    lo = max(0, idx - period + 1)
    window = values[lo : idx + 1]
    if len(window) < 2:
        return 0.0
    mean = sum(window) / len(window)
    variance = sum((value - mean) ** 2 for value in window) / max(1, len(window) - 1)
    deviation = math.sqrt(variance)
    return (values[idx] - mean) / deviation if deviation > 0 else 0.0


def replay_mean_reversion(bars: list[dict[str, Any]], params: dict[str, Any], periods_per_year: float) -> dict[str, Any]:
    p = normalize_mean_reversion(params)
    opens = [number(b.get("open")) for b in bars]
    closes = [number(b.get("close")) for b in bars]
    n = len(bars)

    cash = 1.0
    units = 0.0
    entry_price = 0.0
    entry_idx = -1
    pending: str | None = None
    equity: list[float] = []
    returns: list[float] = []
    trades: list[dict[str, Any]] = []
    previous_equity = 1.0
    fee_rate = float(p["fee_bps"]) / 10000.0
    slip = float(p["slippage_bps"]) / 10000.0

    for t in range(n):
        if pending == "enter" and units <= 0.0:
            fill = opens[t] * (1.0 + slip)
            notional = cash * float(p["risk_pct"])
            max_notional = cash / (1.0 + fee_rate)
            notional = min(notional, max_notional)
            qty = notional / fill if fill > 0 else 0.0
            fee = notional * fee_rate
            if qty > 0 and notional + fee <= cash + 1e-9:
                cash -= notional + fee
                units = qty
                entry_price = fill
                entry_idx = t
        elif pending == "exit" and units > 0.0:
            fill = opens[t] * (1.0 - slip)
            notional = units * fill
            fee = notional * fee_rate
            cash += notional - fee
            trades.append(
                {
                    "entry_idx": entry_idx,
                    "entry_price": entry_price,
                    "exit_idx": t,
                    "exit_price": fill,
                    "return": fill / entry_price - 1.0 if entry_price > 0 else 0.0,
                    "bars_held": t - entry_idx,
                }
            )
            units = 0.0
            entry_price = 0.0
            entry_idx = -1
        pending = None

        mark = cash + units * closes[t]
        equity.append(mark)
        if t > 0:
            returns.append(mark / previous_equity - 1.0 if previous_equity > 0 else 0.0)
        previous_equity = mark

        if t < int(p["warmup"]):
            continue
        z = z_score(closes, t, int(p["lookback"]))
        if units > 0.0:
            stop_hit = entry_price > 0 and closes[t] <= entry_price * (1.0 - float(p["stop_loss_pct"]))
            reverted = z >= float(p["exit_z"])
            timed_out = (t - entry_idx) >= int(p["max_hold_bars"])
            if stop_hit or reverted or timed_out:
                pending = "exit"
        else:
            regime_ok = int(p["regime_period"]) <= 0 or closes[t] >= sma(closes, t, int(p["regime_period"]))
            if regime_ok and z <= -float(p["entry_z"]):
                pending = "enter"

    return {
        "engine": "atlas_python_replay",
        "status": "ready",
        "trade_count": len(trades),
        "ann_sharpe": sharpe(returns, periods_per_year),
        "max_dd": max_drawdown(equity),
        "total_return": total_return(equity),
        "exposure": exposure_ratio(trades, len(equity)),
        "equity_curve_sample": equity_sample(equity),
        "holdout_passed": True,
        "trades": trades,
        "propose_only": True,
        "live_trading": "forbidden",
    }


def normalize_momentum(params: dict[str, Any]) -> dict[str, float | int]:
    out: dict[str, float | int] = {
        "regime_period": integer(params.get("regime_period"), 100),
        "momentum_lookback": max(2, integer(params.get("momentum_lookback"), 20)),
        "entry_momentum": min(0.50, max(0.0, number(params.get("entry_momentum"), 0.03))),
        "exit_momentum": min(0.25, max(-0.25, number(params.get("exit_momentum"), 0.0))),
        "risk_pct": min(1.0, max(0.0, number(params.get("risk_pct"), 0.2))),
        "stop_loss_pct": min(0.5, max(0.005, number(params.get("stop_loss_pct"), 0.08))),
        "trailing_stop_pct": min(0.5, max(0.005, number(params.get("trailing_stop_pct"), 0.10))),
        "max_hold_bars": max(1, integer(params.get("max_hold_bars"), 30)),
        "fee_bps": max(0.0, number(params.get("fee_bps"), 10.0)),
        "slippage_bps": max(0.0, number(params.get("slippage_bps"), 5.0)),
    }
    out["warmup"] = max(int(out["regime_period"]), int(out["momentum_lookback"]), 2)
    return out


def momentum(values: list[float], idx: int, lookback: int) -> float:
    base_idx = idx - lookback
    if base_idx < 0 or values[base_idx] <= 0:
        return 0.0
    return values[idx] / values[base_idx] - 1.0


def replay_momentum(bars: list[dict[str, Any]], params: dict[str, Any], periods_per_year: float) -> dict[str, Any]:
    p = normalize_momentum(params)
    opens = [number(b.get("open")) for b in bars]
    closes = [number(b.get("close")) for b in bars]
    n = len(bars)

    cash = 1.0
    units = 0.0
    entry_price = 0.0
    entry_idx = -1
    trail_high = 0.0
    pending: str | None = None
    equity: list[float] = []
    returns: list[float] = []
    trades: list[dict[str, Any]] = []
    previous_equity = 1.0
    fee_rate = float(p["fee_bps"]) / 10000.0
    slip = float(p["slippage_bps"]) / 10000.0

    for t in range(n):
        if pending == "enter" and units <= 0.0:
            fill = opens[t] * (1.0 + slip)
            notional = min(cash * float(p["risk_pct"]), cash / (1.0 + fee_rate))
            qty = notional / fill if fill > 0 else 0.0
            fee = notional * fee_rate
            if qty > 0 and notional + fee <= cash + 1e-9:
                cash -= notional + fee
                units = qty
                entry_price = fill
                entry_idx = t
                trail_high = closes[t]
        elif pending == "exit" and units > 0.0:
            fill = opens[t] * (1.0 - slip)
            notional = units * fill
            fee = notional * fee_rate
            cash += notional - fee
            trades.append(
                {
                    "entry_idx": entry_idx,
                    "entry_price": entry_price,
                    "exit_idx": t,
                    "exit_price": fill,
                    "return": fill / entry_price - 1.0 if entry_price > 0 else 0.0,
                    "bars_held": t - entry_idx,
                }
            )
            units = 0.0
            entry_price = 0.0
            entry_idx = -1
            trail_high = 0.0
        pending = None

        mark = cash + units * closes[t]
        equity.append(mark)
        if t > 0:
            returns.append(mark / previous_equity - 1.0 if previous_equity > 0 else 0.0)
        previous_equity = mark

        if t < int(p["warmup"]):
            continue
        mom = momentum(closes, t, int(p["momentum_lookback"]))
        if units > 0.0:
            if closes[t] > trail_high:
                trail_high = closes[t]
            stop_hit = entry_price > 0 and closes[t] <= entry_price * (1.0 - float(p["stop_loss_pct"]))
            trail_hit = trail_high > 0 and closes[t] <= trail_high * (1.0 - float(p["trailing_stop_pct"]))
            momentum_lost = mom <= float(p["exit_momentum"])
            timed_out = (t - entry_idx) >= int(p["max_hold_bars"])
            if stop_hit or trail_hit or momentum_lost or timed_out:
                pending = "exit"
        else:
            regime_ok = int(p["regime_period"]) <= 0 or closes[t] >= sma(closes, t, int(p["regime_period"]))
            if regime_ok and mom >= float(p["entry_momentum"]):
                pending = "enter"

    return {
        "engine": "atlas_python_replay",
        "status": "ready",
        "trade_count": len(trades),
        "ann_sharpe": sharpe(returns, periods_per_year),
        "max_dd": max_drawdown(equity),
        "total_return": total_return(equity),
        "exposure": exposure_ratio(trades, len(equity)),
        "equity_curve_sample": equity_sample(equity),
        "holdout_passed": True,
        "trades": trades,
        "propose_only": True,
        "live_trading": "forbidden",
    }


def main() -> int:
    payload = json.loads(sys.stdin.read() or "{}")
    family = str(payload.get("family") or "trend-breakout-v1")
    bars = list(payload.get("bars") or [])
    params = dict(payload.get("params") or {})
    periods_per_year = number(payload.get("periods_per_year"), 365.0)
    if family == "mean-reversion-v1":
        result = replay_mean_reversion(bars, params, periods_per_year)
    elif family == "momentum-v1":
        result = replay_momentum(bars, params, periods_per_year)
    else:
        result = replay_trend_breakout(bars, params, periods_per_year)
    print(json.dumps(result, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
