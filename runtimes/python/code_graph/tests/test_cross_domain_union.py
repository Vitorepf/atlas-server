"""Tests for code_graph cross-workspace UNION cross-domain graph (AP-815, block X-4).

Runnable with pytest OR directly: `python3 tests/test_cross_domain_union.py`
(networkx/pytest may be absent in the local runtime, so it self-runs).
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from atlas_code_graph.cross_domain_union import union_graph  # noqa: E402


def _edge_keys(result: dict) -> set:
    """Set of (from, to, source) tuples for membership checks."""
    return {(e["from"], e["to"], e["source"]) for e in result["edges"]}


def _node_ids(result: dict) -> set:
    """Set of node ids for membership checks."""
    return {n["id"] for n in result["nodes"]}


def _node_source(result: dict) -> dict:
    """Map node id -> source for assertions."""
    return {n["id"]: n["source"] for n in result["nodes"]}


def test_spec_example_merge_with_tags_and_counts() -> None:
    # One workspace with 2 code edges + 2 domain edges merge into one graph.
    workspace_graphs = [
        {
            "workspace": "api",
            "edges": [
                {"from": "a.py", "to": "b.py", "type": "imports"},
                {"from": "b.py", "to": "c.py", "type": "calls"},
            ],
        },
    ]
    domain_edges = [
        {"from_domain": "engineering", "to_domain": "finance", "type": "handoff"},
        {"from_domain": "finance", "to_domain": "legal", "type": "governs"},
    ]
    result = union_graph(workspace_graphs, domain_edges)

    assert result["schema_version"] == "atlas.code_graph.cross_domain_union.v1"

    # Code edges carried through verbatim, tagged source=code.
    keys = _edge_keys(result)
    assert ("a.py", "b.py", "code") in keys
    assert ("b.py", "c.py", "code") in keys
    # Domain edges prefixed + tagged source=domain.
    assert ("domain:engineering", "domain:finance", "domain") in keys
    assert ("domain:finance", "domain:legal", "domain") in keys

    # Counts.
    assert result["counts"]["code_edges"] == 2
    assert result["counts"]["domain_edges"] == 2
    assert result["counts"]["edges"] == 4
    # Code nodes: a.py, b.py, c.py (3). Domain nodes: engineering, finance,
    # legal (3). Total 6 distinct.
    assert result["counts"]["nodes"] == 6

    # Workspaces + domains listed.
    assert result["workspaces"] == ["api"]
    assert result["domains"] == ["engineering", "finance", "legal"]


def test_node_source_tags() -> None:
    workspace_graphs = [
        {"workspace": "w", "edges": [{"from": "f1", "to": "f2", "type": "imports"}]},
    ]
    domain_edges = [{"from_domain": "eng", "to_domain": "fin", "type": "x"}]
    result = union_graph(workspace_graphs, domain_edges)
    src = _node_source(result)
    assert src["f1"] == "code"
    assert src["f2"] == "code"
    assert src["domain:eng"] == "domain"
    assert src["domain:fin"] == "domain"


def test_nodes_deduped_within_code() -> None:
    # b.py appears as a 'to' and a 'from' -> one node, not two.
    workspace_graphs = [
        {
            "workspace": "w",
            "edges": [
                {"from": "a.py", "to": "b.py", "type": "imports"},
                {"from": "b.py", "to": "c.py", "type": "imports"},
            ],
        },
    ]
    result = union_graph(workspace_graphs, [])
    ids = _node_ids(result)
    assert ids == {"a.py", "b.py", "c.py"}
    assert result["counts"]["nodes"] == 3


def test_same_file_across_workspaces_deduped_node() -> None:
    # The same shared.py touched by two workspaces is ONE node in the union.
    workspace_graphs = [
        {"workspace": "api", "edges": [{"from": "api.py", "to": "shared.py", "type": "imports"}]},
        {"workspace": "web", "edges": [{"from": "web.py", "to": "shared.py", "type": "imports"}]},
    ]
    result = union_graph(workspace_graphs, [])
    src = _node_source(result)
    assert src["shared.py"] == "code"
    # shared.py counted once across both workspaces.
    assert result["counts"]["nodes"] == 3  # api.py, web.py, shared.py
    assert result["workspaces"] == ["api", "web"]


def test_domain_prefix_prevents_collision_with_code_node() -> None:
    # A code node literally named 'finance' must NOT collide with domain:finance.
    workspace_graphs = [
        {"workspace": "w", "edges": [{"from": "finance", "to": "x", "type": "imports"}]},
    ]
    domain_edges = [{"from_domain": "finance", "to_domain": "legal", "type": "g"}]
    result = union_graph(workspace_graphs, domain_edges)
    src = _node_source(result)
    # Code 'finance' and domain 'finance' are distinct ids.
    assert src["finance"] == "code"
    assert src["domain:finance"] == "domain"
    assert "domain:finance" in src


def test_edges_deterministic_sorted() -> None:
    workspace_graphs = [
        {
            "workspace": "w",
            "edges": [
                {"from": "z.py", "to": "a.py", "type": "imports"},
                {"from": "a.py", "to": "b.py", "type": "imports"},
            ],
        },
    ]
    domain_edges = [{"from_domain": "zeta", "to_domain": "alpha", "type": "x"}]
    result = union_graph(workspace_graphs, domain_edges)
    order = [(e["from"], e["to"]) for e in result["edges"]]
    # Sorted by (from, to, type, source): a.py edge, then domain:zeta, then z.py.
    assert order == [
        ("a.py", "b.py"),
        ("domain:zeta", "domain:alpha"),
        ("z.py", "a.py"),
    ]


def test_duplicate_edges_deduped() -> None:
    # The exact same code edge declared twice (e.g. two workspaces re-export the
    # same module pair) collapses to one edge.
    workspace_graphs = [
        {"workspace": "a", "edges": [{"from": "x.py", "to": "y.py", "type": "imports"}]},
        {"workspace": "b", "edges": [{"from": "x.py", "to": "y.py", "type": "imports"}]},
    ]
    result = union_graph(workspace_graphs, [])
    xy = [e for e in result["edges"] if e["from"] == "x.py" and e["to"] == "y.py"]
    assert len(xy) == 1
    assert result["counts"]["code_edges"] == 1
    # Both workspaces still counted (they each contributed a real edge).
    assert result["workspaces"] == ["a", "b"]


def test_same_endpoints_different_type_kept_separate() -> None:
    # imports vs calls between the same pair are distinct structural links.
    workspace_graphs = [
        {
            "workspace": "w",
            "edges": [
                {"from": "a.py", "to": "b.py", "type": "imports"},
                {"from": "a.py", "to": "b.py", "type": "calls"},
            ],
        },
    ]
    result = union_graph(workspace_graphs, [])
    assert result["counts"]["code_edges"] == 2


def test_duplicate_domain_edges_deduped() -> None:
    domain_edges = [
        {"from_domain": "eng", "to_domain": "fin", "type": "handoff"},
        {"from_domain": "eng", "to_domain": "fin", "type": "handoff"},
    ]
    result = union_graph([], domain_edges)
    assert result["counts"]["domain_edges"] == 1
    assert result["domains"] == ["eng", "fin"]


def test_missing_type_defaults_unknown() -> None:
    workspace_graphs = [
        {"workspace": "w", "edges": [{"from": "a", "to": "b"}]},
    ]
    domain_edges = [{"from_domain": "x", "to_domain": "y"}]
    result = union_graph(workspace_graphs, domain_edges)
    code_edge = next(e for e in result["edges"] if e["source"] == "code")
    domain_edge = next(e for e in result["edges"] if e["source"] == "domain")
    assert code_edge["type"] == "unknown"
    assert domain_edge["type"] == "unknown"


def test_workspace_without_name_edges_still_merged() -> None:
    # A graph missing a workspace name still contributes its edges; it just
    # doesn't appear in the workspaces list.
    workspace_graphs = [
        {"edges": [{"from": "a", "to": "b", "type": "imports"}]},
    ]
    result = union_graph(workspace_graphs, [])
    assert result["counts"]["code_edges"] == 1
    assert result["workspaces"] == []


def test_empty_input_is_safe() -> None:
    result = union_graph([], [])
    assert result["nodes"] == []
    assert result["edges"] == []
    assert result["workspaces"] == []
    assert result["domains"] == []
    assert result["counts"] == {
        "nodes": 0,
        "edges": 0,
        "code_edges": 0,
        "domain_edges": 0,
    }


def test_only_code_edges() -> None:
    workspace_graphs = [
        {"workspace": "w", "edges": [{"from": "a", "to": "b", "type": "imports"}]},
    ]
    result = union_graph(workspace_graphs, [])
    assert result["counts"]["code_edges"] == 1
    assert result["counts"]["domain_edges"] == 0
    assert result["domains"] == []


def test_only_domain_edges() -> None:
    domain_edges = [{"from_domain": "a", "to_domain": "b", "type": "x"}]
    result = union_graph([], domain_edges)
    assert result["counts"]["code_edges"] == 0
    assert result["counts"]["domain_edges"] == 1
    assert result["workspaces"] == []
    assert _node_ids(result) == {"domain:a", "domain:b"}


def test_garbage_workspace_graphs_skipped_no_raise() -> None:
    workspace_graphs = [
        None,
        "not-a-dict",
        123,
        {"workspace": "no-edges"},  # missing edges -> skip
        {"workspace": "bad-edges", "edges": "not-a-list"},  # bad edges -> skip
        {"workspace": "real", "edges": [{"from": "a", "to": "b", "type": "imports"}]},
    ]
    result = union_graph(workspace_graphs, [])
    assert result["counts"]["code_edges"] == 1
    assert result["workspaces"] == ["real"]


def test_garbage_edge_entries_skipped() -> None:
    workspace_graphs = [
        {
            "workspace": "w",
            "edges": [
                None,
                "nope",
                {"from": "a"},          # missing to -> skip
                {"to": "b"},            # missing from -> skip
                {"from": "", "to": "b"},  # blank from -> skip
                {"from": "a", "to": "", "type": "imports"},  # blank to -> skip
                {"from": "good_from", "to": "good_to", "type": "imports"},
            ],
        },
    ]
    result = union_graph(workspace_graphs, [])
    assert result["counts"]["code_edges"] == 1
    keys = _edge_keys(result)
    assert ("good_from", "good_to", "code") in keys


def test_garbage_domain_edges_skipped() -> None:
    domain_edges = [
        None,
        42,
        {"from_domain": "a"},                       # missing to_domain -> skip
        {"to_domain": "b"},                         # missing from_domain -> skip
        {"from_domain": "", "to_domain": "b"},      # blank -> skip
        {"from_domain": "eng", "to_domain": "fin", "type": "handoff"},
    ]
    result = union_graph([], domain_edges)
    assert result["counts"]["domain_edges"] == 1
    assert result["domains"] == ["eng", "fin"]


def test_empty_edges_workspace_not_counted() -> None:
    # A workspace whose edge list is empty / all-garbage must not appear.
    workspace_graphs = [
        {"workspace": "empty", "edges": []},
        {"workspace": "garbage-only", "edges": [None, "x", {"from": "a"}]},
        {"workspace": "real", "edges": [{"from": "a", "to": "b", "type": "imports"}]},
    ]
    result = union_graph(workspace_graphs, [])
    assert result["workspaces"] == ["real"]


def test_non_list_args_are_safe() -> None:
    assert union_graph(None, None)["edges"] == []
    assert union_graph("garbage", "garbage")["nodes"] == []
    assert union_graph(42, {"from_domain": "a"})["counts"]["edges"] == 0
    # dict (not list) for either arg is tolerated.
    assert union_graph({"workspace": "a"}, [])["workspaces"] == []


def test_whitespace_trimmed() -> None:
    workspace_graphs = [
        {"workspace": "  w  ", "edges": [{"from": "  a  ", "to": "  b  ", "type": "  imports  "}]},
    ]
    domain_edges = [{"from_domain": "  eng  ", "to_domain": "  fin  ", "type": "  x  "}]
    result = union_graph(workspace_graphs, domain_edges)
    assert result["workspaces"] == ["w"]
    assert result["domains"] == ["eng", "fin"]
    keys = _edge_keys(result)
    assert ("a", "b", "code") in keys
    assert ("domain:eng", "domain:fin", "domain") in keys


def test_nodes_sorted() -> None:
    workspace_graphs = [
        {"workspace": "w", "edges": [{"from": "zzz", "to": "aaa", "type": "imports"}]},
    ]
    domain_edges = [{"from_domain": "mmm", "to_domain": "bbb", "type": "x"}]
    result = union_graph(workspace_graphs, domain_edges)
    ids = [n["id"] for n in result["nodes"]]
    # Sorted lexically across both halves (domain: prefix sorts among them).
    assert ids == sorted(ids)
    assert ids == ["aaa", "domain:bbb", "domain:mmm", "zzz"]


def test_deterministic_across_runs() -> None:
    workspace_graphs = [
        {"workspace": "api", "edges": [{"from": "a.py", "to": "b.py", "type": "imports"}]},
        {"workspace": "web", "edges": [{"from": "c.py", "to": "a.py", "type": "calls"}]},
    ]
    domain_edges = [
        {"from_domain": "engineering", "to_domain": "finance", "type": "handoff"},
        {"from_domain": "finance", "to_domain": "legal", "type": "governs"},
    ]
    assert union_graph(workspace_graphs, domain_edges) == union_graph(
        workspace_graphs, domain_edges
    )


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
