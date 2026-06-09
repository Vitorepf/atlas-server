"""Tests for the python symbol-edge resolver (AP-815 C5) — the flag-gated mirror
of the PHP CodeGraphSymbolResolver.

Runnable with pytest OR directly: `python3 tests/test_edge_resolver.py`
(pytest may be absent in the local runtime, so it self-runs).

These pin the equivalence-critical behaviours of the PHP resolver:
  - existence + single-candidate gate (no edge to an unknown / ambiguous FQN),
  - import-evidence promotion (EXTRACTED vs INFERRED for references/tests),
  - dependency always EXTRACTED, dedup with strongest-evidence-wins,
  - self-edge suppression, deterministic ordering, node-id length capping,
  - fail-safe handling of malformed input (never raises).
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from atlas_code_graph.edge_resolver import (  # noqa: E402
    CONFIDENCE_EXTRACTED,
    CONFIDENCE_INFERRED,
    SCHEMA,
    resolve_edges,
)


def _sym(name: str, type_: str, file_path: str) -> dict:
    return {"name": name, "type": type_, "file_path": file_path}


def _rel(file_path: str, symbol: str, kind: str) -> dict:
    return {"file_path": file_path, "symbol": symbol, "kind": kind}


def _edge_keys(result: dict) -> set:
    """Set of (from, to, edge_type) for membership assertions."""
    return {(e["from_node_id"], e["to_node_id"], e["edge_type"]) for e in result["edges"]}


def _by_key(result: dict, from_id: str, to_id: str, edge_type: str) -> dict:
    for e in result["edges"]:
        if (e["from_node_id"], e["to_node_id"], e["edge_type"]) == (from_id, to_id, edge_type):
            return e
    raise AssertionError(f"edge {from_id}->{to_id} ({edge_type}) not found")


def test_schema_and_empty_shape() -> None:
    result = resolve_edges({"symbols": [], "relations": []})
    assert result["schema_version"] == SCHEMA == "atlas.code_graph.symbol_edges.v1"
    assert result["edges"] == []
    assert result["symbol_node_ids"] == []
    assert result["stats"]["relations"] == 0
    assert result["stats"]["extracted"] == 0
    assert result["stats"]["inferred"] == 0


def test_dependency_is_extracted_depends_on() -> None:
    # Alpha (in Alpha.php) `use`s Beta -> single EXTRACTED depends_on edge.
    symbols = [
        _sym("App\\Alpha", "class", "src/Alpha.php"),
        _sym("App\\Beta", "class", "src/Beta.php"),
    ]
    relations = [_rel("src/Alpha.php", "App\\Beta", "use")]
    result = resolve_edges({"symbols": symbols, "relations": relations})

    assert len(result["edges"]) == 1
    edge = result["edges"][0]
    assert edge["from_node_id"] == "sym:App\\Alpha"
    assert edge["to_node_id"] == "sym:App\\Beta"
    assert edge["edge_type"] == "depends_on"
    assert edge["confidence"] == CONFIDENCE_EXTRACTED
    assert edge["confidence_score"] == 1.0
    assert edge["metadata"]["resolver"] == SCHEMA
    assert edge["metadata"]["relation"] == "use"
    assert edge["metadata"]["source_ref"] == "src/Alpha.php"
    assert edge["metadata"]["occurrences"] == 1
    assert edge["metadata"]["inferred"] is False
    # Both endpoints are used nodes.
    assert set(result["symbol_node_ids"]) == {"sym:App\\Alpha", "sym:App\\Beta"}
    assert result["stats"]["extracted"] == 1
    assert result["stats"]["inferred"] == 0


def test_reference_without_import_is_inferred() -> None:
    # A bare symbol_reference (no matching dependency) -> INFERRED depends_on 0.85.
    symbols = [
        _sym("App\\Alpha", "class", "src/Alpha.php"),
        _sym("App\\Beta", "class", "src/Beta.php"),
    ]
    relations = [_rel("src/Alpha.php", "App\\Beta", "symbol_references")]
    result = resolve_edges({"symbols": symbols, "relations": relations})

    edge = _by_key(result, "sym:App\\Alpha", "sym:App\\Beta", "depends_on")
    assert edge["confidence"] == CONFIDENCE_INFERRED
    assert edge["confidence_score"] == 0.85
    assert edge["metadata"]["inferred"] is True
    assert result["stats"]["inferred"] == 1
    assert result["stats"]["extracted"] == 0


def test_reference_with_import_evidence_is_promoted_to_extracted() -> None:
    # Same reference, but the file ALSO imports Beta -> promoted to EXTRACTED.
    # The two relations dedup into ONE edge (strongest-evidence-wins == EXTRACTED).
    symbols = [
        _sym("App\\Alpha", "class", "src/Alpha.php"),
        _sym("App\\Beta", "class", "src/Beta.php"),
    ]
    relations = [
        _rel("src/Alpha.php", "App\\Beta", "use"),               # dependency
        _rel("src/Alpha.php", "App\\Beta", "symbol_references"),  # reference, promoted
    ]
    result = resolve_edges({"symbols": symbols, "relations": relations})

    assert len(result["edges"]) == 1
    edge = result["edges"][0]
    assert edge["confidence"] == CONFIDENCE_EXTRACTED
    assert edge["confidence_score"] == 1.0
    # Deduped: two relations -> occurrences 2, one dedup hit.
    assert edge["metadata"]["occurrences"] == 2
    assert result["stats"]["deduped"] == 1


def test_test_edge_inferred_then_promoted() -> None:
    # A test_target without import -> tests/INFERRED/0.8.
    symbols = [
        _sym("Tests\\AlphaTest", "class", "tests/AlphaTest.php"),
        _sym("App\\Alpha", "class", "src/Alpha.php"),
    ]
    relations = [_rel("tests/AlphaTest.php", "App\\Alpha", "test_targets")]
    result = resolve_edges({"symbols": symbols, "relations": relations})
    edge = _by_key(result, "sym:Tests\\AlphaTest", "sym:App\\Alpha", "tests")
    assert edge["confidence"] == CONFIDENCE_INFERRED
    assert edge["confidence_score"] == 0.8

    # Now with import evidence in the same file -> tests/EXTRACTED/1.0.
    relations_promoted = [
        _rel("tests/AlphaTest.php", "App\\Alpha", "use"),          # dependency import
        _rel("tests/AlphaTest.php", "App\\Alpha", "test_targets"),  # promoted test
    ]
    promoted = resolve_edges({"symbols": symbols, "relations": relations_promoted})
    # dependency 'use' emits a depends_on EXTRACTED, test_targets emits tests EXTRACTED.
    tests_edge = _by_key(promoted, "sym:Tests\\AlphaTest", "sym:App\\Alpha", "tests")
    assert tests_edge["confidence"] == CONFIDENCE_EXTRACTED
    assert tests_edge["confidence_score"] == 1.0


def test_unresolved_target_emits_no_edge() -> None:
    # Beta is referenced but never DEFINED as a symbol -> no edge, counted skipped.
    symbols = [_sym("App\\Alpha", "class", "src/Alpha.php")]
    relations = [_rel("src/Alpha.php", "App\\Beta", "use")]
    result = resolve_edges({"symbols": symbols, "relations": relations})
    assert result["edges"] == []
    assert result["stats"]["skipped_unresolved"] == 1


def test_ambiguous_target_is_never_used() -> None:
    # The SAME FQN defined in two different files is AMBIGUOUS: never an edge target.
    symbols = [
        _sym("App\\Alpha", "class", "src/Alpha.php"),
        _sym("App\\Dup", "class", "src/DupA.php"),
        _sym("App\\Dup", "class", "src/DupB.php"),  # collision -> ambiguous
    ]
    relations = [_rel("src/Alpha.php", "App\\Dup", "use")]
    result = resolve_edges({"symbols": symbols, "relations": relations})
    assert result["edges"] == []
    assert result["stats"]["ambiguous_target"] == 1


def test_self_edge_is_suppressed() -> None:
    # A class that references itself produces no self-loop.
    symbols = [_sym("App\\Alpha", "class", "src/Alpha.php")]
    relations = [_rel("src/Alpha.php", "App\\Alpha", "use")]
    result = resolve_edges({"symbols": symbols, "relations": relations})
    assert result["edges"] == []
    assert result["stats"]["skipped_self"] == 1


def test_multiple_sources_in_one_file_each_get_an_edge() -> None:
    # Two classes in the same file both referencing Beta -> two edges from the file.
    symbols = [
        _sym("App\\Alpha", "class", "src/Multi.php"),
        _sym("App\\Gamma", "class", "src/Multi.php"),
        _sym("App\\Beta", "class", "src/Beta.php"),
    ]
    relations = [_rel("src/Multi.php", "App\\Beta", "use")]
    result = resolve_edges({"symbols": symbols, "relations": relations})
    keys = _edge_keys(result)
    assert ("sym:App\\Alpha", "sym:App\\Beta", "depends_on") in keys
    assert ("sym:App\\Gamma", "sym:App\\Beta", "depends_on") in keys
    assert len(result["edges"]) == 2


def test_method_symbols_are_not_edge_sources() -> None:
    # A 'method' symbol is a node kind but NOT a class-like edge source: a relation
    # in a file that only defines a method (no class) produces no edge.
    symbols = [
        _sym("App\\Alpha::run", "method", "src/Alpha.php"),
        _sym("App\\Beta", "class", "src/Beta.php"),
    ]
    relations = [_rel("src/Alpha.php", "App\\Beta", "use")]
    result = resolve_edges({"symbols": symbols, "relations": relations})
    assert result["edges"] == []
    assert result["stats"]["skipped_unresolved"] == 1


def test_non_node_kind_target_is_unresolved() -> None:
    # A target whose only definition is a non-node kind (e.g. 'function') never
    # becomes a defining symbol -> unresolved.
    symbols = [
        _sym("App\\Alpha", "class", "src/Alpha.php"),
        _sym("helper", "function", "src/helpers.php"),
    ]
    relations = [_rel("src/Alpha.php", "helper", "use")]
    result = resolve_edges({"symbols": symbols, "relations": relations})
    assert result["edges"] == []
    assert result["stats"]["skipped_unresolved"] == 1


def test_leading_backslash_is_normalized() -> None:
    # FQNs are ltrim'd of a leading backslash on BOTH symbol defs and relations.
    symbols = [
        _sym("\\App\\Alpha", "class", "src/Alpha.php"),
        _sym("\\App\\Beta", "class", "src/Beta.php"),
    ]
    relations = [_rel("src/Alpha.php", "\\App\\Beta", "use")]
    result = resolve_edges({"symbols": symbols, "relations": relations})
    edge = result["edges"][0]
    assert edge["from_node_id"] == "sym:App\\Alpha"
    assert edge["to_node_id"] == "sym:App\\Beta"


def test_long_fqn_node_id_is_capped_with_hash() -> None:
    import hashlib

    long_ns = "App\\" + "\\".join(f"Very{i}Long{i}Segment{i}Name" for i in range(20))
    target = long_ns + "\\Target"
    source = "App\\Short"
    symbols = [
        _sym(source, "class", "src/Short.php"),
        _sym(target, "class", "src/Target.php"),
    ]
    relations = [_rel("src/Short.php", target, "use")]
    result = resolve_edges({"symbols": symbols, "relations": relations})
    edge = result["edges"][0]
    # Source is short -> plain id; target is long -> capped tail + 12-hex sha1.
    assert edge["from_node_id"] == "sym:App\\Short"
    expected_hash = hashlib.sha1(target.encode("utf-8")).hexdigest()[:12]
    expected_to = "sym:" + target[-130:] + "#" + expected_hash
    assert edge["to_node_id"] == expected_to
    assert len(edge["to_node_id"]) <= 160


def test_edges_sorted_by_from_to_type() -> None:
    symbols = [
        _sym("App\\Z", "class", "src/Z.php"),
        _sym("App\\A", "class", "src/A.php"),
        _sym("App\\Beta", "class", "src/Beta.php"),
    ]
    relations = [
        _rel("src/Z.php", "App\\Beta", "use"),
        _rel("src/A.php", "App\\Beta", "use"),
    ]
    result = resolve_edges({"symbols": symbols, "relations": relations})
    froms = [e["from_node_id"] for e in result["edges"]]
    assert froms == sorted(froms)
    assert froms == ["sym:App\\A", "sym:App\\Z"]


def test_dedup_keeps_strongest_evidence_regardless_of_order() -> None:
    # INFERRED reference seen FIRST, then EXTRACTED dependency: stored edge upgrades.
    symbols = [
        _sym("App\\Alpha", "class", "src/Alpha.php"),
        _sym("App\\Beta", "class", "src/Beta.php"),
    ]
    relations = [
        _rel("src/Alpha.php", "App\\Beta", "symbol_references"),  # would be INFERRED
        _rel("src/Alpha.php", "App\\Beta", "use"),               # dependency EXTRACTED
    ]
    result = resolve_edges({"symbols": symbols, "relations": relations})
    assert len(result["edges"]) == 1
    edge = result["edges"][0]
    # Note: the reference is ALSO promoted (file imports Beta), so both are EXTRACTED;
    # the point is the single deduped edge is EXTRACTED with occurrences 2.
    assert edge["confidence"] == CONFIDENCE_EXTRACTED
    assert edge["confidence_score"] == 1.0
    assert edge["metadata"]["occurrences"] == 2


def test_deterministic_across_runs() -> None:
    symbols = [
        _sym("App\\Alpha", "class", "src/Alpha.php"),
        _sym("App\\Beta", "class", "src/Beta.php"),
        _sym("App\\Gamma", "class", "src/Gamma.php"),
    ]
    relations = [
        _rel("src/Alpha.php", "App\\Beta", "use"),
        _rel("src/Gamma.php", "App\\Beta", "symbol_references"),
        _rel("src/Beta.php", "App\\Gamma", "use"),
    ]
    payload = {"symbols": symbols, "relations": relations}
    assert resolve_edges(payload) == resolve_edges(payload)


def test_garbage_input_is_safe_no_raise() -> None:
    # Top-level non-dict.
    assert resolve_edges(None)["edges"] == []
    assert resolve_edges("garbage")["edges"] == []
    assert resolve_edges(42)["symbol_node_ids"] == []
    # Malformed members are skipped; the one valid pair still resolves.
    symbols = [
        None,
        "not-a-dict",
        {"name": "", "type": "class", "file_path": "src/Empty.php"},      # empty name
        {"name": "App\\Beta", "type": "", "file_path": "src/Beta.php"},   # empty type -> not a node kind
        _sym("App\\Alpha", "class", "src/Alpha.php"),
        _sym("App\\Beta", "class", "src/Beta.php"),
    ]
    relations = [
        None,
        123,
        {"file_path": "", "symbol": "App\\Beta", "kind": "use"},          # empty file
        {"file_path": "src/Alpha.php", "symbol": "", "kind": "use"},      # empty symbol
        _rel("src/Alpha.php", "App\\Beta", "use"),                        # the valid one
    ]
    result = resolve_edges({"symbols": symbols, "relations": relations})
    assert _edge_keys(result) == {("sym:App\\Alpha", "sym:App\\Beta", "depends_on")}


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
