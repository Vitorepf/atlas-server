"""Tests for the PHP type-aware call extractor (AP-811/812). Run with venv python:
    .venv/bin/python tests/test_typed_callgraph.py
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from atlas_code_graph.typed_callgraph import SCHEMA, extract_typed_calls  # noqa: E402

# Heavy dep: the tree-sitter parser bundle. Absent in venv-less runs, where
# extract_typed_calls degrades to empty — nothing to assert — so self-skip
# cleanly (exit 0) instead of erroring the suite. Matches leiden/hybrid pattern.
try:  # pragma: no cover - environment dependent
    import tree_sitter_language_pack  # noqa: F401
except Exception:  # noqa: BLE001
    print("dep-skip: tree_sitter_language_pack absent; heavy path not exercised")
    raise SystemExit(0)

# Required snippet from the slice spec (wrapped in a PHP open tag so the grammar
# parses it as PHP rather than inline HTML).
PHP = """<?php
namespace App\\X;
use App\\Y\\Helper;
class Svc {
    function run(){
        $this->step();
        self::boot();
        Helper::make();
        parent::init();
    }
    function step(){}
}
"""

# Extra coverage: aliased import, static::, qualified Name::, and a dynamic
# $var-> call that must stay "unknown" (no type guess in v1).
PHP_EXTRA = """<?php
namespace App\\Z;
use App\\Y\\Helper as H;
use Foo\\Bar;
class Worker {
    function go(){
        static::tick();
        \\App\\Q\\Other::run();
        $svc->dynamic();
    }
}
"""


def _find(calls, callee):
    for c in calls:
        if c["callee_name"] == callee:
            return c
    return None


def test_schema_version() -> None:
    r = extract_typed_calls([{"path": "Svc.php", "language": "php", "content": PHP}])
    assert r["schema_version"] == SCHEMA == "atlas.code_graph.typed_callgraph.v1", r["schema_version"]


def test_namespace_and_imports() -> None:
    r = extract_typed_calls([{"path": "Svc.php", "language": "php", "content": PHP}])
    assert r["namespace_by_file"]["Svc.php"] == "App\\X", r["namespace_by_file"]
    assert r["imports_by_file"]["Svc.php"] == {"Helper": "App\\Y\\Helper"}, r["imports_by_file"]


def test_receiver_this_step() -> None:
    r = extract_typed_calls([{"path": "Svc.php", "language": "php", "content": PHP}])
    c = _find(r["calls"], "step")
    assert c is not None, "no call to step()"
    assert c["receiver"] == "this", c
    assert c["caller_class"] == "App\\X\\Svc", c
    assert c["caller_method"] == "run", c


def test_receiver_self_boot() -> None:
    r = extract_typed_calls([{"path": "Svc.php", "language": "php", "content": PHP}])
    c = _find(r["calls"], "boot")
    assert c is not None, "no call to boot()"
    assert c["receiver"] == "self", c
    assert c["caller_class"] == "App\\X\\Svc", c
    assert c["caller_method"] == "run", c


def test_receiver_static_class_make() -> None:
    r = extract_typed_calls([{"path": "Svc.php", "language": "php", "content": PHP}])
    c = _find(r["calls"], "make")
    assert c is not None, "no call to make()"
    assert c["receiver"] == {"static_class": "Helper"}, c
    assert c["caller_class"] == "App\\X\\Svc", c
    assert c["caller_method"] == "run", c


def test_receiver_parent_init() -> None:
    r = extract_typed_calls([{"path": "Svc.php", "language": "php", "content": PHP}])
    c = _find(r["calls"], "init")
    assert c is not None, "no call to init()"
    assert c["receiver"] == "parent", c
    assert c["caller_class"] == "App\\X\\Svc", c
    assert c["caller_method"] == "run", c


def test_receiver_var_type_from_param_hint_and_new() -> None:
    php = """<?php
namespace App\\V;
class Svc {
    function m(Helper $h){
        $h->go();
        $x = new Other();
        $x->run();
    }
}
"""
    r = extract_typed_calls([{"path": "V.php", "language": "php", "content": php}])
    go = _find(r["calls"], "go")
    run = _find(r["calls"], "run")
    assert go is not None and go["receiver"] == {"var_type": "Helper"}, go
    assert run is not None and run["receiver"] == {"var_type": "Other"}, run


def test_all_four_receivers_present() -> None:
    r = extract_typed_calls([{"path": "Svc.php", "language": "php", "content": PHP}])
    by_callee = {c["callee_name"]: c["receiver"] for c in r["calls"]}
    assert by_callee.get("step") == "this", by_callee
    assert by_callee.get("boot") == "self", by_callee
    assert by_callee.get("make") == {"static_class": "Helper"}, by_callee
    assert by_callee.get("init") == "parent", by_callee


def test_extra_static_qualified_and_unknown() -> None:
    r = extract_typed_calls([{"path": "Worker.php", "language": "php", "content": PHP_EXTRA}])
    by_callee = {c["callee_name"]: c["receiver"] for c in r["calls"]}
    assert by_callee.get("tick") == "static", by_callee
    # Qualified \App\Q\Other::run() -> short last segment "Other".
    assert by_callee.get("run") == {"static_class": "Other"}, by_callee
    # Dynamic $svc->dynamic() must NOT guess a type in v1.
    assert by_callee.get("dynamic") == "unknown", by_callee
    # Aliased import: alias key -> FQN.
    assert r["imports_by_file"]["Worker.php"]["H"] == "App\\Y\\Helper", r["imports_by_file"]
    assert r["imports_by_file"]["Worker.php"]["Bar"] == "Foo\\Bar", r["imports_by_file"]
    for c in r["calls"]:
        assert c["caller_class"] == "App\\Z\\Worker", c
        assert c["caller_method"] == "go", c


def test_deterministic() -> None:
    files = [{"path": "Svc.php", "language": "php", "content": PHP}]
    assert extract_typed_calls(files) == extract_typed_calls(files)


def test_non_php_skipped() -> None:
    r = extract_typed_calls([
        {"path": "x.py", "language": "python", "content": "class A:\n    def m(self): self.n()\n"},
        {"path": "y.go", "language": "go", "content": "package main"},
    ])
    assert r["calls"] == [], r["calls"]
    assert r["namespace_by_file"] == {}, r["namespace_by_file"]
    assert r["imports_by_file"] == {}, r["imports_by_file"]


def test_calls_outside_class_method_excluded() -> None:
    # A scoped call at file scope (not inside a class method) must be excluded.
    php = "<?php\nnamespace App\\F;\nHelper::boot();\n"
    r = extract_typed_calls([{"path": "free.php", "language": "php", "content": php}])
    assert r["calls"] == [], r["calls"]
    assert r["namespace_by_file"]["free.php"] == "App\\F", r["namespace_by_file"]


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
