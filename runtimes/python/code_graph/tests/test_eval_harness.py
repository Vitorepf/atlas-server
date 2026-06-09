"""Tests for the code_graph precision/recall eval harness (AP-815 Q-2).

Runnable with pytest OR directly: `python3 tests/test_eval_harness.py`
(networkx/pytest may be absent in the local runtime, so it self-runs).
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from atlas_code_graph.eval_harness import (  # noqa: E402
    SCHEMA,
    evaluate,
    evaluate_by_type,
)


def _approx(a: float, b: float, eps: float = 1e-6) -> bool:
    # The harness rounds metrics to 6 decimals (matching centrality.py), so the
    # comparison tolerance must accommodate that rounding (e.g. 1/3 -> 0.333333).
    return abs(a - b) <= eps


def test_known_set_exact_tp_fp_fn_and_metrics() -> None:
    # gold has 4 edges; predicted has 4 edges; 3 overlap, 1 is a wrong type.
    gold = [
        {"from": "a", "to": "b", "type": "calls"},
        {"from": "b", "to": "c", "type": "calls"},
        {"from": "c", "to": "d", "type": "imports"},
        {"from": "d", "to": "e", "type": "calls"},
    ]
    predicted = [
        {"from": "a", "to": "b", "type": "calls"},      # TP
        {"from": "b", "to": "c", "type": "calls"},      # TP
        {"from": "c", "to": "d", "type": "imports"},    # TP
        {"from": "x", "to": "y", "type": "calls"},      # FP (not in gold)
    ]
    result = evaluate(predicted, gold)

    assert result["schema_version"] == SCHEMA
    assert result["tp"] == 3
    assert result["fp"] == 1          # x->y
    assert result["fn"] == 1          # d->e missing
    assert result["predicted"] == 4
    assert result["gold"] == 4
    assert _approx(result["precision"], 3 / 4)        # 0.75
    assert _approx(result["recall"], 3 / 4)           # 0.75
    assert _approx(result["f1"], 0.75)                # harmonic of 0.75,0.75


def test_precision_recall_differ_when_counts_differ() -> None:
    # 1 TP, 2 FP, 0 FN -> precision 1/3, recall 1.0
    gold = [["a", "b", "calls"]]
    predicted = [
        ["a", "b", "calls"],   # TP
        ["a", "c", "calls"],   # FP
        ["a", "d", "calls"],   # FP
    ]
    result = evaluate(predicted, gold)
    assert result["tp"] == 1
    assert result["fp"] == 2
    assert result["fn"] == 0
    assert _approx(result["precision"], 1 / 3)
    assert _approx(result["recall"], 1.0)
    # f1 = 2*p*r/(p+r) = 2*(1/3)*1 / (1/3 + 1) = (2/3)/(4/3) = 0.5
    assert _approx(result["f1"], 0.5)


def test_type_mismatch_counts_as_miss() -> None:
    # Same endpoints, different type -> NOT a match (FP + FN, not TP).
    gold = [{"from": "a", "to": "b", "type": "calls"}]
    predicted = [{"from": "a", "to": "b", "type": "imports"}]
    result = evaluate(predicted, gold)
    assert result["tp"] == 0
    assert result["fp"] == 1
    assert result["fn"] == 1
    assert result["precision"] == 0.0
    assert result["recall"] == 0.0
    assert result["f1"] == 0.0


def test_perfect_match_is_one() -> None:
    edges = [
        {"from": "a", "to": "b", "type": "calls"},
        ["b", "c", "imports"],
    ]
    # Same logical set, mixed dict/tuple form on the predicted side.
    result = evaluate(edges, edges)
    assert result["tp"] == 2
    assert result["fp"] == 0
    assert result["fn"] == 0
    assert result["precision"] == 1.0
    assert result["recall"] == 1.0
    assert result["f1"] == 1.0


def test_empty_inputs_are_zeros_no_error() -> None:
    for pred, gold in (([], []), (None, None), ([], [["a", "b"]]), ([["a", "b"]], [])):
        result = evaluate(pred, gold)
        assert result["precision"] == 0.0
        assert result["recall"] == 0.0
        assert result["f1"] == 0.0
        # tp is always 0 in these cases (no possible overlap).
        assert result["tp"] == 0


def test_type_defaults_to_empty_and_trims_whitespace() -> None:
    # Missing type on both sides -> both normalise to "" -> they MATCH.
    gold = [{"from": " a ", "to": "b"}]
    predicted = [["a", " b "]]   # whitespace + no type
    result = evaluate(predicted, gold)
    assert result["tp"] == 1
    assert result["fp"] == 0
    assert result["fn"] == 0
    assert result["f1"] == 1.0


def test_runtime_native_keys_are_accepted() -> None:
    # The CodeGraphEdgeResolver-native key names must compare equal to the
    # {from,to,type} literal form for the same logical edge.
    gold = [{"from": "a", "to": "b", "type": "calls"}]
    predicted = [{"from_node_id": "a", "to_node_id": "b", "edge_type": "calls"}]
    result = evaluate(predicted, gold)
    assert result["tp"] == 1
    assert result["fp"] == 0
    assert result["fn"] == 0


def test_duplicate_edges_collapse_to_set() -> None:
    # A duplicated prediction must not change tp/fp (set semantics).
    gold = [["a", "b", "calls"]]
    predicted = [["a", "b", "calls"], ["a", "b", "calls"], ["a", "b", "calls"]]
    result = evaluate(predicted, gold)
    assert result["tp"] == 1
    assert result["fp"] == 0
    assert result["fn"] == 0
    assert result["predicted"] == 1   # collapsed


def test_malformed_edges_are_skipped_not_raised() -> None:
    gold = [{"from": "a", "to": "b", "type": "calls"}]
    predicted = [
        {"from": "a", "to": "b", "type": "calls"},  # valid TP
        {"from": "a"},                               # missing 'to' -> skip
        {"to": "b"},                                 # missing 'from' -> skip
        {"from": "", "to": "b"},                     # blank endpoint -> skip
        ["only-one"],                                # short tuple -> skip
        42,                                          # wrong type -> skip
        None,                                        # None -> skip
        "a->b",                                      # string -> skip
    ]
    result = evaluate(predicted, gold)
    assert result["tp"] == 1
    assert result["fp"] == 0
    assert result["fn"] == 0
    assert result["predicted"] == 1


def test_deterministic_across_runs() -> None:
    gold = [["a", "b", "calls"], ["c", "d", "imports"]]
    predicted = [["a", "b", "calls"], ["c", "d", "calls"]]
    assert evaluate(predicted, gold) == evaluate(predicted, gold)


def test_evaluate_by_type_buckets_metrics() -> None:
    gold = [
        ["a", "b", "calls"],
        ["b", "c", "calls"],
        ["c", "d", "imports"],
    ]
    predicted = [
        ["a", "b", "calls"],     # calls TP
        ["b", "c", "calls"],     # calls TP
        ["c", "d", "calls"],     # WRONG type: calls FP + imports FN
    ]
    result = evaluate_by_type(predicted, gold)

    assert result["schema_version"] == SCHEMA
    # Overall mirrors evaluate(...) exactly.
    assert result["overall"] == evaluate(predicted, gold)

    calls = result["by_type"]["calls"]
    assert calls["tp"] == 2
    assert calls["fp"] == 1          # the mis-typed c->d:calls
    assert calls["fn"] == 0
    assert _approx(calls["precision"], 2 / 3)
    assert _approx(calls["recall"], 1.0)

    imports = result["by_type"]["imports"]
    assert imports["tp"] == 0
    assert imports["fp"] == 0
    assert imports["fn"] == 1         # c->d:imports never predicted
    assert imports["precision"] == 0.0
    assert imports["recall"] == 0.0
    assert imports["f1"] == 0.0


def test_evaluate_by_type_empty_is_safe() -> None:
    result = evaluate_by_type([], [])
    assert result["by_type"] == {}
    assert result["overall"]["f1"] == 0.0


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
