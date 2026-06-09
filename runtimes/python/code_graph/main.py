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

# AP-815 [py] ops (Wave F): eval harness (Q-2), co-change (P-8), hybrid ranker (E-6).
# Each module is stdlib-only at import time (heavy/optional deps are lazy inside).
from atlas_code_graph.co_change import co_change
from atlas_code_graph.eval_harness import evaluate, evaluate_by_type
from atlas_code_graph.hybrid_ranker import rank as hybrid_rank

# AP-815 [py] ops (Wave G): cross-workspace traverse (X-1), blast-radius (X-2), supply-chain (X-5).
from atlas_code_graph.blast_radius import blast_radius
from atlas_code_graph.cross_workspace_traverse import traverse as cross_workspace_traverse
from atlas_code_graph.supply_chain import supply_chain

# AP-815 [py] ops (Wave H): cross-workspace patterns (X-3), portfolio centrality (X-6), k-hop neighborhood (D-3).
from atlas_code_graph.cross_workspace_patterns import recurring_patterns
from atlas_code_graph.neighborhood import neighborhood
from atlas_code_graph.portfolio_centrality import portfolio_centrality

# AP-815 [py] ops (Wave I): cross-language contract (P-10), cross-domain union (X-4).
from atlas_code_graph.cross_domain_union import union_graph
from atlas_code_graph.cross_language_contract import contract_graph

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
    # AP-815 Wave F [py] ops (Q-2 / P-8 / E-6).
    "eval_precision_recall": lambda m: evaluate(m.get("predicted", []), m.get("gold", [])),
    "eval_by_type": lambda m: evaluate_by_type(m.get("predicted", []), m.get("gold", [])),
    "co_change": lambda m: co_change(
        m.get("commits", []),
        min_support=int(m.get("min_support", 2)),
        max_pairs=int(m.get("max_pairs", 100000)),
    ),
    "hybrid_rank": lambda m: hybrid_rank(m.get("query", ""), m.get("candidates", []), weights=m.get("weights")),
    # AP-815 Wave G [py] ops (X-1 / X-2 / X-5).
    "cross_workspace_traverse": lambda m: cross_workspace_traverse(
        m.get("seeds", []), m.get("edges", []),
        max_depth=int(m.get("max_depth", 4)), max_nodes=int(m.get("max_nodes", 200)),
    ),
    "blast_radius": lambda m: blast_radius(
        m.get("changed", []), m.get("edges", []),
        max_depth=int(m.get("max_depth", 3)), max_nodes=int(m.get("max_nodes", 500)),
    ),
    "supply_chain": lambda m: supply_chain(m.get("manifests", [])),
    # AP-815 Wave H [py] ops (X-3 / X-6 / D-3).
    "cross_workspace_patterns": lambda m: recurring_patterns(m.get("graphs", []), min_workspaces=int(m.get("min_workspaces", 2))),
    "portfolio_centrality": lambda m: portfolio_centrality(m.get("graphs", []), top=int(m.get("top", 20))),
    "neighborhood": lambda m: neighborhood(
        m.get("node", ""), m.get("edges", []),
        k=int(m.get("k", 2)), max_nodes=int(m.get("max_nodes", 300)),
    ),
    # AP-815 Wave I [py] ops (P-10 / X-4).
    "cross_language_contract": lambda m: contract_graph(m.get("specs", [])),
    "cross_domain_union": lambda m: union_graph(m.get("workspace_graphs", []), m.get("domain_edges", [])),
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
