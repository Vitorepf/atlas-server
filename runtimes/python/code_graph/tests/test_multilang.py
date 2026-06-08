"""Tests for code_graph multi-language symbol+import extraction (P-1/P-2).

Runnable with pytest OR directly: `python3 tests/test_multilang.py`
(networkx/pytest may be absent in the local runtime, so it self-runs).

Imports the module directly (not via the package __init__) so it is callable
even before the op is wired into the runtime's public surface.
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from atlas_code_graph.multilang import (  # noqa: E402
    SCHEMA,
    extract_symbols_and_imports,
)


def _by_id(result):
    return {n["id"]: n for n in result["nodes"]}


def _edge_set(result):
    return {
        (e["from_node_id"], e["to_node_id"], e["edge_type"]) for e in result["edges"]
    }


# --- python: real AST -------------------------------------------------------

_PYTHON_SRC = '''\
import os
from collections import defaultdict, deque
from . import sibling


class Widget:
    def method(self):
        return 1


def top_level_fn(x):
    return x + 1


async def async_fn():
    return None
'''


def test_python_yields_class_and_function_symbols_via_ast():
    files = [{"path": "pkg/mod.py", "language": "python", "content": _PYTHON_SRC}]
    result = extract_symbols_and_imports(files)

    assert result["schema_version"] == SCHEMA
    assert result["schema_version"] == "atlas.code_graph.multilang.v1"

    by_id = _by_id(result)
    file_id = "node:file:pkg/mod.py"
    assert file_id in by_id
    assert by_id[file_id]["kind"] == "file"
    assert by_id[file_id]["language"] == "python"

    # Top-level class + functions (the nested `method` must NOT be a top-level
    # symbol — proves we read the AST body, not a regex over every `def`).
    class_id = "node:sym:python:pkg/mod.py:class:Widget"
    fn_id = "node:sym:python:pkg/mod.py:function:top_level_fn"
    async_id = "node:sym:python:pkg/mod.py:function:async_fn"
    assert class_id in by_id and by_id[class_id]["kind"] == "class"
    assert fn_id in by_id and by_id[fn_id]["kind"] == "function"
    assert async_id in by_id and by_id[async_id]["kind"] == "function"
    assert "node:sym:python:pkg/mod.py:function:method" not in by_id

    edges = _edge_set(result)
    # defines edges file -> each top-level symbol, all EXTRACTED (real AST).
    assert (file_id, class_id, "defines") in edges
    assert (file_id, fn_id, "defines") in edges
    assert (file_id, async_id, "defines") in edges
    for e in result["edges"]:
        if e["edge_type"] == "defines":
            assert e["confidence"] == "EXTRACTED"

    # imports: os, collections (from-import module), and the relative `.`.
    assert (file_id, "node:import:os", "imports") in edges
    assert (file_id, "node:import:collections", "imports") in edges
    assert (file_id, "node:import:.", "imports") in edges


def test_python_syntax_error_degrades_gracefully():
    files = [{"path": "bad.py", "language": "python", "content": "def ("}]
    result = extract_symbols_and_imports(files)
    by_id = _by_id(result)
    # File node still present; no symbols/imports invented from broken source.
    assert "node:file:bad.py" in by_id
    assert all(n["kind"] == "file" for n in result["nodes"])
    assert result["edges"] == []


# --- go: regex import edges -------------------------------------------------

_GO_SRC = '''\
package main

import "fmt"

import (
\t"os"
\tjson "encoding/json"
)

type Server struct {
\tName string
}

func (s *Server) Start() error {
\treturn nil
}

func main() {
\tfmt.Println("hi")
}
'''


def test_go_yields_import_edges_and_top_level_defs_via_regex():
    files = [{"path": "main.go", "language": "go", "content": _GO_SRC}]
    result = extract_symbols_and_imports(files)
    file_id = "node:file:main.go"
    edges = _edge_set(result)

    # import edges (single + grouped block, with and without alias).
    assert (file_id, "node:import:fmt", "imports") in edges
    assert (file_id, "node:import:os", "imports") in edges
    assert (file_id, "node:import:encoding/json", "imports") in edges

    # top-level type + funcs (method `Start` and `main` both captured).
    by_id = _by_id(result)
    assert "node:sym:go:main.go:type:Server" in by_id
    assert "node:sym:go:main.go:function:Start" in by_id
    assert "node:sym:go:main.go:function:main" in by_id

    # regex-derived facts are INFERRED, never EXTRACTED.
    for e in result["edges"]:
        assert e["confidence"] == "INFERRED"


# --- typescript: regex import edges -----------------------------------------

_TS_SRC = '''\
import { Foo } from "./foo";
import * as path from "path";
import Default from 'lib/default';
const fs = require("fs");

export interface Shape {
  size: number;
}

export class Engine {
  run() {}
}

export function build(): void {}

type Id = string;
'''


def test_typescript_yields_import_edges_via_regex():
    files = [{"path": "src/app.ts", "language": "typescript", "content": _TS_SRC}]
    result = extract_symbols_and_imports(files)
    file_id = "node:file:src/app.ts"
    edges = _edge_set(result)

    # import edges: named, namespace, default, and CommonJS require.
    assert (file_id, "node:import:./foo", "imports") in edges
    assert (file_id, "node:import:path", "imports") in edges
    assert (file_id, "node:import:lib/default", "imports") in edges
    assert (file_id, "node:import:fs", "imports") in edges

    # top-level TS declarations (interface/class/function/type).
    by_id = _by_id(result)
    assert "node:sym:typescript:src/app.ts:interface:Shape" in by_id
    assert "node:sym:typescript:src/app.ts:class:Engine" in by_id
    assert "node:sym:typescript:src/app.ts:function:build" in by_id
    assert "node:sym:typescript:src/app.ts:type:Id" in by_id

    for e in result["edges"]:
        assert e["confidence"] == "INFERRED"


# --- determinism ------------------------------------------------------------


def test_determinism_across_runs_and_input_order():
    files = [
        {"path": "main.go", "language": "go", "content": _GO_SRC},
        {"path": "pkg/mod.py", "language": "python", "content": _PYTHON_SRC},
        {"path": "src/app.ts", "language": "typescript", "content": _TS_SRC},
    ]
    a = extract_symbols_and_imports(files)
    b = extract_symbols_and_imports(files)
    assert a == b

    # Output is order-independent of input file order (sorted nodes/edges).
    c = extract_symbols_and_imports(list(reversed(files)))
    assert a == c

    # Nodes sorted by id; edges sorted by (edge_type, from, to).
    node_ids = [n["id"] for n in a["nodes"]]
    assert node_ids == sorted(node_ids)
    edge_keys = [(e["edge_type"], e["from_node_id"], e["to_node_id"]) for e in a["edges"]]
    assert edge_keys == sorted(edge_keys)


def test_empty_and_malformed_input_is_safe():
    assert extract_symbols_and_imports([])["nodes"] == []
    assert extract_symbols_and_imports([])["edges"] == []
    # Non-list input, non-dict entries, missing path -> safely ignored.
    assert extract_symbols_and_imports(None)["nodes"] == []
    assert extract_symbols_and_imports(["nope", 7, {}])["nodes"] == []
    assert extract_symbols_and_imports([{"language": "python", "content": "x=1"}])[
        "nodes"
    ] == []


def test_unknown_language_registers_file_node_only():
    files = [{"path": "data.bin", "language": "cobol", "content": "WHATEVER"}]
    result = extract_symbols_and_imports(files)
    assert [n["id"] for n in result["nodes"]] == ["node:file:data.bin"]
    assert result["edges"] == []


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
