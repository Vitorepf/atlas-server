"""Boundary contract: manifest validation, forbidden keys, batch dispatch,
the honesty_gate one-shot, and the receipt's anti-fake proof."""

from __future__ import annotations

import pytest

from atlas_honest_metrics.contract import (
    ManifestError,
    probe,
    run_manifest,
    validate_manifest,
)


def test_forbidden_keys_are_rejected():
    with pytest.raises(ManifestError):
        validate_manifest({"operation": "moments", "x": [1, 2], "api_key": "sk-secret"})
    with pytest.raises(ManifestError):
        validate_manifest({"operation": "pbo", "matrix": [[1.0]], "model": "override"})


def test_invalid_operation_rejected():
    with pytest.raises(ManifestError):
        validate_manifest({"operation": "drop_database"})
    with pytest.raises(ManifestError):
        validate_manifest({})


def test_valid_manifests_pass():
    validate_manifest({"operation": "moments", "x": [1.0, 2.0]})
    validate_manifest({"operation": "deflated_sharpe", "sr": 0.1, "n_obs": 500, "skew": 0.0, "kurt": 3.0, "trials": 10, "var_sharpe": 0.01})
    validate_manifest({"operation": "batch", "jobs": [{"id": "a", "op": "pbo", "matrix": [[1.0]]}]})


def test_batch_requires_well_formed_jobs():
    with pytest.raises(ManifestError):
        validate_manifest({"operation": "batch", "jobs": "nope"})
    with pytest.raises(ManifestError):
        validate_manifest({"operation": "batch", "jobs": [{"op": "moments"}]})  # missing id
    with pytest.raises(ManifestError):
        validate_manifest({"operation": "batch", "jobs": [{"id": "x", "op": "evil"}]})


def test_receipt_carries_real_boundary_proof():
    out = run_manifest({"operation": "moments", "x": [1.0, 2.0, 3.0, 4.0]})
    boundary = out["boundary"]
    assert boundary["honest_metrics_in_python"] is True
    assert boundary["real_metrics"] is True
    assert boundary["fabricated"] is False
    assert boundary["library"] == "numpy"
    assert out["result"]["mean"] == 2.5


def test_deflated_sharpe_dispatches_with_lo2002_floor():
    out = run_manifest(
        {"operation": "deflated_sharpe", "sr": 0.0717, "n_obs": 2303, "skew": 0.6, "kurt": 14.0, "trials": 100000, "var_sharpe": 0.0}
    )
    # the floor makes N bite even at zero sibling variance (proven == old PHP):
    assert abs(out["result"]["deflated_sharpe"] - 0.166464559030) < 1e-9
    assert out["boundary"]["real_metrics"] is True


def test_pbo_dispatches():
    out = run_manifest(
        {
            "operation": "pbo",
            "matrix": [
                [0.05, 0.05, 0.05, 0.05, -0.05, -0.05, -0.05, -0.05],
                [-0.05, -0.05, -0.05, -0.05, 0.05, 0.05, 0.05, 0.05],
            ],
            "blocks": 8,
        }
    )
    assert out["result"]["pbo"] > 0.5  # regime-flip overfit


def test_honesty_gate_one_shot_computes_everything():
    out = run_manifest(
        {
            "operation": "honesty_gate",
            "winner_daily_returns": [0.001 * (i % 7 - 3) for i in range(500)],
            "sibling_sharpes": [0.12, 0.10, 0.11, 0.09, 0.13],
            "sibling_windows": [
                [0.01, 0.02, 0.03, 0.02, 0.01, 0.02, 0.03, 0.02],
                [0.005, 0.004, 0.005, 0.004, 0.005, 0.004, 0.005, 0.004],
            ],
            "scenarios_explored": 47,
            "periods_per_year": 365,
        }
    )
    r = out["result"]
    assert r["n_trials"] == 47
    assert "var_sharpe_across_trials" in r and "deflated_sharpe" in r and "pbo" in r and "scoring_sharpe" in r
    # var_sharpe = std([sibling sharpes])^2, a real positive number for diverse siblings:
    assert r["var_sharpe_across_trials"] > 0.0
    assert 0.0 <= r["deflated_sharpe"] <= 1.0
    assert 0.0 <= r["pbo"] <= 1.0


def test_batch_computes_many_metrics_in_one_call():
    out = run_manifest(
        {
            "operation": "batch",
            "jobs": [
                {"id": "m", "op": "moments", "x": [1.0, 2.0, 3.0, 4.0]},
                {"id": "d", "op": "deflated_sharpe", "sr": 0.2, "n_obs": 1000, "skew": 0.0, "kurt": 3.0, "trials": 1, "var_sharpe": 0.0},
                {"id": "p", "op": "pbo", "matrix": [[0.03, 0.02, 0.03, 0.02], [-0.01, -0.02, -0.01, -0.02]]},
            ],
        }
    )
    assert out["operation"] == "batch"
    assert out["count"] == 3
    assert set(out["results"].keys()) == {"m", "d", "p"}
    assert out["results"]["m"]["mean"] == 2.5
    assert out["results"]["d"]["deflated_sharpe"] > 0.95
    assert out["boundary"]["real_metrics"] is True


def test_moments_rejects_non_numbers():
    with pytest.raises(ManifestError):
        run_manifest({"operation": "moments", "x": [1.0, "two", 3.0]})


def test_probe_reports_availability_honestly():
    p = probe()
    assert isinstance(p, dict) and "available" in p
    if p["available"]:
        assert p["library"] == "numpy"
