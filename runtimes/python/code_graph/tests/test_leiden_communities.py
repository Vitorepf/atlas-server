"""Tests for TRUE Leiden community detection (AP-815 P-13).

Runnable with pytest OR directly: `python3 tests/test_leiden_communities.py`
(igraph/leidenalg/pytest may be absent in some runtimes, so it self-runs and the
heavy-dep path degrades safely instead of failing the suite).
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from atlas_code_graph.leiden_communities import (  # noqa: E402
    SCHEMA,
    leiden_communities,
)

# Whether the heavy deps are importable in THIS runtime. If not, the heavy-dep
# tests assert the documented fail-safe contract instead of the partition.
try:  # pragma: no cover - environment dependent
    import igraph  # noqa: F401
    import leidenalg  # noqa: F401

    _HAS_LEIDEN = True
except Exception:  # noqa: BLE001
    _HAS_LEIDEN = False


def _edge(a: str, b: str, etype: str = "calls") -> dict:
    """Build one edge dict in the {from,to,type} shape the block specifies."""
    return {"from": a, "to": b, "type": etype}


def _two_triangles() -> list:
    """Two clearly separated triangles, no cross edge: {A,B,C} and {X,Y,Z}."""
    return [
        _edge("A", "B"), _edge("B", "C"), _edge("A", "C"),
        _edge("X", "Y"), _edge("Y", "Z"), _edge("X", "Z"),
    ]


def _members_set(result: dict) -> set:
    """Return the set of frozenset(members) for order-independent comparison."""
    return {frozenset(c["members"]) for c in result["communities"]}


# --- shape / contract tests (run regardless of whether the deps exist) ------

def test_schema_and_algorithm_fields_always_present() -> None:
    result = leiden_communities(_two_triangles())
    assert result["schema_version"] == SCHEMA
    assert result["algorithm"] == "leiden"
    assert isinstance(result["communities"], list)
    assert isinstance(result["modularity"], float)
    assert isinstance(result["node_count"], int)
    assert isinstance(result["edge_count"], int)


def test_empty_edges_is_safe() -> None:
    result = leiden_communities([])
    assert result["communities"] == []
    assert result["node_count"] == 0
    assert result["edge_count"] == 0
    assert result["modularity"] == 0.0
    assert result["algorithm"] == "leiden"
    assert "note" in result  # explains the empty graph


def test_garbage_edges_never_raise() -> None:
    garbage = [
        None,
        "not-a-dict",
        123,
        {"from": "a"},                 # missing 'to'
        {"to": "b"},                   # missing 'from'
        {"from": 1, "to": 2},          # non-str endpoints
        {"from": "", "to": "  "},      # empty after strip
        {"from": "a", "to": "a"},      # self-loop dropped
    ]
    result = leiden_communities(garbage)
    # Nothing valid survived -> safe empty, no exception.
    assert result["communities"] == []
    assert result["node_count"] == 0
    assert result["edge_count"] == 0


def test_non_list_edges_arg_is_safe() -> None:
    assert leiden_communities(None)["communities"] == []
    assert leiden_communities("garbage")["communities"] == []
    assert leiden_communities(42)["node_count"] == 0


def test_bad_resolution_and_seed_fall_back_safely() -> None:
    # Non-numeric / negative tunables must not raise.
    r1 = leiden_communities(_two_triangles(), resolution="bad", seed="nope")
    assert r1["algorithm"] == "leiden"
    r2 = leiden_communities(_two_triangles(), resolution=-5.0)
    assert r2["algorithm"] == "leiden"


# --- partition tests (only meaningful when the heavy deps are installed) -----

def test_two_separated_triangles_give_two_communities() -> None:
    result = leiden_communities(_two_triangles())
    if not _HAS_LEIDEN:
        # Documented fail-safe: degrade, report parsed size, carry a note.
        assert result["communities"] == []
        assert result["node_count"] == 6
        assert result["edge_count"] == 6
        assert "note" in result
        return

    assert result["node_count"] == 6
    assert result["edge_count"] == 6
    assert len(result["communities"]) == 2
    # Exactly one community per triangle.
    assert _members_set(result) == {
        frozenset({"A", "B", "C"}),
        frozenset({"X", "Y", "Z"}),
    }
    # Each community is sorted, sized correctly, and ids are 0..n-1.
    ids = [c["id"] for c in result["communities"]]
    assert ids == [0, 1]
    for c in result["communities"]:
        assert c["members"] == sorted(c["members"])
        assert c["size"] == len(c["members"]) == 3
    assert result["modularity"] > 0.0  # well-separated -> positive modularity


def test_single_edge_is_one_community() -> None:
    result = leiden_communities([_edge("a", "b")])
    if not _HAS_LEIDEN:
        assert result["node_count"] == 2
        assert result["edge_count"] == 1
        return
    assert result["node_count"] == 2
    assert result["edge_count"] == 1
    assert len(result["communities"]) == 1
    assert result["communities"][0]["members"] == ["a", "b"]
    assert result["communities"][0]["size"] == 2


def test_duplicate_and_reversed_edges_deduped() -> None:
    # {a,b} given 3 ways must collapse to a single undirected edge.
    edges = [_edge("a", "b"), _edge("b", "a"), _edge("a", "b", "imports")]
    result = leiden_communities(edges)
    assert result["edge_count"] == 1
    assert result["node_count"] == 2


def test_communities_sorted_by_size_desc_then_first_member() -> None:
    if not _HAS_LEIDEN:
        return
    # A big 4-clique {a,b,c,d} and a small 2-clique {y,z}; expect the big one
    # first (size desc). High resolution keeps the two cliques apart.
    edges = [
        _edge("a", "b"), _edge("a", "c"), _edge("a", "d"),
        _edge("b", "c"), _edge("b", "d"), _edge("c", "d"),
        _edge("y", "z"),
    ]
    result = leiden_communities(edges, resolution=1.0)
    sizes = [c["size"] for c in result["communities"]]
    # Sizes are non-increasing (the core sort guarantee).
    assert sizes == sorted(sizes, reverse=True)
    # The 4-clique should be detected as one community and come first.
    assert result["communities"][0]["size"] >= result["communities"][-1]["size"]
    assert frozenset({"a", "b", "c", "d"}) in _members_set(result)


def test_deterministic_across_runs() -> None:
    edges = _two_triangles()
    assert leiden_communities(edges) == leiden_communities(edges)
    # Same seed + same input -> identical communities AND modularity.
    a = leiden_communities(edges, seed=7)
    b = leiden_communities(edges, seed=7)
    assert a == b


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
            except Exception as exc:  # noqa: BLE001 — surface unexpected raises
                failures += 1
                print(f"ERROR {name}: {exc!r}")
    print(f"\n{'OK' if failures == 0 else 'FAILED'}: {failures} failure(s)")
    raise SystemExit(1 if failures else 0)
