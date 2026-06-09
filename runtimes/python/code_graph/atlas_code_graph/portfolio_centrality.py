"""Portfolio-level god-nodes across ALL workspaces (AP-815, python_ai_data).

Answers the operator question "across my WHOLE portfolio of repos, which nodes
are the structural hubs?" — not per-repo, but after merging every workspace's
edges into one graph. A symbol that is central in one repo may be a true
portfolio god-node only once you account for the fact that the same node id
appears (and is depended upon) across several repos at once. Single-repo
centrality cannot see that; this op merges first, then ranks.

This is the kind of cross-workspace graph aggregation that, per
atlas-ai-runtime-language-boundaries.md, belongs in the python_ai_data runtime
(the muscle): the Laravel Kernel hands us the per-workspace edge sets and we
merge + rank them. The Kernel keeps policy/provider/domain decisions — this
module decides nothing. NOT promoted to production until human review
(runtime_promotion_policy.v1).

Input ``graphs``: a list of per-workspace graphs, each a dict::

    {"workspace": str, "edges": [{"from": str, "to": str, "type"?: str}, ...]}

Edges are directed (``from -> to``). The same node id may appear in multiple
workspaces; every workspace a node participates in (as either endpoint of a
valid edge) is tracked and reported.

Output: ``{"schema_version", "nodes": int, "edges": int, "god_nodes":
[{"id", "degree", "in", "out", "workspaces": [...]}, ...]}`` where ``degree`` is
the merged in-degree + out-degree of the node across the union graph, sorted by
descending ``degree`` then ``id`` and limited to the top ``top`` entries.

Design note — degree-only ranking: this op intentionally ranks by merged degree
centrality (in + out) and does NOT compute betweenness. Brandes' betweenness
(available as ``atlas_code_graph.centrality.betweenness_centrality``) is
O(V*E) and would run over the *union* of every workspace's edges, which is the
single largest graph in the portfolio — too costly for the cheap, deterministic
god-node summary this op is meant to provide. The block spec explicitly allows
degree-only when documented; we keep it degree-only here. (As a secondary
reason, that function expects ``from_node_id``/``to_node_id`` edge keys rather
than this op's ``from``/``to`` shape, so wiring it in would also require a
field remap.) The cheap degree signal is exactly what the Kernel's own
``CodeGraphAnalytics`` god-node path uses; this op just lifts it to the
whole-portfolio union graph.

Pure stdlib (collections only), deterministic (sorted everything), fail-safe:
malformed input never raises — garbage graphs/edges are skipped and a
well-formed default is returned.
"""

from __future__ import annotations

from collections import defaultdict
from typing import Any, Dict, List

SCHEMA = "atlas.code_graph.portfolio_centrality.v1"


def _clean_workspace(value: Any) -> str:
    """Coerce a graph's ``workspace`` field into a non-empty label.

    Non-string or blank workspace labels collapse to ``"unknown"`` so a node's
    workspace list is always made of usable, deterministic strings.
    """
    if isinstance(value, str):
        value = value.strip()
        if value:
            return value
    return "unknown"


def _iter_valid_edges(edges: Any):
    """Yield ``(from, to)`` for each well-formed directed edge in ``edges``.

    An edge must be a dict with non-blank string ``from`` and ``to`` that are
    not equal (no self-loops). Everything else is skipped silently. The optional
    ``type`` field is accepted but not used by degree ranking, so it is ignored
    here. Non-list ``edges`` yields nothing.
    """
    if not isinstance(edges, (list, tuple)):
        return
    for edge in edges:
        if not isinstance(edge, dict):
            continue
        src = edge.get("from")
        dst = edge.get("to")
        if not isinstance(src, str) or not isinstance(dst, str):
            continue
        src, dst = src.strip(), dst.strip()
        if not src or not dst or src == dst:
            continue
        yield src, dst


def portfolio_centrality(graphs: Any, top: int = 20) -> Dict[str, Any]:
    """Rank portfolio-level god-nodes across the union of all workspace graphs.

    Every workspace's directed edges are merged into one graph. The same node id
    appearing in multiple workspaces is treated as a single merged node, and the
    set of workspaces it participates in is tracked. Degree centrality
    (in-degree + out-degree, counting parallel/duplicate edges once per distinct
    direction-pair) is computed per merged node, and the top ``top`` nodes are
    returned, sorted by descending degree then lexical id for stable ties.

    Args:
        graphs: list of per-workspace graphs, each
            ``{"workspace": str, "edges": [{"from", "to", "type"?}, ...]}``.
        top: maximum number of god-nodes to return (>= 1). Non-int / sub-1
            values fall back to a safe default of 20.

    Returns:
        ``{"schema_version", "nodes": int, "edges": int, "god_nodes":
        [{"id", "degree", "in", "out", "workspaces": [...]}, ...]}``. ``nodes``
        and ``edges`` describe the merged union graph (``edges`` counts distinct
        directed ``(from, to)`` pairs after merging across all workspaces).
        Always a well-formed dict; never raises on malformed input.
    """
    try:
        top = int(top)
    except (TypeError, ValueError):
        top = 20
    if top < 1:
        top = 1

    # Distinct directed pairs across the whole portfolio (so re-listing the same
    # edge in two workspaces, or twice in one, does not inflate degree). Degree
    # is the classic graph-theoretic degree of the merged simple digraph.
    out_neighbours: defaultdict = defaultdict(set)
    in_neighbours: defaultdict = defaultdict(set)
    node_workspaces: defaultdict = defaultdict(set)
    distinct_edges = set()

    if isinstance(graphs, (list, tuple)):
        for graph in graphs:
            if not isinstance(graph, dict):
                continue
            workspace = _clean_workspace(graph.get("workspace"))
            for src, dst in _iter_valid_edges(graph.get("edges")):
                out_neighbours[src].add(dst)
                in_neighbours[dst].add(src)
                node_workspaces[src].add(workspace)
                node_workspaces[dst].add(workspace)
                distinct_edges.add((src, dst))

    # The node universe is every endpoint that appeared in at least one valid
    # edge (node_workspaces is keyed by exactly those nodes).
    node_ids = sorted(node_workspaces.keys())

    god_nodes: List[Dict[str, Any]] = []
    for node_id in node_ids:
        in_deg = len(in_neighbours.get(node_id, ()))
        out_deg = len(out_neighbours.get(node_id, ()))
        god_nodes.append(
            {
                "id": node_id,
                "degree": in_deg + out_deg,
                "in": in_deg,
                "out": out_deg,
                "workspaces": sorted(node_workspaces[node_id]),
            }
        )

    # Deterministic order: highest degree first, then lexical id for ties.
    god_nodes.sort(key=lambda n: (-n["degree"], n["id"]))

    return {
        "schema_version": SCHEMA,
        "nodes": len(node_ids),
        "edges": len(distinct_edges),
        "god_nodes": god_nodes[:top],
    }
