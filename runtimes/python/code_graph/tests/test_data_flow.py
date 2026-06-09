"""Tests for code_graph intra-file data-flow def-use edges (AP-815 P-3).

Runnable with pytest OR directly: `python3 tests/test_data_flow.py`
(networkx/pytest may be absent in the local runtime, so it self-runs).
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from atlas_code_graph.data_flow import def_use_edges  # noqa: E402


def _edge_keys(result: dict) -> set:
    """Set of (from, to) for easy membership assertions."""
    return {(e["from"], e["to"]) for e in result["edges"]}


def _ev(file: str, var: str, kind: str, line: int, symbol=None) -> dict:
    event = {"file": file, "var": var, "kind": kind, "line": line}
    if symbol is not None:
        event["symbol"] = symbol
    return event


def test_schema_and_shape() -> None:
    result = def_use_edges([])
    assert result["schema_version"] == "atlas.code_graph.data_flow.v1"
    assert result["edges"] == []
    assert result["files"] == 0
    assert result["resolved"] == 0
    assert result["unresolved"] == 0


def test_assign_then_use_yields_one_edge() -> None:
    # x assigned at line 1, used at line 3 -> exactly one def_use edge for x.
    events = [_ev("a.py", "x", "assign", 1), _ev("a.py", "x", "use", 3)]
    result = def_use_edges(events)
    assert len(result["edges"]) == 1
    edge = result["edges"][0]
    assert edge["from"] == "def:a.py:x@1"
    assert edge["to"] == "use:a.py:x@3"
    assert edge["var"] == "x"
    assert edge["file"] == "a.py"
    assert edge["edge_type"] == "data_flow"
    assert result["files"] == 1
    assert result["resolved"] == 1
    assert result["unresolved"] == 0


def test_use_before_any_assign_is_unresolved() -> None:
    # A use with no prior assign is counted but emits no edge.
    events = [_ev("a.py", "x", "use", 1), _ev("a.py", "x", "assign", 2)]
    result = def_use_edges(events)
    assert result["edges"] == []
    assert result["resolved"] == 0
    assert result["unresolved"] == 1
    assert result["files"] == 1


def test_two_assigns_then_use_links_latest() -> None:
    # x assigned at 1 and again at 5, used at 7 -> use links to the LATEST (5).
    events = [
        _ev("a.py", "x", "assign", 1),
        _ev("a.py", "x", "assign", 5),
        _ev("a.py", "x", "use", 7),
    ]
    result = def_use_edges(events)
    assert len(result["edges"]) == 1
    edge = result["edges"][0]
    assert edge["from"] == "def:a.py:x@5"
    assert edge["to"] == "use:a.py:x@7"
    assert result["resolved"] == 1
    assert result["unresolved"] == 0


def test_use_between_two_assigns_links_first_then_reassign() -> None:
    # assign@1, use@2 (links @1), assign@3, use@4 (links @3): two edges.
    events = [
        _ev("a.py", "x", "assign", 1),
        _ev("a.py", "x", "use", 2),
        _ev("a.py", "x", "assign", 3),
        _ev("a.py", "x", "use", 4),
    ]
    result = def_use_edges(events)
    keys = _edge_keys(result)
    assert ("def:a.py:x@1", "use:a.py:x@2") in keys
    assert ("def:a.py:x@3", "use:a.py:x@4") in keys
    assert len(result["edges"]) == 2
    assert result["resolved"] == 2


def test_multi_file_isolation() -> None:
    # An assign in a.py must NOT reach a use of the same var in b.py.
    events = [
        _ev("a.py", "x", "assign", 1),
        _ev("b.py", "x", "use", 1),  # unresolved: no assign in b.py
        _ev("b.py", "x", "assign", 2),
        _ev("b.py", "x", "use", 3),  # resolves within b.py only
    ]
    result = def_use_edges(events)
    keys = _edge_keys(result)
    assert ("def:b.py:x@2", "use:b.py:x@3") in keys
    assert len(result["edges"]) == 1
    assert result["files"] == 2
    assert result["resolved"] == 1
    assert result["unresolved"] == 1


def test_distinct_vars_do_not_cross() -> None:
    # x's assign must not feed y's use.
    events = [
        _ev("a.py", "x", "assign", 1),
        _ev("a.py", "y", "use", 2),  # unresolved (no y assign)
        _ev("a.py", "y", "assign", 3),
        _ev("a.py", "x", "use", 4),  # resolves to x@1
        _ev("a.py", "y", "use", 5),  # resolves to y@3
    ]
    result = def_use_edges(events)
    keys = _edge_keys(result)
    assert ("def:a.py:x@1", "use:a.py:x@4") in keys
    assert ("def:a.py:y@3", "use:a.py:y@5") in keys
    assert len(result["edges"]) == 2
    assert result["resolved"] == 2
    assert result["unresolved"] == 1


def test_same_line_assign_reaches_same_line_use() -> None:
    # assign and use on the same line: the assign reaches the use (e.g. x = x+1
    # modelled as both, or chained assignment). Order-in-list must not matter.
    events = [
        _ev("a.py", "x", "use", 1),     # listed first but same line as assign
        _ev("a.py", "x", "assign", 1),
    ]
    result = def_use_edges(events)
    assert len(result["edges"]) == 1
    edge = result["edges"][0]
    assert edge["from"] == "def:a.py:x@1"
    assert edge["to"] == "use:a.py:x@1"
    assert result["resolved"] == 1
    assert result["unresolved"] == 0


def test_edges_sorted_by_file_var_from_to() -> None:
    events = [
        _ev("b.py", "y", "assign", 1),
        _ev("b.py", "y", "use", 2),
        _ev("a.py", "z", "assign", 1),
        _ev("a.py", "z", "use", 2),
        _ev("a.py", "a", "assign", 1),
        _ev("a.py", "a", "use", 2),
    ]
    result = def_use_edges(events)
    order = [(e["file"], e["var"]) for e in result["edges"]]
    # Sorted by file then var: a.py/a, a.py/z, b.py/y.
    assert order == [("a.py", "a"), ("a.py", "z"), ("b.py", "y")]


def test_event_order_does_not_affect_result() -> None:
    # Same events shuffled by the caller -> identical output (sort by line wins).
    base = [
        _ev("a.py", "x", "assign", 1),
        _ev("a.py", "x", "use", 2),
        _ev("a.py", "x", "assign", 3),
        _ev("a.py", "x", "use", 4),
    ]
    shuffled = [base[3], base[1], base[2], base[0]]
    assert def_use_edges(base) == def_use_edges(shuffled)


def test_symbol_field_is_optional_and_ignored_for_edges() -> None:
    # Presence of symbol must not change edge identity (not part of the edge).
    events = [
        _ev("a.py", "x", "assign", 1, symbol="a.py::f::x"),
        _ev("a.py", "x", "use", 2, symbol="a.py::f::x"),
    ]
    result = def_use_edges(events)
    assert len(result["edges"]) == 1
    assert "symbol" not in result["edges"][0]


def test_garbage_events_skipped_no_raise() -> None:
    events = [
        None,
        "not-a-dict",
        123,
        {"file": "", "var": "x", "kind": "assign", "line": 1},   # empty file
        {"file": "a.py", "var": "", "kind": "use", "line": 1},   # empty var
        {"file": "a.py", "var": "x", "kind": "weird", "line": 1},  # bad kind
        {"file": "a.py", "var": "x", "kind": "assign", "line": "nope"},  # bad line
        {"file": "a.py", "var": "x", "kind": "assign"},           # missing line
        _ev("a.py", "x", "assign", 1),  # the one valid assign
        _ev("a.py", "x", "use", 2),     # the one valid use -> single edge
    ]
    result = def_use_edges(events)
    keys = _edge_keys(result)
    assert keys == {("def:a.py:x@1", "use:a.py:x@2")}
    assert result["files"] == 1
    assert result["resolved"] == 1


def test_non_list_input_is_safe() -> None:
    assert def_use_edges(None)["edges"] == []
    assert def_use_edges("garbage")["edges"] == []
    assert def_use_edges(42)["files"] == 0
    assert def_use_edges({"file": "a.py"})["edges"] == []


def test_kind_case_insensitive_and_trimmed() -> None:
    events = [
        _ev("a.py", "x", " ASSIGN ", 1),
        _ev("a.py", "x", "Use", 2),
    ]
    result = def_use_edges(events)
    assert len(result["edges"]) == 1
    assert result["edges"][0]["from"] == "def:a.py:x@1"


def test_float_and_str_line_numbers_coerced() -> None:
    # Integer-valued float / numeric string line numbers are accepted.
    events = [
        _ev("a.py", "x", "assign", 1.0),
        _ev("a.py", "x", "use", "3"),
    ]
    result = def_use_edges(events)
    assert len(result["edges"]) == 1
    edge = result["edges"][0]
    assert edge["from"] == "def:a.py:x@1"
    assert edge["to"] == "use:a.py:x@3"


def test_bool_line_rejected() -> None:
    # bool is an int subclass but is never a valid line number.
    events = [
        {"file": "a.py", "var": "x", "kind": "assign", "line": True},
        _ev("a.py", "x", "use", 2),
    ]
    result = def_use_edges(events)
    # The assign was dropped -> the use is unresolved, no edge.
    assert result["edges"] == []
    assert result["unresolved"] == 1


def test_deterministic_across_runs() -> None:
    events = [
        _ev("a.py", "x", "assign", 1),
        _ev("a.py", "y", "assign", 1),
        _ev("a.py", "x", "use", 2),
        _ev("b.py", "x", "assign", 1),
        _ev("b.py", "x", "use", 9),
        _ev("a.py", "y", "use", 3),
    ]
    assert def_use_edges(events) == def_use_edges(events)


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
