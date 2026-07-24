"""Tests for code_graph governed semantic edges via local embeddings (AP-815 P-4).

Runnable with pytest OR directly: `python3 tests/test_semantic_edges.py`
(pytest may be absent in the local runtime, so it self-runs).

The embedding model (BAAI/bge-small-en-v1.5) downloads on first use (~130MB);
that is expected and approved. Inputs are kept TINY. The embedding-backed test
asserts RELATIVE similarity ordering / membership, never an absolute float, so it
stays robust to model/version drift.
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from atlas_code_graph.semantic_edges import semantic_edges  # noqa: E402

# Heavy dep: the fastembed local embedding model. Absent in venv-less runs, where
# semantic_edges degrades to an empty result — nothing to assert — so self-skip
# cleanly (exit 0) instead of erroring the suite. Matches leiden/hybrid pattern.
try:  # pragma: no cover - environment dependent
    import fastembed  # noqa: F401
except Exception:  # noqa: BLE001
    print("dep-skip: fastembed absent; heavy embedding path not exercised")
    raise SystemExit(0)


def _edge_set(result: dict) -> set:
    """Map {(from, to)} for membership assertions."""
    return {(e["from"], e["to"]) for e in result["edges"]}


def _edge_index(result: dict) -> dict:
    """Map (from, to) -> score for ordering assertions."""
    return {(e["from"], e["to"]): e["score"] for e in result["edges"]}


# --- Pure / fail-safe behaviour (no model needed) ----------------------------

def test_empty_input_is_safe() -> None:
    result = semantic_edges([])
    assert result["schema_version"] == "atlas.code_graph.semantic.v1"
    assert result["edges"] == []
    assert result["nodes"] == 0
    assert result["threshold"] == 0.78


def test_non_list_input_is_safe() -> None:
    assert semantic_edges(None)["edges"] == []
    assert semantic_edges("garbage")["nodes"] == 0
    assert semantic_edges(42)["edges"] == []


def test_single_node_yields_no_edges_without_model() -> None:
    # < 2 usable nodes short-circuits BEFORE importing fastembed, so this is
    # fast and never touches the model.
    result = semantic_edges([{"id": "only", "text": "a single lonely node"}])
    assert result["edges"] == []
    assert result["nodes"] == 1


def test_junk_nodes_skipped_no_raise() -> None:
    # None, non-dict, missing id, empty text, non-string id all dropped; fewer
    # than two survivors -> short-circuits before the model. Must not raise.
    nodes = [
        None,
        "not-a-dict",
        {"text": "no id here"},
        {"id": "x", "text": ""},
        {"id": "y", "text": "   "},
        {"id": 123, "text": "non-string id dropped"},
        {"id": "survivor", "text": "the only real node"},
    ]
    result = semantic_edges(nodes)
    assert result["nodes"] == 1
    assert result["edges"] == []


def test_duplicate_ids_collapsed() -> None:
    # Duplicate id keeps first occurrence; here that leaves a single usable node
    # so we stay on the fast no-model path.
    nodes = [
        {"id": "dup", "text": "first text"},
        {"id": "dup", "text": "second text with same id"},
    ]
    result = semantic_edges(nodes)
    assert result["nodes"] == 1
    assert result["edges"] == []


def test_bad_threshold_falls_back_safely() -> None:
    # Non-float threshold must not raise; on the <2-node path the default 0.78
    # surfaces in the result.
    result = semantic_edges([{"id": "a", "text": "lonely"}], threshold="bad")
    assert result["threshold"] == 0.78


def test_threshold_clamped_to_unit_range() -> None:
    hi = semantic_edges([{"id": "a", "text": "lonely"}], threshold=5.0)
    lo = semantic_edges([{"id": "a", "text": "lonely"}], threshold=-5.0)
    assert hi["threshold"] == 1.0
    assert lo["threshold"] == -1.0


# --- Embedding-backed behaviour (downloads model on first run) ---------------

def test_similar_pair_edges_unrelated_does_not() -> None:
    # Two semantically close texts + one unrelated. Assert the similar pair is
    # connected and the unrelated node is NOT linked to either. RELATIVE
    # membership, not an absolute float.
    nodes = [
        {"id": "auth_a", "text": "user login authentication"},
        {"id": "auth_b", "text": "authenticate a user session"},
        {"id": "tax", "text": "compute sales tax on an invoice"},
    ]
    # Moderate threshold: separates the auth pair from the tax outlier without
    # depending on exact model magnitudes.
    result = semantic_edges(nodes, threshold=0.6)
    edges = _edge_set(result)

    assert result["nodes"] == 3
    # The two auth nodes are connected (canonical from < to: auth_a < auth_b).
    assert ("auth_a", "auth_b") in edges
    # The unrelated tax node is NOT connected to either auth node.
    assert ("auth_a", "tax") not in edges
    assert ("auth_b", "tax") not in edges
    assert ("auth_b", "tax") not in edges
    assert ("tax", "auth_a") not in edges  # also check non-canonical direction
    assert ("tax", "auth_b") not in edges


def test_similar_pair_scores_higher_than_unrelated_pair() -> None:
    # Order-independent of threshold: at threshold=-1 ALL pairs are emitted, so
    # we can assert the auth-auth score strictly exceeds each auth-tax score.
    nodes = [
        {"id": "auth_a", "text": "user login authentication"},
        {"id": "auth_b", "text": "authenticate a user session"},
        {"id": "tax", "text": "compute sales tax on an invoice"},
    ]
    result = semantic_edges(nodes, threshold=-1.0)
    idx = _edge_index(result)

    # All three unordered pairs present at threshold -1.
    assert len(result["edges"]) == 3
    auth_score = idx[("auth_a", "auth_b")]
    tax_a = idx[("auth_a", "tax")]
    tax_b = idx[("auth_b", "tax")]
    assert auth_score > tax_a
    assert auth_score > tax_b


def test_edge_shape_and_canonical_direction() -> None:
    # Very similar texts, ids deliberately out of lexical order, to confirm the
    # emitted edge has from < to and the documented field shape.
    nodes = [
        {"id": "zzz", "text": "user login authentication"},
        {"id": "aaa", "text": "authenticate a user session"},
    ]
    result = semantic_edges(nodes, threshold=0.5)
    assert len(result["edges"]) == 1
    edge = result["edges"][0]
    assert edge["from"] == "aaa"
    assert edge["to"] == "zzz"
    assert edge["edge_type"] == "semantic"
    assert isinstance(edge["score"], float)
    # score rounded to 4 decimals and within cosine bounds.
    assert round(edge["score"], 4) == edge["score"]
    assert -1.0 <= edge["score"] <= 1.0


def test_high_threshold_excludes_weak_pairs() -> None:
    # With an unreachably high threshold, no edge survives even for the auth pair.
    nodes = [
        {"id": "auth_a", "text": "user login authentication"},
        {"id": "tax", "text": "compute sales tax on an invoice"},
    ]
    result = semantic_edges(nodes, threshold=0.999)
    assert result["edges"] == []
    assert result["nodes"] == 2


def test_max_edges_bounds_output() -> None:
    # Four near-identical texts => 6 unordered pairs at low threshold; cap to 2.
    nodes = [
        {"id": "n1", "text": "user login authentication flow"},
        {"id": "n2", "text": "authenticate the user session"},
        {"id": "n3", "text": "user sign in and authentication"},
        {"id": "n4", "text": "logging in an authenticated user"},
    ]
    result = semantic_edges(nodes, threshold=-1.0, max_edges=2)
    assert len(result["edges"]) == 2
    # Kept edges are the highest-scoring (sorted) ones: scores non-increasing.
    scores = [e["score"] for e in result["edges"]]
    assert scores == sorted(scores, reverse=True)


def test_deterministic_across_runs() -> None:
    nodes = [
        {"id": "auth_a", "text": "user login authentication"},
        {"id": "auth_b", "text": "authenticate a user session"},
        {"id": "tax", "text": "compute sales tax on an invoice"},
    ]
    assert semantic_edges(nodes, threshold=0.3) == semantic_edges(nodes, threshold=0.3)


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
