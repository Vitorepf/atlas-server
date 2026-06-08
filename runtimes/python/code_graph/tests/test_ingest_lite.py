"""Tests for the stdlib-only lite ingesters (AP-811/AP-812 P-11).

Runnable with pytest OR directly: `python3 tests/test_ingest_lite.py`
(pytest may be absent in the local runtime, so it self-runs).

Covers, per the P-11 contract:
  - ingest_mcp_config: server/command/package/env_var nodes + requires_env edges;
    and the hard security invariant that env VALUES never appear anywhere.
  - ingest_scip_json: symbol nodes + scip_ref/scip_def/scip_impl edges emitted
    ONLY for boolean-true flags; truthy strings/ints are rejected.
  - ingest_markdown: heading nodes + the contains hierarchy.
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from atlas_code_graph.ingest_lite import (  # noqa: E402
    ingest_markdown,
    ingest_mcp_config,
    ingest_scip_json,
)


def _node_ids(result) -> set:
    return {n["node_id"] for n in result["nodes"]}


def _node_types(result) -> set:
    return {n["node_type"] for n in result["nodes"]}


def _edge_triples(result) -> set:
    return {
        (e["from_node_id"], e["to_node_id"], e["edge_type"]) for e in result["edges"]
    }


def _assert_edge_shape(result) -> None:
    for edge in result["edges"]:
        for key in (
            "from_node_id",
            "to_node_id",
            "edge_type",
            "confidence",
            "confidence_score",
            "metadata",
        ):
            assert key in edge, f"edge missing key {key}: {edge}"
        assert edge["confidence"] in ("EXTRACTED", "INFERRED", "AMBIGUOUS")
        assert isinstance(edge["confidence_score"], float)
        assert isinstance(edge["metadata"], dict)


# --------------------------------------------------------------------------- #
# MCP config
# --------------------------------------------------------------------------- #
def test_mcp_config_emits_server_command_package_env_nodes() -> None:
    obj = {
        "mcpServers": {
            "atlas-open-brain": {
                "command": "npx",
                "args": ["-y", "@atlas/open-brain-mcp"],
                "env": {"ATLAS_TOKEN": "super-secret-value", "ATLAS_HOST": "localhost"},
            }
        }
    }
    result = ingest_mcp_config(obj)

    types = _node_types(result)
    assert "mcp_server" in types
    assert "mcp_command" in types
    assert "mcp_package" in types
    assert "env_var" in types

    ids = _node_ids(result)
    assert "mcp:server:atlas-open-brain" in ids
    assert "mcp:command:npx" in ids
    assert "mcp:package:@atlas/open-brain-mcp" in ids
    assert "mcp:env:ATLAS_TOKEN" in ids
    assert "mcp:env:ATLAS_HOST" in ids

    triples = _edge_triples(result)
    server = "mcp:server:atlas-open-brain"
    assert (server, "mcp:command:npx", "runs") in triples
    assert (server, "mcp:package:@atlas/open-brain-mcp", "launches") in triples
    assert (server, "mcp:env:ATLAS_TOKEN", "requires_env") in triples
    assert (server, "mcp:env:ATLAS_HOST", "requires_env") in triples
    _assert_edge_shape(result)


def test_mcp_config_never_leaks_env_values() -> None:
    """The single most important invariant: env VALUES must never appear in any
    node, edge, label or metadata — only the NAMES."""
    secret = "sk-THIS-MUST-NEVER-APPEAR-9f3b2a"
    obj = {
        "mcpServers": {
            "svc": {
                "command": "python3",
                "args": ["server.py"],  # a path/script, not a package
                "env": {"SECRET_KEY": secret, "OTHER": "another-secret-val"},
            }
        }
    }
    result = ingest_mcp_config(obj)

    # The whole serialized graph must not contain any secret value substring.
    blob = json.dumps(result)
    assert secret not in blob
    assert "another-secret-val" not in blob

    # But the NAMES must be present (node + edge metadata var_name).
    assert "mcp:env:SECRET_KEY" in _node_ids(result)
    assert "mcp:env:OTHER" in _node_ids(result)

    # A script-path arg is NOT a package, so no package node is invented.
    assert "mcp_package" not in _node_types(result)

    # Sanity: the value never sneaks into a node label either.
    for node in result["nodes"]:
        assert secret not in json.dumps(node)


def test_mcp_config_handles_minimal_and_bad_shapes() -> None:
    # Server with only a command — no args/env.
    minimal = ingest_mcp_config({"mcpServers": {"s": {"command": "node"}}})
    assert "mcp:server:s" in _node_ids(minimal)
    assert "mcp:command:node" in _node_ids(minimal)
    assert minimal["edges"]  # at least the runs edge

    # Bad shapes degrade to empty, never raise.
    assert ingest_mcp_config(None)["nodes"] == []
    assert ingest_mcp_config({"mcpServers": "nope"})["nodes"] == []
    assert ingest_mcp_config({})["nodes"] == []
    assert ingest_mcp_config({"mcpServers": {"x": "not-a-dict"}})["nodes"] == []


# --------------------------------------------------------------------------- #
# SCIP JSON
# --------------------------------------------------------------------------- #
def test_scip_json_emits_symbol_nodes_and_relationship_edges() -> None:
    obj = {
        "documents": [
            {
                "relative_path": "src/foo.py",
                "symbols": [
                    {
                        "symbol": "scip-python . . Foo#",
                        "is_definition": True,
                        "relationships": [
                            {"symbol": "scip-python . . Bar#", "is_reference": True},
                            {"symbol": "scip-python . . Base#", "is_implementation": True},
                        ],
                    },
                    {
                        "symbol": "scip-python . . Bar#",
                        "relationships": [
                            {"symbol": "scip-python . . Foo#", "is_definition": True},
                        ],
                    },
                ],
            }
        ]
    }
    result = ingest_scip_json(obj)

    ids = _node_ids(result)
    assert "scip:symbol:scip-python . . Foo#" in ids
    assert "scip:symbol:scip-python . . Bar#" in ids
    assert "scip:symbol:scip-python . . Base#" in ids
    assert "scip:doc:src/foo.py" in ids

    triples = _edge_triples(result)
    foo = "scip:symbol:scip-python . . Foo#"
    bar = "scip:symbol:scip-python . . Bar#"
    base = "scip:symbol:scip-python . . Base#"
    assert (foo, bar, "scip_ref") in triples
    assert (foo, base, "scip_impl") in triples
    assert (bar, foo, "scip_def") in triples
    # Document defines its declared symbols.
    assert ("scip:doc:src/foo.py", foo, "defines") in triples
    assert ("scip:doc:src/foo.py", bar, "defines") in triples
    _assert_edge_shape(result)


def test_scip_json_rejects_truthy_non_boolean_flags() -> None:
    """ONLY a real boolean True emits a relationship edge. Truthy strings, ints,
    and "false"-ish strings must all be rejected — no edge fabricated."""
    obj = {
        "documents": [
            {
                "relative_path": "src/bad.py",
                "symbols": [
                    {
                        "symbol": "scip A#",
                        "relationships": [
                            {"symbol": "scip B#", "is_reference": "true"},   # string
                            {"symbol": "scip C#", "is_definition": 1},        # int
                            {"symbol": "scip D#", "is_implementation": "yes"},  # string
                            {"symbol": "scip E#", "is_reference": "false"},   # falsey-ish string
                            {"symbol": "scip F#", "is_reference": None},      # null
                        ],
                    }
                ],
            }
        ]
    }
    result = ingest_scip_json(obj)

    rel_edge_types = {"scip_ref", "scip_def", "scip_impl"}
    rel_edges = [e for e in result["edges"] if e["edge_type"] in rel_edge_types]
    assert rel_edges == [], f"truthy-string flags must NOT create edges: {rel_edges}"

    # The related symbol nodes are still registered (we saw them referenced),
    # but no relationship edge connects them.
    ids = _node_ids(result)
    assert "scip:symbol:scip A#" in ids
    assert "scip:symbol:scip B#" in ids


def test_scip_json_emits_when_flag_is_exactly_true_not_when_false() -> None:
    obj = {
        "documents": [
            {
                "relative_path": "p.py",
                "symbols": [
                    {
                        "symbol": "scip X#",
                        "relationships": [
                            {"symbol": "scip Y#", "is_reference": True},   # -> edge
                            {"symbol": "scip Z#", "is_reference": False},  # -> no edge
                        ],
                    }
                ],
            }
        ]
    }
    result = ingest_scip_json(obj)
    triples = _edge_triples(result)
    assert ("scip:symbol:scip X#", "scip:symbol:scip Y#", "scip_ref") in triples
    assert ("scip:symbol:scip X#", "scip:symbol:scip Z#", "scip_ref") not in triples


def test_scip_json_handles_bad_shapes() -> None:
    assert ingest_scip_json(None)["nodes"] == []
    assert ingest_scip_json({"documents": "nope"})["nodes"] == []
    assert ingest_scip_json({"documents": [None, 7, "x"]})["nodes"] == []
    assert ingest_scip_json({"documents": [{"symbols": "bad"}]})["nodes"] == []


# --------------------------------------------------------------------------- #
# Markdown
# --------------------------------------------------------------------------- #
def test_markdown_emits_doc_and_heading_hierarchy() -> None:
    text = (
        "# Title\n"
        "intro text\n"
        "## Section A\n"
        "body\n"
        "### Sub A1\n"
        "## Section B\n"
    )
    result = ingest_markdown(text, "docs/readme.md")

    ids = _node_ids(result)
    doc = "doc:docs/readme.md"
    title = "doc:heading:docs/readme.md#title"
    sec_a = "doc:heading:docs/readme.md#section-a"
    sub_a1 = "doc:heading:docs/readme.md#sub-a1"
    sec_b = "doc:heading:docs/readme.md#section-b"
    assert doc in ids
    assert {title, sec_a, sub_a1, sec_b} <= ids

    triples = _edge_triples(result)
    # Hierarchy: doc -> title -> {section a, section b}; section a -> sub a1.
    assert (doc, title, "contains") in triples
    assert (title, sec_a, "contains") in triples
    assert (title, sec_b, "contains") in triples
    assert (sec_a, sub_a1, "contains") in triples
    _assert_edge_shape(result)


def test_markdown_ignores_headings_inside_code_fences() -> None:
    text = (
        "# Real Heading\n"
        "```bash\n"
        "# this is a shell comment, not a heading\n"
        "echo hi\n"
        "```\n"
        "## After Code\n"
    )
    result = ingest_markdown(text, "f.md")
    headings = [n for n in result["nodes"] if n["node_type"] == "doc_heading"]
    labels = {h["label"] for h in headings}
    assert labels == {"Real Heading", "After Code"}
    assert "this is a shell comment, not a heading" not in labels


def test_markdown_duplicate_heading_text_gets_unique_ids() -> None:
    text = "# Notes\n## Notes\n## Notes\n"
    result = ingest_markdown(text, "d.md")
    heading_ids = sorted(
        n["node_id"] for n in result["nodes"] if n["node_type"] == "doc_heading"
    )
    # 3 headings, all distinct ids despite identical text.
    assert len(heading_ids) == 3
    assert len(set(heading_ids)) == 3
    assert "doc:heading:d.md#notes" in heading_ids
    assert "doc:heading:d.md#notes-1" in heading_ids
    assert "doc:heading:d.md#notes-2" in heading_ids


def test_markdown_empty_and_bad_inputs() -> None:
    # Empty text -> just the doc node, no headings.
    empty = ingest_markdown("", "x.md")
    assert _node_ids(empty) == {"doc:x.md"}
    assert empty["edges"] == []

    # Unusable path -> empty result, no raise.
    assert ingest_markdown("# H\n", None)["nodes"] == []
    assert ingest_markdown("# H\n", "  ")["nodes"] == []

    # A line that is just '#' with no text is not a heading.
    only_hash = ingest_markdown("#\n#nope\n", "y.md")
    assert [n for n in only_hash["nodes"] if n["node_type"] == "doc_heading"] == []


# --------------------------------------------------------------------------- #
# Cross-cutting
# --------------------------------------------------------------------------- #
def test_all_ingesters_are_deterministic() -> None:
    mcp = {"mcpServers": {"b": {"command": "x", "env": {"Z": "1", "A": "2"}},
                          "a": {"command": "y"}}}
    assert ingest_mcp_config(mcp) == ingest_mcp_config(mcp)

    scip = {"documents": [{"relative_path": "p", "symbols": [
        {"symbol": "S", "relationships": [{"symbol": "T", "is_reference": True}]}]}]}
    assert ingest_scip_json(scip) == ingest_scip_json(scip)

    md = "# A\n## B\n### C\n"
    assert ingest_markdown(md, "p.md") == ingest_markdown(md, "p.md")


def test_all_results_carry_schema_version() -> None:
    assert ingest_mcp_config({})["schema_version"] == "atlas.code_graph.ingest_lite.v1"
    assert ingest_scip_json({})["schema_version"] == "atlas.code_graph.ingest_lite.v1"
    assert ingest_markdown("", "p.md")["schema_version"] == "atlas.code_graph.ingest_lite.v1"


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
    print(f"\n{'OK' if failures == 0 else 'FAILED'}: {failures} failure(s)")
    raise SystemExit(1 if failures else 0)
