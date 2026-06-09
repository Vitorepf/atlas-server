"""Boundary contract: manifest validation, forbidden keys, dispatch,
and the receipt's anti-fake proof."""

from __future__ import annotations

import pytest

from atlas_graph_rank.contract import (
    ManifestError,
    probe,
    run_manifest,
    validate_manifest,
)


def _manifest(**over):
    m = {"operation": "rank", "nodes": [], "edges": [], "query": {}}
    m.update(over)
    return m


def test_forbidden_keys_are_rejected():
    with pytest.raises(ManifestError):
        validate_manifest(_manifest(api_key="sk-secret"))
    with pytest.raises(ManifestError):
        validate_manifest(_manifest(model="override"))
    with pytest.raises(ManifestError):
        validate_manifest(_manifest(provider="anthropic"))


def test_invalid_operation_rejected():
    with pytest.raises(ManifestError):
        validate_manifest({"operation": "drop_database", "nodes": [], "edges": [], "query": {}})
    with pytest.raises(ManifestError):
        validate_manifest({})


def test_malformed_shapes_rejected():
    with pytest.raises(ManifestError):
        validate_manifest({"operation": "rank", "nodes": "nope", "edges": [], "query": {}})
    with pytest.raises(ManifestError):
        validate_manifest({"operation": "rank", "nodes": [], "edges": "nope", "query": {}})
    with pytest.raises(ManifestError):
        validate_manifest({"operation": "rank", "nodes": [], "edges": [], "query": "nope"})


def test_valid_manifest_passes():
    validate_manifest(_manifest())
    validate_manifest(
        _manifest(
            nodes=[{"node_id": "a", "node_type": "service", "path": "app/A.php"}],
            edges=[{"from_node_id": "a", "to_node_id": "b", "edge_type": "tests"}],
            query={"textual_seeds": ["a"]},
        )
    )


def test_receipt_carries_real_boundary_proof():
    out = run_manifest(
        _manifest(
            nodes=[
                {"node_id": "service:router", "node_type": "service", "path": "app/Services/Ai/Router/RouterService.php", "flow_id": "atlas_router", "capabilities": [], "risks": []},
            ],
            edges=[],
            query={"textual_seeds": ["router"], "target_files": ["app/services/ai/router/routerservice.php"], "target_flows": ["atlas_router"]},
        )
    )
    boundary = out["boundary"]
    assert boundary["graph_rank_in_python"] is True
    assert boundary["real_graph_math"] is True
    assert boundary["fabricated"] is False
    assert boundary["library"] == "networkx+numpy"
    assert out["operation"] == "rank"
    assert out["ranked_order"] == ["service:router"]
    assert out["scored"][0]["score"] > 0.0


def test_probe_reports_availability_honestly():
    p = probe()
    assert isinstance(p, dict) and "available" in p
    if p["available"]:
        assert p["library"] == "networkx+numpy"
