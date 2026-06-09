"""Bounded bidirectional k-hop neighborhood extraction (AP-815, python_ai_data).

Pulls the local context around a single node out of a large code graph: every
node reachable within ``k`` hops of a center, treating edges as *undirected*
(traversing both ``from -> to`` and ``to -> from``) so the neighborhood captures
both what the center depends on AND what depends on the center. This is the kind
of heavy bounded traversal that, per atlas-ai-runtime-language-boundaries.md,
belongs in the python_ai_data runtime (the muscle): the Laravel Kernel hands us
the (possibly very large) edge set and a center node, and we return a small,
deterministic local subgraph the Kernel can feed to a model as context. The
Kernel keeps policy/provider/domain decisions — this module decides nothing.

Pure stdlib (collections.deque + dict), deterministic (BFS in sorted neighbour
order; nodes sorted by ``(depth, id)``; induced edges sorted by
``(from, to, type)``), cycle-safe (each node enqueued at most once, so a cyclic
graph terminates), bounded (``k`` hops and ``max_nodes`` nodes) and fail-safe:
malformed input never raises — garbage edges are skipped and a well-formed
default is returned. NOT promoted to production until human review
(runtime_promotion_policy.v1).

Input ``node``: the center node id (str).
Input ``edges``: ``[{"from": str, "to": str, "type"?: str}, ...]`` — directed
edges that are traversed in BOTH directions for undirected reachability;
``type`` is optional metadata preserved on the returned induced edges.

Output: ``{"schema_version", "center", "nodes": [{"id", "depth"}], "edges":
[{"from", "to", "type"}], "truncated": bool, "node_count", "edge_count"}``.
``depth`` is the minimum hop count from the center (the center is depth 0). The
returned ``edges`` are the induced subgraph: every input edge whose endpoints
are BOTH in the returned node set (deduped, in canonical ``from``/``to`` input
direction). ``truncated`` is ``True`` when the ``max_nodes`` cap stopped the
traversal before exhausting the reachable set.
"""

from __future__ import annotations

from collections import defaultdict, deque
from typing import Any, Dict, List, Tuple

SCHEMA = "atlas.code_graph.neighborhood.v1"


def _clean_center(node: Any) -> str:
    """Coerce the center argument into a clean node id (``""`` if invalid).

    Non-string / blank centers yield ``""``, which the caller treats as "no
    valid center" and returns an empty neighborhood for.
    """
    if not isinstance(node, str):
        return ""
    return node.strip()


def _build_undirected_adjacency(
    edges: Any,
) -> Tuple[Dict[str, List[str]], List[Tuple[str, str, str]]]:
    """Build an undirected adjacency map plus the deduped clean edge list.

    Each edge ``{"from", "to", "type"?}`` is added to the adjacency of BOTH
    endpoints so reachability is undirected. Malformed edges (non-dict, missing
    or non-string endpoints, blank endpoints, self-loops) are skipped silently.

    The second return value is the deterministic list of distinct cleaned edges
    in canonical input direction — ``(from, to, type)`` tuples deduped on the
    full triple — used later to compute the induced subgraph without re-walking
    the raw (possibly huge) input. ``type`` defaults to ``""`` when absent.

    Returns ``(adjacency, clean_edges)`` where:
      * ``adjacency``: ``{node: sorted_unique_list_of_undirected_neighbours}``
      * ``clean_edges``: sorted list of distinct ``(from, to, type)`` triples.
    """
    adjacency: defaultdict = defaultdict(set)
    seen_edges: set = set()

    if not isinstance(edges, (list, tuple)):
        return {}, []

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

        edge_type = edge.get("type")
        if isinstance(edge_type, str):
            edge_type = edge_type.strip()
        else:
            edge_type = ""

        # Undirected reachability: each endpoint can reach the other.
        adjacency[src].add(dst)
        adjacency[dst].add(src)
        # Preserve the edge in its canonical (input) direction for the induced
        # subgraph; dedup on the full (from, to, type) triple.
        seen_edges.add((src, dst, edge_type))

    clean_adjacency = {node: sorted(neighbours) for node, neighbours in adjacency.items()}
    clean_edges = sorted(seen_edges)
    return clean_adjacency, clean_edges


