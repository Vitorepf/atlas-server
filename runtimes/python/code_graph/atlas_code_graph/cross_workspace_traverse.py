"""Cross-workspace forward reachability over the Atlas code graph (AP-815, python_ai_data).

Answers "in ANY repo, what does X reach?" — a forward BFS that deliberately
ignores workspace boundaries while still *reporting* which workspaces it
touched. Static intra-repo analysis cannot see that a symbol in repo A reaches
a symbol in repo B; this op walks an edge set that may span workspaces (each
edge optionally carries a ``workspace`` tag) and returns the union of reachable
nodes with the hop-distance and owning workspace of each.

This is the kind of bounded graph traversal that, per
atlas-ai-runtime-language-boundaries.md, belongs in the python_ai_data runtime
(the muscle): the Laravel Kernel hands us the resolved cross-workspace edge set
and the seed node(s); we walk it and hand back a deterministic frontier. The
Kernel keeps policy/provider/domain decisions — this module decides nothing.

Pure stdlib (collections.deque), deterministic (BFS in sorted neighbour order,
output sorted by ``(depth, id)``), fail-safe (never raises on malformed input —
garbage entries are skipped and a well-formed default is returned). NOT promoted
to production until human review (runtime_promotion_policy.v1).

Input ``seeds``: a single node id (str) or a list of node ids.
Input ``edges``: ``[{"from": str, "to": str, "workspace"?: str}, ...]`` —
directed forward edges; ``workspace`` is optional and may differ per edge so a
single set can span repos.

Output: ``{"reachable": [{"id", "depth", "workspace"}], "workspaces_touched":
[...], "truncated": bool, "seeds": [...]}``. ``depth`` is the minimum hop count
from the nearest seed (seeds are depth 0). ``workspace`` is the workspace tag of
the edge that first discovered the node (``None`` for seeds and for nodes whose
discovering edge carried no tag). ``workspaces_touched`` is the sorted union of
every edge ``workspace`` tag encountered while walking.
"""

from __future__ import annotations

from collections import defaultdict, deque
from typing import Any, Dict, List, Optional

SCHEMA = "atlas.code_graph.cross_workspace_traverse.v1"


def _clean_seeds(seeds: Any) -> List[str]:
    """Coerce ``seeds`` into a deterministic list of distinct non-empty ids.

    Accepts a single string seed or an iterable of seeds. Non-string / empty
    entries are dropped. Sorted so traversal order (and the reported seed list)
    is deterministic regardless of caller ordering.
    """
    if isinstance(seeds, str):
        candidates = [seeds]
    elif isinstance(seeds, (list, tuple, set)):
        candidates = list(seeds)
    else:
        return []
    seen = set()
    for seed in candidates:
        if not isinstance(seed, str):
            continue
        seed = seed.strip()
        if not seed:
            continue
        seen.add(seed)
    return sorted(seen)


def _build_forward_adjacency(edges: Any):
    """Build a forward adjacency map and a per-(from,to) workspace tag.

    Returns ``(adj, edge_workspace)`` where ``adj[node]`` is the sorted list of
    distinct forward neighbours and ``edge_workspace[(from, to)]`` is the
    workspace tag of that edge (``None`` if untagged). Malformed edges are
    skipped. When the same (from, to) appears multiple times with different
    tags, the lexically-smallest non-empty tag wins so the result is
    deterministic.
    """
    adj: defaultdict = defaultdict(set)
    edge_workspace: Dict[tuple, Optional[str]] = {}

    if not isinstance(edges, (list, tuple)):
        return {}, edge_workspace

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
            workspace = workspace.strip() or None
        else:
            workspace = None

        adj[src].add(dst)
        key = (src, dst)
        if key not in edge_workspace:
            edge_workspace[key] = workspace
        elif workspace is not None:
            existing = edge_workspace[key]
            if existing is None or workspace < existing:
                edge_workspace[key] = workspace

    return {node: sorted(neighbours) for node, neighbours in adj.items()}, edge_workspace


