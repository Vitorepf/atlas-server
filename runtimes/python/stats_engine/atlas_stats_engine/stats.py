"""Real telemetry statistics, computed in numpy.

This is the REAL engine the runtime_language_boundary canon mandates: the PHP
kernel must not hand-roll these numeric routines, it invokes this runtime. Each
function is a faithful numpy port of the (now-removed) hand-rolled PHP reference
in app/Services/Ai/Telemetry/Engine/Stats/*.php — same formulas, same guard
thresholds, same suppression semantics, same 6-dp rounding — so the swap is
behaviour-preserving. numpy ONLY: no scipy/statsmodels/sklearn (the normal CDF
and KS series are closed-form, so no extra dependency is needed or taken).
"""

from __future__ import annotations

import math
from typing import Any

import numpy as np

# ─── thresholds (mirror the PHP class consts exactly) ──────────────────────────

KS_MIN_N_PER_GROUP = 20
KS_ABSOLUTE_MIN_N_PER_GROUP = 8
KOLMOGOROV_TERMS = 100

MANN_KENDALL_MIN_N = 7

CUSUM_MIN_WINDOW = 15
CUSUM_MIN_REFERENCE_HALF = 7
CUSUM_K_MULTIPLIER = 0.5
CUSUM_H_MULTIPLIER = 4.0

EWMA_DEFAULT_ALPHA = 0.20
EWMA_DEFAULT_K_SIGMA = 2.5
EWMA_DEFAULT_WARMUP_DAYS = 10
EWMA_SIGMA_FLOOR = 1e-6

WILSON_MIN_N = 5
WILSON_Z_95 = 1.96


def _normal_cdf(z: float) -> float:
    """Φ(z) via Abramowitz-Stegun 26.2.17 — the exact approximation the PHP used,
    so p-values match the prior PHP output bit-for-bit within rounding."""
    if z < 0.0:
        return 1.0 - _normal_cdf(-z)
    b1, b2, b3, b4, b5 = (
        0.319381530,
        -0.356563782,
        1.781477937,
        -1.821255978,
        1.330274429,
    )
    p = 0.2316419
    t = 1.0 / (1.0 + p * z)
    pdf = math.exp(-0.5 * z * z) / math.sqrt(2.0 * math.pi)
    return 1.0 - pdf * (
        b1 * t + b2 * t**2 + b3 * t**3 + b4 * t**4 + b5 * t**5
    )


# ─── Kolmogorov-Smirnov (+ Mann-Whitney fallback) ──────────────────────────────


def _kolmogorov_p(z: float) -> float:
    if z < 1e-7:
        return 1.0
    s = 0.0
    for k in range(1, KOLMOGOROV_TERMS + 1):
        term = (1 if k % 2 == 1 else -1) * math.exp(-2.0 * k * k * z * z)
        s += term
        if abs(term) < 1e-10:
            break
    p = 2.0 * s
    return max(0.0, min(1.0, p))


def _mann_whitney(sample1: list[float], sample2: list[float]) -> dict[str, Any]:
    n1, n2 = len(sample1), len(sample2)
    a1 = np.asarray(sample1, dtype=float)
    a2 = np.asarray(sample2, dtype=float)
    combined = np.concatenate([a1, a2])
    groups = np.concatenate([np.ones(n1, dtype=int), np.full(n2, 2, dtype=int)])

    order = np.argsort(combined, kind="mergesort")  # stable, like usort + <=> on equal
    cv = combined[order]
    cg = groups[order]

    total = n1 + n2
    rank_sum1 = 0.0
    i = 0
    while i < total:
        j = i
        while j + 1 < total and cv[j + 1] == cv[i]:
            j += 1
        avg_rank = (i + 1 + j + 1) / 2.0  # average rank over the tie block
        block = cg[i : j + 1]
        rank_sum1 += avg_rank * int(np.count_nonzero(block == 1))
        i = j + 1

    u1 = rank_sum1 - (n1 * (n1 + 1)) / 2.0
    mu_u = (n1 * n2) / 2.0
    sigma_u = math.sqrt((n1 * n2 * (n1 + n2 + 1)) / 12.0)
    z = (u1 - mu_u) / sigma_u if sigma_u > 0 else 0.0
    p_value = 2.0 * (1.0 - _normal_cdf(abs(z)))

    direction = (
        "sample1_higher_distribution"
        if z > 0
        else ("sample1_lower_distribution" if z < 0 else "none")
    )
    return {
        "method": "mann_whitney",
        "d_statistic": None,
        "p_value": round(p_value, 6),
        "n1": n1,
        "n2": n2,
        "direction": direction,
        "suppressed": False,
        "suppression_reason": None,
    }


