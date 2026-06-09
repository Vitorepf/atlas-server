"""TRUE Leiden community detection over the Atlas code graph (AP-815 P-13).

The stdlib op (``communities.py``) implements Louvain by hand in pure Python.
Louvain is known to occasionally produce *badly connected* (even internally
disconnected) communities; Leiden (Traag, Waltman & van Eck, 2019) fixes that
with a refinement phase and is the stronger algorithm. This module delivers the
REAL thing via ``igraph`` + ``leidenalg`` rather than re-deriving it — the kind
of heavy graph analytic that, per atlas-ai-runtime-language-boundaries.md, lives
in the python_ai_data runtime (the muscle), never in the Laravel Kernel.

Read-model only: it FEEDS code-graph intelligence and never decides
provider/model/domain/policy, never executes anything it reads. NOT promoted to
production until human review (runtime_promotion_policy.v1).

FAIL-SAFE by contract: if ``igraph``/``leidenalg`` are unavailable at runtime, or
the input is empty/garbage, the function returns a well-formed empty result with
a ``note`` instead of raising. Heavy deps are imported lazily INSIDE the function
so importing this module stays cheap (matching ``pdf_ingest`` /
``treesitter_extract``).

Determinism: edges are deduped and nodes sorted before the graph is built, the
partition is run with a fixed ``seed``, and communities are emitted in a stable
order ((size desc, then first member)) with sorted member lists — so the same
input yields byte-identical output across runs.

Input ``edges``: ``[{"from": str, "to": str, "type": str}, ...]`` (``type`` is
accepted for graph-shape parity but does not affect the undirected partition).
Output schema: ``atlas.code_graph.leiden_communities.v1`` (see
:func:`leiden_communities`).
"""

from __future__ import annotations

from typing import Any, Dict, List, Tuple

SCHEMA = "atlas.code_graph.leiden_communities.v1"

# Resolution is exposed for the caller to tune; the runtime never decides it on
# its own. 1.0 is the classic modularity-like setting.
_DEFAULT_RESOLUTION = 1.0
_DEFAULT_SEED = 42


def _empty_result(note: str) -> Dict[str, Any]:
    """A well-formed, safe empty result carrying an explanatory ``note``.

    Used for every degenerate / fail-safe path so the caller always receives the
    same shape and never has to handle an exception.
    """
    return {
        "schema_version": SCHEMA,
        "communities": [],
        "modularity": 0.0,
        "node_count": 0,
        "edge_count": 0,
        "algorithm": "leiden",
        "note": note,
    }


def _clean_edges(edges: Any) -> Tuple[List[str], List[Tuple[int, int]]]:
    """Coerce raw edge dicts into (sorted_nodes, index_pairs).

    Drops anything that is not a ``{"from": str, "to": str}`` dict, trims the
    endpoints, ignores self-loops, and dedupes unordered pairs. Returns the
    sorted unique node list plus the unique edges expressed as pairs of indices
    into that list (the form igraph wants). Deterministic by construction:
    sorted nodes + sorted unique pairs, so the built graph is identical run to
    run regardless of the caller's ordering.
    """
    if not isinstance(edges, (list, tuple)):
        return [], []

    nodes: set = set()
    pairs: set = set()
    for edge in edges:
        if not isinstance(edge, dict):
            continue
        a = edge.get("from")
        b = edge.get("to")
        if not isinstance(a, str) or not isinstance(b, str):
            continue
        a, b = a.strip(), b.strip()
        if not a or not b or a == b:
            continue
        nodes.add(a)
        nodes.add(b)
        # Canonical (lo, hi) so {a,b} and {b,a} dedupe to one undirected edge.
        lo, hi = (a, b) if a <= b else (b, a)
        pairs.add((lo, hi))

    sorted_nodes = sorted(nodes)
    index_of = {name: i for i, name in enumerate(sorted_nodes)}
    # Sort the index pairs too, so igraph receives edges in a stable order.
    index_pairs = sorted((index_of[lo], index_of[hi]) for lo, hi in pairs)
    return sorted_nodes, index_pairs


