"""Tests for code_graph SCIP reference/occurrence edges (AP-815 P-5b).

Runnable with pytest OR directly: `python3 tests/test_scip_references.py`
(networkx/pytest may be absent in the local runtime, so it self-runs).
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from atlas_code_graph.scip_references import scip_reference_edges  # noqa: E402


def _edge_keys(result: dict) -> set:
    """Set of (from, to) edge endpoints for easy assertions."""
    return {(e["from"], e["to"]) for e in result["edges"]}


def test_spec_example_def_then_reference() -> None:
    # One doc DEFINES symbol S (role 1), another doc REFERENCES S (role 0).
    scip = {
        "documents": [
            {
                "relative_path": "src/def.py",
                "occurrences": [{"symbol": "S", "symbol_roles": 1}],
            },
            {
                "relative_path": "src/use.py",
                "occurrences": [{"symbol": "S", "symbol_roles": 0}],
            },
        ]
    }
    result = scip_reference_edges(scip)

    assert result["schema_version"] == "atlas.code_graph.scip_references.v1"
    assert result["documents"] == 2
    assert result["definitions"] == 1
    assert result["references"] == 1
    assert len(result["edges"]) == 1
    edge = result["edges"][0]
    assert edge["from"] == "ref:src/use.py"
    assert edge["to"] == "sym:S"
    assert edge["symbol"] == "S"
    assert edge["edge_type"] == "scip_reference"


def test_definition_only_occurrence_yields_no_edge() -> None:
    scip = {
        "documents": [
            {
                "relative_path": "src/def.py",
                "occurrences": [{"symbol": "S", "symbol_roles": 1}],
            }
        ]
    }
    result = scip_reference_edges(scip)
    assert result["edges"] == []
    assert result["definitions"] == 1
    assert result["references"] == 0
    assert result["documents"] == 1


def test_defined_symbol_node_emitted() -> None:
    scip = {
        "documents": [
            {
                "relative_path": "src/def.py",
                "occurrences": [{"symbol": "Foo#", "symbol_roles": 1}],
            }
        ]
    }
    result = scip_reference_edges(scip)
    assert result["nodes"] == [{"id": "sym:Foo#", "path": "src/def.py"}]


def test_definition_bit_set_in_compound_mask() -> None:
    # symbol_roles with the 0x1 bit set alongside other bits is a definition.
    scip = {
        "documents": [
            {
                "relative_path": "src/def.py",
                # 0x1 (Definition) | 0x4 (some other role) == 5
                "occurrences": [{"symbol": "S", "symbol_roles": 5}],
            }
        ]
    }
    result = scip_reference_edges(scip)
    assert result["definitions"] == 1
    assert result["references"] == 0
    assert result["edges"] == []


def test_non_definition_compound_mask_is_reference() -> None:
    # A mask WITHOUT the 0x1 bit (e.g. 0x8 == Import) is a reference.
    scip = {
        "documents": [
            {
                "relative_path": "src/use.py",
                "occurrences": [{"symbol": "S", "symbol_roles": 8}],
            }
        ]
    }
    result = scip_reference_edges(scip)
    assert result["references"] == 1
    assert result["definitions"] == 0
    assert _edge_keys(result) == {("ref:src/use.py", "sym:S")}


def test_missing_symbol_roles_defaults_to_reference() -> None:
    # No symbol_roles key -> not a definition -> counts as a reference.
    scip = {
        "documents": [
            {
                "relative_path": "src/use.py",
                "occurrences": [{"symbol": "S"}],
            }
        ]
    }
    result = scip_reference_edges(scip)
    assert result["references"] == 1
    assert result["definitions"] == 0
    assert _edge_keys(result) == {("ref:src/use.py", "sym:S")}


def test_duplicate_references_deduped_to_one_edge() -> None:
    # A symbol referenced many times from one document -> a single edge,
    # but every occurrence still increments the references count.
    scip = {
        "documents": [
            {
                "relative_path": "src/use.py",
                "occurrences": [
                    {"symbol": "S", "symbol_roles": 0},
                    {"symbol": "S", "symbol_roles": 0},
                    {"symbol": "S", "symbol_roles": 0},
                ],
            }
        ]
    }
    result = scip_reference_edges(scip)
    assert len(result["edges"]) == 1
    assert result["references"] == 3


def test_same_symbol_from_two_docs_yields_two_edges() -> None:
    scip = {
        "documents": [
            {
                "relative_path": "src/a.py",
                "occurrences": [{"symbol": "S", "symbol_roles": 0}],
            },
            {
                "relative_path": "src/b.py",
                "occurrences": [{"symbol": "S", "symbol_roles": 0}],
            },
        ]
    }
    result = scip_reference_edges(scip)
    assert _edge_keys(result) == {
        ("ref:src/a.py", "sym:S"),
        ("ref:src/b.py", "sym:S"),
    }


def test_edges_sorted_by_from_then_to() -> None:
    scip = {
        "documents": [
            {
                "relative_path": "z.py",
                "occurrences": [
                    {"symbol": "B", "symbol_roles": 0},
                    {"symbol": "A", "symbol_roles": 0},
                ],
            },
            {
                "relative_path": "a.py",
                "occurrences": [{"symbol": "C", "symbol_roles": 0}],
            },
        ]
    }
    result = scip_reference_edges(scip)
    order = [(e["from"], e["to"]) for e in result["edges"]]
    assert order == [
        ("ref:a.py", "sym:C"),
        ("ref:z.py", "sym:A"),
        ("ref:z.py", "sym:B"),
    ]


def test_def_node_first_declaring_path_wins() -> None:
    # Same symbol defined in two docs -> single node, first (sorted-by-iteration)
    # declaring path wins; node list deduped by id.
    scip = {
        "documents": [
            {
                "relative_path": "src/first.py",
                "occurrences": [{"symbol": "S", "symbol_roles": 1}],
            },
            {
                "relative_path": "src/second.py",
                "occurrences": [{"symbol": "S", "symbol_roles": 1}],
            },
        ]
    }
    result = scip_reference_edges(scip)
    assert result["nodes"] == [{"id": "sym:S", "path": "src/first.py"}]
    assert result["definitions"] == 2


def test_boolean_symbol_roles_not_a_definition() -> None:
    # bool is an int subclass; True == 1 must NOT be treated as a definition
    # bitmask (a non-numeric export shouldn't fabricate definitions).
    scip = {
        "documents": [
            {
                "relative_path": "src/use.py",
                "occurrences": [{"symbol": "S", "symbol_roles": True}],
            }
        ]
    }
    result = scip_reference_edges(scip)
    assert result["definitions"] == 0
    assert result["references"] == 1


def test_string_symbol_roles_treated_as_reference() -> None:
    # "1" is not an int bitmask -> not a definition -> reference.
    scip = {
        "documents": [
            {
                "relative_path": "src/use.py",
                "occurrences": [{"symbol": "S", "symbol_roles": "1"}],
            }
        ]
    }
    result = scip_reference_edges(scip)
    assert result["definitions"] == 0
    assert result["references"] == 1


def test_document_without_path_skipped() -> None:
    scip = {
        "documents": [
            {
                # no relative_path
                "occurrences": [{"symbol": "S", "symbol_roles": 0}],
            },
            {
                "relative_path": "   ",  # blank -> unusable
                "occurrences": [{"symbol": "S", "symbol_roles": 0}],
            },
        ]
    }
    result = scip_reference_edges(scip)
    assert result["documents"] == 0
    assert result["edges"] == []
    assert result["references"] == 0


def test_document_with_path_but_no_occurrences_counts() -> None:
    scip = {
        "documents": [
            {"relative_path": "src/empty.py"},
            {"relative_path": "src/bad.py", "occurrences": "not-a-list"},
        ]
    }
    result = scip_reference_edges(scip)
    assert result["documents"] == 2
    assert result["edges"] == []
    assert result["references"] == 0
    assert result["definitions"] == 0


def test_garbage_occurrences_skipped_no_raise() -> None:
    scip = {
        "documents": [
            {
                "relative_path": "src/use.py",
                "occurrences": [
                    None,
                    "not-a-dict",
                    123,
                    {"symbol": None, "symbol_roles": 0},  # bad symbol
                    {"symbol": "", "symbol_roles": 0},     # empty symbol
                    {"symbol": "  ", "symbol_roles": 0},   # whitespace symbol
                    {"symbol": "S", "symbol_roles": 0},    # the only survivor
                ],
            }
        ]
    }
    result = scip_reference_edges(scip)
    assert result["references"] == 1
    assert _edge_keys(result) == {("ref:src/use.py", "sym:S")}


def test_symbol_trimmed_before_namespacing() -> None:
    scip = {
        "documents": [
            {
                "relative_path": "src/use.py",
                "occurrences": [{"symbol": "  S  ", "symbol_roles": 0}],
            }
        ]
    }
    result = scip_reference_edges(scip)
    assert _edge_keys(result) == {("ref:src/use.py", "sym:S")}


def test_empty_documents_list_is_safe() -> None:
    result = scip_reference_edges({"documents": []})
    assert result["edges"] == []
    assert result["nodes"] == []
    assert result["documents"] == 0
    assert result["definitions"] == 0
    assert result["references"] == 0


def test_missing_documents_key_is_safe() -> None:
    result = scip_reference_edges({})
    assert result["edges"] == []
    assert result["documents"] == 0


def test_non_dict_input_is_safe() -> None:
    for bad in (None, "garbage", 42, [], ["x"]):
        result = scip_reference_edges(bad)
        assert result["schema_version"] == "atlas.code_graph.scip_references.v1"
        assert result["edges"] == []
        assert result["nodes"] == []
        assert result["documents"] == 0
        assert result["definitions"] == 0
        assert result["references"] == 0


def test_documents_not_a_list_is_safe() -> None:
    result = scip_reference_edges({"documents": "nope"})
    assert result["edges"] == []
    assert result["documents"] == 0


def test_non_dict_documents_skipped() -> None:
    scip = {
        "documents": [
            None,
            "not-a-doc",
            123,
            {
                "relative_path": "src/use.py",
                "occurrences": [{"symbol": "S", "symbol_roles": 0}],
            },
        ]
    }
    result = scip_reference_edges(scip)
    assert result["documents"] == 1
    assert _edge_keys(result) == {("ref:src/use.py", "sym:S")}


def test_def_and_ref_in_same_document() -> None:
    # A document can both define one symbol and reference another.
    scip = {
        "documents": [
            {
                "relative_path": "src/mod.py",
                "occurrences": [
                    {"symbol": "Local", "symbol_roles": 1},   # define
                    {"symbol": "Imported", "symbol_roles": 0},  # reference
                ],
            }
        ]
    }
    result = scip_reference_edges(scip)
    assert result["definitions"] == 1
    assert result["references"] == 1
    assert _edge_keys(result) == {("ref:src/mod.py", "sym:Imported")}
    assert result["nodes"] == [{"id": "sym:Local", "path": "src/mod.py"}]


def test_deterministic_across_runs() -> None:
    scip = {
        "documents": [
            {
                "relative_path": "src/a.py",
                "occurrences": [
                    {"symbol": "X", "symbol_roles": 1},
                    {"symbol": "Y", "symbol_roles": 0},
                ],
            },
            {
                "relative_path": "src/b.py",
                "occurrences": [
                    {"symbol": "X", "symbol_roles": 0},
                    {"symbol": "Z", "symbol_roles": 0},
                ],
            },
        ]
    }
    assert scip_reference_edges(scip) == scip_reference_edges(scip)


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