def neighborhood(
    node: Any,
    edges: Any,
    k: int = 2,
    max_nodes: int = 300,
) -> Dict[str, Any]:
    """Extract the bounded bidirectional k-hop neighborhood of ``node``.

    Performs an undirected breadth-first search from ``node`` over ``edges``
    (each edge traversed in both directions), bounded by ``k`` hops and
    ``max_nodes`` nodes. Each reachable node is reported once at its minimum
    hop-distance (``depth``) from the center; the center itself is depth 0 and
    is always included (even when it has no edges, and even for an unknown
    center — an unknown center simply has an empty edge set, so only the center
    is returned). The returned ``edges`` are the induced subgraph: every input
    edge whose endpoints are both in the returned node set.

    Cycles are safe: a node is enqueued at most once (first discovery wins), so
    a cyclic graph terminates rather than looping forever.

    Args:
        node: the center node id (str). Blank / non-string yields an empty
            neighborhood (no nodes, no edges).
        edges: ``[{"from", "to", "type"?}, ...]`` directed edges, traversed in
            BOTH directions for undirected reachability.
        k: maximum hop distance from the center to include (>= 0). ``k=0``
            returns only the center; ``k=1`` the center plus direct neighbours.
        max_nodes: hard cap on the number of nodes returned (>= 1; the center
            always counts). When the cap stops traversal early, ``truncated`` is
            ``True`` and the lowest-depth / lexically-smallest nodes are kept.

    Returns:
        ``{"schema_version", "center", "nodes": [{"id", "depth"}], "edges":
        [{"from", "to", "type"}], "truncated": bool, "node_count",
        "edge_count"}``. Always a well-formed dict; never raises on malformed
        input.
    """
    try:
        k = int(k)
    except (TypeError, ValueError):
        k = 2
    if k < 0:
        k = 0

    try:
        max_nodes = int(max_nodes)
    except (TypeError, ValueError):
        max_nodes = 300
    # The center always occupies one slot, so the floor is 1.
    if max_nodes < 1:
        max_nodes = 1

    center = _clean_center(node)

    # No valid center -> empty, well-formed result.
    if not center:
        return {
            "schema_version": SCHEMA,
            "center": "",
            "nodes": [],
            "edges": [],
            "truncated": False,
            "node_count": 0,
            "edge_count": 0,
        }

    adjacency, clean_edges = _build_undirected_adjacency(edges)

    # depth[node] = minimum undirected hop distance from the center.
    depth: Dict[str, int] = {center: 0}
    truncated = False
    queue: deque = deque([center])

    while queue:
        current = queue.popleft()
        current_depth = depth[current]
        if current_depth >= k:
            # Cannot expand past the k-hop bound.
            continue
        next_depth = current_depth + 1
        for neighbour in adjacency.get(current, ()):  # sorted -> deterministic
            if neighbour in depth:
                continue  # already reached at a <= depth (cycle-safe)
            if len(depth) >= max_nodes:
                # Cap reached: stop adding nodes. More were reachable -> truncate.
                truncated = True
                break
            depth[neighbour] = next_depth
            queue.append(neighbour)
        if truncated:
            break

    nodes: List[Dict[str, Any]] = [
        {"id": node_id, "depth": node_depth} for node_id, node_depth in depth.items()
    ]
    # Deterministic order: nearest first, then lexical id for ties.
    nodes.sort(key=lambda item: (item["depth"], item["id"]))

    # Induced subgraph: keep only edges whose BOTH endpoints survived.
    kept = set(depth)
    induced: List[Dict[str, Any]] = [
        {"from": src, "to": dst, "type": edge_type}
        for (src, dst, edge_type) in clean_edges
        if src in kept and dst in kept
    ]
    # clean_edges is already sorted by (from, to, type); the filter preserves it.

    return {
        "schema_version": SCHEMA,
        "center": center,
        "nodes": nodes,
        "edges": induced,
        "truncated": truncated,
        "node_count": len(nodes),
        "edge_count": len(induced),
    }
