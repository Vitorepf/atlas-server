"""Betweenness centrality over the Atlas code graph (AP-812, python_ai_data).

Brandes' algorithm for unweighted graphs — O(V*E). This is the kind of
heavier graph analytic that, per atlas-ai-runtime-language-boundaries.md,
belongs in the python_ai_data runtime rather than the Laravel Kernel (the
Kernel keeps the cheap degree-based god-nodes in CodeGraphAnalytics.php).

Pure stdlib, deterministic (sorted traversal). No networkx — Leiden/NetworkX
are gated behind dependency approval in AP-812's promotion review.

Input edges: [{"from_node_id": str, "to_node_id": str}, ...] (undirected here).
Output: deterministic ranking of nodes by betweenness.
"""

from __future__ import annotations

from collections import defaultdict, deque

SCHEMA = "atlas.code_graph.centrality.v1"


def _build_adjacency(edges):
    adj = defaultdict(set)
    nodes = set()
    edge_count = 0
    for edge in edges:
        if not isinstance(edge, dict):
            continue
        a = edge.get("from_node_id")
        b = edge.get("to_node_id")
        if not isinstance(a, str) or not isinstance(b, str):
            continue
        a, b = a.strip(), b.strip()
        if not a or not b or a == b:
            continue
        adj[a].add(b)
        adj[b].add(a)
        nodes.add(a)
        nodes.add(b)
        edge_count += 1
    # deterministic neighbour order
    return {n: sorted(adj[n]) for n in nodes}, sorted(nodes), edge_count


def betweenness_centrality(edges, normalized: bool = True, limit: int = 20):
    adj, nodes, edge_count = _build_adjacency(edges)
    cb = {n: 0.0 for n in nodes}

    for s in nodes:  # Brandes, source by source
        stack = []
        preds = {w: [] for w in nodes}
        sigma = dict.fromkeys(nodes, 0.0)
        sigma[s] = 1.0
        dist = dict.fromkeys(nodes, -1)
        dist[s] = 0
        queue = deque([s])
        while queue:
            v = queue.popleft()
            stack.append(v)
            for w in adj[v]:
                if dist[w] < 0:
                    dist[w] = dist[v] + 1
                    queue.append(w)
                if dist[w] == dist[v] + 1:
                    sigma[w] += sigma[v]
                    preds[w].append(v)
        delta = dict.fromkeys(nodes, 0.0)
        while stack:
            w = stack.pop()
            for v in preds[w]:
                if sigma[w] > 0:
                    delta[v] += (sigma[v] / sigma[w]) * (1.0 + delta[w])
            if w != s:
                cb[w] += delta[w]

    # undirected: each shortest path counted twice
    for n in nodes:
        cb[n] /= 2.0

    n = len(nodes)
    if normalized and n > 2:
        scale = 1.0 / ((n - 1) * (n - 2) / 2.0)
        for node in cb:
            cb[node] *= scale

    ranked = [
        {"node_id": node, "betweenness": round(cb[node], 6)}
        for node in nodes
    ]
    ranked.sort(key=lambda r: (-r["betweenness"], r["node_id"]))

    return {
        "schema_version": SCHEMA,
        "node_count": n,
        "edge_count": edge_count,
        "ranked": ranked[: max(1, limit)],
    }
