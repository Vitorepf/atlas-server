"""Tests for code_graph co-change / temporal-coupling edges (AP-815).

Runnable with pytest OR directly: `python3 tests/test_co_change.py`
(networkx/pytest may be absent in the local runtime, so it self-runs).
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from atlas_code_graph.co_change import co_change  # noqa: E402


def _edges_index(result: dict) -> dict:
    """Map (from, to) -> weight for easy assertions."""
    return {(e["from"], e["to"]): e["weight"] for e in result["edges"]}


def test_spec_example_min_support_2() -> None:
    # commits=[[a,b,c],[a,b],[a,c]] with min_support=2:
    #   a-b appears in commit1 + commit2 -> weight 2 (kept)
    #   a-c appears in commit1 + commit3 -> weight 2 (kept)
    #   b-c appears only in commit1     -> weight 1 (dropped)
    result = co_change([["a", "b", "c"], ["a", "b"], ["a", "c"]], min_support=2)
    idx = _edges_index(result)

    assert result["schema_version"] == "atlas.code_graph.co_change.v1"
    assert result["commits"] == 3
    assert result["files"] == 3
    assert idx.get(("a", "b")) == 2
    assert idx.get(("a", "c")) == 2
    assert ("b", "c") not in idx
    assert len(result["edges"]) == 2


def test_min_support_1_includes_all() -> None:
    result = co_change([["a", "b", "c"], ["a", "b"], ["a", "c"]], min_support=1)
    idx = _edges_index(result)
    assert idx.get(("a", "b")) == 2
    assert idx.get(("a", "c")) == 2
    assert idx.get(("b", "c")) == 1
    assert len(result["edges"]) == 3


def test_single_file_commits_produce_no_pairs() -> None:
    result = co_change([["a"], ["b"], ["c"]], min_support=1)
    assert result["edges"] == []
    assert result["commits"] == 3
    assert result["files"] == 3


def test_empty_input_is_safe() -> None:
    result = co_change([])
    assert result["edges"] == []
    assert result["files"] == 0
    assert result["commits"] == 0


def test_support_and_weight_fields_present() -> None:
    result = co_change([["x", "y"], ["x", "y"]], min_support=2)
    assert len(result["edges"]) == 1
    edge = result["edges"][0]
    assert edge["from"] == "x"
    assert edge["to"] == "y"
    assert edge["weight"] == 2
    assert edge["support"] == 2


def test_canonical_direction_from_less_than_to() -> None:
    # Files given out of order still emit (from < to) lexical direction.
    result = co_change([["z.py", "a.py"], ["z.py", "a.py"]], min_support=2)
    assert len(result["edges"]) == 1
    edge = result["edges"][0]
    assert edge["from"] == "a.py"
    assert edge["to"] == "z.py"


def test_duplicate_files_within_commit_counted_once() -> None:
    # A file listed twice in one commit must not self-inflate or create a pair.
    result = co_change([["a", "a", "b"]], min_support=1)
    idx = _edges_index(result)
    assert idx == {("a", "b"): 1}
    assert result["files"] == 2


def test_sorted_by_weight_then_lexical() -> None:
    # a-b changes 3x, c-d changes 2x, e-f changes 2x.
    commits = [
        ["a", "b"],
        ["a", "b"],
        ["a", "b"],
        ["c", "d"],
        ["c", "d"],
        ["e", "f"],
        ["e", "f"],
    ]
    result = co_change(commits, min_support=2)
    order = [(e["from"], e["to"]) for e in result["edges"]]
    # strongest first (a-b w3), then ties broken lexically (c-d before e-f).
    assert order == [("a", "b"), ("c", "d"), ("e", "f")]


def test_max_pairs_bounds_output() -> None:
    commits = [
        ["a", "b"], ["a", "b"],
        ["c", "d"], ["c", "d"],
        ["e", "f"], ["e", "f"],
    ]
    result = co_change(commits, min_support=2, max_pairs=2)
    assert len(result["edges"]) == 2
    # The cap keeps the highest-priority (sorted) edges.
    order = [(e["from"], e["to"]) for e in result["edges"]]
    assert order == [("a", "b"), ("c", "d")]


def test_garbage_entries_skipped_no_raise() -> None:
    commits = [
        None,
        "not-a-list",
        123,
        ["a", None, 5, "", "  ", "b"],  # only a + b survive
        [["nested"], "c", "d"],          # nested list dropped, c+d survive
    ]
    result = co_change(commits, min_support=1)
    idx = _edges_index(result)
    assert idx.get(("a", "b")) == 1
    assert idx.get(("c", "d")) == 1
    # nothing exploded; non-list garbage commits don't count as commits.
    assert result["commits"] == 2


def test_non_list_commits_arg_is_safe() -> None:
    assert co_change(None)["edges"] == []
    assert co_change("garbage")["edges"] == []
    assert co_change(42)["files"] == 0


def test_bad_min_support_falls_back_safely() -> None:
    # Non-int / sub-1 min_support must not raise.
    result = co_change([["a", "b"], ["a", "b"]], min_support="bad")
    assert len(result["edges"]) == 1  # falls back to default 2
    result2 = co_change([["a", "b"]], min_support=0)
    assert len(result2["edges"]) == 1  # clamped to 1


def test_deterministic_across_runs() -> None:
    commits = [["a", "b", "c"], ["a", "b"], ["b", "c"], ["a", "c"]]
    assert co_change(commits) == co_change(commits)


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