def kolmogorov_smirnov(sample1: list[float], sample2: list[float]) -> dict[str, Any]:
    n1, n2 = len(sample1), len(sample2)

    if n1 < KS_ABSOLUTE_MIN_N_PER_GROUP or n2 < KS_ABSOLUTE_MIN_N_PER_GROUP:
        return {
            "method": "none",
            "d_statistic": None,
            "p_value": None,
            "n1": n1,
            "n2": n2,
            "direction": "none",
            "suppressed": True,
            "suppression_reason": f"n_below_absolute_min:{KS_ABSOLUTE_MIN_N_PER_GROUP}",
        }

    if n1 < KS_MIN_N_PER_GROUP or n2 < KS_MIN_N_PER_GROUP:
        return _mann_whitney(sample1, sample2)

    s1 = np.sort(np.asarray(sample1, dtype=float))
    s2 = np.sort(np.asarray(sample2, dtype=float))
    combined = np.unique(np.concatenate([s1, s2]))  # sorted unique support points

    # ECDF of each sample at every support point (vectorised: count of v <= x).
    f1 = np.searchsorted(s1, combined, side="right") / n1
    f2 = np.searchsorted(s2, combined, side="right") / n2
    diff = f1 - f2

    idx = int(np.argmax(np.abs(diff)))
    max_d = float(abs(diff[idx]))
    direction = (
        "sample1_lower_distribution"
        if diff[idx] > 0
        else ("sample1_higher_distribution" if diff[idx] < 0 else "none")
    )

    z = max_d * math.sqrt((n1 * n2) / (n1 + n2))
    p_value = _kolmogorov_p(z)

    return {
        "method": "ks",
        "d_statistic": round(max_d, 6),
        "p_value": round(p_value, 6),
        "n1": n1,
        "n2": n2,
        "direction": direction,
        "suppressed": False,
        "suppression_reason": None,
    }


# ─── Mann-Kendall trend + Sen's slope ──────────────────────────────────────────


def mann_kendall(series: list[float]) -> dict[str, Any]:
    n = len(series)
    if n < MANN_KENDALL_MIN_N:
        return {
            "n": n,
            "s_statistic": 0,
            "z": None,
            "p_value": None,
            "sens_slope": None,
            "direction": "none",
            "suppressed": True,
            "suppression_reason": f"n_below_min:{MANN_KENDALL_MIN_N}",
        }

    x = np.asarray(series, dtype=float)
    # All i<j pairwise differences and lags (upper triangle), vectorised.
    i_idx, j_idx = np.triu_indices(n, k=1)
    diffs = x[j_idx] - x[i_idx]
    lags = (j_idx - i_idx).astype(float)

    s = int(np.sum(np.sign(diffs)))  # sign() gives -1/0/+1 like the PHP ternary
    slopes = diffs / lags

    variance = (n * (n - 1) * (2 * n + 5)) / 18.0
    if s > 0:
        z = (s - 1) / math.sqrt(variance)
    elif s < 0:
        z = (s + 1) / math.sqrt(variance)
    else:
        z = 0.0

    p_value = 2.0 * (1.0 - _normal_cdf(abs(z)))
    sens_slope = float(np.median(slopes))

    if z > 0 and p_value < 0.05:
        direction = "increasing"
    elif z < 0 and p_value < 0.05:
        direction = "decreasing"
    elif p_value < 0.10:
        direction = "tentative"
    else:
        direction = "none"

    return {
        "n": n,
        "s_statistic": s,
        "z": round(z, 4),
        "p_value": round(p_value, 6),
        "sens_slope": round(sens_slope, 6),
        "direction": direction,
        "suppressed": False,
        "suppression_reason": None,
    }


# ─── CUSUM change-point ────────────────────────────────────────────────────────


def _cusum_suppress(reason: str) -> dict[str, Any]:
    return {
        "fired": False,
        "change_point_index": None,
        "direction": "none",
        "magnitude_sigma": None,
        "suppressed": True,
        "suppression_reason": reason,
    }


