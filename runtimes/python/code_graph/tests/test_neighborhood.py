"""Tests for code_graph bounded bidirectional k-hop neighborhood (AP-815, D-3).

Runnable with pytest OR directly: `python3 tests/test_neighborhood.py`
(networkx/pytest may be absent in the local runtime, so it self-runs).
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from atlas_code_graph.neighborhood import neighborhood  # noqa: E402


def _depth_index(result: dict) -> dict:
    """Map node id -> depth for easy assertions."""
    return {n["id"]: n["depth"] for n in result["nodes"]}


def _edge_set(result: dict) -> set:
    """Set of (from, to) pairs in the induced subgraph."""
    return {(e["from"], e["to"]) for e in result["edges"]}


# A-B-C-D as a path of directed edges (traversed undirected by the module).
_LINE = [
    {"from": "A", "to": "B", "type": "calls"},
    {"from": "B", "to": "C", "type": "calls"},
    {"from": "C", "to": "D", "type": "calls"},
]


def test_line_k2_from_b_reaches_a_c_at_1_and_d_at_2() -> None:
    result = neighborhood("B", _LINE, k=2)
    idx = _depth_index(result)

    assert result["schema_version"] == "atlas.code_graph.neighborhood.v1"
    assert result["center"] == "B"
    assert idx == {"B": 0, "A": 1, "C": 1, "D": 2}
    assert result["truncated"] is False
    assert result["node_count"] == 4


def test_line_sorted_by_depth_then_id() -> None:
    # B(0), then depth-1 lexical (A before C), then D(2).
    result = neighborhood("B", _LINE, k=2)
    order = [n["id"] for n in result["nodes"]]
    assert order == ["B", "A", "C", "D"]


def test_induced_edges_among_returned_nodes_included() -> None:
    # With all of A,B,C,D present every line edge is in the induced subgraph,
    # preserved in canonical input direction.
    result = neighborhood("B", _LINE, k=2)
    assert _edge_set(result) == {("A", "B"), ("B", "C"), ("C", "D")}
    assert result["edge_count"] == 3
    # type metadata preserved.
    assert all(e["type"] == "calls" for e in result["edges"])


def test_k1_limits_to_a_b_c() -> None:
    # One hop from B reaches A and C, but NOT D; the C-D edge is excluded from
    # the induced subgraph because D is not in the node set.
    result = neighborhood("B", _LINE, k=1)
    idx = _depth_index(result)
    assert idx == {"B": 0, "A": 1, "C": 1}
    assert "D" not in idx
    assert _edge_set(result) == {("A", "B"), ("B", "C")}
    assert result["truncated"] is False


def test_k0_returns_only_center() -> None:
    result = neighborhood("B", _LINE, k=0)
    assert _depth_index(result) == {"B": 0}
    assert result["edges"] == []
    assert result["truncated"] is False


def test_bidirectional_reachability_both_directions() -> None:
    # Edges X->center and center->Y; undirected traversal reaches BOTH from
    # the center at depth 1.
    edges = [
        {"from": "X", "to": "CENTER", "type": "imports"},  # incoming
        {"from": "CENTER", "to": "Y", "type": "imports"},  # outgoing
    ]
    result = neighborhood("CENTER", edges, k=1)
    idx = _depth_index(result)
    assert idx == {"CENTER": 0, "X": 1, "Y": 1}


def test_unknown_center_returns_just_the_center() -> None:
    result = neighborhood("ZZZ", _LINE, k=2)
    assert _depth_index(result) == {"ZZZ": 0}
    assert result["center"] == "ZZZ"
    assert result["edges"] == []
    assert result["node_count"] == 1
    assert result["truncated"] is False


def test_max_nodes_truncates() -> None:
    # Star: center C with many leaves; cap below the full set must truncate.
    edges = [{"from": "C", "to": f"L{i}", "type": "calls"} for i in range(10)]
    result = neighborhood("C", edges, k=1, max_nodes=4)
    assert result["node_count"] == 4  # center + 3 leaves
    assert result["truncated"] is True
    # Center is always kept; the lexically smallest leaves fill the rest.
    idx = _depth_index(result)
    assert idx["C"] == 0
    assert all(v in (0, 1) for v in idx.values())


def test_max_nodes_one_keeps_only_center() -> None:
    result = neighborhood("C", [{"from": "C", "to": "L0", "type": "x"}], k=1, max_nodes=1)
    assert _depth_index(result) == {"C": 0}
    assert result["truncated"] is True


def test_cycle_is_safe_terminates() -> None:
    # A<->B<->C<->A cycle must terminate and report each node once.
    edges = [
        {"from": "A", "to": "B", "type": "calls"},
        {"from": "B", "to": "C", "type": "calls"},
        {"from": "C", "to": "A", "type": "calls"},
    ]
    result = neighborhood("A", edges, k=5)
    idx = _depth_index(result)
    assert idx == {"A": 0, "B": 1, "C": 1}  # B & C both one undirected hop away
    assert result["truncated"] is False
    assert _edge_set(result) == {("A", "B"), ("B", "C"), ("C", "A")}


def test_self_loops_and_duplicate_edges_handled() -> None:
    edges = [
        {"from": "A", "to": "A", "type": "self"},  # self-loop dropped
        {"from": "A", "to": "B", "type": "calls"},
        {"from": "A", "to": "B", "type": "calls"},  # exact duplicate deduped
    ]
    result = neighborhood("A", edges, k=1)
    assert _depth_index(result) == {"A": 0, "B": 1}
    # self-loop excluded; duplicate collapsed to one induced edge.
    assert _edge_set(result) == {("A", "B")}
    assert result["edge_count"] == 1


def test_distinct_types_same_pair_kept_separately() -> None:
    # Same (from, to) with different types are distinct edges in the subgraph.
    edges = [
        {"from": "A", "to": "B", "type": "calls"},
        {"from": "A", "to": "B", "type": "imports"},
    ]
    result = neighborhood("A", edges, k=1)
    induced = sorted((e["from"], e["to"], e["type"]) for e in result["edges"])
    assert induced == [("A", "B", "calls"), ("A", "B", "imports")]


def test_missing_type_defaults_to_empty_string() -> None:
    result = neighborhood("A", [{"from": "A", "to": "B"}], k=1)
    assert result["edges"] == [{"from": "A", "to": "B", "type": ""}]


def test_garbage_edges_skipped_no_raise() -> None:
    edges = [
        None,
        "not-a-dict",
        123,
        {"from": "A"},                       # missing to
        {"to": "B"},                         # missing from
        {"from": 5, "to": "B"},              # non-string from
        {"from": "A", "to": ""},             # blank to
        {"from": "  ", "to": "B"},           # blank-after-strip from
        {"from": "A", "to": "B", "type": "ok"},  # the only valid edge
    ]
    result = neighborhood("A", edges, k=2)
    assert _depth_index(result) == {"A": 0, "B": 1}
    assert _edge_set(result) == {("A", "B")}


def test_invalid_center_returns_empty() -> None:
    for bad in (None, 123, "", "   ", ["A"], {"x": 1}):
        result = neighborhood(bad, _LINE, k=2)
        assert result["center"] == ""
        assert result["nodes"] == []
        assert result["edges"] == []
        assert result["node_count"] == 0
        assert result["truncated"] is False


def test_non_list_edges_arg_is_safe() -> None:
    assert neighborhood("A", None)["nodes"] == [{"id": "A", "depth": 0}]
    assert neighborhood("A", "garbage")["edges"] == []
    assert neighborhood("A", 42)["node_count"] == 1


def test_bad_k_and_max_nodes_fall_back_safely() -> None:
    # Non-int k falls back to default 2.
    res_k = neighborhood("B", _LINE, k="bad")
    assert _depth_index(res_k) == {"B": 0, "A": 1, "C": 1, "D": 2}
    # Negative k clamps to 0.
    res_neg = neighborhood("B", _LINE, k=-3)
    assert _depth_index(res_neg) == {"B": 0}
    # Non-int max_nodes falls back to a large default (no truncation here).
    res_mn = neighborhood("B", _LINE, k=2, max_nodes="bad")
    assert res_mn["truncated"] is False
    assert res_mn["node_count"] == 4
    # Sub-1 max_nodes clamps to 1 (center only).
    res_zero = neighborhood("B", _LINE, k=2, max_nodes=0)
    assert _depth_index(res_zero) == {"B": 0}
    assert res_zero["truncated"] is True


def test_center_with_no_edges_in_graph() -> None:
    # Center exists nowhere in the edge set but is still returned at depth 0.
    edges = [{"from": "X", "to": "Y", "type": "calls"}]
    result = neighborhood("LONELY", edges, k=3)
    assert _depth_index(result) == {"LONELY": 0}
    assert result["edges"] == []


def test_center_whitespace_is_stripped() -> None:
    result = neighborhood("  B  ", _LINE, k=1)
    assert result["center"] == "B"
    assert _depth_index(result) == {"B": 0, "A": 1, "C": 1}


def test_deterministic_across_runs() -> None:
    edges = [
        {"from": "A", "to": "B", "type": "calls"},
        {"from": "B", "to": "C", "type": "imports"},
        {"from": "D", "to": "B", "type": "calls"},
        {"from": "C", "to": "A", "type": "calls"},
    ]
    assert neighborhood("B", edges, k=2) == neighborhood("B", edges, k=2)


def test_induced_edges_sorted_deterministically() -> None:
    edges = [
        {"from": "C", "to": "A", "type": "z"},
        {"from": "A", "to": "B", "type": "a"},
        {"from": "B", "to": "C", "type": "m"},
    ]
    result = neighborhood("A", edges, k=2)
    induced = [(e["from"], e["to"], e["type"]) for e in result["edges"]]
    # Sorted by (from, to, type).
    assert induced == [("A", "B", "a"), ("B", "C", "m"), ("C", "A", "z")]


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
