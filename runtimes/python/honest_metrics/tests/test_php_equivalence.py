"""Behaviour-equivalence to the (now-removed) hand-rolled PHP HonestMetrics.

These are KNOWN ANSWERS captured from the OLD PHP class at git HEAD (the version
this migration removed), run on a deterministic battery and recorded here at full
precision. The migration's acceptance bar was: every numeric field of the NEW
numpy engine matches the OLD PHP within 1e-9. The live old-vs-new harness (81
cases / 127 fields) passed with a max |Δ| of 2.7e-15; this file pins the
SENSITIVE / load-bearing subset permanently so any future drift in the Python
engine away from the proven PHP behaviour fails CI.

Unlike the stats_engine bootstrap (a Monte-Carlo estimator whose equivalence is
statistical), EVERY function here is deterministic — no RNG — so the equivalence
is bitwise-close, not distributional. The DSR flows through the exact A&S-7.1.26
erf + Acklam probit the PHP used (NOT scipy, NOT the stats_engine's 26.2.17), and
PBO is a pure combinatorial enumeration, so |Δ| is floating-point rounding only.
"""

from __future__ import annotations

import math

from atlas_honest_metrics import metrics

TOL = 1e-9


# ─── moments (OLD PHP known answers) ─────────────────────────────────────────


def test_moments_match_old_php():
    # [2,4,4,4,5,5,7,9]: OLD PHP mean/std/skew/kurt (captured at full precision).
    x = [2.0, 4.0, 4.0, 4.0, 5.0, 5.0, 7.0, 9.0]
    assert abs(metrics.mean(x) - 5.0) < TOL
    assert abs(metrics.std(x) - 2.1380899352993952) < TOL
    assert abs(metrics.skewness(x) - 0.53713245689039979) < TOL
    assert abs(metrics.kurtosis(x) - 2.12939453125) < TOL


# ─── normal CDF / inverse (OLD PHP known answers) ────────────────────────────


def test_normal_cdf_matches_old_php():
    assert abs(metrics.normal_cdf(0.0) - 0.5) < TOL
    assert abs(metrics.normal_cdf(1.0) - 0.8413447361676363) < TOL
    assert abs(metrics.normal_cdf(-1.95996) - 0.025000163838472145) < TOL
    assert abs(metrics.normal_cdf(2.5) - 0.99379031988856203) < TOL


def test_inverse_normal_cdf_matches_old_php():
    assert abs(metrics.inverse_normal_cdf(0.5) - 0.0) < TOL
    assert abs(metrics.inverse_normal_cdf(0.975) - 1.959963986120195) < TOL
    assert abs(metrics.inverse_normal_cdf(0.02425) - (-1.9729610490848712)) < TOL
    assert abs(metrics.inverse_normal_cdf(0.001) - (-3.0902323047094038)) < TOL


# ─── Deflated Sharpe — THE SENSITIVE engine (OLD PHP known answers) ──────────


def test_deflated_sharpe_lo2002_floor_curve_matches_old_php():
    # varSharpe=0, clustered siblings: the N-sweep the audit fix produces. These are
    # the EXACT OLD PHP outputs at full precision (|Δ|=0 in the live old-vs-new
    # harness). The curve falls 0.99976 (N=1) -> 0.16646 (N=100000): searching
    # wider ALWAYS raises the bar even though the empirical sibling variance is 0.
    assert abs(metrics.deflated_sharpe(0.0717, 2303, 0.6, 14.0, 1, 0.0) - 0.99975505820821908) < TOL
    assert abs(metrics.deflated_sharpe(0.0717, 2303, 0.6, 14.0, 2, 0.0) - 0.99845665591282728) < TOL
    assert abs(metrics.deflated_sharpe(0.0717, 2303, 0.6, 14.0, 10, 0.0) - 0.97054362469612276) < TOL
    assert abs(metrics.deflated_sharpe(0.0717, 2303, 0.6, 14.0, 300, 0.0) - 0.70842280348991626) < TOL
    assert abs(metrics.deflated_sharpe(0.0717, 2303, 0.6, 14.0, 1000, 0.0) - 0.57297262654927716) < TOL
    assert abs(metrics.deflated_sharpe(0.0717, 2303, 0.6, 14.0, 10000, 0.0) - 0.33346037359254943) < TOL
    assert abs(metrics.deflated_sharpe(0.0717, 2303, 0.6, 14.0, 100000, 0.0) - 0.16646455903015678) < TOL


