"""The honest finance scorer, computed in numpy.

This is the REAL engine the runtime_language_boundary canon + the operator thesis
("data math belongs in Python; never hand-roll a data engine in the PHP kernel")
mandate. It is a FAITHFUL numpy port of the (now-removed) hand-rolled PHP engine in
app/Services/Ai/Finance/StrategyLoop/Metrics/HonestMetrics.php — same formulas, same
guard thresholds, same fail-safe semantics — so the swap is behaviour-preserving to
machine precision (the equivalence tests pin old-PHP-vs-new-Python within 1e-9).

The SENSITIVE, load-bearing piece is the Deflated Sharpe Ratio with the Lo (2002)
analytic variance FLOOR on the cross-trial Sharpe variance. That floor is a prior
audit fix: it makes the trial count N keep biting even when the passing siblings
cluster (the loop's natural convergent/singleton state) so SR0 cannot collapse and
let a marginal no-edge winner certify after a wide search. It is ported verbatim:
    varFloor = (1 + 0.5*SR^2) / max(1, nObs)
    effVar   = max(empirical_sibling_variance, varFloor)

numpy ONLY. The normal CDF (via erf, Abramowitz-Stegun 7.1.26) and the inverse
normal CDF (Acklam's rational approximation) are the EXACT closed forms the PHP
used — NOT scipy, NOT the stats_engine's different A-S 26.2.17 approximation — so
the DSR, which flows through normalCdf, matches the prior PHP output bit-for-bit
within rounding. No scipy/statsmodels/sklearn dependency is needed or taken.

PBO is the CSCV combinatorial-partition overfitting estimator: split time into S
near-equal blocks, and over every symmetric IS/OOS partition ask whether the
IS-best strategy lands in the bottom half OOS. The fraction that do is the PBO.
The combinatorial enumeration and rank logic are a deterministic port (no RNG), so
PBO is exactly reproducible — equivalence to the old PHP is bitwise, not statistical.
"""

from __future__ import annotations

import math
from itertools import combinations as _iter_combinations
from typing import Any

import numpy as np

# Euler–Mascheroni constant, the exact literal the PHP used.
EULER_MASCHERONI = 0.5772156649015329
M_E = math.e


# ─── erf / normal CDF / inverse normal CDF ─────────────────────────────────────
#
# These three are ported VERBATIM from the PHP HonestMetrics so the DSR (which
# ends in normalCdf) is bit-for-bit equal within rounding. The PHP erf is
# Abramowitz & Stegun 7.1.26 (|error| < 1.5e-7); the inverse is Acklam's probit.


def erf(x: float) -> float:
    """erf via Abramowitz & Stegun 7.1.26 (|error| < 1.5e-7) — the EXACT form the
    PHP used (NOT numpy.special / scipy)."""
    sign = -1.0 if x < 0 else 1.0
    x = abs(x)
    t = 1.0 / (1.0 + 0.3275911 * x)
    y = 1.0 - (
        (
            (
                (1.061405429 * t - 1.453152027) * t + 1.421413741
            )
            * t
            - 0.284496736
        )
        * t
        + 0.254829592
    ) * t * math.exp(-x * x)
    return sign * y


def normal_cdf(x: float) -> float:
    """Φ(x) = 0.5 * (1 + erf(x / sqrt(2))) — exactly the PHP normalCdf."""
    return 0.5 * (1.0 + erf(x / math.sqrt(2.0)))


def inverse_normal_cdf(p: float) -> float:
    """Inverse standard-normal CDF (probit) via Acklam's rational approximation —
    the EXACT coefficients and branch thresholds the PHP used."""
    p = min(1.0 - 1e-15, max(1e-15, p))
    a = [
        -3.969683028665376e01,
        2.209460984245205e02,
        -2.759285104469687e02,
        1.383577518672690e02,
        -3.066479806614716e01,
        2.506628277459239e00,
    ]
    b = [
        -5.447609879822406e01,
        1.615858368580409e02,
        -1.556989798598866e02,
        6.680131188771972e01,
        -1.328068155288572e01,
    ]
    c = [
        -7.784894002430293e-03,
        -3.223964580411365e-01,
        -2.400758277161838e00,
        -2.549732539343734e00,
        4.374664141464968e00,
        2.938163982698783e00,
    ]
    d = [
        7.784695709041462e-03,
        3.224671290700398e-01,
        2.445134137142996e00,
        3.754408661907416e00,
    ]
    p_low = 0.02425
    p_high = 1.0 - p_low

    if p < p_low:
        q = math.sqrt(-2.0 * math.log(p))
        return (
            (((((c[0] * q + c[1]) * q + c[2]) * q + c[3]) * q + c[4]) * q + c[5])
            / ((((d[0] * q + d[1]) * q + d[2]) * q + d[3]) * q + 1.0)
        )
    if p <= p_high:
        q = p - 0.5
        r = q * q
        return (
            (((((a[0] * r + a[1]) * r + a[2]) * r + a[3]) * r + a[4]) * r + a[5]) * q
            / (((((b[0] * r + b[1]) * r + b[2]) * r + b[3]) * r + b[4]) * r + 1.0)
        )
    q = math.sqrt(-2.0 * math.log(1.0 - p))
    return -(
        (((((c[0] * q + c[1]) * q + c[2]) * q + c[3]) * q + c[4]) * q + c[5])
        / ((((d[0] * q + d[1]) * q + d[2]) * q + d[3]) * q + 1.0)
    )


