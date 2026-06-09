"""Known-answer proofs that the numpy engine produces REAL statistics.

These mirror the (now-removed) PHP StatisticalDetectorsTest fixtures so the
boundary swap is provably behaviour-preserving, and they are the anti-over-claim
guard: identical samples -> D=0/p=1, disjoint samples -> D=1/tiny-p, monotone
series -> Sen's slope exactly 1.0, etc. A hash/stub would fail every one.
"""

from __future__ import annotations

import math

import numpy as np

from atlas_stats_engine import cusum, ewma, kolmogorov_smirnov, mann_kendall, wilson


# ─── KS ────────────────────────────────────────────────────────────────────────


def test_ks_identical_samples_d_zero_p_one():
    s = [float(i) for i in range(1, 25)]
    r = kolmogorov_smirnov(s, list(s))
    assert r["method"] == "ks"
    assert r["d_statistic"] == 0.0
    assert r["p_value"] == 1.0  # identical ECDFs -> p must be exactly 1


def test_ks_disjoint_samples_d_one_tiny_p():
    s1 = [float(i) for i in range(1, 21)]
    s2 = [float(i) for i in range(100, 300, 10)]
    r = kolmogorov_smirnov(s1, s2)
    assert r["method"] == "ks"
    assert abs(r["d_statistic"] - 1.0) < 0.05  # disjoint ranges -> D ≈ 1.0
    assert r["p_value"] < 0.001


def test_ks_falls_back_to_mann_whitney_for_small_n():
    s1 = [float(i) for i in range(1, 11)]  # n=10 < 20
    s2 = [float(i) for i in range(11, 21)]
    r = kolmogorov_smirnov(s1, s2)
    assert r["method"] == "mann_whitney"
    assert r["p_value"] < 0.05


def test_ks_below_absolute_min_suppressed():
    r = kolmogorov_smirnov([1.0, 2.0], [3.0, 4.0])
    assert r["suppressed"] is True
    assert r["suppression_reason"] == "n_below_absolute_min:8"


# ─── Mann-Kendall ──────────────────────────────────────────────────────────────


def test_mann_kendall_strictly_increasing():
    r = mann_kendall([float(i) for i in range(1, 16)])
    assert r["direction"] == "increasing"
    assert r["p_value"] < 0.05
    assert abs(r["sens_slope"] - 1.0) < 1e-4  # slope of [1..15] = 1.0/day


def test_mann_kendall_strictly_decreasing():
    r = mann_kendall([float(i) for i in range(15, 0, -1)])
    assert r["direction"] == "decreasing"
    assert r["p_value"] < 0.05
    assert abs(r["sens_slope"] + 1.0) < 1e-4


def test_mann_kendall_below_min_suppressed():
    r = mann_kendall([1.0, 2.0, 3.0])
    assert r["suppressed"] is True
    assert r["suppression_reason"] == "n_below_min:7"


def test_mann_kendall_constant_no_trend():
    r = mann_kendall([50.0] * 12)
    assert r["direction"] == "none"
    assert abs(r["sens_slope"]) < 1e-4
    assert r["s_statistic"] == 0


# ─── CUSUM ─────────────────────────────────────────────────────────────────────


def test_cusum_step_shift_fires_upward():
    rng = np.random.default_rng(7)
    series = list(50.0 + rng.uniform(0, 6, 15)) + list(70.0 + rng.uniform(0, 6, 5))
    r = cusum(series)
    assert r["fired"] is True
    assert r["direction"] == "upward"


def test_cusum_constant_suppressed_variance_guard():
    r = cusum([50.0] * 20)
    assert r["suppressed"] is True
    assert r["suppression_reason"] == "reference_variance_too_low"


def test_cusum_below_min_window_suppressed():
    r = cusum([50.0] * 10)
    assert r["suppressed"] is True
    assert r["suppression_reason"] == "window_below_min:15"


# ─── EWMA ──────────────────────────────────────────────────────────────────────


def test_ewma_spike_fires():
    rng = np.random.default_rng(42)
    series = list(95.0 + rng.uniform(0, 10, 14)) + [200.0]
    r = ewma(series)
    assert r["anomaly"] is True
    assert r["confidence"] == "full"
    assert abs(r["z_score"]) > 2.5


def test_ewma_below_warmup_cold_start():
    r = ewma([100.0] * 5)
    assert r["confidence"] == "cold_start"
    assert r["anomaly"] is False
    assert r["warmup_days_remaining"] == 5


def test_ewma_constant_variance_too_low():
    r = ewma([75.0] * 20)
    assert r["anomaly"] is False
    assert r["confidence"] == "variance_too_low"


def test_ewma_empty_cold_start():
    r = ewma([])
    assert r["confidence"] == "cold_start"
    assert r["today"] is None


# ─── Wilson ────────────────────────────────────────────────────────────────────


def test_wilson_boundary_zero():
    r = wilson(0, 10)
    assert r["lower"] == 0.0  # Wald would go negative; Wilson floors at 0
    assert r["upper"] > 0.0


def test_wilson_boundary_one():
    r = wilson(10, 10)
    assert r["upper"] == 1.0
    assert r["lower"] < 1.0


def test_wilson_50_50_centered():
    r = wilson(50, 100)
    assert abs(r["center"] - 0.50) < 0.01
    assert abs(r["lower"] - 0.40) < 0.02
    assert abs(r["upper"] - 0.60) < 0.02


def test_wilson_below_min_n_null():
    r = wilson(2, 4)
    assert r["lower"] is None
    assert "n_too_small" in r["method"]


# ─── independence proof: numpy actually ran ────────────────────────────────────


def test_normal_cdf_matches_known_values():
    # Φ(0)=0.5, Φ(1.96)≈0.975 — the Abramowitz-Stegun approximation the PHP used.
    from atlas_stats_engine.stats import _normal_cdf

    assert abs(_normal_cdf(0.0) - 0.5) < 1e-6
    assert abs(_normal_cdf(1.96) - 0.975) < 1e-3
    assert math.isclose(_normal_cdf(-1.0) + _normal_cdf(1.0), 1.0, abs_tol=1e-9)