def cusum(series: list[float]) -> dict[str, Any]:
    n = len(series)
    if n < CUSUM_MIN_WINDOW:
        return _cusum_suppress(f"window_below_min:{CUSUM_MIN_WINDOW}")

    ref_half_length = n // 2
    if ref_half_length < CUSUM_MIN_REFERENCE_HALF:
        return _cusum_suppress("reference_half_too_small")

    x = np.asarray(series, dtype=float)
    reference = x[:ref_half_length]
    mu_ref = float(np.mean(reference))
    # Population variance (divide by N) — matches the PHP /refHalfLength.
    sigma_ref = float(np.sqrt(np.mean((reference - mu_ref) ** 2)))

    if sigma_ref < 1e-6:
        return _cusum_suppress("reference_variance_too_low")

    k = CUSUM_K_MULTIPLIER * sigma_ref
    h = CUSUM_H_MULTIPLIER * sigma_ref

    # Sequential recursion with the exact PHP "last index where C reset to 0"
    # change-point bookkeeping — kept as a loop because each step depends on the
    # previous (a vectorised cumsum would change the reset semantics).
    c_plus = 0.0
    c_minus = 0.0
    c_plus_zero = 0
    c_minus_zero = 0
    fired_index: int | None = None
    direction = "none"
    magnitude_sigma: float | None = None

    for i in range(ref_half_length, n):
        xi = float(x[i])
        c_plus = max(0.0, c_plus + (xi - mu_ref - k))
        c_minus = max(0.0, c_minus - (xi - mu_ref + k))

        if c_plus == 0.0:
            c_plus_zero = i
        if c_minus == 0.0:
            c_minus_zero = i

        if c_plus > h:
            fired_index = c_plus_zero
            direction = "upward"
            magnitude_sigma = round(c_plus / sigma_ref, 4)
            break
        if c_minus > h:
            fired_index = c_minus_zero
            direction = "downward"
            magnitude_sigma = round(c_minus / sigma_ref, 4)
            break

    return {
        "fired": fired_index is not None,
        "change_point_index": fired_index,
        "direction": direction,
        "magnitude_sigma": magnitude_sigma,
        "suppressed": False,
        "suppression_reason": None,
    }


# ─── EWMA single-day anomaly ───────────────────────────────────────────────────


def _ewma_empty(warmup_days: int) -> dict[str, Any]:
    return {
        "anomaly": False,
        "z_score": None,
        "baseline_ewma": None,
        "sigma": None,
        "today": None,
        "confidence": "cold_start",
        "warmup_days_remaining": warmup_days,
    }


def ewma(
    series: list[float],
    alpha: float = EWMA_DEFAULT_ALPHA,
    k_sigma: float = EWMA_DEFAULT_K_SIGMA,
    warmup_days: int = EWMA_DEFAULT_WARMUP_DAYS,
) -> dict[str, Any]:
    n = len(series)
    if n == 0:
        return _ewma_empty(warmup_days)

    x = np.asarray(series, dtype=float)
    today = float(x[-1])
    if n < warmup_days:
        return {
            "anomaly": False,
            "z_score": None,
            "baseline_ewma": None,
            "sigma": None,
            "today": today,
            "confidence": "cold_start",
            "warmup_days_remaining": warmup_days - n,
        }

    # Recursive EWMA level + variance over x[1 .. n-2] (today = x[-1] is held out),
    # exactly as the PHP loop `for i in 1..n-2`. Sequential by definition.
    level = float(x[0])
    variance = 0.0
    for i in range(1, n - 1):
        xi = float(x[i])
        residual = xi - level
        variance = alpha * (residual**2) + (1.0 - alpha) * variance
        level = alpha * xi + (1.0 - alpha) * level

    sigma = math.sqrt(variance / max(alpha, EWMA_SIGMA_FLOOR))
    if sigma < EWMA_SIGMA_FLOOR:
        return {
            "anomaly": False,
            "z_score": None,
            "baseline_ewma": level,
            "sigma": sigma,
            "today": today,
            "confidence": "variance_too_low",
            "warmup_days_remaining": 0,
        }

    z_score = (today - level) / sigma
    anomaly = abs(z_score) > k_sigma

    return {
        "anomaly": bool(anomaly),
        "z_score": round(z_score, 4),
        "baseline_ewma": round(level, 4),
        "sigma": round(sigma, 4),
        "today": today,
        "confidence": "full",
        "warmup_days_remaining": 0,
    }


# ─── Wilson score interval ─────────────────────────────────────────────────────


def wilson(k: int, n: int, z: float = WILSON_Z_95) -> dict[str, Any]:
    if n < WILSON_MIN_N:
        return {
            "lower": None,
            "upper": None,
            "center": None,
            "n": n,
            "k": k,
            "method": "wilson:n_too_small",
        }
    if k < 0 or k > n:
        return {
            "lower": None,
            "upper": None,
            "center": None,
            "n": n,
            "k": k,
            "method": "wilson:invalid_input",
        }

    p_hat = k / n
    z2 = z * z
    denominator = 1.0 + z2 / n
    center = (p_hat + z2 / (2.0 * n)) / denominator
    margin = z * math.sqrt(p_hat * (1.0 - p_hat) / n + z2 / (4.0 * n * n)) / denominator

    return {
        "lower": round(max(0.0, center - margin), 6),
        "upper": round(min(1.0, center + margin), 6),
        "center": round(center, 6),
        "n": n,
        "k": k,
        "method": "wilson",
    }
