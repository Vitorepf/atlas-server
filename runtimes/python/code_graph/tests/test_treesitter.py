"""Tests for tree-sitter extraction (AP-812 P-1). Run with the venv python:
    .venv/bin/python tests/test_treesitter.py
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from atlas_code_graph.treesitter_extract import extract  # noqa: E402

# Heavy dep: the tree-sitter parser bundle. Absent in venv-less runs, where
# extract degrades to empty — nothing to assert — so self-skip cleanly (exit 0)
# instead of erroring the suite. Matches leiden/hybrid_ranker pattern.
try:  # pragma: no cover - environment dependent
    import tree_sitter_language_pack  # noqa: F401
except Exception:  # noqa: BLE001
    print("dep-skip: tree_sitter_language_pack absent; heavy path not exercised")
    raise SystemExit(0)

PY = """
import os
from collections import defaultdict

class Foo:
    def method_a(self):
        return 1

def top_level():
    return 2
"""

GO = """
package main
import "fmt"
func Hello() { fmt.Println("hi") }
"""


def test_python_captures_nested_method() -> None:
    r = extract([{"path": "x.py", "language": "python", "content": PY}])
    labels = {n["label"] for n in r["nodes"]}
    assert "Foo" in labels
    assert "method_a" in labels  # nested method — precision beyond ast top-level
    assert "top_level" in labels
    assert any(e["edge_type"] == "imports" for e in r["edges"])


def test_go_captures_function_and_import() -> None:
    r = extract([{"path": "m.go", "language": "go", "content": GO}])
    labels = {n["label"] for n in r["nodes"]}
    assert "Hello" in labels
    assert any(e["edge_type"] == "imports" for e in r["edges"])


def test_deterministic() -> None:
    a = extract([{"path": "x.py", "language": "python", "content": PY}])
    b = extract([{"path": "x.py", "language": "python", "content": PY}])
    assert a == b


def test_unknown_language_skipped() -> None:
    r = extract([{"path": "x.cbl", "language": "cobol", "content": "X"}])
    assert r["node_count"] == 0


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
                print(f"ERROR {name}: {exc}")
    print(f"\n{'OK' if failures == 0 else 'FAILED'}: {failures} failure(s)")
    raise SystemExit(1 if failures else 0)
