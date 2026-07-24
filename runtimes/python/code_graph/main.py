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
  callgraph     {files:[{path,language,content}]}    tree-sitter (caller,callee) call pairs (venv)
  typed_callgraph {files:[{path,language,content}]}  PHP type-aware call edges + imports (venv)
  ingest_mcp    {config}                             MCP server config -> graph
  ingest_scip   {scip}                               SCIP index -> graph
  ingest_md     {text, path}                          markdown -> doc nodes
  pdf           {path}                                PDF -> document nodes (venv)
"""

from __future__ import annotations

import sys
from pathlib import Path

_RUNTIME_ROOT = Path(__file__).resolve().parents[1]
if str(_RUNTIME_ROOT) not in sys.path:
    sys.path.insert(0, str(_RUNTIME_ROOT))

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
from atlas_code_graph.callgraph import extract_calls
from atlas_code_graph.typed_callgraph import extract_typed_calls

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

# AP-815 [py] ops (Wave J — operator-approved deps): semantic edges (P-4), entity resolution
# (X-7), Leiden communities (P-13), multimodal ingest (P-12). Heavy deps lazy-imported inside.
from atlas_code_graph.entity_resolution import resolve_entities
from atlas_code_graph.leiden_communities import leiden_communities
from atlas_code_graph.multimodal_ingest import ingest as ingest_multimodal
from atlas_code_graph.semantic_edges import semantic_edges

# AP-815 [py] ops (Wave K): data-flow def-use (P-3), SCIP references (P-5b).
from atlas_code_graph.data_flow import def_use_edges
from atlas_code_graph.scip_references import scip_reference_edges

# AP-815 [py] op (C5): flag-gated symbol->symbol edge resolver — a faithful mirror
# of the PHP CodeGraphSymbolResolver (PHP stays the default; this is opt-in to MEASURE).
from atlas_code_graph.edge_resolver import resolve_edges
from atlas_runtime_contract import run_json_manifest_entrypoint

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
    "callgraph": lambda m: extract_calls(m.get("files", [])),
    "typed_callgraph": lambda m: extract_typed_calls(m.get("files", [])),
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
    # AP-815 Wave J [py] ops (P-4 / X-7 / P-13 / P-12).
    "semantic_edges": lambda m: semantic_edges(m.get("nodes", []), threshold=float(m.get("threshold", 0.78)), max_edges=int(m.get("max_edges", 5000))),
    "entity_resolution": lambda m: resolve_entities(m.get("entities", []), threshold=float(m.get("threshold", 0.85))),
    "leiden_communities": lambda m: leiden_communities(m.get("edges", []), resolution=float(m.get("resolution", 1.0)), seed=int(m.get("seed", 42))),
    "multimodal_ingest": lambda m: ingest_multimodal(m.get("path", "")),
    # AP-815 Wave K [py] ops (P-3 / P-5b).
    "data_flow": lambda m: def_use_edges(m.get("events", [])),
    "scip_references": lambda m: scip_reference_edges(m.get("scip", {})),
    # AP-815 C5 [py] op: flag-gated symbol-edge resolution (PHP mirror).
    "resolve_edges": lambda m: resolve_edges({"symbols": m.get("symbols", []), "relations": m.get("relations", [])}),
}


def _run_manifest(manifest: dict) -> dict:
    op = manifest.get("op", "betweenness")
    handler = _OPS.get(op)
    if handler is None:
        raise ValueError(f"unknown_op:{op}")

    return handler(manifest)


def main(argv: list[str]) -> int:
    return run_json_manifest_entrypoint(
        argv,
        _run_manifest,
        include_exception_type=False,
    )


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
