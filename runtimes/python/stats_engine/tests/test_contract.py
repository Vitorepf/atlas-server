"""Boundary contract: manifest validation, forbidden keys, batch dispatch,
and the receipt's anti-fake proof."""

from __future__ import annotations

import pytest

from atlas_stats_engine.contract import (
    ManifestError,
    probe,
    run_manifest,
    validate_manifest,
)


def test_forbidden_keys_are_rejected():
    with pytest.raises(ManifestError):
        validate_manifest({"operation": "ewma", "series": [1, 2], "api_key": "sk-secret"})
    with pytest.raises(ManifestError):
        validate_manifest({"operation": "wilson", "k": 1, "n": 10, "model": "override"})


def test_invalid_operation_rejected():
    with pytest.raises(ManifestError):
        validate_manifest({"operation": "drop_database"})
    with pytest.raises(ManifestError):
        validate_manifest({})


def test_valid_manifests_pass():
    validate_manifest({"operation": "ewma", "series": [1.0, 2.0]})
    validate_manifest({"operation": "wilson", "k": 1, "n": 10})
    validate_manifest({"operation": "batch", "jobs": [{"id": "a", "op": "cusum", "series": [1.0]}]})


def test_batch_requires_well_formed_jobs():
    with pytest.raises(ManifestError):
        validate_manifest({"operation": "batch", "jobs": "nope"})
    with pytest.raises(ManifestError):
        validate_manifest({"operation": "batch", "jobs": [{"op": "ewma"}]})  # missing id
    with pytest.raises(ManifestError):
        validate_manifest({"operation": "batch", "jobs": [{"id": "x", "op": "evil"}]})


def test_receipt_carries_real_boundary_proof():
    out = run_manifest({"operation": "wilson", "k": 50, "n": 100})
    boundary = out["boundary"]
    assert boundary["stats_engine_in_python"] is True
    assert boundary["real_stats"] is True
    assert boundary["fabricated"] is False
    assert boundary["library"] == "numpy"
    assert out["result"]["method"] == "wilson"


def test_batch_computes_many_stats_in_one_call():
    out = run_manifest(
        {
            "operation": "batch",
            "jobs": [
                {"id": "ewma:q", "op": "ewma", "series": [float(i) for i in range(20)]},
                {"id": "mk:q", "op": "mann_kendall", "series": [float(i) for i in range(1, 16)]},
                {"id": "cusum:q", "op": "cusum", "series": [50.0] * 20},
            ],
        }
    )
    assert out["operation"] == "batch"
    assert out["count"] == 3
    assert set(out["results"].keys()) == {"ewma:q", "mk:q", "cusum:q"}
    assert out["results"]["mk:q"]["direction"] == "increasing"
    assert out["results"]["cusum:q"]["suppression_reason"] == "reference_variance_too_low"
    assert out["boundary"]["real_stats"] is True


def test_probe_reports_availability_honestly():
    p = probe()
    assert isinstance(p, dict) and "available" in p
    if p["available"]:
        assert p["library"] == "numpy"
