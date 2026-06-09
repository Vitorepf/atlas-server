"""Tests for code_graph cross-workspace recurring structural patterns (AP-815).

Runnable with pytest OR directly: `python3 tests/test_cross_workspace_patterns.py`
(networkx/pytest may be absent in the local runtime, so it self-runs).
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from atlas_code_graph.cross_workspace_patterns import (  # noqa: E402
    SCHEMA,
    recurring_patterns,
)


def _by_signature(result: dict) -> dict:
    """Map signature string -> entry for easy assertions."""
    return {e["signature"]: e for e in result["recurring"]}


# A node with out_degree 3 / in_degree 0 over "calls" edges. Distinct targets
# per workspace keeps it self-contained while giving the hub out-degree 3.
def _fanout3_calls(hub: str, prefix: str) -> list:
    return [
        {"from": hub, "to": f"{prefix}_t1", "type": "calls"},
        {"from": hub, "to": f"{prefix}_t2", "type": "calls"},
        {"from": hub, "to": f"{prefix}_t3", "type": "calls"},
    ]


def test_spec_signature_recurs_across_two_workspaces() -> None:
    # Two workspaces each have a node with out_degree 3 / in_degree 0 / "calls".
    graphs = [
        {"workspace": "repo_a", "edges": _fanout3_calls("hub_a", "a")},
        {"workspace": "repo_b", "edges": _fanout3_calls("hub_b", "b")},
    ]
    result = recurring_patterns(graphs, min_workspaces=2)
    assert result["schema_version"] == SCHEMA

    by_sig = _by_signature(result)
    hub_sig = "out=3|in=0|types=[calls]"
    assert hub_sig in by_sig
    entry = by_sig[hub_sig]
    assert entry["workspaces"] == ["repo_a", "repo_b"]
    # The two hubs themselves -> node_count 2 for that signature.
    assert entry["node_count"] == 2


def test_signature_unique_to_one_workspace_excluded() -> None:
    # repo_b's hub is fan-out-2, not 3, so the out=3 signature is unique to
    # repo_a and must NOT appear in recurring (min_workspaces=2).
    graphs = [
        {"workspace": "repo_a", "edges": _fanout3_calls("hub_a", "a")},
        {
            "workspace": "repo_b",
            "edges": [
                {"from": "hub_b", "to": "b_t1", "type": "calls"},
                {"from": "hub_b", "to": "b_t2", "type": "calls"},
            ],
        },
    ]
    result = recurring_patterns(graphs, min_workspaces=2)
    by_sig = _by_signature(result)
    assert "out=3|in=0|types=[calls]" not in by_sig
    # out=2 hub is also unique to repo_b -> excluded too.
    assert "out=2|in=0|types=[calls]" not in by_sig


def test_signature_excludes_node_id_and_content() -> None:
    # Different hub ids + different leaf ids in each ws still share a signature:
    # proves the grouping key is structure, not id/content.
    graphs = [
        {"workspace": "alpha", "edges": _fanout3_calls("PaymentService", "x")},
        {"workspace": "beta", "edges": _fanout3_calls("OrderRouter", "y")},
    ]
    result = recurring_patterns(graphs, min_workspaces=2)
    by_sig = _by_signature(result)
    hub_sig = "out=3|in=0|types=[calls]"
    assert hub_sig in by_sig
    # The signature string itself never contains a node id.
    assert "PaymentService" not in hub_sig
    assert "OrderRouter" not in hub_sig
    # Ids appear ONLY inside examples (the explicit witness escape hatch).
    example_ids = {ex["id"] for ex in by_sig[hub_sig]["examples"]}
    assert example_ids == {"PaymentService", "OrderRouter"}


def test_in_degree_distinguishes_signatures() -> None:
    # Same out-degree but different in-degree => different signatures, so a
    # pure-source hub and a hub that is also called do NOT collapse together.
    graphs = [
        {
            "workspace": "repo_a",
            "edges": [
                {"from": "h", "to": "t1", "type": "calls"},
                {"from": "t1", "to": "h", "type": "calls"},  # h gets in_degree 1
            ],
        },
        {
            "workspace": "repo_b",
            "edges": [
                {"from": "h", "to": "t1", "type": "calls"},
                {"from": "t1", "to": "h", "type": "calls"},
            ],
        },
    ]
    result = recurring_patterns(graphs, min_workspaces=2)
    by_sig = _by_signature(result)
    # h: out=1,in=1 ; t1: out=1,in=1 too -> single shared signature.
    assert "out=1|in=1|types=[calls]" in by_sig
    # No bare out=1|in=0 hub here.
    assert "out=1|in=0|types=[calls]" not in by_sig


def test_incident_types_are_sorted_and_unioned() -> None:
    # A node touched by both "calls" and "imports" edges yields a signature
    # whose type list is the sorted union, identical across workspaces.
    edges = [
        {"from": "h", "to": "a", "type": "imports"},
        {"from": "h", "to": "b", "type": "calls"},
    ]
    graphs = [
        {"workspace": "w1", "edges": list(edges)},
        {"workspace": "w2", "edges": list(edges)},
    ]
    result = recurring_patterns(graphs, min_workspaces=2)
    by_sig = _by_signature(result)
    # sorted union -> calls,imports (alphabetical), not insertion order.
    assert "out=2|in=0|types=[calls,imports]" in by_sig


def test_untyped_edges_fold_to_sentinel() -> None:
    # Edges with no/empty type must still form a stable signature.
    graphs = [
        {"workspace": "w1", "edges": [{"from": "h", "to": "a"}]},
        {"workspace": "w2", "edges": [{"from": "h", "to": "a", "type": "  "}]},
    ]
    result = recurring_patterns(graphs, min_workspaces=2)
    by_sig = _by_signature(result)
    assert "out=1|in=0|types=[untyped]" in by_sig


def test_same_workspace_twice_is_not_two_workspaces() -> None:
    # A signature appearing many times within ONE workspace is not recurring
    # across workspaces. Distinct-workspace counting must dedupe by name.
    graphs = [
        {
            "workspace": "solo",
            "edges": _fanout3_calls("h1", "p") + _fanout3_calls("h2", "q"),
        },
    ]
    result = recurring_patterns(graphs, min_workspaces=2)
    by_sig = _by_signature(result)
    # Two fan-out-3 hubs but only one workspace -> not recurring.
    assert "out=3|in=0|types=[calls]" not in by_sig
    # Same workspace name repeated must also collapse to one distinct ws.
    graphs2 = [
        {"workspace": "dup", "edges": _fanout3_calls("h1", "p")},
        {"workspace": "dup", "edges": _fanout3_calls("h2", "q")},
    ]
    result2 = recurring_patterns(graphs2, min_workspaces=2)
    assert "out=3|in=0|types=[calls]" not in _by_signature(result2)


def test_node_count_sums_across_workspaces() -> None:
    # Two hubs in ws1 + one hub in ws2 with the same signature -> node_count 3,
    # workspaces 2.
    graphs = [
        {
            "workspace": "w1",
            "edges": _fanout3_calls("h1", "p") + _fanout3_calls("h2", "q"),
        },
        {"workspace": "w2", "edges": _fanout3_calls("h3", "r")},
    ]
    result = recurring_patterns(graphs, min_workspaces=2)
    entry = _by_signature(result)["out=3|in=0|types=[calls]"]
    assert entry["node_count"] == 3
    assert entry["workspaces"] == ["w1", "w2"]


def test_sorted_by_workspace_count_then_signature() -> None:
    # Primary sort key is descending workspace count; ties break by signature
    # string (ascending). Build 3-ws signatures and 2-ws signatures and assert
    # ALL 3-ws ones precede ALL 2-ws ones, with lexical order within each tier.
    wide = [
        {"workspace": ws, "edges": _fanout3_calls("h", ws)}
        for ws in ("w1", "w2", "w3")
    ]
    # A separate "uses" coupling present in only 2 of the workspaces.
    narrow = [
        {"workspace": "w1", "edges": [{"from": "u", "to": "v", "type": "uses"}]},
        {"workspace": "w2", "edges": [{"from": "u", "to": "v", "type": "uses"}]},
    ]
    result = recurring_patterns(wide + narrow, min_workspaces=2)
    counts = [len(e["workspaces"]) for e in result["recurring"]]
    # Non-increasing workspace counts (primary key honoured).
    assert counts == sorted(counts, reverse=True)

    # The fan-out hub recurs in all 3 workspaces; the "uses" pair in only 2.
    sigs = [e["signature"] for e in result["recurring"]]
    hub = "out=3|in=0|types=[calls]"
    uses = "out=1|in=0|types=[uses]"
    assert hub in sigs and uses in sigs
    assert sigs.index(hub) < sigs.index(uses)  # 3-ws ranks before 2-ws

    # Within the 3-ws tier, ties break lexically (out=0... before out=3...).
    tier3 = [e["signature"] for e in result["recurring"] if len(e["workspaces"]) == 3]
    assert tier3 == sorted(tier3)


def test_min_workspaces_threshold_respected() -> None:
    graphs = [
        {"workspace": "w1", "edges": _fanout3_calls("h", "a")},
        {"workspace": "w2", "edges": _fanout3_calls("h", "b")},
        {"workspace": "w3", "edges": _fanout3_calls("h", "c")},
    ]
    # Needs 4 workspaces -> nothing recurs.
    assert recurring_patterns(graphs, min_workspaces=4)["recurring"] == []
    # Needs 3 -> the fan-out hub recurs.
    r3 = recurring_patterns(graphs, min_workspaces=3)
    assert "out=3|in=0|types=[calls]" in _by_signature(r3)


def test_examples_are_bounded_and_deterministic() -> None:
    # 7 distinct workspaces each contributing one identical-signature hub:
    # examples must be capped (<= 5) and stable across runs.
    graphs = [
        {"workspace": f"w{i}", "edges": _fanout3_calls(f"hub{i}", f"p{i}")}
        for i in range(7)
    ]
    result = recurring_patterns(graphs, min_workspaces=2)
    entry = _by_signature(result)["out=3|in=0|types=[calls]"]
    assert len(entry["examples"]) <= 5
    assert entry["node_count"] == 7  # full count even though examples capped
    assert len(entry["workspaces"]) == 7
    # Determinism: identical structure -> identical output.
    assert recurring_patterns(graphs) == recurring_patterns(graphs)


def test_signatures_count_includes_non_recurring() -> None:
    # signatures = ALL distinct signatures seen, even those below threshold.
    graphs = [
        {"workspace": "w1", "edges": _fanout3_calls("h", "a")},
        {"workspace": "w2", "edges": _fanout3_calls("h", "b")},
    ]
    result = recurring_patterns(graphs, min_workspaces=2)
    # Hubs (out=3,in=0,calls) recur; leaves (out=0,in=1,calls) recur too.
    # Distinct signatures observed: hub + leaf = 2.
    assert result["signatures"] == 2
    assert len(result["recurring"]) == 2


def test_empty_input_is_safe() -> None:
    result = recurring_patterns([])
    assert result["schema_version"] == SCHEMA
    assert result["signatures"] == 0
    assert result["recurring"] == []


def test_garbage_graphs_skipped_no_raise() -> None:
    graphs = [
        None,
        "not-a-graph",
        123,
        {"no_workspace_key": True},
        {"workspace": "", "edges": _fanout3_calls("h", "a")},  # empty ws name
        {"workspace": "  ", "edges": []},                       # blank ws name
        {"workspace": "ok", "edges": "not-a-list"},             # bad edges
        {"workspace": "real", "edges": _fanout3_calls("h", "z")},
    ]
    result = recurring_patterns(graphs, min_workspaces=1)
    # Only "real" contributed nodes; min_workspaces=1 so its hub signature shows.
    by_sig = _by_signature(result)
    assert "out=3|in=0|types=[calls]" in by_sig
    assert by_sig["out=3|in=0|types=[calls]"]["workspaces"] == ["real"]


def test_garbage_edges_within_graph_skipped() -> None:
    graphs = [
        {
            "workspace": "w1",
            "edges": [
                None,
                "nope",
                42,
                {"from": "h"},                       # missing to
                {"to": "x"},                         # missing from
                {"from": 5, "to": "x"},              # non-str from
                {"from": "h", "to": ""},             # empty to
                {"from": " ", "to": "x"},            # blank from
                {"from": "h", "to": "a", "type": "calls"},  # the one good edge
            ],
        },
        {"workspace": "w2", "edges": _fanout3_calls("other", "q")[:1]},
        # w2 good edge: other->q_t1 calls => other out=1,in=0,calls
    ]
    result = recurring_patterns(graphs, min_workspaces=1)
    by_sig = _by_signature(result)
    # w1's single good edge gives h: out=1,in=0,calls ; a: out=0,in=1,calls.
    assert "out=1|in=0|types=[calls]" in by_sig  # h and w2's other share this
    assert by_sig["out=1|in=0|types=[calls]"]["workspaces"] == ["w1", "w2"]


def test_non_list_graphs_arg_is_safe() -> None:
    assert recurring_patterns(None)["recurring"] == []
    assert recurring_patterns("garbage")["signatures"] == 0
    assert recurring_patterns(42)["recurring"] == []
    assert recurring_patterns({"workspace": "x"})["recurring"] == []  # dict, not list


def test_bad_min_workspaces_falls_back_safely() -> None:
    graphs = [
        {"workspace": "w1", "edges": _fanout3_calls("h", "a")},
        {"workspace": "w2", "edges": _fanout3_calls("h", "b")},
    ]
    # Non-int -> default 2 -> hub recurs.
    r = recurring_patterns(graphs, min_workspaces="bad")
    assert "out=3|in=0|types=[calls]" in _by_signature(r)
    # Sub-1 -> clamped to 1 -> still recurs (and would include singletons).
    r2 = recurring_patterns(graphs, min_workspaces=0)
    assert "out=3|in=0|types=[calls]" in _by_signature(r2)


def test_self_loop_counts_in_degree_and_out_degree() -> None:
    # A self-loop contributes both an out-edge and an in-edge to the node.
    graphs = [
        {"workspace": "w1", "edges": [{"from": "h", "to": "h", "type": "calls"}]},
        {"workspace": "w2", "edges": [{"from": "h", "to": "h", "type": "calls"}]},
    ]
    result = recurring_patterns(graphs, min_workspaces=2)
    by_sig = _by_signature(result)
    assert "out=1|in=1|types=[calls]" in by_sig


def test_whitespace_in_ids_and_types_normalized() -> None:
    # Stripping means "  h " and "h" and "calls " / "calls" share a signature.
    graphs = [
        {"workspace": "w1", "edges": [{"from": " h ", "to": "a", "type": "calls "}]},
        {"workspace": "w2", "edges": [{"from": "h", "to": " a ", "type": " calls"}]},
    ]
    result = recurring_patterns(graphs, min_workspaces=2)
    by_sig = _by_signature(result)
    sig = "out=1|in=0|types=[calls]"
    assert sig in by_sig
    # Witness ids are the stripped form.
    assert {"workspace": "w1", "id": "h"} in by_sig[sig]["examples"]


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
