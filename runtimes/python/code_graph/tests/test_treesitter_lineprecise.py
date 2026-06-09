"""Line-precise anchoring tests for tree-sitter extraction (AP-812 P-11).

Asserts each extracted symbol carries 1-based line_start/line_end derived from the
tree-sitter node start_point/end_point rows. Run with the venv python:
    .venv/bin/python tests/test_treesitter_lineprecise.py
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from atlas_code_graph.treesitter_extract import extract  # noqa: E402

# 5-line snippet, NO leading newline so line numbers are unambiguous:
#   line 1: import os
#   line 2: class Foo:
#   line 3:     def method_a(self):
#   line 4:         return 1
#   line 5: def top_level(): return 2
PY = (
    "import os\n"
    "class Foo:\n"
    "    def method_a(self):\n"
    "        return 1\n"
    "def top_level(): return 2\n"
)


def _by_label(result):
    return {n["label"]: n for n in result["nodes"]}


def test_symbols_have_line_fields() -> None:
    r = extract([{"path": "x.py", "language": "python", "content": PY}])
    for node in r["nodes"]:
        assert "line_start" in node, f"missing line_start in {node['id']}"
        assert "line_end" in node, f"missing line_end in {node['id']}"


def test_python_class_line_range() -> None:
    r = extract([{"path": "x.py", "language": "python", "content": PY}])
    foo = _by_label(r)["Foo"]
    # class Foo spans line 2 (def) through line 4 (its last body line).
    assert foo["line_start"] == 2, foo
    assert foo["line_end"] == 4, foo


def test_python_nested_method_line_range() -> None:
    r = extract([{"path": "x.py", "language": "python", "content": PY}])
    method = _by_label(r)["method_a"]
    # def method_a(self): ... return 1 spans lines 3-4.
    assert method["line_start"] == 3, method
    assert method["line_end"] == 4, method


def test_python_top_level_function_line_range() -> None:
    r = extract([{"path": "x.py", "language": "python", "content": PY}])
    fn = _by_label(r)["top_level"]
    # def top_level(): return 2 — single line, line 5.
    assert fn["line_start"] == 5, fn
    assert fn["line_end"] == 5, fn


def test_line_fields_are_additive() -> None:
    # Existing keys must remain untouched alongside the new fields.
    r = extract([{"path": "x.py", "language": "python", "content": PY}])
    foo = _by_label(r)["Foo"]
    for key in ("id", "label", "kind", "path", "language", "line"):
        assert key in foo, f"existing key {key} dropped from {foo}"
    # legacy byte-counted 'line' still agrees with the new precise start.
    assert foo["line"] == foo["line_start"], foo


def test_line_fields_deterministic() -> None:
    a = extract([{"path": "x.py", "language": "python", "content": PY}])
    b = extract([{"path": "x.py", "language": "python", "content": PY}])
    assert a == b


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
