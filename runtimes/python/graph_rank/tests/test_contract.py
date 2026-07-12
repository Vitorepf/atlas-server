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


def test_ppr_shadow_receipt_compares_against_bfs_baseline():
    out = run_manifest(
        _manifest(
            operation="ppr_shadow",
            nodes=[
                {"node_id": "memory:m1", "node_type": "memory", "path": "embedding decision", "capabilities": ["seed"]},
                {"node_id": "code:c1", "node_type": "module", "path": "embedding code module", "capabilities": []},
                {"node_id": "doc:d1", "node_type": "doc", "path": "unrelated doc", "capabilities": []},
            ],
            edges=[
                {"from_node_id": "memory:m1", "to_node_id": "code:c1", "edge_type": "references", "confidence": 1.0},
                {"from_node_id": "code:c1", "to_node_id": "doc:d1", "edge_type": "belongs_to", "confidence": 0.7},
            ],
            query={"textual_seeds": ["embedding"], "target_capabilities": ["seed"], "seed_node_ids": ["memory:m1"]},
            baseline_order=["memory:m1", "doc:d1", "code:c1"],
            targets=["code:c1"],
        )
    )

    assert out["operation"] == "ppr_shadow"
    assert out["boundary"]["graph_rank_in_python"] is True
    assert out["seed_node_ids"] == ["memory:m1"]
    assert "code:c1" in out["ppr_order"][:5]
    assert out["dual_read"]["baseline"] == "bfs_insertion_order"
    assert out["dual_read"]["candidate"] == "personalized_pagerank"
    assert out["dual_read"]["cases"] == 1
    assert out["dual_read"]["targets_available"] == 1
    assert out["dual_read"]["ppr_recall_at_5"] >= out["dual_read"]["baseline_recall_at_5"]


def test_probe_reports_availability_honestly():
    p = probe()
    assert isinstance(p, dict) and "available" in p
    if p["available"]:
        assert p["library"] == "networkx+numpy"