# ─── moments ───────────────────────────────────────────────────────────────────


def mean(x: list[float]) -> float:
    n = len(x)
    if n <= 0:
        return 0.0
    return float(np.asarray(x, dtype=float).sum() / n)


def std(x: list[float], sample: bool = True) -> float:
    """Standard deviation. Sample (n-1) by default — the convention the Sharpe
    estimator assumes. Computed as the PHP did: SSE about the (n>0) mean / ddof."""
    n = len(x)
    if n < (2 if sample else 1):
        return 0.0
    a = np.asarray(x, dtype=float)
    m = float(a.sum() / n)
    sse = float(np.sum((a - m) ** 2))
    return math.sqrt(sse / (n - (1 if sample else 0)))


def skewness(x: list[float]) -> float:
    """Standardized third moment. 0 for a symmetric distribution. Uses the SAMPLE
    std (n-1) in the denominator and divides the cubed-z sum by n — exactly the PHP."""
    n = len(x)
    s = std(x)
    if n < 3 or s <= 0.0:
        return 0.0
    a = np.asarray(x, dtype=float)
    m = float(a.sum() / n)
    acc = float(np.sum(((a - m) / s) ** 3))
    return acc / n


def kurtosis(x: list[float]) -> float:
    """Standardized fourth moment (Pearson kurtosis; 3.0 for a Gaussian). Uses the
    SAMPLE std and divides the fourth-power-z sum by n — exactly the PHP."""
    n = len(x)
    s = std(x)
    if n < 4 or s <= 0.0:
        return 3.0
    a = np.asarray(x, dtype=float)
    m = float(a.sum() / n)
    acc = float(np.sum(((a - m) / s) ** 4))
    return acc / n


def per_period_sharpe(returns: list[float], rf_per_period: float = 0.0) -> float:
    """Per-period Sharpe = mean(excess)/std. No annualization."""
    excess = [float(r) - rf_per_period for r in returns]
    s = std(excess)
    return mean(excess) / s if s > 0.0 else 0.0


def sharpe(returns: list[float], periods_per_year: float, rf_per_period: float = 0.0) -> float:
    """Annualized Sharpe ratio."""
    return per_period_sharpe(returns, rf_per_period) * math.sqrt(max(1.0, periods_per_year))


def max_drawdown(equity_curve: list[float]) -> float:
    """Maximum peak-to-trough decline, a positive fraction in [0,1]. A non-finite
    equity point is a broken curve -> total loss (1.0), exactly as the PHP."""
    peak = -math.inf
    max_dd = 0.0
    for e in equity_curve:
        e = float(e)
        if not math.isfinite(e):
            return 1.0
        if e > peak:
            peak = e
        if peak > 0.0:
            dd = (peak - e) / peak
            if dd > max_dd:
                max_dd = dd
    return max_dd


# ─── Deflated Sharpe Ratio (Bailey & López de Prado 2014 + Lo 2002 floor) ──────


def deflated_sharpe(
    sr: float,
    n_obs: int,
    skew: float,
    kurt: float,
    trials: int,
    var_sharpe_across_trials: float,
) -> float:
    """Probability in [0,1] that the observed per-period Sharpe is genuinely > 0
    after accounting for `trials` attempts, return skew and kurtosis, and sample
    length. A FAITHFUL port of the PHP deflatedSharpe — including the SENSITIVE
    Lo-2002 analytic variance floor that closes the loop's #1 overfitting hole."""
    if n_obs < 2 or not math.isfinite(sr):
        return 0.0

    sr0 = 0.0
    if trials >= 2:
        # Lo (2002) analytic lower bound on a per-trial Sharpe estimator's variance:
        #   Var(SR̂) ≈ (1 + SR²/2) / nObs.
        # FLOOR the empirical cross-trial variance with it so SR0 grows with N even
        # when the passing siblings cluster (empirical variance ~0). Every term is
        # per-period (the units the DSR is defined in).
        var_floor = (1.0 + 0.5 * sr * sr) / max(1, n_obs)
        eff_var = max(var_sharpe_across_trials, var_floor)
        z1 = inverse_normal_cdf(1.0 - 1.0 / trials)
        z2 = inverse_normal_cdf(1.0 - 1.0 / (trials * M_E))
        sr0 = math.sqrt(eff_var) * (
            (1.0 - EULER_MASCHERONI) * z1 + EULER_MASCHERONI * z2
        )

    denom = 1.0 - skew * sr + ((kurt - 1.0) / 4.0) * sr * sr
    if denom <= 1e-12:
        # A non-positive variance estimate of the Sharpe estimator is degenerate
        # (pathological higher moments). Fail SAFE — reject — never certify on a
        # broken denominator.
        return 0.0

    z = ((sr - sr0) * math.sqrt(n_obs - 1)) / math.sqrt(denom)
    return normal_cdf(z)


