"""Entry point for the code_graph python_ai_data runtime (AP-811/AP-812).

Matches the manifest-in / json-out convention of the existing
programming_intelligence runtime. Invoked by the Laravel Kernel
(CodeGraphRuntimeInvoker) via a signed runtime.invoke payload; never decides
domain/provider/policy on its own.

Usage: python3 main.py <manifest.json>   (heavy ops need the .venv python)
Manifest: {"op": "<op>", ...op-specific keys...}

Ops:
  betweenness   {edges, normalized?, limit?}        centrality at scale
  communities   {edges, max_passes?}                Louvain communities
  surprises     {edges, assignments?}               surprising connections
  questions     {edges, god_nodes?, assignments?}   suggested review questions
  multilang     {files:[{path,language,content}]}    ast+regex symbols/imports
  treesitter    {files:[{path,language,content}]}    tree-sitter precise AST (venv)
  ingest_mcp    {config}                             MCP server config -> graph
  ingest_scip   {scip}                               SCIP index -> graph
  ingest_md     {text, path}                          markdown -> doc nodes
  pdf           {path}                                PDF -> document nodes (venv)
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

from atlas_code_graph import (
    betweenness_centrality,
    detect_communities,
    suggested_questions,
    surprising_connections,
)

# Direct module imports (not via package __all__). Each module's top level is
# stdlib-only; heavy deps (tree-sitter, pypdf) are imported lazily inside the
# functions, so importing here is safe even on the system python.
from atlas_code_graph.ingest_lite import ingest_markdown, ingest_mcp_config, ingest_scip_json
from atlas_code_graph.multilang import extract_symbols_and_imports
from atlas_code_graph.pdf_ingest import ingest_pdf
from atlas_code_graph.treesitter_extract import extract as treesitter_extract

_OPS = {
    "betweenness": lambda m: betweenness_centrality(
        m.get("edges", []),
        normalized=bool(m.get("normalized", True)),
        limit=int(m.get("limit", 20)),
    ),
    "communities": lambda m: detect_communities(
        m.get("edges", []),
        max_passes=int(m.get("max_passes", 50)),
    ),
    "surprises": lambda m: surprising_connections(
        m.get("edges", []),
        assignments=m.get("assignments"),
    ),
    "questions": lambda m: suggested_questions(
        m.get("edges", []),
        god_nodes=m.get("god_nodes"),
        assignments=m.get("assignments"),
    ),
    "multilang": lambda m: extract_symbols_and_imports(m.get("files", [])),
    "treesitter": lambda m: treesitter_extract(m.get("files", [])),
    "ingest_mcp": lambda m: ingest_mcp_config(m.get("config", {})),
    "ingest_scip": lambda m: ingest_scip_json(m.get("scip", {})),
    "ingest_md": lambda m: ingest_markdown(m.get("text", ""), m.get("path", "")),
    "pdf": lambda m: ingest_pdf(m.get("path", "")),
}


def main(argv: list[str]) -> int:
    if len(argv) != 2:
        print(json.dumps({"ok": False, "error": "manifest_path_required"}))
        return 2

    try:
        manifest = json.loads(Path(argv[1]).read_text(encoding="utf-8"))
        op = manifest.get("op", "betweenness")
        handler = _OPS.get(op)
        if handler is None:
            print(json.dumps({"ok": False, "error": f"unknown_op:{op}"}))
            return 1
        result = handler(manifest)
    except Exception as exc:  # noqa: BLE001 — boundary returns structured error
        print(json.dumps({"ok": False, "error": str(exc)}))
        return 1

    print(json.dumps({"ok": True, "result": result}, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
