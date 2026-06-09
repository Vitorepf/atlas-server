"""Tests for code_graph cross-workspace forward traversal (AP-815, X-1).

Runnable with pytest OR directly: `python3 tests/test_cross_workspace_traverse.py`
(networkx/pytest may be absent in the local runtime, so it self-runs).
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from atlas_code_graph.cross_workspace_traverse import traverse  # noqa: E402


def _edge(src: str, dst: str, workspace: str | None = None) -> dict:
    edge = {"from": src, "to": dst}
    if workspace is not None:
        edge["workspace"] = workspace
    return edge


def _depth_index(result: dict) -> dict:
    """Map node id -> depth for easy assertions."""
    return {r["id"]: r["depth"] for r in result["reachable"]}


def _workspace_index(result: dict) -> dict:
    """Map node id -> discovering workspace for easy assertions."""
    return {r["id"]: r["workspace"] for r in result["reachable"]}


def test_reaches_nodes_across_three_workspaces() -> None:
    # seed (ws "repo-a") -> b (repo-a) -> c (repo-b) -> d (repo-c).
    edges = [
        _edge("seed", "b", "repo-a"),
        _edge("b", "c", "repo-b"),
        _edge("c", "d", "repo-c"),
    ]
    result = traverse("seed", edges)

    assert result["schema_version"] == "atlas.code_graph.cross_workspace_traverse.v1"
    assert result["seeds"] == ["seed"]
    depth = _depth_index(result)
    assert depth == {"seed": 0, "b": 1, "c": 2, "d": 3}
    # Reaches into the two OTHER workspaces beyond the seed's own.
    assert result["workspaces_touched"] == ["repo-a", "repo-b", "repo-c"]
    assert result["truncated"] is False


def test_discovering_workspace_recorded_per_node() -> None:
    edges = [
        _edge("seed", "b", "repo-a"),
        _edge("b", "c", "repo-b"),
        _edge("c", "d", "repo-c"),
    ]
    result = traverse("seed", edges)
    ws = _workspace_index(result)
    # Seed has no discovering edge -> None; each other node carries its edge tag.
    assert ws == {"seed": None, "b": "repo-a", "c": "repo-b", "d": "repo-c"}


def test_max_depth_one_limits_to_direct_successors() -> None:
    edges = [
        _edge("seed", "b", "repo-a"),
        _edge("b", "c", "repo-b"),
        _edge("c", "d", "repo-c"),
    ]
    result = traverse("seed", edges, max_depth=1)
    depth = _depth_index(result)
    assert depth == {"seed": 0, "b": 1}
    assert "c" not in depth
    assert "d" not in depth
    # Only the edge we actually traversed (seed->b) contributes a touched ws.
    assert result["workspaces_touched"] == ["repo-a"]


def test_max_depth_zero_keeps_only_seeds() -> None:
    edges = [_edge("seed", "b", "repo-a")]
    result = traverse("seed", edges, max_depth=0)
    assert _depth_index(result) == {"seed": 0}
    assert result["workspaces_touched"] == []


def test_unknown_seed_returns_empty_reachable_but_keeps_seed() -> None:
    edges = [_edge("a", "b", "repo-a")]
    result = traverse("ghost", edges)
    # The seed itself is a node at depth 0 even if it has no outgoing edges.
    assert _depth_index(result) == {"ghost": 0}
    assert result["seeds"] == ["ghost"]
    assert result["workspaces_touched"] == []
    assert result["truncated"] is False


def test_seed_not_in_graph_with_empty_edges() -> None:
    result = traverse("only-seed", [])
    assert _depth_index(result) == {"only-seed": 0}
    assert result["workspaces_touched"] == []


def test_cycle_does_not_loop_forever() -> None:
    # a -> b -> c -> a (cycle) plus a tail c -> d.
    edges = [
        _edge("a", "b", "w1"),
        _edge("b", "c", "w1"),
        _edge("c", "a", "w1"),
        _edge("c", "d", "w2"),
    ]
    result = traverse("a", edges, max_depth=10)
    depth = _depth_index(result)
    # Each node reached once at its minimum depth; no infinite loop.
    assert depth == {"a": 0, "b": 1, "c": 2, "d": 3}
    assert result["workspaces_touched"] == ["w1", "w2"]


def test_self_cycle_seed_is_safe() -> None:
    # Self-edge is dropped (from == to); seed still present.
    result = traverse("a", [_edge("a", "a", "w1")])
    assert _depth_index(result) == {"a": 0}


def test_multiple_seeds_minimum_depth_wins() -> None:
    # s1 -> x (depth 1 from s1); s2 -> y -> x (x is depth 2 from s2).
    # x's reported depth must be the MINIMUM (1).
    edges = [
        _edge("s1", "x", "w1"),
        _edge("s2", "y", "w2"),
        _edge("y", "x", "w2"),
    ]
    result = traverse(["s2", "s1"], edges)
    depth = _depth_index(result)
    assert depth["s1"] == 0
    assert depth["s2"] == 0
    assert depth["y"] == 1
    assert depth["x"] == 1  # nearest seed (s1) wins, not 2 via s2
    assert result["seeds"] == ["s1", "s2"]  # sorted, deduped


def test_max_nodes_truncates_and_flags() -> None:
    # seed fans out to many nodes; cap keeps shallowest/lexical-smallest.
    edges = [_edge("seed", f"n{i:02d}", "w1") for i in range(10)]
    result = traverse("seed", edges, max_nodes=3)
    assert result["truncated"] is True
    assert len(result["reachable"]) == 3
    ids = [r["id"] for r in result["reachable"]]
    # seed (depth 0) plus the two lexically-smallest neighbours.
    assert ids == ["seed", "n00", "n01"]


def test_max_nodes_zero_is_safe() -> None:
    edges = [_edge("seed", "b", "w1")]
    result = traverse("seed", edges, max_nodes=0)
    assert result["reachable"] == []
    assert result["truncated"] is True


def test_output_sorted_by_depth_then_id() -> None:
    edges = [
        _edge("s", "z", "w1"),
        _edge("s", "a", "w1"),
        _edge("a", "m", "w1"),
    ]
    result = traverse("s", edges)
    order = [(r["depth"], r["id"]) for r in result["reachable"]]
    assert order == [(0, "s"), (1, "a"), (1, "z"), (2, "m")]


def test_untagged_edges_yield_none_workspace() -> None:
    edges = [_edge("a", "b"), _edge("b", "c")]  # no workspace tags
    result = traverse("a", edges)
    ws = _workspace_index(result)
    assert ws == {"a": None, "b": None, "c": None}
    assert result["workspaces_touched"] == []


def test_diamond_first_discovery_workspace_deterministic() -> None:
    # a -> b (w-mid) -> d (w-left); a -> c (w-mid) -> d (w-right).
    # d is reached at depth 2 via two paths; sorted neighbour order means
    # b is expanded before c, so d's discovering edge is b->d (w-left).
    edges = [
        _edge("a", "b", "w-mid"),
        _edge("a", "c", "w-mid"),
        _edge("b", "d", "w-left"),
        _edge("c", "d", "w-right"),
    ]
    result = traverse("a", edges)
    depth = _depth_index(result)
    ws = _workspace_index(result)
    assert depth["d"] == 2
    assert ws["d"] == "w-left"  # b sorts before c -> b->d wins
    assert result["workspaces_touched"] == ["w-left", "w-mid", "w-right"]


def test_duplicate_edge_conflicting_tag_smallest_wins() -> None:
    # Same (a,b) edge appears twice with different tags -> lexically smallest.
    edges = [_edge("a", "b", "w-zzz"), _edge("a", "b", "w-aaa")]
    result = traverse("a", edges)
    assert _workspace_index(result)["b"] == "w-aaa"


def test_deterministic_across_runs() -> None:
    edges = [
        _edge("s", "b", "w1"),
        _edge("b", "c", "w2"),
        _edge("c", "s", "w1"),
        _edge("c", "d", "w3"),
    ]
    assert traverse("s", edges) == traverse("s", edges)


def test_garbage_edges_skipped_no_raise() -> None:
    edges = [
        None,
        "not-a-dict",
        123,
        {"from": "a"},                       # missing to
        {"to": "b"},                         # missing from
        {"from": 5, "to": "b"},              # non-str from
        {"from": "a", "to": ""},             # empty to
        {"from": "  ", "to": "b"},           # whitespace-only from
        {"from": "a", "to": "b", "workspace": "w1"},   # the only valid one
    ]
    result = traverse("a", edges)
    assert _depth_index(result) == {"a": 0, "b": 1}
    assert result["workspaces_touched"] == ["w1"]


def test_non_string_workspace_tag_treated_as_none() -> None:
    edges = [{"from": "a", "to": "b", "workspace": 123}]
    result = traverse("a", edges)
    assert _workspace_index(result)["b"] is None
    assert result["workspaces_touched"] == []


def test_bad_seeds_arg_is_safe() -> None:
    assert traverse(None, [_edge("a", "b")])["reachable"] == []
    assert traverse(42, [_edge("a", "b")])["seeds"] == []
    assert traverse(123, [])["reachable"] == []


def test_seed_list_with_garbage_entries() -> None:
    edges = [_edge("a", "b", "w1")]
    result = traverse(["a", None, "", 5, "  "], edges)
    assert result["seeds"] == ["a"]
    assert _depth_index(result) == {"a": 0, "b": 1}


def test_bad_bounds_fall_back_safely() -> None:
    edges = [_edge("a", "b", "w1"), _edge("b", "c", "w2")]
    # Non-int max_depth / max_nodes must not raise -> fall back to defaults.
    result = traverse("a", edges, max_depth="bad", max_nodes="bad")
    assert _depth_index(result) == {"a": 0, "b": 1, "c": 2}
    # Negative bounds clamp.
    result2 = traverse("a", edges, max_depth=-3)
    assert _depth_index(result2) == {"a": 0}
    result3 = traverse("a", edges, max_nodes=-1)
    assert result3["reachable"] == []
    assert result3["truncated"] is True


def test_empty_string_seed_is_dropped() -> None:
    result = traverse("", [_edge("a", "b")])
    assert result["seeds"] == []
    assert result["reachable"] == []


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