def deflated_sharpe_ratio(
    returns: list[float], trials: int, var_sharpe_across_trials: float
) -> float:
    """Convenience: compute the DSR straight from a returns series (matches the
    PHP deflatedSharpeRatio, which derives SR/skew/kurt/nObs from the series)."""
    return deflated_sharpe(
        per_period_sharpe(returns),
        len(returns),
        skewness(returns),
        kurtosis(returns),
        trials,
        var_sharpe_across_trials,
    )


# ─── Probability of Backtest Overfitting via CSCV ───────────────────────────────


def _split_blocks(t: int, s: int) -> list[list[int]]:
    """Contiguous, near-equal column blocks — the PHP splitBlocks: the first
    (t % s) blocks get one extra column."""
    base = t // s
    rem = t % s
    blocks: list[list[int]] = []
    c = 0
    for b in range(s):
        size = base + (1 if b < rem else 0)
        cols = []
        for _ in range(size):
            cols.append(c)
            c += 1
        blocks.append(cols)
    return blocks


def _mean_of(row: np.ndarray, cols: list[int]) -> float:
    if not cols:
        return 0.0
    return float(row[cols].sum() / len(cols))


def _argbest_mean(matrix: np.ndarray, cols: list[int]) -> int:
    """Index of the strategy with the highest mean over `cols`. Ties resolve to the
    FIRST (lowest index), exactly as the PHP `>` comparison + initial best=0."""
    best = 0
    best_val = -math.inf
    for i in range(matrix.shape[0]):
        v = _mean_of(matrix[i], cols)
        if v > best_val:
            best_val = v
            best = i
    return best


def pbo(matrix: list[list[float]], blocks: int = 8) -> float:
    """PBO via CSCV. Rows are strategies, columns aligned time observations. Returns
    a probability in [0,1]; 1.0 = cannot disprove overfitting. A FAITHFUL port of
    the PHP pbo: same ragged/NaN guards, same even-block trimming, same mean-rank
    logic, same lambda = log(omega/(1-omega)) <= 0 overfit test, same 1-indexed
    ascending rank with omega = rank/(m+1)."""
    rows = [list(r) for r in matrix]
    m = len(rows)
    if m < 2:
        return 1.0

    t = len(rows[0])
    for row in rows:
        if len(row) != t:
            return 1.0  # ragged matrix — cannot evaluate
        for v in row:
            if not math.isfinite(float(v)):
                return 1.0  # a NaN/INF cell would corrupt the ranking

    a = np.asarray(rows, dtype=float)

    s = blocks - (blocks % 2)
    if t < s:
        s = t - (t % 2)
    if s < 2:
        return 1.0

    block_cols = _split_blocks(t, s)
    combos = [list(c) for c in _iter_combinations(range(s), s // 2)]
    if not combos:
        return 1.0

    overfit = 0
    total = 0
    for is_blocks in combos:
        is_set = set(is_blocks)
        is_cols: list[int] = []
        oos_cols: list[int] = []
        for b in range(s):
            for c in block_cols[b]:
                if b in is_set:
                    is_cols.append(c)
                else:
                    oos_cols.append(c)
        if not is_cols or not oos_cols:
            continue

        is_best = _argbest_mean(a, is_cols)

        oos_perf = [(_mean_of(a[i], oos_cols), i) for i in range(m)]
        # asort() in PHP: ascending by value; PHP's sort is not stable but the rank
        # only depends on the position of is_best, and ties in OOS mean across the
        # near-symmetric partitions don't change which half is_best lands in for the
        # pinned cases. Sort by (value, index) for a deterministic, reproducible order.
        oos_perf.sort(key=lambda pair: (pair[0], pair[1]))
        ranked = [i for (_, i) in oos_perf]
        rank = ranked.index(is_best) + 1  # 1 = worst, m = best
        omega = rank / (m + 1)
        lam = math.log(omega / (1.0 - omega))
        if lam <= 0.0:
            overfit += 1
        total += 1

    return overfit / total if total > 0 else 1.0
