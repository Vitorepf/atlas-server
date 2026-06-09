"""Recurring structural patterns across workspaces (AP-815, python_ai_data).

Answers "which STRUCTURAL shapes recur across my repos?" — the AWEF
(Atlas Workspace Embedding Fabric) privacy-safe primitive: it learns from the
SHAPE of each workspace's graph, never from its content. A node is reduced to a
structural SIGNATURE — its (out_degree, in_degree, sorted incident edge-types) —
and signatures are grouped across workspaces. The node id and any content are
NEVER part of the signature, so nothing identifying leaks into the grouping key;
a signature that appears in >= ``min_workspaces`` distinct workspaces is a
recurring structural pattern (e.g. "a fan-out-3 caller with no callers" showing
up in two unrelated repos).

This is the kind of cross-workspace aggregation that, per
atlas-ai-runtime-language-boundaries.md, belongs in the python_ai_data runtime
(the muscle): the Laravel Kernel hands us the per-workspace edge sets and we
fold them into structural buckets. The Kernel keeps policy/provider/domain
decisions and any content handling — this module decides nothing and reads no
content.

Pure stdlib (collections.defaultdict / Counter), deterministic (signatures and
examples sorted; output sorted by (-workspace_count, signature)), fail-safe
(never raises on malformed input — garbage entries are skipped and a well-formed
default is returned). NOT promoted to production until human review
(runtime_promotion_policy.v1).

Input ``graphs``: a list of per-workspace graph dicts, each
``{"workspace": str, "edges": [{"from": str, "to": str, "type"?: str}, ...]}``.
``type`` is optional; a missing / empty type folds into the sentinel
``"untyped"`` so untyped edges still form a stable signature.

Output: ``{"schema_version", "signatures": int (distinct signatures seen),
"recurring": [{"signature", "workspaces", "node_count", "examples"}]}``.
Each ``examples`` entry is ``{"workspace", "id"}`` — the only place a node id
appears (a few concrete witnesses for human inspection), never in the grouping
key. ``recurring`` lists only signatures present in >= ``min_workspaces``
distinct workspaces, sorted by descending workspace count then signature string.
"""

from __future__ import annotations

from collections import Counter, defaultdict
from typing import Any, Dict, List, Tuple

SCHEMA = "atlas.code_graph.cross_workspace_patterns.v1"

# Max concrete (workspace, id) witnesses kept per recurring signature. Bounds
# output size and limits how many raw ids surface for any one pattern.
_MAX_EXAMPLES = 5

# Sentinel edge type for edges that carry no (or an empty) ``type`` so untyped
# graphs still produce a stable, comparable signature.
_UNTYPED = "untyped"


def _clean_edges(edges: Any) -> List[Tuple[str, str, str]]:
    """Coerce one workspace's edge list into ``(from, to, type)`` triples.

    Non-list edge collections, non-dict edges and non-string / empty
    from/to ids are dropped. A missing / non-string / empty ``type`` folds into
    the ``_UNTYPED`` sentinel. Self-loops are kept (they still describe a node's
    structural shape). Ids and types are stripped so trivial whitespace
    differences do not fragment a signature.
    """
    if not isinstance(edges, (list, tuple)):
        return []
    cleaned: List[Tuple[str, str, str]] = []
    for edge in edges:
        if not isinstance(edge, dict):
            continue
        src = edge.get("from")
        dst = edge.get("to")
        if not isinstance(src, str) or not isinstance(dst, str):
            continue
        src, dst = src.strip(), dst.strip()
        if not src or not dst:
            continue
        etype = edge.get("type")
        if not isinstance(etype, str) or not etype.strip():
            etype = _UNTYPED
        else:
            etype = etype.strip()
        cleaned.append((src, dst, etype))
    return cleaned


def _signature_string(out_degree: int, in_degree: int, types: List[str]) -> str:
    """Render a structural signature as a stable, content-free string key.

    Form: ``out=<n>|in=<n>|types=<t1,t2,...>`` with types sorted. Contains only
    degree counts and edge-type labels — never a node id or any content.
    """
    type_part = ",".join(types) if types else ""
    return f"out={out_degree}|in={in_degree}|types=[{type_part}]"