def leiden_communities(
    edges: Any,
    resolution: float = _DEFAULT_RESOLUTION,
    seed: int = _DEFAULT_SEED,
) -> Dict[str, Any]:
    """Detect communities with the TRUE Leiden algorithm (igraph + leidenalg).

    Builds an UNDIRECTED igraph graph from the unique nodes/edges in ``edges``
    and runs :func:`leidenalg.find_partition` with
    ``RBConfigurationVertexPartition`` (a resolution-aware modularity objective),
    seeded for determinism.

    Args:
        edges: list of ``{"from": str, "to": str, "type": str}`` dicts. ``type``
            is accepted for parity with the rest of the graph but does not affect
            the undirected partition. Malformed rows are skipped, self-loops and
            duplicate unordered pairs are dropped.
        resolution: resolution parameter for the partition; higher -> more,
            smaller communities. Non-numeric / negative values fall back to the
            default ``1.0``.
        seed: RNG seed handed to ``find_partition`` for reproducibility.
            Non-int values fall back to the default ``42``.

    Returns:
        ``{"schema_version", "communities": [{"id": int, "members": [sorted node
        ids], "size": int}], "modularity": float (rounded 4), "node_count": int,
        "edge_count": int, "algorithm": "leiden"}``. Communities are ordered by
        ``(size desc, then first member asc)`` and re-``id``'d 0..n-1 in that
        order. On any failure (missing dep / empty / garbage input) returns
        :func:`_empty_result` with a ``note`` — it NEVER raises.
    """
    # --- normalise tunables (fail-safe, never raise) -----------------------
    try:
        resolution = float(resolution)
    except (TypeError, ValueError):
        resolution = _DEFAULT_RESOLUTION
    if resolution < 0:
        resolution = _DEFAULT_RESOLUTION

    try:
        seed = int(seed)
    except (TypeError, ValueError):
        seed = _DEFAULT_SEED

    sorted_nodes, index_pairs = _clean_edges(edges)
    if not sorted_nodes:
        return _empty_result("no valid edges; empty graph")

    # --- lazy heavy-dep import (keeps module import cheap, fail-safe) -------
    try:
        import igraph  # noqa: F401  (venv-only heavy dep)
        import leidenalg
    except Exception as exc:  # noqa: BLE001 — degrade, never raise
        result = _empty_result(f"igraph/leidenalg unavailable: {exc!r}")
        # Still report the graph size we managed to parse, for observability.
        result["node_count"] = len(sorted_nodes)
        result["edge_count"] = len(index_pairs)
        return result

    try:
        graph = igraph.Graph(
            n=len(sorted_nodes),
            edges=index_pairs,
            directed=False,
        )
        partition = leidenalg.find_partition(
            graph,
            leidenalg.RBConfigurationVertexPartition,
            resolution_parameter=resolution,
            seed=seed,
        )

        # Map each membership (a list of vertex indices) back to node ids.
        raw_groups: List[List[str]] = []
        for member_indices in partition:
            members = sorted(sorted_nodes[i] for i in member_indices)
            if members:
                raw_groups.append(members)

        # Stable ordering: largest first, ties broken by the lexically-first
        # member, then renumber 0..n-1 so ids are deterministic too.
        raw_groups.sort(key=lambda members: (-len(members), members[0]))
        communities = [
            {"id": cid, "members": members, "size": len(members)}
            for cid, members in enumerate(raw_groups)
        ]

        try:
            modularity = round(float(partition.modularity), 4)
        except Exception:  # noqa: BLE001 — modularity is best-effort
            modularity = 0.0

        return {
            "schema_version": SCHEMA,
            "communities": communities,
            "modularity": modularity,
            "node_count": len(sorted_nodes),
            "edge_count": len(index_pairs),
            "algorithm": "leiden",
        }
    except Exception as exc:  # noqa: BLE001 — any runtime failure -> safe empty
        result = _empty_result(f"leiden partition failed: {exc!r}")
        result["node_count"] = len(sorted_nodes)
        result["edge_count"] = len(index_pairs)
        return result
