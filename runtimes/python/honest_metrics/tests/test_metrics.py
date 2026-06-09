"""Known-answer proofs that the numpy engine produces the REAL honest metrics.

These mirror the (now-removed) PHP HonestMetricsTest fixtures so the boundary swap
is provably behaviour-preserving, and they are the anti-over-claim guard. The two
that matter most for the loop's integrity:
  - the Deflated Sharpe FALLS as the trial count rises (anti-Goodhart), AND keeps
    biting at ZERO observed sibling variance (the Lo-2002 floor — a prior audit
    fix), and
  - PBO is ~0 for a dominant strategy but ~1 for a regime-flip overfit.

The old-PHP-vs-new-Python numeric equivalence (every field within 1e-9, incl. the
exact varSharpe=0 N-sweep 0.9998->0.166) is pinned separately in
tests/test_php_equivalence.py.
"""

from __future__ import annotations

import math

from atlas_honest_metrics import metrics


# ─── moments ─────────────────────────────────────────────────────────────────


def test_mean_std_skew_kurtosis():
    assert abs(metrics.mean([1, 2, 3, 4]) - 2.5) < 1e-12
    assert abs(metrics.std([2, 4, 4, 4, 5, 5, 7, 9]) - 2.1380) < 1e-3
    assert abs(metrics.skewness([-2, -1, 0, 1, 2]) - 0.0) < 1e-9  # symmetric
    assert metrics.kurtosis([-2, -1, 0, 1, 2]) > 0.0


def test_moment_guards_match_php():
    assert metrics.std([5.0]) == 0.0  # n<2 sample -> 0
    assert metrics.std([5.0], sample=False) == 0.0  # n<1? no: n=1 ok -> 0 spread
    assert metrics.skewness([3.0, 3.0]) == 0.0  # std 0 -> 0
    assert metrics.kurtosis([3.0, 3.0]) == 3.0  # std 0 / n<4 -> 3.0 (Gaussian)
    assert metrics.skewness([1.0, 2.0]) == 0.0  # n<3 -> 0
    assert metrics.kurtosis([1.0, 2.0, 3.0]) == 3.0  # n<4 -> 3.0


def test_sharpe_zero_std_is_zero():
    assert metrics.per_period_sharpe([0.0, 0.0, 0.0, 0.0]) == 0.0
    assert metrics.sharpe([0.01], 365) == 0.0  # n=1 std 0


# ─── max drawdown ────────────────────────────────────────────────────────────


def test_max_drawdown():
    assert abs(metrics.max_drawdown([1.0, 1.2, 0.9, 1.0, 0.8]) - 0.3333) < 1e-3
    assert abs(metrics.max_drawdown([1.0, 1.1, 1.2, 1.3]) - 0.0) < 1e-12  # monotonic up


def test_max_drawdown_flags_non_finite_equity():
    assert metrics.max_drawdown([1.0, math.inf, 0.5]) == 1.0
    assert metrics.max_drawdown([1.0, math.nan, 0.5]) == 1.0


# ─── normal CDF / inverse (the EXACT PHP closed forms) ───────────────────────


def test_normal_cdf_and_inverse():
    assert abs(metrics.normal_cdf(0.0) - 0.5) < 1e-9
    assert abs(metrics.normal_cdf(1.95996) - 0.975) < 2e-3
    assert abs(metrics.inverse_normal_cdf(0.975) - 1.95996) < 1e-3
    assert abs(metrics.inverse_normal_cdf(0.5) - 0.0) < 1e-9


def test_normal_cdf_uses_as_7_1_26_not_26_2_17():
    # The PHP HonestMetrics used erf via A&S 7.1.26 (NOT the stats_engine's
    # 26.2.17). Pin the exact A&S-7.1.26 value of Φ(1.0) (== the OLD PHP output
    # 0.84134473616763628, proven in test_php_equivalence) so a future refactor
    # that swaps in a different approximation is caught.
    assert abs(metrics.normal_cdf(1.0) - 0.8413447361676363) < 1e-13
    # Φ symmetry holds exactly under this approximation (the erf is odd by sign):
    assert abs((metrics.normal_cdf(-1.0) + metrics.normal_cdf(1.0)) - 1.0) < 1e-12


# ─── Deflated Sharpe (the SENSITIVE engine) ──────────────────────────────────


