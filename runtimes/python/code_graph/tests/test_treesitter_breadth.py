"""Language-breadth tests for tree-sitter extraction (AP-812 P-5a).

Proves extract() reaches grammars beyond the original path via
tree_sitter_language_pack — and that file-extension / alias language ids
(e.g. "rs" -> rust, "rb" -> ruby) resolve and parse. Each extracted symbol
keeps the SAME node shape the module already emits (id/label/kind/path/
language/line + the P-11 line_start/line_end). Run with the venv python:
    .venv/bin/python tests/test_treesitter_breadth.py
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from atlas_code_graph.treesitter_extract import extract  # noqa: E402

# Tiny Go snippet: a func + a type (struct). No leading newline so lines are 1-based clean.
GO = (
    "package main\n"      # line 1
    'import "fmt"\n'      # line 2
    "func Hello() {\n"    # line 3
    '    fmt.Println("hi")\n'  # line 4
    "}\n"                # line 5
    "type Point struct {\n"   # line 6
    "    X int\n"        # line 7
    "}\n"                # line 8
)

# Tiny Rust snippet: a fn + a struct.
RUST = (
    "use std::fmt;\n"        # line 1
    "fn add(a: i32, b: i32) -> i32 {\n"  # line 2
    "    a + b\n"           # line 3
    "}\n"                  # line 4
    "struct Point {\n"      # line 5
    "    x: i32,\n"        # line 6
    "}\n"                  # line 7
)


def _by_label(result):
    return {n["label"]: n for n in result["nodes"]}


def _assert_symbol_shape(node) -> None:
    for key in ("id", "label", "kind", "path", "language", "line", "line_start", "line_end"):
        assert key in node, f"symbol {node.get('id')} missing key {key}: {node}"
    assert isinstance(node["line_start"], int) and node["line_start"] >= 1, node
    assert isinstance(node["line_end"], int) and node["line_end"] >= node["line_start"], node


def test_go_breadth_function_and_type() -> None:
    r = extract([{"path": "m.go", "language": "go", "content": GO}])
    by = _by_label(r)
    assert "Hello" in by, f"Go func not extracted: {sorted(by)}"
    assert "Point" in by, f"Go type not extracted: {sorted(by)}"
    assert by["Hello"]["kind"] == "function"
    assert by["Point"]["kind"] == "type"
    for label in ("Hello", "Point"):
        node = by[label]
        assert node["language"] == "go", node
        _assert_symbol_shape(node)


def test_rust_breadth_fn_and_struct() -> None:
    r = extract([{"path": "m.rs", "language": "rust", "content": RUST}])
    by = _by_label(r)
    assert "add" in by, f"Rust fn not extracted: {sorted(by)}"
    assert "Point" in by, f"Rust struct not extracted: {sorted(by)}"
    assert by["add"]["kind"] == "function"
    assert by["Point"]["kind"] == "struct"
    for label in ("add", "Point"):
        node = by[label]
        assert node["language"] == "rust", node
        _assert_symbol_shape(node)


def test_extension_alias_resolves_to_grammar() -> None:
    # P-5a reach: a file given by its EXTENSION ("rs") still parses as rust.
    r = extract([{"path": "lib.rs", "language": "rs", "content": RUST}])
    by = _by_label(r)
    assert "add" in by and "Point" in by, f"alias 'rs' did not resolve: {sorted(by)}"
    assert by["add"]["language"] == "rust", by["add"]


def test_ruby_breadth_via_extension() -> None:
    # Ruby is a genuinely-new grammar reached through the pack; "rb" -> ruby.
    rb = "class Dog\n  def bark\n    'woof'\n  end\nend\n"
    r = extract([{"path": "d.rb", "language": "rb", "content": rb}])
    by = _by_label(r)
    assert "Dog" in by and by["Dog"]["kind"] == "class", f"ruby class missing: {sorted(by)}"
    assert "bark" in by and by["bark"]["kind"] == "method", f"ruby method missing: {sorted(by)}"
    for node in (by["Dog"], by["bark"]):
        assert node["language"] == "ruby", node
        _assert_symbol_shape(node)


def test_breadth_failsafe_missing_grammar_skipped() -> None:
    # c_sharp is NOT in the bundle: it must be skipped silently while a known
    # language in the SAME batch keeps working (additive, never raises).
    r = extract([
        {"path": "x.cs", "language": "c_sharp", "content": "class Foo { void Bar() {} }"},
        {"path": "m.go", "language": "go", "content": GO},
    ])
    by = _by_label(r)
    assert "Hello" in by, f"go regressed when c_sharp present: {sorted(by)}"
    assert not any(n["path"] == "x.cs" for n in r["nodes"]), "c_sharp should have produced no nodes"


def test_breadth_deterministic() -> None:
    a = extract([{"path": "m.go", "language": "go", "content": GO}])
    b = extract([{"path": "m.go", "language": "go", "content": GO}])
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
