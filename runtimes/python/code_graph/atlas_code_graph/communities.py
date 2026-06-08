"""Deterministic community detection over the Atlas code graph (AP-811/AP-812).

Louvain modularity optimization, pure stdlib — no networkx/graspologic. Per
atlas-ai-runtime-language-boundaries.md this heavier graph analytic lives in the
python_ai_data runtime, never in the Laravel Kernel. Output is a read-model only;
it FEEDS code-graph intelligence and never decides provider/model/domain/policy.
NOT promoted to production until human review (runtime_promotion_policy.v1).

Determinism is a hard requirement: same input -> byte-identical output across
runs and machines. We get there by (a) never relying on dict/set iteration order
for any decision, (b) iterating nodes in SORTED order, (c) breaking every tie by
the smallest community-representative string, and (d) labelling final community
ids by (size desc, then min node) so the numbering is stable too.

The base graph is treated as UNDIRECTED and UNWEIGHTED. Phase-2 aggregation
collapses each community into a super-node, which produces a *weighted* multigraph
(community self-loops carry internal edge weight); the move loop is fully weighted
so it handles those aggregated levels correctly.

Input edges: [{"from_node_id": str, "to_node_id": str}, ...].
Output schema: atlas.code_graph.communities.v1 (see ``detect_communities``).
"""

from __future__ import annotations

from collections import defaultdict

SCHEMA = "atlas.code_graph.communities.v1"

# Resolution parameter (classic modularity). Kept at 1.0 — exposed so the caller
# can tune later, but the runtime never decides this on its own.
_DEFAULT_RESOLUTION = 1.0
_DEFAULT_MAX_PASSES = 50


def _build_base_graph(edges):
    """Return (adjacency, sorted_nodes, edge_count).

    ``adjacency`` maps node -> {neighbour: weight}. The input is undirected and
    unweighted; parallel edges accumulate weight so the math stays correct even
    if the resolver hands us duplicates. Self-loops and malformed rows dropped.
    """
    adj = defaultdict(lambda: defaultdict(float))
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
        adj[a][b] += 1.0
        adj[b][a] += 1.0
        nodes.add(a)
        nodes.add(b)
        edge_count += 1
    # Materialise plain dicts in deterministic (sorted) key order.
    sorted_nodes = sorted(nodes)
    graph = {n: {nb: adj[n][nb] for nb in sorted(adj[n])} for n in sorted_nodes}
    return graph, sorted_nodes, edge_count


def _weighted_degrees(graph):
    """k_i for every node, counting a self-loop twice (standard convention)."""
    deg = {}
    for n, nbrs in graph.items():
        total = 0.0
        for nb, w in nbrs.items():
            total += w * 2.0 if nb == n else w
        deg[n] = total
    return deg


def _total_weight(degrees):
    """m = (1/2) * sum_i k_i  (sum of edge weights, self-loops counted once)."""
    return sum(degrees.values()) / 2.0


def _one_level(graph, two_m):
    """Run phase-1 of Louvain on ``graph`` until no node move improves Q.

    Returns ``community_of`` mapping (node -> community-rep string) and whether
    any move was made at this level. Communities are identified by the smallest
    node string they contain (the "representative"), which keeps everything
    deterministic and tie-breaks naturally on a string compare.
    """
    nodes = sorted(graph.keys())
    degrees = _weighted_degrees(graph)

    # Each node starts alone; its community rep is itself.
    community_of = {n: n for n in nodes}
    # sigma_tot[c] = sum of weighted degrees of nodes currently in community c.
    sigma_tot = {n: degrees[n] for n in nodes}
    members = {n: {n} for n in nodes}

    moved_any = False
    improved = True
    while improved:
        improved = False
        for node in nodes:  # SORTED, deterministic order
            cur_com = community_of[node]
            k_i = degrees[node]

            # Weight from `node` into each candidate community (excluding the
            # self-loop, which never crosses a community boundary).
            weight_to_com = defaultdict(float)
            for nb, w in graph[node].items():
                if nb == node:
                    continue
                weight_to_com[community_of[nb]] += w

            # Remove `node` from its current community before scoring, so the
            # gain math compares like-for-like (node is momentarily isolated).
            sigma_tot[cur_com] -= k_i
            members[cur_com].discard(node)
            if not members[cur_com]:
                # Community emptied; drop its bookkeeping.
                sigma_tot.pop(cur_com, None)
                members.pop(cur_com, None)

            # Candidate communities: every neighbour community PLUS the node's
            # own (so "stay put" is always on the table). Staying isolated maps
            # to community-rep == node.
            candidates = set(weight_to_com.keys())
            candidates.add(cur_com if cur_com in sigma_tot else node)
            # If the node was the sole member, `cur_com` may now be gone; the
            # rep for an isolated node is the node itself.
            if node not in members:
                candidates.add(node)

            best_com = None
            best_gain = 0.0  # only strictly-positive gains move the node
            # Gain of inserting isolated `node` into community c (drop the
            # constant 1/two_m factor — it's the same for every candidate, so
            # the argmax is identical; we keep it for an honest modularity sense
            # but it does not change the decision).
            for c in sorted(candidates):  # tie-break by smallest rep string
                k_i_in = weight_to_com.get(c, 0.0)
                sig = sigma_tot.get(c, 0.0)
                gain = k_i_in - (_DEFAULT_RESOLUTION * sig * k_i) / two_m
                if gain > best_gain + 1e-12:
                    best_gain = gain
                    best_com = c

            # Fall back to the node's own isolated community if nothing beat the
            # zero baseline (i.e. staying/becoming isolated).
            target = best_com if best_com is not None else node

            # Re-insert `node` into the chosen community.
            sigma_tot[target] = sigma_tot.get(target, 0.0) + k_i
            members.setdefault(target, set()).add(node)
            community_of[node] = target

            if target != cur_com:
                moved_any = True
                improved = True

    return community_of, moved_any


