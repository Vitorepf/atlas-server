"""Tests for the code_graph betweenness centrality (AP-812).

Runnable with pytest OR directly: `python3 tests/test_centrality.py`
(networkx/pytest may be absent in the local runtime, so it self-runs).
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from atlas_code_graph import betweenness_centrality  # noqa: E402


def _edge(a: str, b: str) -> dict:
    return {"from_node_id": a, "to_node_id": b}


def test_path_graph_middle_node_is_most_central() -> None:
    # a - b - c - d - e : c sits on the most shortest paths.
    edges = [_edge("a", "b"), _edge("b", "c"), _edge("c", "d"), _edge("d", "e")]
    result = betweenness_centrality(edges)

    assert result["schema_version"] == "atlas.code_graph.centrality.v1"
    assert result["node_count"] == 5
    assert result["edge_count"] == 4
    assert result["ranked"][0]["node_id"] == "c"
    scores = {r["node_id"]: r["betweenness"] for r in result["ranked"]}
    assert scores["c"] > scores["b"]
    assert scores["b"] == scores["d"]  # symmetric
    assert scores["a"] == 0.0
    assert scores["e"] == 0.0


def test_star_graph_center_is_most_central() -> None:
    edges = [_edge("hub", "a"), _edge("hub", "b"), _edge("hub", "c"), _edge("hub", "d")]
    result = betweenness_centrality(edges)

    assert result["ranked"][0]["node_id"] == "hub"
    scores = {r["node_id"]: r["betweenness"] for r in result["ranked"]}
    assert scores["hub"] > 0.0
    for leaf in ("a", "b", "c", "d"):
        assert scores[leaf] == 0.0


def test_deterministic_across_runs() -> None:
    edges = [_edge("a", "b"), _edge("b", "c"), _edge("c", "a"), _edge("c", "d")]
    assert betweenness_centrality(edges) == betweenness_centrality(edges)


def test_empty_graph_is_safe() -> None:
    result = betweenness_centrality([])
    assert result["node_count"] == 0
    assert result["ranked"] == []


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
