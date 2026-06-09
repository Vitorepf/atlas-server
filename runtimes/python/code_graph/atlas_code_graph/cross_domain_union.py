"""Unified code+domain graph: cross-workspace UNION cross-domain mesh (AP-815).

This is the SEAM that lets ONE query span both worlds the operator reasons over:
the concrete cross-workspace CODE graph (files / symbols / modules across every
repo) AND the abstract M-8 cross-domain MESH (engineering -> finance -> legal ...
governance edges between the 21 canonical domains). Kept apart they answer
different questions; folded into a single tagged graph they answer the question
neither can alone — "what code reaches which domains, and which domains gate
which code" — without the caller juggling two shapes. Per
atlas-ai-runtime-language-boundaries.md this cross-graph aggregation belongs in
the python_ai_data runtime (the muscle): the Laravel Kernel hands us each
workspace's edge list plus the precomputed domain mesh and we union them.

Pure stdlib (dict/set aggregation), deterministic (every list sorted), fail-safe
(never raises on malformed input — garbage workspaces / edges are skipped and a
safe default is returned). NOT promoted to production until human review
(runtime_promotion_policy.v1).

Input ``workspace_graphs``: a list of per-workspace code graphs, each
``{"workspace": str, "edges": [{"from": str, "to": str, "type": str}]}``.
Input ``domain_edges``: the M-8 cross-domain mesh, a list of
``{"from_domain": str, "to_domain": str, "type": str}``.

Every code edge is carried through verbatim (node ids kept as-is) tagged
``"source": "code"``; every domain edge becomes a ``domain:<from>`` ->
``domain:<to>`` edge tagged ``"source": "domain"``. The ``domain:`` prefix is
what keeps the two id spaces from colliding so a single traversal can hop from a
file node onto a domain node and back.

Output:
  ``nodes``: distinct ``{"id", "source"}`` across both halves (a code node and a
    domain node never collide thanks to the ``domain:`` prefix), sorted by
    (id, source).
  ``edges``: every accepted edge ``{"from", "to", "type", "source"}``, sorted by
    (from, to, type, source) and deduped.
  ``workspaces``: sorted distinct workspace names that contributed >=1 code edge.
  ``domains``: sorted distinct domain names seen in the mesh.
  ``counts``: ``{"nodes", "edges", "code_edges", "domain_edges"}``.
"""

from __future__ import annotations

from typing import Any, Dict, List

SCHEMA = "atlas.code_graph.cross_domain_union.v1"


def _clean_str(value: Any) -> str:
    """Coerce a value to a stripped string; non-strings / blanks -> ''."""
    if not isinstance(value, str):
        return ""
    return value.strip()


def _clean_type(value: Any) -> str:
    """Coerce an edge type to a stripped string, defaulting to ``"unknown"``.

    Edge ``type`` is descriptive metadata that messy inputs frequently omit; a
    missing / non-string type falls back to ``"unknown"`` so the edge is kept
    (it is still a real structural link) rather than silently dropped.
    """
    cleaned = _clean_str(value)
    return cleaned if cleaned else "unknown"


def union_graph(workspace_graphs: Any, domain_edges: Any) -> Dict[str, Any]:
    """Merge cross-workspace code graphs and the cross-domain mesh into one graph.

    Each well-formed code edge is carried through with its node ids unchanged and
    tagged ``source="code"``; each well-formed domain edge becomes a
    ``domain:<from_domain> -> domain:<to_domain>`` edge tagged ``source="domain"``.
    Nodes are collected from the accepted edges (each tagged with the half it
    came from), everything is deduped, and all output lists are sorted so the
    result is byte-identical across runs.

    Args:
        workspace_graphs: list of per-workspace code graphs, each
            ``{"workspace", "edges": [{"from", "to", "type"}]}``.
        domain_edges: list of cross-domain mesh edges, each
            ``{"from_domain", "to_domain", "type"}``.

    Returns:
        ``{"schema_version", "nodes", "edges", "workspaces", "domains",
        "counts"}``. Always a well-formed dict; never raises on malformed input.
    """
    # id -> source ("code" / "domain"). A domain id is prefixed so it can never
    # collide with a code id; first writer wins (domain ids only ever come from
    # the domain half, code ids only from the code half).
    node_source: Dict[str, str] = {}
    # Dedupe whole edges on (from, to, type, source) so repeated links collapse.
    seen_edges: set = set()
    edges: List[Dict[str, Any]] = []
    workspaces: set = set()
    domains: set = set()
    code_edges = 0
    domain_edges_count = 0

    def _add_node(node_id: str, source: str) -> None:
        if node_id not in node_source:
            node_source[node_id] = source

    def _add_edge(frm: str, to: str, etype: str, source: str) -> bool:
        edge_key = (frm, to, etype, source)
        if edge_key in seen_edges:
            return False
        seen_edges.add(edge_key)
        edges.append(
            {"from": frm, "to": to, "type": etype, "source": source}
        )
        return True

    # --- code half: cross-workspace graphs ---------------------------------
    if isinstance(workspace_graphs, (list, tuple)):
        for graph in workspace_graphs:
            if not isinstance(graph, dict):
                continue
            workspace = _clean_str(graph.get("workspace"))
            ws_edges = graph.get("edges")
            if not isinstance(ws_edges, (list, tuple)):
                continue

            counted_workspace = False
            for edge in ws_edges:
                if not isinstance(edge, dict):
                    continue
                frm = _clean_str(edge.get("from"))
                to = _clean_str(edge.get("to"))
                # An edge needs both endpoints to be a real link; skip otherwise.
                if not frm or not to:
                    continue
                etype = _clean_type(edge.get("type"))

                # Only count a workspace once it has contributed >=1 real edge so
                # empty / garbage-edge graphs don't inflate the workspace list.
                if workspace and not counted_workspace:
                    workspaces.add(workspace)
                    counted_workspace = True

                _add_node(frm, "code")
                _add_node(to, "code")
                if _add_edge(frm, to, etype, "code"):
                    code_edges += 1

    # --- domain half: M-8 cross-domain mesh --------------------------------
    if isinstance(domain_edges, (list, tuple)):
        for edge in domain_edges:
            if not isinstance(edge, dict):
                continue
            from_domain = _clean_str(edge.get("from_domain"))
            to_domain = _clean_str(edge.get("to_domain"))
            if not from_domain or not to_domain:
                continue
            etype = _clean_type(edge.get("type"))

            domains.add(from_domain)
            domains.add(to_domain)

            frm = "domain:" + from_domain
            to = "domain:" + to_domain
            _add_node(frm, "domain")
            _add_node(to, "domain")
            if _add_edge(frm, to, etype, "domain"):
                domain_edges_count += 1

    nodes: List[Dict[str, Any]] = [
        {"id": node_id, "source": source}
        for node_id, source in node_source.items()
    ]
    # Deterministic order across both halves.
    nodes.sort(key=lambda n: (n["id"], n["source"]))
    edges.sort(key=lambda e: (e["from"], e["to"], e["type"], e["source"]))

    return {
        "schema_version": SCHEMA,
        "nodes": nodes,
        "edges": edges,
        "workspaces": sorted(workspaces),
        "domains": sorted(domains),
        "counts": {
            "nodes": len(nodes),
            "edges": len(edges),
            "code_edges": code_edges,
            "domain_edges": domain_edges_count,
        },
    }
