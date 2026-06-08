"""Tests for code_graph surprising-connections + suggested-questions (P-8).

Runnable with pytest OR directly: `python3 tests/test_insights.py`
(networkx/pytest may be absent in the local runtime, so it self-runs).
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from atlas_code_graph import (  # noqa: E402
    suggested_questions,
    surprising_connections,
)


def _edge(a, b, confidence=None, edge_type="depends_on", inferred=None):
    edge = {"from_node_id": a, "to_node_id": b, "edge_type": edge_type}
    if confidence is not None:
        edge["confidence"] = confidence
    meta = {}
    if confidence is not None:
        meta["confidence"] = confidence
    if inferred is not None:
        meta["inferred"] = inferred
    if meta:
        edge["metadata"] = meta
    return edge


def _two_community_graph():
    """Two dense triangles (communities A and B) joined by one bridge edge.

    Community A: a1-a2-a3 (triangle).  Community B: b1-b2-b3 (triangle).
    Bridge: a1 -> b1 (the surprising cross-community connection).
    """
    edges = [
        _edge("node:a1", "node:a2", "EXTRACTED"),
        _edge("node:a2", "node:a3", "EXTRACTED"),
        _edge("node:a3", "node:a1", "EXTRACTED"),
        _edge("node:b1", "node:b2", "EXTRACTED"),
        _edge("node:b2", "node:b3", "EXTRACTED"),
        _edge("node:b3", "node:b1", "EXTRACTED"),
        _edge("node:a1", "node:b1", "EXTRACTED"),  # the bridge
    ]
    assignments = {
        "node:a1": 0,
        "node:a2": 0,
        "node:a3": 0,
        "node:b1": 1,
        "node:b2": 1,
        "node:b3": 1,
    }
    return edges, assignments


# --- surprising_connections ------------------------------------------------


def test_cross_community_edge_outranks_intra_community_edge():
    edges, assignments = _two_community_graph()
    ranked = surprising_connections(edges, assignments=assignments)

    # The bridge a1->b1 is the only cross-community edge -> must be rank 0.
    assert ranked, "expected at least one surprising connection"
    top = ranked[0]
    assert top["from_node_id"] == "node:a1"
    assert top["to_node_id"] == "node:b1"

    by_pair = {(r["from_node_id"], r["to_node_id"]): r for r in ranked}
    bridge_score = by_pair[("node:a1", "node:b1")]["score"]

    # Every intra-community (same-cluster) edge must score strictly lower.
    for (a, b), row in by_pair.items():
        if assignments[a] == assignments[b]:
            assert row["score"] < bridge_score, (
                f"intra-community edge {a}->{b} ({row['score']}) "
                f"should rank below bridge ({bridge_score})"
            )

    # The bridge's reason set must mention the cross-community jump.
    assert any("cross-community" in w for w in top["why"])


def test_periphery_to_hub_is_surprising_without_assignments():
    # Hub with degree 4; one lonely leaf hangs off it. No community info.
    edges = [
        _edge("node:hub", "node:x1", "EXTRACTED"),
        _edge("node:hub", "node:x2", "EXTRACTED"),
        _edge("node:hub", "node:x3", "EXTRACTED"),
        _edge("node:leaf", "node:hub", "EXTRACTED"),
    ]
    ranked = surprising_connections(edges)  # assignments omitted
    by_pair = {(r["from_node_id"], r["to_node_id"]): r for r in ranked}

    assert ("node:leaf", "node:hub") in by_pair
    why = by_pair[("node:leaf", "node:hub")]["why"]
    assert any("periphery->hub" in w for w in why)


def test_inferred_and_ambiguous_edges_score_above_extracted():
    # Independent pairs (every node degree 1) so the periphery->hub term never
    # fires and we isolate the pure confidence-weight contribution.
    edges = [
        _edge("node:q1", "node:q2", "EXTRACTED"),
        _edge("node:r1", "node:r2", "INFERRED"),
        _edge("node:s1", "node:s2", "AMBIGUOUS"),
    ]
    ranked = surprising_connections(edges)
    by_pair = {(r["from_node_id"], r["to_node_id"]): r for r in ranked}

    # EXTRACTED-only, no other signal -> not surprising at all.
    assert ("node:q1", "node:q2") not in by_pair
    # AMBIGUOUS weight (1.5) > INFERRED weight (1.0).
    assert by_pair[("node:s1", "node:s2")]["score"] > by_pair[
        ("node:r1", "node:r2")
    ]["score"]
    # Sanity: the ambiguous edge ranks first overall here.
    assert ranked[0]["from_node_id"] == "node:s1"


def test_surprising_connections_deterministic():
    edges, assignments = _two_community_graph()
    a = surprising_connections(edges, assignments=assignments)
    b = surprising_connections(edges, assignments=assignments)
    assert a == b


def test_surprising_connections_empty_is_safe():
    assert surprising_connections([]) == []
    assert surprising_connections([], assignments={}) == []


def test_inferred_flag_in_metadata_is_honored():
    # No confidence label, but metadata.inferred=True should still count.
    edges = [
        {
            "from_node_id": "node:m",
            "to_node_id": "node:n",
            "edge_type": "depends_on",
            "metadata": {"inferred": True},
        }
    ]
    ranked = surprising_connections(edges)
    by_pair = {(r["from_node_id"], r["to_node_id"]): r for r in ranked}
    assert ("node:m", "node:n") in by_pair
    assert any("inferred" in w for w in by_pair[("node:m", "node:n")]["why"])


# --- suggested_questions ---------------------------------------------------


def test_questions_non_empty_and_in_range():
    edges, assignments = _two_community_graph()
    god_nodes = [
        {"node_id": "node:a1", "degree": 3},
        {"node_id": "node:b1", "degree": 3},
    ]
    qs = suggested_questions(edges, god_nodes=god_nodes, assignments=assignments)

    assert isinstance(qs, list)
    assert 4 <= len(qs) <= 6
    for entry in qs:
        assert set(entry.keys()) == {"question", "basis"}
        assert isinstance(entry["question"], str) and entry["question"].strip()
        assert isinstance(entry["basis"], str) and entry["basis"].strip()


def test_questions_deterministic():
    edges, assignments = _two_community_graph()
    god_nodes = [{"node_id": "node:a1", "degree": 3}]
    a = suggested_questions(edges, god_nodes=god_nodes, assignments=assignments)
    b = suggested_questions(edges, god_nodes=god_nodes, assignments=assignments)
    assert a == b


def test_questions_use_inferred_hub_signal():
    # Hub reached through an inferred edge -> a question must call that out.
    edges = [
        _edge("node:hub", "node:x1", "EXTRACTED"),
        _edge("node:hub", "node:x2", "EXTRACTED"),
        _edge("node:hub", "node:x3", "EXTRACTED"),
        _edge("node:weird", "node:hub", "INFERRED"),
    ]
    god_nodes = [{"node_id": "node:hub", "degree": 4}]
    qs = suggested_questions(edges, god_nodes=god_nodes)
    joined = " ".join(q["question"] for q in qs)
    assert "node:hub" in joined
    assert "inferred" in joined.lower()


def test_questions_fallback_hubs_without_god_nodes():
    # god_nodes omitted: questions should still be produced from degree.
    edges, assignments = _two_community_graph()
    qs = suggested_questions(edges, assignments=assignments)
    assert 4 <= len(qs) <= 6


def test_questions_empty_graph_is_safe():
    assert suggested_questions([]) == []


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
            except Exception as exc:  # noqa: BLE001
                failures += 1
                print(f"ERROR {name}: {exc!r}")
    print(f"\n{'OK' if failures == 0 else 'FAILED'}: {failures} failure(s)")
    raise SystemExit(1 if failures else 0)