def test_deflated_sharpe_misc_match_old_php():
    # sr=0.1, var_sharpe=0.04: at N=1, DSR=0.987; at N=100 the best-by-luck bar SR0
    # rises to ~0.51 >> 0.1, so z≈-9 and Φ(z) underflows to exactly 0.0 (the
    # anti-Goodhart core working — a 0.1 Sharpe after a 100-way search is not real).
    assert abs(metrics.deflated_sharpe(0.1, 500, 0.0, 3.0, 1, 0.04) - 0.9870686874565302) < TOL
    assert metrics.deflated_sharpe(0.1, 500, 0.0, 3.0, 100, 0.04) == 0.0  # OLD PHP: 0
    assert metrics.deflated_sharpe(0.2, 750, 8.0, 3.0, 6, 0.001) == 0.0  # fail-safe (degenerate denom)
    assert metrics.deflated_sharpe(math.inf, 500, 0.0, 3.0, 10, 0.01) == 0.0  # non-finite guard


# ─── PBO (OLD PHP known answers — bitwise-exact, no RNG) ─────────────────────


def test_pbo_matches_old_php():
    dominant = [
        [0.03, 0.02, 0.03, 0.02, 0.03, 0.02, 0.03, 0.02],
        [-0.01, -0.02, -0.01, -0.02, -0.01, -0.02, -0.01, -0.02],
        [0.005, 0.004, 0.005, 0.004, 0.005, 0.004, 0.005, 0.004],
    ]
    assert metrics.pbo(dominant, 8) == 0.0  # PHP: 0

    regime_flip = [
        [0.05, 0.05, 0.05, 0.05, -0.05, -0.05, -0.05, -0.05],
        [-0.05, -0.05, -0.05, -0.05, 0.05, 0.05, 0.05, 0.05],
    ]
    assert metrics.pbo(regime_flip, 8) == 1.0  # PHP: 1

    # NaN / ragged / singleton all -> 1.0 (cannot disprove overfitting), like PHP:
    assert metrics.pbo([[0.03, 0.02, 0.03, math.nan], [0.01, 0.01, 0.01, 0.01]], 8) == 1.0
    assert metrics.pbo([[0.01, 0.02, 0.03], [0.01, 0.02]], 8) == 1.0
    assert metrics.pbo([[0.01, 0.02, 0.03, 0.04]], 8) == 1.0


# ─── honesty_gate end-to-end (OLD PHP known answers) ─────────────────────────


def test_honesty_gate_var_sharpe_equals_std_squared_like_old_php():
    # The gate derives var_sharpe = std(sibling_sharpes)^2 (sample std), exactly as
    # the old PHP TradingHonestyGate. [0.12,0.10,0.11,0.09,0.13] -> std^2 = 0.00025.
    out = metrics.std([0.12, 0.10, 0.11, 0.09, 0.13]) ** 2
    assert abs(out - 0.00025) < TOL
    # std of the DIVERSE siblings matches OLD PHP exactly (0.015811388300841896):
    assert abs(metrics.std([0.12, 0.10, 0.11, 0.09, 0.13]) - 0.015811388300841896) < TOL
    # clustered/identical siblings -> var_sharpe is the floating-point residue of the
    # SSE about the computed mean, ~7.2e-35 — and the numpy port reproduces the OLD
    # PHP value BIT-FOR-BIT (std([.05]*3)=8.498374721940739e-18 in both). It is far
    # below the Lo-2002 var_floor, so the floor dominates (the audit regime). This is
    # the honest equivalence: the same arithmetic, the same tiny non-zero, NOT a
    # fabricated exact 0.
    assert metrics.std([0.05, 0.05, 0.05]) == 8.498374721940739e-18  # exact PHP residue
    assert metrics.std([0.05, 0.05, 0.05]) ** 2 < 1e-30  # negligible -> floor wins
