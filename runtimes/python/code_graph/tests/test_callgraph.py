"""Tests for call-graph extraction (AP-811/812). Run with the venv python:
    .venv/bin/python tests/test_callgraph.py
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from atlas_code_graph.callgraph import extract_calls  # noqa: E402

PY = """
def inner():
    return 1

def outer():
    return inner()
"""

PY_METHOD = """
class Service:
    def run(self):
        self.helper()
        return other.compute()

    def helper(self):
        return 1
"""

JS = """
function inner() { return 1; }
function outer() { return inner(); }
"""

GO = """
package main
func inner() {}
func outer() { inner() }
"""

PHP = """<?php
function inner() { return 1; }
function outer() { return inner(); }
class C {
  public function run() { $this->helper(); }
  public function helper() {}
}
"""


def _pairs(result):
    return {(c["caller"], c["callee"]) for c in result["calls"]}


def test_python_outer_calls_inner() -> None:
    r = extract_calls([{"path": "x.py", "language": "python", "content": PY}])
    assert r["schema_version"] == "atlas.code_graph.callgraph.v1"
    assert ("outer", "inner") in _pairs(r)  # enclosing function -> called name


def test_python_method_call_uses_trailing_name() -> None:
    r = extract_calls([{"path": "s.py", "language": "python", "content": PY_METHOD}])
    pairs = _pairs(r)
    assert ("run", "helper") in pairs       # self.helper() -> trailing name 'helper'
    assert ("run", "compute") in pairs      # other.compute() -> trailing name 'compute'
    # receiver names must NOT become the callee
    assert not any(callee in ("self", "other") for _, callee in pairs)


def test_javascript_outer_calls_inner() -> None:
    r = extract_calls([{"path": "x.js", "language": "javascript", "content": JS}])
    assert ("outer", "inner") in _pairs(r)


def test_go_outer_calls_inner() -> None:
    r = extract_calls([{"path": "m.go", "language": "go", "content": GO}])
    assert ("outer", "inner") in _pairs(r)


def test_php_function_and_method_calls() -> None:
    r = extract_calls([{"path": "c.php", "language": "php", "content": PHP}])
    pairs = _pairs(r)
    assert ("outer", "inner") in pairs      # function_call_expression
    assert ("run", "helper") in pairs       # member_call_expression $this->helper()


def test_top_level_call_caller_is_path() -> None:
    r = extract_calls([{"path": "t.py", "language": "python", "content": "inner()\n"}])
    assert ("t.py", "inner") in _pairs(r)   # no enclosing function -> file path


def test_deterministic() -> None:
    a = extract_calls([{"path": "x.py", "language": "python", "content": PY}])
    b = extract_calls([{"path": "x.py", "language": "python", "content": PY}])
    assert a == b


def test_empty_safe() -> None:
    assert extract_calls([])["calls"] == []
    assert extract_calls([{"path": "x.py", "language": "python", "content": ""}])["calls"] == []


def test_unknown_language_skipped() -> None:
    r = extract_calls([{"path": "x.cbl", "language": "cobol", "content": "CALL X"}])
    assert r["calls"] == []


def test_non_string_content_skipped() -> None:
    r = extract_calls([{"path": "x.py", "language": "python", "content": None}])
    assert r["calls"] == []


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