def test_deflated_sharpe_falls_as_trials_rise():
    one = metrics.deflated_sharpe(0.1, 500, 0.0, 3.0, 1, 0.04)
    many = metrics.deflated_sharpe(0.1, 500, 0.0, 3.0, 100, 0.04)
    assert one > many, "more trials must lower the DSR"
    for v in (one, many):
        assert 0.0 <= v <= 1.0


def test_deflated_sharpe_strong_vs_weak():
    strong = metrics.deflated_sharpe(0.20, 1000, 0.0, 3.0, 1, 0.0)
    assert strong > 0.95
    weak = metrics.deflated_sharpe(0.02, 300, 0.0, 3.0, 200, 0.04)
    assert weak < 0.5


def test_floor_makes_n_bite_even_with_zero_sibling_variance():
    # THE audit regression: convergent loops cluster sibling Sharpes => observed
    # variance 0. Without the Lo-2002 analytic floor N was inert. Now N must still
    # raise the bar — and the exact curve is the one the memory records.
    n2 = metrics.deflated_sharpe(0.0717, 2303, 0.6, 14.0, 2, 0.0)
    n300 = metrics.deflated_sharpe(0.0717, 2303, 0.6, 14.0, 300, 0.0)
    assert n2 > n300, "N must still bite at zero observed sibling variance"
    assert n300 < 0.95, "a marginal winner must NOT certify after a 300-way search"


def test_floor_curve_matches_recorded_audit_reproduction():
    # The re-reproduced curve: at varSharpe=0, DSR falls 0.99976 -> 0.16646 across
    # N=1..100000 at the marginal SR. These are the OLD PHP values (proven equal in
    # test_php_equivalence) baked in as known answers.
    assert abs(metrics.deflated_sharpe(0.0717, 2303, 0.6, 14.0, 1, 0.0) - 0.99975505820821908) < 1e-9
    assert abs(metrics.deflated_sharpe(0.0717, 2303, 0.6, 14.0, 100000, 0.0) - 0.16646455903015678) < 1e-9


def test_deflated_sharpe_fails_safe_on_degenerate_higher_moments():
    # extreme skew drives the Sharpe-estimator variance non-positive: REJECT (0.0),
    # never certify on a broken denominator.
    assert metrics.deflated_sharpe(0.2, 750, 8.0, 3.0, 6, 0.001) == 0.0


def test_deflated_sharpe_non_finite_and_tiny_n_guards():
    assert metrics.deflated_sharpe(math.inf, 500, 0.0, 3.0, 10, 0.01) == 0.0
    assert metrics.deflated_sharpe(0.1, 1, 0.0, 3.0, 10, 0.01) == 0.0


# ─── PBO via CSCV ────────────────────────────────────────────────────────────


def test_pbo_is_low_for_a_dominant_strategy():
    matrix = [
        [0.03, 0.02, 0.03, 0.02, 0.03, 0.02, 0.03, 0.02],
        [-0.01, -0.02, -0.01, -0.02, -0.01, -0.02, -0.01, -0.02],
        [0.005, 0.004, 0.005, 0.004, 0.005, 0.004, 0.005, 0.004],
    ]
    assert metrics.pbo(matrix, 8) < 0.2


def test_pbo_is_high_for_a_regime_flip_overfit():
    matrix = [
        [0.05, 0.05, 0.05, 0.05, -0.05, -0.05, -0.05, -0.05],
        [-0.05, -0.05, -0.05, -0.05, 0.05, 0.05, 0.05, 0.05],
    ]
    assert metrics.pbo(matrix, 8) > 0.5


def test_pbo_rejects_a_non_finite_cell():
    matrix = [
        [0.03, 0.02, 0.03, 0.02, 0.03, 0.02, 0.03, math.nan],
        [0.01, 0.01, 0.01, 0.01, 0.01, 0.01, 0.01, 0.01],
    ]
    assert metrics.pbo(matrix, 8) == 1.0  # cannot disprove overfitting


def test_pbo_rejects_ragged_and_singleton():
    assert metrics.pbo([[0.01, 0.02, 0.03], [0.01, 0.02]], 8) == 1.0  # ragged
    assert metrics.pbo([[0.01, 0.02, 0.03, 0.04]], 8) == 1.0  # m<2
