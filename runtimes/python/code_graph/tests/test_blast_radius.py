"""Tests for code_graph cross-repo blast-radius / reverse reachability (AP-815).

Runnable with pytest OR directly: `python3 tests/test_blast_radius.py`
(networkx/pytest may be absent in the local runtime, so it self-runs).
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from atlas_code_graph.blast_radius import blast_radius  # noqa: E402


def _edge(src: str, dst: str, workspace: str | None = None) -> dict:
    """``src`` DEPENDS ON ``dst`` (optionally tagged with a workspace)."""
    edge = {"from": src, "to": dst}
    if workspace is not None:
        edge["workspace"] = workspace
    return edge


def _impacted_index(result: dict) -> dict:
    """Map impacted node id -> depth for easy assertions."""
    return {item["id"]: item["depth"] for item in result["impacted"]}


def test_spec_example_reverse_reachability() -> None:
    # A->B, X->B, Y->X : A and X depend on B (depth 1); Y depends on X (depth 2).
    edges = [_edge("A", "B"), _edge("X", "B"), _edge("Y", "X")]
    result = blast_radius(["B"], edges)

    assert result["schema_version"] == "atlas.code_graph.blast_radius.v1"
    idx = _impacted_index(result)
    assert idx == {"A": 1, "X": 1, "Y": 2}
    assert result["impacted_count"] == 3
    assert result["changed"] == ["B"]
    assert result["truncated"] is False


def test_depth_one_stops_at_direct_dependents() -> None:
    # max_depth=1 -> only the nodes directly depending on B (A, X); Y is excluded.
    edges = [_edge("A", "B"), _edge("X", "B"), _edge("Y", "X")]
    result = blast_radius(["B"], edges, max_depth=1)
    idx = _impacted_index(result)
    assert idx == {"A": 1, "X": 1}
    assert "Y" not in idx


def test_empty_changed_yields_empty_impacted() -> None:
    edges = [_edge("A", "B"), _edge("X", "B"), _edge("Y", "X")]
    result = blast_radius([], edges)
    assert result["impacted"] == []
    assert result["impacted_count"] == 0
    assert result["by_workspace"] == {}
    assert result["changed"] == []


def test_by_workspace_counts_across_repos() -> None:
    # A and X depend on B (depth 1), Y depends on X (depth 2). Each dependent
    # carries the workspace from its OUTGOING edge (the impacted node's repo).
    edges = [
        _edge("A", "B", workspace="repo_a"),
        _edge("X", "B", workspace="repo_b"),
        _edge("Y", "X", workspace="repo_b"),
    ]
    result = blast_radius(["B"], edges)
    assert _impacted_index(result) == {"A": 1, "X": 1, "Y": 2}
    # repo_a -> {A}; repo_b -> {X, Y}.
    assert result["by_workspace"] == {"repo_a": 1, "repo_b": 2}


def test_changed_nodes_excluded_from_impacted() -> None:
    # B and X are both changed; X depends on B but must not appear as impacted.
    edges = [_edge("A", "B"), _edge("X", "B"), _edge("Y", "X")]
    result = blast_radius(["B", "X"], edges)
    idx = _impacted_index(result)
    assert "B" not in idx
    assert "X" not in idx
    # A depends on B (depth 1); Y depends on X (depth 1, since X is a seed).
    assert idx == {"A": 1, "Y": 1}
    assert set(result["changed"]) == {"B", "X"}


def test_minimum_depth_reported_for_multiple_paths() -> None:
    # D depends on B directly (depth 1) AND via C (would be depth 2). BFS must
    # report the shallower depth 1.
    edges = [_edge("C", "B"), _edge("D", "B"), _edge("D", "C")]
    result = blast_radius(["B"], edges)
    idx = _impacted_index(result)
    assert idx == {"C": 1, "D": 1}


def test_diamond_deeper_only_via_longer_path() -> None:
    # top depends on left and right; left and right depend on base.
    # base changed -> left, right at depth 1; top at depth 2.
    edges = [
        _edge("top", "left"),
        _edge("top", "right"),
        _edge("left", "base"),
        _edge("right", "base"),
    ]
    result = blast_radius(["base"], edges)
    assert _impacted_index(result) == {"left": 1, "right": 1, "top": 2}


def test_cycle_terminates_and_is_bounded() -> None:
    # A->B->C->A dependency cycle; changing A must terminate (no infinite loop).
    edges = [_edge("A", "B"), _edge("B", "C"), _edge("C", "A")]
    result = blast_radius(["A"], edges)
    idx = _impacted_index(result)
    # Who depends on A? C. Who depends on C? B. Who depends on B? A (a seed,
    # excluded). So C(1), B(2).
    assert idx == {"C": 1, "B": 2}


def test_unknown_workspace_default_when_untagged() -> None:
    edges = [_edge("A", "B")]  # no workspace tag
    result = blast_radius(["B"], edges)
    assert result["impacted"][0]["workspace"] == "unknown"
    assert result["by_workspace"] == {"unknown": 1}


def test_no_dependents_yields_empty() -> None:
    # Nothing depends on a leaf that only itself depends on others.
    edges = [_edge("A", "B")]  # A depends on B; nothing depends on A.
    result = blast_radius(["A"], edges)
    assert result["impacted"] == []
    assert result["impacted_count"] == 0


def test_max_nodes_truncates() -> None:
    # Five distinct nodes depend on hub; cap impacted at 3.
    edges = [_edge(dep, "hub") for dep in ("a", "b", "c", "d", "e")]
    result = blast_radius(["hub"], edges, max_nodes=3)
    assert result["impacted_count"] == 3
    assert result["truncated"] is True
    # Deterministic: lexically smallest direct dependents kept.
    assert [item["id"] for item in result["impacted"]] == ["a", "b", "c"]


def test_max_depth_zero_yields_empty() -> None:
    edges = [_edge("A", "B"), _edge("X", "B")]
    result = blast_radius(["B"], edges, max_depth=0)
    assert result["impacted"] == []
    assert result["truncated"] is False


def test_impacted_sorted_by_depth_then_id() -> None:
    edges = [
        _edge("zeta", "B"),
        _edge("alpha", "B"),
        _edge("deep", "alpha"),
    ]
    result = blast_radius(["B"], edges)
    order = [(item["depth"], item["id"]) for item in result["impacted"]]
    # depth 1: alpha, zeta (lexical); depth 2: deep.
    assert order == [(1, "alpha"), (1, "zeta"), (2, "deep")]


def test_duplicate_edges_counted_once() -> None:
    edges = [_edge("A", "B"), _edge("A", "B"), _edge("A", "B")]
    result = blast_radius(["B"], edges)
    idx = _impacted_index(result)
    assert idx == {"A": 1}
    assert result["impacted_count"] == 1


def test_garbage_edges_skipped_no_raise() -> None:
    edges = [
        None,
        "not-a-dict",
        42,
        {"from": "A"},                      # missing to
        {"to": "B"},                        # missing from
        {"from": "", "to": "B"},            # blank from
        {"from": "A", "to": ""},            # blank to
        {"from": "S", "to": "S"},           # self-loop dropped
        {"from": 5, "to": "B"},             # non-string from
        {"from": "A", "to": "B"},           # the one valid edge
    ]
    result = blast_radius(["B"], edges)
    idx = _impacted_index(result)
    assert idx == {"A": 1}


def test_non_list_args_are_safe() -> None:
    assert blast_radius(None, None)["impacted"] == []
    assert blast_radius("garbage", "garbage")["impacted_count"] == 0
    assert blast_radius(42, {"not": "a list"})["by_workspace"] == {}
    # changed present but edges malformed -> still safe.
    assert blast_radius(["B"], None)["impacted"] == []


def test_bad_bounds_fall_back_safely() -> None:
    edges = [_edge("A", "B"), _edge("X", "B"), _edge("Y", "X")]
    # Non-int max_depth falls back to default 3 (Y at depth 2 included).
    r1 = blast_radius(["B"], edges, max_depth="bad")
    assert _impacted_index(r1) == {"A": 1, "X": 1, "Y": 2}
    # Negative max_depth clamps to 0.
    r2 = blast_radius(["B"], edges, max_depth=-5)
    assert r2["impacted"] == []
    # Non-int max_nodes falls back to default 500.
    r3 = blast_radius(["B"], edges, max_nodes="bad")
    assert r3["impacted_count"] == 3
    # Negative max_nodes clamps to 0.
    r4 = blast_radius(["B"], edges, max_nodes=-1)
    assert r4["impacted"] == []
    assert r4["truncated"] is True


def test_deterministic_across_runs() -> None:
    edges = [
        _edge("A", "B", workspace="r1"),
        _edge("X", "B", workspace="r2"),
        _edge("Y", "X", workspace="r2"),
        _edge("Z", "Y", workspace="r3"),
    ]
    assert blast_radius(["B"], edges) == blast_radius(["B"], edges)


def test_multiple_changed_seeds_union() -> None:
    # Two independent subgraphs, both seeded.
    edges = [
        _edge("a1", "B1"),
        _edge("a2", "B2"),
    ]
    result = blast_radius(["B1", "B2"], edges)
    assert _impacted_index(result) == {"a1": 1, "a2": 1}


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