def traverse(
    seeds: Any,
    edges: Any,
    max_depth: int = 4,
    max_nodes: int = 200,
) -> Dict[str, Any]:
    """Forward BFS from ``seeds`` over a (possibly cross-workspace) edge set.

    Walks directed ``from -> to`` edges breadth-first from the seed node(s),
    bounded by ``max_depth`` hops and ``max_nodes`` discovered nodes. Each
    reachable node is reported once at its minimum depth from the nearest seed,
    together with the workspace tag of the edge that first discovered it. The
    union of all edge workspace tags seen while walking is returned as
    ``workspaces_touched``.

    Cycles are safe: a node is enqueued at most once (first discovery wins), so
    a cyclic graph terminates rather than looping forever.

    Args:
        seeds: a single node id (str) or a list of node ids to start from.
        edges: ``[{"from", "to", "workspace"?}, ...]`` directed forward edges;
            edges may span workspaces.
        max_depth: maximum hop distance from a seed to expand (>= 0). Seeds are
            depth 0; ``max_depth=1`` keeps only the seeds' direct successors.
        max_nodes: hard cap on the number of nodes in ``reachable`` (>= 0). When
            the cap is hit, ``truncated`` is ``True`` and the lowest-depth /
            lexically-smallest nodes are kept.

    Returns:
        ``{"schema_version", "reachable": [{"id", "depth", "workspace"}],
        "workspaces_touched": [...], "truncated": bool, "seeds": [...]}``.
        Always a well-formed dict; never raises on malformed input.
    """
    try:
        max_depth = int(max_depth)
    except (TypeError, ValueError):
        max_depth = 4
    if max_depth < 0:
        max_depth = 0

    try:
        max_nodes = int(max_nodes)
    except (TypeError, ValueError):
        max_nodes = 200
    if max_nodes < 0:
        max_nodes = 0

    clean_seeds = _clean_seeds(seeds)
    adj, edge_workspace = _build_forward_adjacency(edges)

    # depth + discovering-workspace per reached node (first discovery wins).
    depth_of: Dict[str, int] = {}
    workspace_of: Dict[str, Optional[str]] = {}
    workspaces_touched = set()
    truncated = False

    # Seeds enter at depth 0 (with no discovering edge, hence workspace None),
    # but only as many as the cap allows.
    queue: deque = deque()
    for seed in clean_seeds:
        if len(depth_of) >= max_nodes:
            truncated = True
            break
        if seed in depth_of:
            continue
        depth_of[seed] = 0
        workspace_of[seed] = None
        queue.append(seed)

    while queue:
        node = queue.popleft()
        node_depth = depth_of[node]
        if node_depth >= max_depth:
            # Do not expand past the depth bound, but its neighbours' tags are
            # only "touched" once we actually traverse the edge — so skip.
            continue
        for neighbour in adj.get(node, ()):  # sorted -> deterministic
            tag = edge_workspace.get((node, neighbour))
            if tag is not None:
                workspaces_touched.add(tag)
            if neighbour in depth_of:
                continue  # already reached at a <= depth (cycle-safe)
            if len(depth_of) >= max_nodes:
                truncated = True
                continue  # keep scanning remaining edges only for their tags
            depth_of[neighbour] = node_depth + 1
            workspace_of[neighbour] = tag
            queue.append(neighbour)

    reachable: List[Dict[str, Any]] = [
        {"id": node, "depth": depth_of[node], "workspace": workspace_of[node]}
        for node in depth_of
    ]
    # Deterministic order: shallowest first, then lexical id for ties.
    reachable.sort(key=lambda r: (r["depth"], r["id"]))

    return {
        "schema_version": SCHEMA,
        "reachable": reachable,
        "workspaces_touched": sorted(workspaces_touched),
        "truncated": truncated,
        "seeds": clean_seeds,
    }
