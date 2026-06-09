"""Cross-repo blast-radius via reverse reachability (AP-815, python_ai_data).

Answers the operator question "if I change X, what breaks across ALL repos?"
Static AST/import analysis tells you what X depends ON; the blast radius is the
inverse — everything that depends on X, transitively, regardless of which
workspace it lives in. This is the heavy reverse-reachability traversal that,
per atlas-ai-runtime-language-boundaries.md, belongs in the python_ai_data
runtime (the muscle): the Laravel Kernel hands us the resolved dependency edges
(possibly spanning many workspaces) and we walk them. NOT promoted to
production until human review (runtime_promotion_policy.v1).

Edge direction: ``{"from", "to", "workspace"?}`` means ``from`` DEPENDS ON
``to``. To find who is impacted by a change to a node we therefore walk
*incoming* edges (reverse adjacency): for a changed node ``N`` we visit every
``from`` whose ``to == N``, then their dependents, and so on. The traversal is
breadth-first so each impacted node is reported at its minimum dependency
distance (``depth``) from the nearest changed node.

Pure stdlib (collections.deque + dict), deterministic (sorted neighbour
expansion), bounded (``max_depth`` hops, ``max_nodes`` impacted) and fail-safe:
malformed input never raises — garbage edges are skipped and a well-formed
default is returned.

Input ``changed``: list of node ids that are being changed.
Input ``edges``: ``[{"from": str, "to": str, "workspace"?: str}, ...]``.
Output: ``{"impacted": [{"id", "depth", "workspace"}], "by_workspace":
{ws: count}, "truncated": bool, "changed": [...]}``.
"""

from __future__ import annotations

from collections import deque
from typing import Any, Dict, List

SCHEMA = "atlas.code_graph.blast_radius.v1"

_UNKNOWN_WORKSPACE = "unknown"


def _clean_ids(values: Any) -> List[str]:
    """Coerce an arbitrary value into a deterministic list of distinct ids.

    Non-list inputs yield an empty list; non-string / blank entries are dropped;
    duplicates are removed. Order is sorted so downstream traversal seeding is
    deterministic regardless of caller ordering.
    """
    if not isinstance(values, (list, tuple)):
        return []
    seen = set()
    for value in values:
        if not isinstance(value, str):
            continue
        value = value.strip()
        if not value:
            continue
        seen.add(value)
    return sorted(seen)


def _build_reverse_adjacency(edges: Any) -> tuple:
    """Build reverse adjacency (``to`` -> dependents) and a node->workspace map.

    Each edge ``{"from", "to", "workspace"?}`` means ``from`` DEPENDS ON ``to``,
    so for reverse reachability we index ``to -> [from, ...]``. The workspace on
    an edge is attributed to the *dependent* (the ``from`` node) since that is the
    node we report as impacted. Malformed edges are skipped silently.

    Returns ``(reverse_adj, node_workspace, edge_count)`` where:
      * ``reverse_adj``: ``{to: sorted_unique_list_of_from}``
      * ``node_workspace``: ``{node_id: workspace}`` (first non-blank wins,
        deterministically — earliest in sorted edge order)
      * ``edge_count``: number of valid edges consumed.
    """
    reverse_pairs: Dict[str, set] = {}
    node_workspace: Dict[str, str] = {}
    edge_count = 0

    if not isinstance(edges, (list, tuple)):
        return {}, {}, 0

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

        workspace = edge.get("workspace")
        if isinstance(workspace, str):
            workspace = workspace.strip()
        else:
            workspace = ""

        reverse_pairs.setdefault(dst, set()).add(src)
        edge_count += 1

        # Attribute the edge's workspace to the dependent (the impacted node).
        if workspace and src not in node_workspace:
            node_workspace[src] = workspace

    reverse_adj = {dst: sorted(srcs) for dst, srcs in reverse_pairs.items()}
    return reverse_adj, node_workspace, edge_count


def blast_radius(
    changed: Any,
    edges: Any,
    max_depth: int = 3,
    max_nodes: int = 500,
) -> Dict[str, Any]:
    """Compute the cross-repo blast radius of changing ``changed`` nodes.

    Walks *incoming* dependency edges (who depends on the changed nodes),
    breadth-first, up to ``max_depth`` hops, collecting every transitively
    dependent node. Each impacted node is reported once, at its minimum
    dependency distance (``depth >= 1``) from the nearest changed node. The
    changed nodes themselves are never reported as impacted.

    Args:
        changed: list of node ids being changed (the traversal seeds).
        edges: list of ``{"from", "to", "workspace"?}`` where ``from`` depends
            on ``to``.
        max_depth: maximum number of reverse hops to walk (>= 0). ``0`` means no
            traversal — an empty impacted set. ``1`` stops at direct dependents.
        max_nodes: hard cap on the number of impacted nodes returned; when the
            cap is hit traversal stops early and ``truncated`` is ``True``.

    Returns:
        ``{"schema_version", "impacted": [{"id", "depth", "workspace"}],
        "by_workspace": {ws: count}, "truncated": bool, "changed": [...],
        "impacted_count": int, "edge_count": int}``. Always a well-formed dict;
        never raises on malformed input.
    """
    try:
        max_depth = int(max_depth)
    except (TypeError, ValueError):
        max_depth = 3
    if max_depth < 0:
        max_depth = 0

    try:
        max_nodes = int(max_nodes)
    except (TypeError, ValueError):
        max_nodes = 500
    if max_nodes < 0:
        max_nodes = 0

    changed_ids = _clean_ids(changed)
    changed_set = set(changed_ids)
    reverse_adj, node_workspace, edge_count = _build_reverse_adjacency(edges)

    # depth[node] = minimum dependency distance from any changed node.
    depth: Dict[str, int] = {}
    truncated = False

    # BFS seeded by the changed nodes at depth 0 (they are NOT impacted; only
    # nodes discovered while expanding are impacted).
    queue: deque = deque((cid, 0) for cid in changed_ids)

    while queue:
        current, current_depth = queue.popleft()
        if current_depth >= max_depth:
            # Cannot expand further than max_depth hops.
            continue
        next_depth = current_depth + 1
        for dependent in reverse_adj.get(current, ()):  # who depends on `current`
            if dependent in changed_set:
                # A changed node is never reported as impacted, even if another
                # changed node depends on it.
                continue
            if dependent in depth:
                # Already discovered at an equal-or-shallower depth (BFS).
                continue
            if len(depth) >= max_nodes:
                truncated = True
                break
            depth[dependent] = next_depth
            queue.append((dependent, next_depth))
        if truncated:
            break

    impacted: List[Dict[str, Any]] = [
        {
            "id": node_id,
            "depth": node_depth,
            "workspace": node_workspace.get(node_id, _UNKNOWN_WORKSPACE),
        }
        for node_id, node_depth in depth.items()
    ]
    # Deterministic order: nearest-impact first, then lexical id for ties.
    impacted.sort(key=lambda item: (item["depth"], item["id"]))

    by_workspace: Dict[str, int] = {}
    for item in impacted:
        ws = item["workspace"]
        by_workspace[ws] = by_workspace.get(ws, 0) + 1

    return {
        "schema_version": SCHEMA,
        "impacted": impacted,
        "by_workspace": by_workspace,
        "truncated": truncated,
        "changed": changed_ids,
        "impacted_count": len(impacted),
        "edge_count": edge_count,
    }
