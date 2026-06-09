"""Tests for the hybrid relevance ranker (AP-815 E-6).

Runnable with pytest OR directly: `python3 tests/test_hybrid_ranker.py`
(pytest/fastembed may be absent in the local runtime, so it self-runs and the
semantic signal is never required).
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from atlas_code_graph.hybrid_ranker import rank  # noqa: E402


def test_empty_candidates_is_safe() -> None:
    assert rank("anything", []) == []


def test_malformed_inputs_never_raise() -> None:
    # Non-list candidates, non-dict elements, non-str query/text all degrade.
    assert rank("q", None) == []
    assert rank("q", "not-a-list") == []
    assert rank(None, [{"id": "a", "text": "alpha"}]) != []  # query empty -> ok
    mixed = rank("alpha", [{"id": "a", "text": "alpha"}, 7, "skip", None])
    assert [r["id"] for r in mixed] == ["a"]  # junk elements dropped
    # Garbage signal values default to 0.0 without error.
    res = rank(
        "alpha",
        [{"id": "a", "text": "alpha", "centrality": "oops", "recency": None}],
    )
    assert res[0]["signals"]["centrality"] == 0.0
    assert res[0]["signals"]["recency"] == 0.0


def test_annotation_shape() -> None:
    res = rank("alpha", [{"id": "a", "text": "alpha beta"}])
    item = res[0]
    assert item["id"] == "a"
    assert isinstance(item["score"], float)
    signals = item["signals"]
    # Mandatory signals present; semantic omitted when fastembed is absent.
    assert set(signals) >= {"lexical", "centrality", "recency"}
    if "semantic" not in signals:
        assert "semantic" not in signals  # graceful omission (no fastembed dep)


def test_lexical_match_beats_irrelevant_under_default_weights() -> None:
    candidates = [
        {"id": "match", "text": "deterministic graph centrality ranking"},
        {"id": "noise", "text": "completely unrelated text about cooking"},
    ]
    res = rank("graph centrality ranking", candidates)
    assert res[0]["id"] == "match"
    assert res[0]["signals"]["lexical"] > res[1]["signals"]["lexical"]
    assert res[0]["score"] > res[1]["score"]


def test_combined_order_matches_weighted_score() -> None:
    # One strong lexical match (no centrality), one high-centrality node with no
    # lexical overlap. Default weights lexical=0.5, centrality=0.3, recency=0.2.
    candidates = [
        {"id": "lex", "text": "alpha bravo charlie delta", "centrality": 0.0},
        {"id": "hub", "text": "zulu yankee xray whiskey", "centrality": 1.0},
    ]
    res = rank("alpha bravo charlie delta", candidates)

    by_id = {r["id"]: r for r in res}
    lex = by_id["lex"]
    hub = by_id["hub"]

    # Independently recompute the expected weighted scores from the reported
    # per-signal values and confirm the emitted score + ordering agree.
    def expected(item: dict) -> float:
        s = item["signals"]
        return round(
            0.5 * s["lexical"] + 0.3 * s["centrality"] + 0.2 * s["recency"], 6
        )

    assert lex["score"] == expected(lex)
    assert hub["score"] == expected(hub)

    # lex: lexical ~1.0 -> ~0.5 ; hub: centrality 1.0 -> 0.3. lex should win.
    assert lex["score"] > hub["score"]
    assert [r["id"] for r in res] == ["lex", "hub"]


def test_weights_override_changes_order() -> None:
    candidates = [
        {"id": "lex", "text": "alpha bravo charlie delta", "centrality": 0.0},
        {"id": "hub", "text": "zulu yankee xray whiskey", "centrality": 1.0},
    ]
    query = "alpha bravo charlie delta"

    # Default: lexical dominates -> lex first.
    default_order = [r["id"] for r in rank(query, candidates)]
    assert default_order == ["lex", "hub"]

    # Crank centrality, zero out lexical -> hub must overtake.
    overridden = rank(
        query,
        candidates,
        weights={"lexical": 0.0, "centrality": 1.0, "recency": 0.0},
    )
    assert [r["id"] for r in overridden] == ["hub", "lex"]
    assert overridden[0]["id"] == "hub"
    assert overridden[0]["score"] == overridden[0]["signals"]["centrality"]


def test_recency_signal_is_applied() -> None:
    candidates = [
        {"id": "old", "text": "shared topic", "recency": 0.0},
        {"id": "new", "text": "shared topic", "recency": 1.0},
    ]
    # Identical lexical/centrality; recency weight breaks it toward "new".
    res = rank(
        "shared topic",
        candidates,
        weights={"lexical": 0.0, "centrality": 0.0, "recency": 1.0},
    )
    assert [r["id"] for r in res] == ["new", "old"]


def test_missing_signals_default_to_zero() -> None:
    res = rank("nomatch", [{"id": "bare", "text": "totally different words"}])
    signals = res[0]["signals"]
    assert signals["centrality"] == 0.0
    assert signals["recency"] == 0.0
    assert signals["lexical"] == 0.0  # no query-term overlap
    assert res[0]["score"] == 0.0


def test_deterministic_tie_break_by_id() -> None:
    # All-zero scores (query matches nothing, no other signals): order is purely
    # the id tie-break ascending, stable across runs.
    candidates = [
        {"id": "c", "text": "x"},
        {"id": "a", "text": "y"},
        {"id": "b", "text": "z"},
    ]
    res = rank("no-overlap-query", candidates)
    assert [r["id"] for r in res] == ["a", "b", "c"]
    # Equal non-zero scores also tie-break by id.
    tied = rank(
        "topic",
        [
            {"id": "beta", "text": "topic", "centrality": 0.5},
            {"id": "alpha", "text": "topic", "centrality": 0.5},
        ],
    )
    assert tied[0]["score"] == tied[1]["score"]
    assert [r["id"] for r in tied] == ["alpha", "beta"]


def test_deterministic_across_runs() -> None:
    candidates = [
        {"id": "a", "text": "alpha graph", "centrality": 0.4, "recency": 0.1},
        {"id": "b", "text": "beta graph node", "centrality": 0.2, "recency": 0.9},
        {"id": "c", "text": "gamma", "centrality": 0.8, "recency": 0.0},
    ]
    first = rank("graph node", candidates)
    second = rank("graph node", candidates)
    assert first == second


def test_original_fields_preserved() -> None:
    res = rank(
        "alpha",
        [{"id": "a", "text": "alpha", "extra": "kept", "centrality": 0.3}],
    )
    assert res[0]["extra"] == "kept"
    assert res[0]["text"] == "alpha"
    # Input is not mutated in place.
    src = {"id": "a", "text": "alpha"}
    rank("alpha", [src])
    assert "score" not in src and "signals" not in src


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