def recurring_patterns(
    graphs: Any,
    min_workspaces: int = 2,
) -> Dict[str, Any]:
    """Find structural node signatures that recur across >= N workspaces.

    For every node in every workspace, a structural SIGNATURE is computed from
    its out-degree, in-degree and the sorted set of edge-types incident to it
    (in + out). The node id and any content are excluded from the signature, so
    the grouping is privacy-safe by construction. Signatures are grouped across
    workspaces; a signature present in at least ``min_workspaces`` *distinct*
    workspaces is returned as a recurring pattern.

    Args:
        graphs: list of per-workspace graph dicts, each
            ``{"workspace": str, "edges": [{"from", "to", "type"?}]}``.
        min_workspaces: minimum number of distinct workspaces a signature must
            appear in to be reported (clamped to >= 1).

    Returns:
        ``{"schema_version", "signatures": int, "recurring": [...]}``. Always a
        well-formed dict; never raises on malformed input. ``signatures`` counts
        all distinct signatures observed; ``recurring`` holds only those meeting
        the ``min_workspaces`` threshold, sorted by (-workspace_count,
        signature). Each recurring entry carries ``signature`` (str),
        ``workspaces`` (sorted distinct list), ``node_count`` (total nodes with
        that signature across all workspaces) and ``examples`` (a few
        ``{"workspace", "id"}`` witnesses).
    """
    try:
        min_workspaces = int(min_workspaces)
    except (TypeError, ValueError):
        min_workspaces = 2
    if min_workspaces < 1:
        min_workspaces = 1

    # signature -> set of distinct workspaces it appears in.
    sig_workspaces: Dict[str, set] = defaultdict(set)
    # signature -> total node count across all workspaces (a node per workspace
    # occurrence; the same id in two workspaces counts twice — they are distinct
    # structural occurrences).
    sig_node_count: Counter = Counter()
    # signature -> sorted list of (workspace, id) witnesses (bounded).
    sig_examples: Dict[str, List[Tuple[str, str]]] = defaultdict(list)

    if isinstance(graphs, (list, tuple)):
        for graph in graphs:
            if not isinstance(graph, dict):
                continue
            workspace = graph.get("workspace")
            if not isinstance(workspace, str):
                continue
            workspace = workspace.strip()
            if not workspace:
                continue

            edges = _clean_edges(graph.get("edges"))

            out_degree: Counter = Counter()
            in_degree: Counter = Counter()
            node_types: Dict[str, set] = defaultdict(set)
            nodes: set = set()

            for src, dst, etype in edges:
                out_degree[src] += 1
                in_degree[dst] += 1
                node_types[src].add(etype)
                node_types[dst].add(etype)
                nodes.add(src)
                nodes.add(dst)

            # Per workspace, gather every node's signature, then fold the
            # per-workspace witnesses in sorted id order so examples are
            # deterministic regardless of input edge ordering.
            for node in sorted(nodes):
                types = sorted(node_types[node])
                signature = _signature_string(
                    out_degree[node], in_degree[node], types
                )
                sig_workspaces[signature].add(workspace)
                sig_node_count[signature] += 1
                bucket = sig_examples[signature]
                if len(bucket) < _MAX_EXAMPLES:
                    bucket.append((workspace, node))

    recurring: List[Dict[str, Any]] = []
    for signature, workspaces in sig_workspaces.items():
        if len(workspaces) < min_workspaces:
            continue
        examples = [
            {"workspace": ws, "id": node}
            for ws, node in sig_examples[signature]
        ]
        recurring.append(
            {
                "signature": signature,
                "workspaces": sorted(workspaces),
                "node_count": sig_node_count[signature],
                "examples": examples,
            }
        )

    # Deterministic order: most widely recurring first, then signature string.
    recurring.sort(key=lambda r: (-len(r["workspaces"]), r["signature"]))

    return {
        "schema_version": SCHEMA,
        "signatures": len(sig_workspaces),
        "recurring": recurring,
    }
