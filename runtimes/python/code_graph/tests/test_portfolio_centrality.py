"""Tests for portfolio-level cross-workspace god-nodes (AP-815, block X-6).

Runnable with pytest OR directly: `python3 tests/test_portfolio_centrality.py`
(networkx/pytest may be absent in the local runtime, so it self-runs).
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from atlas_code_graph.portfolio_centrality import portfolio_centrality  # noqa: E402


def _god_index(result: dict) -> dict:
    """Map node id -> its god_node entry for easy assertions."""
    return {entry["id"]: entry for entry in result["god_nodes"]}


def test_schema_and_empty_is_safe() -> None:
    result = portfolio_centrality([])
    assert result["schema_version"] == "atlas.code_graph.portfolio_centrality.v1"
    assert result["nodes"] == 0
    assert result["edges"] == 0
    assert result["god_nodes"] == []


def test_hub_across_two_workspaces_ranks_top_with_both_workspaces() -> None:
    # `core` is a hub in repo-a (3 outgoing) and also depended on in repo-b.
    graphs = [
        {
            "workspace": "repo-a",
            "edges": [
                {"from": "core", "to": "a1", "type": "calls"},
                {"from": "core", "to": "a2", "type": "calls"},
                {"from": "core", "to": "a3", "type": "calls"},
            ],
        },
        {
            "workspace": "repo-b",
            "edges": [
                {"from": "b1", "to": "core", "type": "imports"},
                {"from": "b2", "to": "core", "type": "imports"},
            ],
        },
    ]
    result = portfolio_centrality(graphs)
    idx = _god_index(result)

    # core is the merged hub: out=3 (a1,a2,a3) + in=2 (b1,b2) = degree 5.
    assert result["god_nodes"][0]["id"] == "core"
    assert idx["core"]["degree"] == 5
    assert idx["core"]["out"] == 3
    assert idx["core"]["in"] == 2
    # It must list BOTH workspaces it participates in, sorted.
    assert idx["core"]["workspaces"] == ["repo-a", "repo-b"]


def test_degree_math_in_plus_out() -> None:
    graphs = [
        {
            "workspace": "ws",
            "edges": [
                {"from": "n", "to": "x"},
                {"from": "n", "to": "y"},
                {"from": "z", "to": "n"},
            ],
        }
    ]
    idx = _god_index(portfolio_centrality(graphs))
    # n: out to x,y (2) + in from z (1) = degree 3.
    assert idx["n"]["out"] == 2
    assert idx["n"]["in"] == 1
    assert idx["n"]["degree"] == 3
    # leaf x: in 1, out 0.
    assert idx["x"]["in"] == 1
    assert idx["x"]["out"] == 0
    assert idx["x"]["degree"] == 1


def test_merge_dedupes_distinct_directed_edges() -> None:
    # Same directed edge listed in two workspaces is ONE merged edge; degree
    # counts the neighbour once, but the node still belongs to both workspaces.
    graphs = [
        {"workspace": "w1", "edges": [{"from": "a", "to": "b"}]},
        {"workspace": "w2", "edges": [{"from": "a", "to": "b"}]},
    ]
    result = portfolio_centrality(graphs)
    idx = _god_index(result)
    assert result["edges"] == 1  # merged to a single (a,b) pair
    assert result["nodes"] == 2
    assert idx["a"]["out"] == 1
    assert idx["a"]["degree"] == 1
    assert idx["a"]["workspaces"] == ["w1", "w2"]
    assert idx["b"]["in"] == 1
    assert idx["b"]["workspaces"] == ["w1", "w2"]


def test_node_count_and_edge_count_of_union() -> None:
    graphs = [
        {"workspace": "w1", "edges": [{"from": "a", "to": "b"}, {"from": "b", "to": "c"}]},
        {"workspace": "w2", "edges": [{"from": "c", "to": "d"}]},
    ]
    result = portfolio_centrality(graphs)
    # nodes: a,b,c,d = 4 ; edges: (a,b),(b,c),(c,d) = 3.
    assert result["nodes"] == 4
    assert result["edges"] == 3


def test_top_limit_respected() -> None:
    # Five disjoint single-edge graphs -> 10 nodes; ask for top 3.
    graphs = [
        {"workspace": f"w{i}", "edges": [{"from": f"s{i}", "to": f"t{i}"}]}
        for i in range(5)
    ]
    result = portfolio_centrality(graphs, top=3)
    assert result["nodes"] == 10
    assert len(result["god_nodes"]) == 3


def test_sorted_by_degree_then_id() -> None:
    # hub has degree 3; a,b,c are degree-1 leaves and must follow in lexical id.
    graphs = [
        {
            "workspace": "w",
            "edges": [
                {"from": "hub", "to": "a"},
                {"from": "hub", "to": "b"},
                {"from": "hub", "to": "c"},
            ],
        }
    ]
    order = [n["id"] for n in portfolio_centrality(graphs)["god_nodes"]]
    assert order == ["hub", "a", "b", "c"]


def test_equal_degree_breaks_ties_lexically() -> None:
    # Two independent edges -> four degree-1 nodes; order must be lexical id.
    graphs = [
        {"workspace": "w", "edges": [{"from": "d", "to": "b"}, {"from": "c", "to": "a"}]},
    ]
    order = [n["id"] for n in portfolio_centrality(graphs)["god_nodes"]]
    assert order == ["a", "b", "c", "d"]


def test_blank_workspace_falls_back_to_unknown() -> None:
    graphs = [
        {"workspace": "  ", "edges": [{"from": "a", "to": "b"}]},
        {"edges": [{"from": "b", "to": "c"}]},  # missing workspace entirely
    ]
    idx = _god_index(portfolio_centrality(graphs))
    assert idx["a"]["workspaces"] == ["unknown"]
    # b appears in both a blank-ws and a missing-ws graph -> one "unknown".
    assert idx["b"]["workspaces"] == ["unknown"]


def test_self_loops_and_garbage_edges_skipped() -> None:
    graphs = [
        {
            "workspace": "w",
            "edges": [
                {"from": "a", "to": "a"},      # self-loop dropped
                {"from": "a", "to": ""},        # blank dst dropped
                {"from": None, "to": "b"},      # non-string src dropped
                {"from": "a", "to": "b"},       # the only valid edge
                "not-a-dict",                    # garbage dropped
                {"weird": "shape"},             # missing keys dropped
            ],
        }
    ]
    result = portfolio_centrality(graphs)
    idx = _god_index(result)
    assert result["edges"] == 1
    assert result["nodes"] == 2
    assert idx["a"]["out"] == 1
    assert idx["a"]["degree"] == 1


def test_non_list_graphs_arg_is_safe() -> None:
    assert portfolio_centrality(None)["god_nodes"] == []
    assert portfolio_centrality("garbage")["nodes"] == 0
    assert portfolio_centrality(42)["edges"] == 0
    # A list containing non-dict graphs must not raise.
    assert portfolio_centrality([None, "x", 5])["god_nodes"] == []


def test_bad_top_falls_back_safely() -> None:
    graphs = [{"workspace": "w", "edges": [{"from": "a", "to": "b"}]}]
    # Non-int top -> default; still returns the (<= default) nodes.
    assert len(portfolio_centrality(graphs, top="bad")["god_nodes"]) == 2
    # top < 1 clamps to 1.
    assert len(portfolio_centrality(graphs, top=0)["god_nodes"]) == 1


def test_whitespace_node_ids_are_stripped_and_merged() -> None:
    # "core" and " core " must be the SAME merged node.
    graphs = [
        {"workspace": "w1", "edges": [{"from": "core", "to": "x"}]},
        {"workspace": "w2", "edges": [{"from": " core ", "to": "y"}]},
    ]
    idx = _god_index(portfolio_centrality(graphs))
    assert "core" in idx
    assert idx["core"]["out"] == 2  # x and y
    assert idx["core"]["workspaces"] == ["w1", "w2"]


def test_deterministic_across_runs() -> None:
    graphs = [
        {"workspace": "a", "edges": [{"from": "h", "to": "1"}, {"from": "h", "to": "2"}]},
        {"workspace": "b", "edges": [{"from": "3", "to": "h"}]},
    ]
    assert portfolio_centrality(graphs) == portfolio_centrality(graphs)


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