def _aggregate(graph, community_of):
    """Collapse each community into a super-node (rep string) -> weighted graph.

    Internal community edges become a self-loop on the super-node; edges between
    communities sum into a single weighted edge. Deterministic by construction
    (sorted iteration, rep strings as keys).
    """
    agg = defaultdict(lambda: defaultdict(float))
    reps = sorted(set(community_of.values()))
    for rep in reps:
        agg[rep]  # ensure isolated super-nodes survive
    for u in sorted(graph.keys()):
        cu = community_of[u]
        for v in sorted(graph[u].keys()):
            cv = community_of[v]
            w = graph[u][v]
            if u == v:
                # original self-loop: weight w (degree counts it twice already)
                agg[cu][cu] += w
            else:
                # each undirected edge {u,v} is seen twice (u->v and v->u);
                # add half each time so the collapsed weight is exact.
                agg[cu][cv] += w / 2.0
    return {r: {nb: agg[r][nb] for nb in sorted(agg[r])} for r in reps}


def detect_communities(edges, max_passes: int = _DEFAULT_MAX_PASSES):
    """Deterministic Louvain community detection over undirected edges.

    Returns::

        {
          "schema_version": "atlas.code_graph.communities.v1",
          "node_count": int,
          "edge_count": int,            # original (deduped semantics) edge count
          "community_count": int,
          "communities": [
              {"community_id": int, "size": int, "nodes": [sorted node ids]},
              ...                        # ordered by (size desc, then min node)
          ],
          "assignments": {node: community_id, ...},
        }
    """
    base_graph, base_nodes, edge_count = _build_base_graph(edges)
    node_count = len(base_nodes)

    if node_count == 0:
        return {
            "schema_version": SCHEMA,
            "node_count": 0,
            "edge_count": 0,
            "community_count": 0,
            "communities": [],
            "assignments": {},
        }

    base_degrees = _weighted_degrees(base_graph)
    two_m = sum(base_degrees.values())  # = 2m

    # `node_to_com` tracks the ORIGINAL node -> current super-community rep, so we
    # can fold every aggregation level back onto the real nodes at the end.
    node_to_com = {n: n for n in base_nodes}

    if two_m <= 0.0:
        # No edges at all: every node is its own community. (Still deterministic.)
        return _finalize(base_nodes, node_to_com, node_count, edge_count)

    graph = base_graph
    passes = 0
    while passes < max_passes:
        passes += 1
        community_of, moved = _one_level(graph, two_m)
        # Fold this level's assignment onto the original nodes.
        node_to_com = {n: community_of[node_to_com[n]] for n in base_nodes}

        # Stop when a level produced exactly one community per node (nothing
        # merged) — i.e. no structural change to aggregate further.
        distinct = len(set(community_of.values()))
        if not moved or distinct == len(community_of):
            break

        graph = _aggregate(graph, community_of)
        if len(graph) <= 1:
            break

    return _finalize(base_nodes, node_to_com, node_count, edge_count)


def _finalize(base_nodes, node_to_com, node_count, edge_count):
    """Renumber community reps to stable integer ids and build the output."""
    # Group original nodes by their final community rep.
    grouped = defaultdict(list)
    for n in base_nodes:  # base_nodes already sorted
        grouped[node_to_com[n]].append(n)

    # Order communities by (size desc, then min node asc) for a stable id space.
    groups = [
        {"rep": rep, "nodes": sorted(members)}
        for rep, members in grouped.items()
    ]
    groups.sort(key=lambda g: (-len(g["nodes"]), g["nodes"][0]))

    communities = []
    assignments = {}
    for cid, g in enumerate(groups):
        communities.append(
            {"community_id": cid, "size": len(g["nodes"]), "nodes": g["nodes"]}
        )
        for n in g["nodes"]:
            assignments[n] = cid

    return {
        "schema_version": SCHEMA,
        "node_count": node_count,
        "edge_count": edge_count,
        "community_count": len(communities),
        "communities": communities,
        # assignments rebuilt in sorted-node order for a deterministic dict dump.
        "assignments": {n: assignments[n] for n in base_nodes},
    }
