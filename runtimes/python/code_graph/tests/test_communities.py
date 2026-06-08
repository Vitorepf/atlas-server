"""Tests for code_graph deterministic community detection (AP-811/AP-812).

Runnable with pytest OR directly: `python3 tests/test_communities.py`
(networkx/pytest may be absent in the local runtime, so it self-runs).
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from atlas_code_graph import detect_communities  # noqa: E402


def _edge(a: str, b: str) -> dict:
    return {"from_node_id": a, "to_node_id": b}


def _clique(nodes):
    """All undirected pairs within ``nodes`` as edge dicts."""
    out = []
    for i in range(len(nodes)):
        for j in range(i + 1, len(nodes)):
            out.append(_edge(nodes[i], nodes[j]))
    return out


def test_two_cliques_joined_by_bridge_resolve_to_two_communities() -> None:
    # Two K4 cliques {a,b,c,d} and {e,f,g,h} joined by a SINGLE bridge edge d-e.
    # Classic Louvain result: exactly two communities split on the bridge.
    left = ["a", "b", "c", "d"]
    right = ["e", "f", "g", "h"]
    edges = _clique(left) + _clique(right) + [_edge("d", "e")]

    result = detect_communities(edges)

    assert result["schema_version"] == "atlas.code_graph.communities.v1"
    assert result["node_count"] == 8
    # 12 intra-clique edges (6 each) + 1 bridge.
    assert result["edge_count"] == 13
    assert result["community_count"] == 2, result["communities"]

    # Membership: each clique is exactly one community (order-independent check).
    members = {frozenset(c["nodes"]) for c in result["communities"]}
    assert members == {frozenset(left), frozenset(right)}, members

    # Every community is size 4.
    assert sorted(c["size"] for c in result["communities"]) == [4, 4]

    # assignments must agree with the community blocks and cover every node.
    assignments = result["assignments"]
    assert set(assignments.keys()) == set(left) | set(right)
    # The two cliques carry two distinct ids; within a clique the id is shared.
    left_ids = {assignments[n] for n in left}
    right_ids = {assignments[n] for n in right}
    assert len(left_ids) == 1 and len(right_ids) == 1
    assert left_ids != right_ids

    # community_id space is the stable [0..k-1] and tie-broken by min node:
    # both communities are size 4, so the one containing the smaller min node
    # ("a") must be id 0.
    by_id = {c["community_id"]: c for c in result["communities"]}
    assert by_id[0]["nodes"][0] == "a"
    assert by_id[1]["nodes"][0] == "e"


def test_deterministic_across_runs() -> None:
    left = ["a", "b", "c", "d"]
    right = ["e", "f", "g", "h"]
    edges = _clique(left) + _clique(right) + [_edge("d", "e")]
    first = detect_communities(edges)
    second = detect_communities(edges)
    assert first == second

    # Determinism must not depend on input edge ORDER either: a shuffled-but-
    # equivalent edge list yields an identical (canonical) result.
    import json
    import random

    shuffled = list(edges)
    random.Random(1234).shuffle(shuffled)
    third = detect_communities(shuffled)
    assert json.dumps(first, sort_keys=True) == json.dumps(third, sort_keys=True)


def test_empty_graph_is_safe() -> None:
    result = detect_communities([])
    assert result["schema_version"] == "atlas.code_graph.communities.v1"
    assert result["node_count"] == 0
    assert result["edge_count"] == 0
    assert result["community_count"] == 0
    assert result["communities"] == []
    assert result["assignments"] == {}


def test_malformed_and_self_loop_edges_are_dropped() -> None:
    edges = [
        _edge("a", "a"),            # self-loop -> dropped
        {"from_node_id": "a"},      # missing to -> dropped
        {"to_node_id": "b"},        # missing from -> dropped
        {"from_node_id": 1, "to_node_id": 2},  # non-str -> dropped
        "not-a-dict",               # junk -> dropped
        _edge(" a ", " b "),         # whitespace trimmed -> real edge a-b
    ]
    result = detect_communities(edges)
    assert result["node_count"] == 2
    assert result["edge_count"] == 1
    assert set(result["assignments"].keys()) == {"a", "b"}


def test_disconnected_components_are_separate_communities() -> None:
    # Two disjoint triangles, no bridge -> two communities of size 3.
    edges = _clique(["a", "b", "c"]) + _clique(["x", "y", "z"])
    result = detect_communities(edges)
    assert result["community_count"] == 2
    members = {frozenset(c["nodes"]) for c in result["communities"]}
    assert members == {frozenset({"a", "b", "c"}), frozenset({"x", "y", "z"})}


def test_single_edge_two_nodes() -> None:
    # Smallest non-trivial graph. Either grouping is valid modularity-wise;
    # what we assert is the structural contract: deterministic + well-formed.
    result = detect_communities([_edge("a", "b")])
    assert result["node_count"] == 2
    assert result["edge_count"] == 1
    assert set(result["assignments"].keys()) == {"a", "b"}
    # community ids are contiguous from 0
    ids = sorted({c["community_id"] for c in result["communities"]})
    assert ids == list(range(len(ids)))
    assert detect_communities([_edge("a", "b")]) == result  # deterministic


if __name__ == "__main__":
    failures = 0
    for name, fn in sorted(globals().items()):
        if name.startswith("test_") and callable(fn):
            try:
                fn()
                print(f"PASS {name}")
            except AssertionError as exc:
                failures += 1
                print(f"FAIL {name}: {exc}")
    print(f"\n{'OK' if failures == 0 else 'FAILED'}: {failures} failure(s)")
    raise SystemExit(1 if failures else 0)
