"""Tests for code_graph cross-repo supply-chain graph (AP-815, block X-5).

Runnable with pytest OR directly: `python3 tests/test_supply_chain.py`
(networkx/pytest may be absent in the local runtime, so it self-runs).
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from atlas_code_graph.supply_chain import supply_chain  # noqa: E402


def _shared_index(result: dict) -> dict:
    """Map package name -> its shared entry for easy assertions."""
    return {s["package"]: s for s in result["shared"]}


def _edge_keys(result: dict) -> set:
    """Set of (from, to) tuples for membership checks."""
    return {(e["from"], e["to"]) for e in result["edges"]}


def test_spec_example_shared_lodash_with_version_conflict() -> None:
    # Two workspaces share 'lodash' at DIFFERENT versions; one unique dep each.
    manifests = [
        {
            "workspace": "api",
            "ecosystem": "npm",
            "dependencies": {"lodash": "4.17.21", "express": "4.18.2"},
        },
        {
            "workspace": "web",
            "ecosystem": "npm",
            "dependencies": {"lodash": "3.10.1", "react": "18.2.0"},
        },
    ]
    result = supply_chain(manifests)

    assert result["schema_version"] == "atlas.code_graph.supply_chain.v1"
    assert result["workspaces"] == 2
    # lodash, express, react -> 3 distinct (ecosystem, package) keys.
    assert result["packages"] == 3

    shared = _shared_index(result)
    # lodash is shared by >1 workspace -> in the blast view.
    assert "lodash" in shared
    assert shared["lodash"]["count"] == 2
    assert shared["lodash"]["workspaces"] == ["api", "web"]
    # Both distinct versions surfaced (version conflict).
    assert shared["lodash"]["versions"] == ["3.10.1", "4.17.21"]
    assert shared["lodash"]["ecosystem"] == "npm"

    # Unique deps are NOT in the shared (cross-repo) view.
    assert "express" not in shared
    assert "react" not in shared


def test_edges_count_and_naming() -> None:
    manifests = [
        {
            "workspace": "api",
            "ecosystem": "npm",
            "dependencies": {"lodash": "4.17.21", "express": "4.18.2"},
        },
        {
            "workspace": "web",
            "ecosystem": "npm",
            "dependencies": {"lodash": "3.10.1", "react": "18.2.0"},
        },
    ]
    result = supply_chain(manifests)
    # 2 deps per workspace * 2 workspaces = 4 edges.
    assert len(result["edges"]) == 4
    keys = _edge_keys(result)
    assert ("ws:api", "pkg:lodash") in keys
    assert ("ws:api", "pkg:express") in keys
    assert ("ws:web", "pkg:lodash") in keys
    assert ("ws:web", "pkg:react") in keys
    # Edge carries version + ecosystem.
    api_lodash = next(
        e for e in result["edges"]
        if e["from"] == "ws:api" and e["to"] == "pkg:lodash"
    )
    assert api_lodash["version"] == "4.17.21"
    assert api_lodash["ecosystem"] == "npm"


def test_shared_excludes_single_workspace_packages() -> None:
    manifests = [
        {"workspace": "solo", "ecosystem": "npm", "dependencies": {"only-me": "1.0.0"}},
    ]
    result = supply_chain(manifests)
    assert result["shared"] == []
    assert result["packages"] == 1
    assert result["workspaces"] == 1
    assert len(result["edges"]) == 1


def test_shared_same_version_no_conflict_single_version() -> None:
    # Shared but pinned identically -> one version in the list (no conflict).
    manifests = [
        {"workspace": "a", "ecosystem": "pypi", "dependencies": {"requests": "2.31.0"}},
        {"workspace": "b", "ecosystem": "pypi", "dependencies": {"requests": "2.31.0"}},
    ]
    result = supply_chain(manifests)
    shared = _shared_index(result)
    assert shared["requests"]["count"] == 2
    assert shared["requests"]["versions"] == ["2.31.0"]


def test_same_name_different_ecosystems_not_conflated() -> None:
    # npm 'foo' and pypi 'foo' are distinct packages, NOT a shared dependency.
    manifests = [
        {"workspace": "a", "ecosystem": "npm", "dependencies": {"foo": "1.0.0"}},
        {"workspace": "b", "ecosystem": "pypi", "dependencies": {"foo": "9.9.9"}},
    ]
    result = supply_chain(manifests)
    # Two distinct (ecosystem, package) keys.
    assert result["packages"] == 2
    # Neither is shared across workspaces (each ecosystem-package has 1 ws).
    assert result["shared"] == []


def test_shared_sorted_by_count_then_name() -> None:
    # 'big' used by 3 ws, 'mid' + 'zeta' by 2 ws each -> order: big, mid, zeta.
    manifests = [
        {"workspace": "w1", "ecosystem": "npm", "dependencies": {"big": "1", "mid": "1", "zeta": "1"}},
        {"workspace": "w2", "ecosystem": "npm", "dependencies": {"big": "1", "mid": "1", "zeta": "1"}},
        {"workspace": "w3", "ecosystem": "npm", "dependencies": {"big": "1"}},
    ]
    result = supply_chain(manifests)
    order = [s["package"] for s in result["shared"]]
    assert order == ["big", "mid", "zeta"]
    assert result["shared"][0]["count"] == 3


def test_workspaces_list_sorted_and_deduped() -> None:
    # Same workspace name across two manifests must dedupe in the shared list.
    manifests = [
        {"workspace": "zzz", "ecosystem": "npm", "dependencies": {"p": "1"}},
        {"workspace": "aaa", "ecosystem": "npm", "dependencies": {"p": "2"}},
    ]
    result = supply_chain(manifests)
    shared = _shared_index(result)
    assert shared["p"]["workspaces"] == ["aaa", "zzz"]


def test_missing_version_falls_back_to_wildcard() -> None:
    manifests = [
        {"workspace": "a", "ecosystem": "npm", "dependencies": {"x": None}},
        {"workspace": "b", "ecosystem": "npm", "dependencies": {"x": ""}},
    ]
    result = supply_chain(manifests)
    shared = _shared_index(result)
    # Both unpinned -> single "*" version, still shared.
    assert shared["x"]["versions"] == ["*"]
    assert shared["x"]["count"] == 2


def test_numeric_version_coerced_bool_not_numeric() -> None:
    manifests = [
        {"workspace": "a", "ecosystem": "npm", "dependencies": {"x": 1}},
        {"workspace": "b", "ecosystem": "npm", "dependencies": {"y": True}},
    ]
    result = supply_chain(manifests)
    # int version coerced to its str form.
    a_edge = next(e for e in result["edges"] if e["to"] == "pkg:x")
    assert a_edge["version"] == "1"
    # bool must NOT become "1"/"True"-as-int — guarded to wildcard.
    b_edge = next(e for e in result["edges"] if e["to"] == "pkg:y")
    assert b_edge["version"] == "*"


def test_missing_ecosystem_defaults_unknown() -> None:
    manifests = [
        {"workspace": "a", "dependencies": {"x": "1"}},
        {"workspace": "b", "dependencies": {"x": "2"}},
    ]
    result = supply_chain(manifests)
    shared = _shared_index(result)
    assert shared["x"]["ecosystem"] == "unknown"


def test_empty_input_is_safe() -> None:
    result = supply_chain([])
    assert result["edges"] == []
    assert result["shared"] == []
    assert result["packages"] == 0
    assert result["workspaces"] == 0


def test_garbage_manifests_skipped_no_raise() -> None:
    manifests = [
        None,
        "not-a-dict",
        123,
        {"ecosystem": "npm", "dependencies": {"x": "1"}},  # no workspace -> skip
        {"workspace": "", "dependencies": {"x": "1"}},      # blank workspace -> skip
        {"workspace": "ok", "ecosystem": "npm", "dependencies": "not-a-dict"},  # bad deps -> skip
        {"workspace": "real", "ecosystem": "npm", "dependencies": {"good": "1.0"}},
    ]
    result = supply_chain(manifests)
    # Only the last real manifest contributes.
    assert result["workspaces"] == 1
    assert result["packages"] == 1
    assert len(result["edges"]) == 1
    assert result["edges"][0]["from"] == "ws:real"


def test_garbage_dep_entries_skipped() -> None:
    manifests = [
        {
            "workspace": "a",
            "ecosystem": "npm",
            "dependencies": {"good": "1.0", "": "x", "  ": "y", 5: "z"},
        },
    ]
    result = supply_chain(manifests)
    # Only the 'good' dep survives (blank/whitespace/non-str names dropped).
    assert len(result["edges"]) == 1
    assert result["edges"][0]["to"] == "pkg:good"


def test_empty_deps_manifest_not_counted_as_workspace() -> None:
    # A manifest with no usable deps must not inflate the workspace count.
    manifests = [
        {"workspace": "empty", "ecosystem": "npm", "dependencies": {}},
        {"workspace": "real", "ecosystem": "npm", "dependencies": {"x": "1"}},
    ]
    result = supply_chain(manifests)
    assert result["workspaces"] == 1


def test_non_list_manifests_arg_is_safe() -> None:
    assert supply_chain(None)["edges"] == []
    assert supply_chain("garbage")["shared"] == []
    assert supply_chain(42)["packages"] == 0
    assert supply_chain({"workspace": "a"})["workspaces"] == 0  # dict, not list


def test_edges_deterministic_sorted() -> None:
    manifests = [
        {"workspace": "web", "ecosystem": "npm", "dependencies": {"zebra": "1", "apple": "1"}},
        {"workspace": "api", "ecosystem": "npm", "dependencies": {"mango": "1"}},
    ]
    result = supply_chain(manifests)
    order = [(e["from"], e["to"]) for e in result["edges"]]
    # Sorted by from then to: ws:api first, then ws:web's apple before zebra.
    assert order == [
        ("ws:api", "pkg:mango"),
        ("ws:web", "pkg:apple"),
        ("ws:web", "pkg:zebra"),
    ]


def test_duplicate_workspace_same_package_idempotent_edge() -> None:
    # Two manifests for the same workspace+ecosystem+package -> one edge,
    # but versions still aggregate across them for the conflict view.
    manifests = [
        {"workspace": "a", "ecosystem": "npm", "dependencies": {"x": "1.0"}},
        {"workspace": "a", "ecosystem": "npm", "dependencies": {"x": "2.0"}},
        {"workspace": "b", "ecosystem": "npm", "dependencies": {"x": "1.0"}},
    ]
    result = supply_chain(manifests)
    # ws:a -> pkg:x appears once (idempotent), ws:b -> pkg:x once = 2 edges.
    a_edges = [e for e in result["edges"] if e["from"] == "ws:a" and e["to"] == "pkg:x"]
    assert len(a_edges) == 1
    # First-seen version wins for the edge (1.0), but conflict view sees both.
    shared = _shared_index(result)
    assert shared["x"]["versions"] == ["1.0", "2.0"]
    assert shared["x"]["count"] == 2  # a + b distinct workspaces


def test_deterministic_across_runs() -> None:
    manifests = [
        {"workspace": "api", "ecosystem": "npm", "dependencies": {"lodash": "4", "express": "4"}},
        {"workspace": "web", "ecosystem": "npm", "dependencies": {"lodash": "3", "react": "18"}},
    ]
    assert supply_chain(manifests) == supply_chain(manifests)


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
