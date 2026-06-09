"""Cross-repo entity / concept resolution via embeddings (AP-815, X-7).

Reveals when the SAME concept is named differently across workspaces — e.g.
``PaymentService`` in the ``api`` repo and ``payment service`` in the ``web``
repo are the same domain entity even though no static reference, import or
co-change ties them together (they live in different repos entirely). String
equality and even fuzzy lexical matching miss this; semantic embeddings catch
it. This is exactly the LOCAL-embedding analytic that, per
atlas-ai-runtime-language-boundaries.md, belongs in the python_ai_data runtime
(the muscle) rather than the Laravel Kernel — the Kernel hands us the entity
list and we cluster over their meaning.

Embeddings are computed with fastembed's local ONNX model
``BAAI/bge-small-en-v1.5`` (no network at inference, downloads the model once on
first use). fastembed is a HEAVY dep and is therefore lazily imported INSIDE the
function so module import stays cheap (matching pdf_ingest.py /
treesitter_extract.py).

Clustering is single-link agglomerative: two names join the same cluster when
their pairwise cosine similarity is >= ``threshold``. Processing is done in
sorted-``id`` order so the cluster assignment is DETERMINISTIC for a fixed model
(the embedding model itself is deterministic for a given input). Within a
cluster the ``canonical`` name is the lexicographically smallest member name, a
stable tie-break independent of input order.

Read-model only: this extracts structure for the graph. It never decides
provider/model/domain/policy, never executes anything it reads, and is
FAIL-SAFE — empty / malformed input, blank names, or an unavailable model all
degrade to a safe empty result with a ``note`` instead of raising. NOT promoted
to production until human review (runtime_promotion_policy.v1).

Input ``entities``: a list of dicts, each ``{"id", "name", "workspace"?}``.
Output: see :data:`SCHEMA` and :func:`resolve_entities`.
"""

from __future__ import annotations

import math
from typing import Any, Dict, List, Optional, Sequence, Tuple

SCHEMA = "atlas.code_graph.entity_resolution.v1"

# Local ONNX embedding model. Approved + installed in the code_graph venv.
_MODEL_NAME = "BAAI/bge-small-en-v1.5"


def _clean_str(value: Any) -> Optional[str]:
    """Return a trimmed non-empty string, or None for anything else."""
    if not isinstance(value, str):
        return None
    trimmed = value.strip()
    return trimmed or None


def _clean_entities(entities: Any) -> List[Dict[str, Any]]:
    """Coerce input into a deterministic list of usable entity records.

    Drops non-dict rows, rows without a usable ``id`` or ``name`` (blank names
    are skipped per spec), and trims ``name`` / ``workspace``. The ``id`` is
    coerced to ``str`` so mixed int/str ids still sort and dedupe. Result is
    sorted by ``id`` so the whole pipeline (embedding order, clustering order,
    output order) is deterministic regardless of caller ordering.
    """
    if not isinstance(entities, (list, tuple)):
        return []

    cleaned: List[Dict[str, Any]] = []
    for row in entities:
        if not isinstance(row, dict):
            continue
        raw_id = row.get("id")
        if raw_id is None:
            continue
        # Accept str or int-ish ids; coerce to a stable string key.
        if isinstance(raw_id, bool):  # bool is a subclass of int — reject it
            continue
        if isinstance(raw_id, str):
            ent_id = raw_id.strip()
        elif isinstance(raw_id, int):
            ent_id = str(raw_id)
        else:
            continue
        if not ent_id:
            continue

        name = _clean_str(row.get("name"))
        if name is None:
            continue  # skip blank / missing names

        workspace = _clean_str(row.get("workspace"))
        cleaned.append({"id": ent_id, "name": name, "workspace": workspace})

    # Deterministic processing order (sorted id, then name as a stable secondary).
    cleaned.sort(key=lambda e: (e["id"], e["name"]))
    return cleaned


def _cosine(a: Sequence[float], b: Sequence[float]) -> float:
    """Cosine similarity between two equal-length vectors.

    Returns 0.0 for a degenerate (zero-norm) vector rather than raising.
    """
    dot = 0.0
    na = 0.0
    nb = 0.0
    for x, y in zip(a, b):
        dot += x * y
        na += x * x
        nb += y * y
    if na <= 0.0 or nb <= 0.0:
        return 0.0
    denom = math.sqrt(na) * math.sqrt(nb)
    if denom <= 0.0:
        return 0.0
    return dot / denom


def _embed(names: List[str]) -> Optional[List[List[float]]]:
    """Embed ``names`` with the local ONNX model, or None if unavailable.

    fastembed is imported HERE (lazy) so importing this module never pulls the
    heavy dep. Any failure — dep missing, model download blocked, runtime
    error — is swallowed and reported as ``None`` so the caller can degrade to a
    safe empty result instead of raising.
    """
    try:
        from fastembed import TextEmbedding  # venv-only heavy dep
    except Exception:
        return None

    try:
        model = TextEmbedding(model_name=_MODEL_NAME)
        # fastembed yields numpy arrays; tolist() makes them plain + comparable.
        vectors = [list(map(float, vec.tolist())) for vec in model.embed(names)]
    except Exception:
        return None

    if len(vectors) != len(names):
        return None
    return vectors


def _cluster_single_link(
    count: int,
    similar: "set[Tuple[int, int]]",
) -> List[List[int]]:
    """Single-link agglomerative clustering via union-find over similar pairs.

    ``similar`` is the set of index pairs (i, j) with i < j whose cosine met the
    threshold. Returns clusters as lists of indices; each cluster's indices are
    sorted, and clusters are ordered by their smallest member index so output is
    deterministic.
    """
    parent = list(range(count))

    def find(x: int) -> int:
        root = x
        while parent[root] != root:
            root = parent[root]
        # Path compression for cheapness on larger inputs.
        while parent[x] != root:
            parent[x], x = root, parent[x]
        return root

    def union(x: int, y: int) -> None:
        rx, ry = find(x), find(y)
        if rx == ry:
            return
        # Attach larger root index under the smaller for stable roots.
        if rx < ry:
            parent[ry] = rx
        else:
            parent[rx] = ry

    for i, j in similar:
        union(i, j)

    groups: Dict[int, List[int]] = {}
    for idx in range(count):
        groups.setdefault(find(idx), []).append(idx)

    clusters = [sorted(members) for members in groups.values()]
    clusters.sort(key=lambda members: members[0])
    return clusters


def resolve_entities(
    entities: Any,
    threshold: float = 0.85,
) -> Dict[str, Any]:
    """Cluster entities whose names mean the same thing across workspaces.

    Each entity name is embedded with the local ``BAAI/bge-small-en-v1.5`` ONNX
    model; names whose pairwise cosine similarity is ``>= threshold`` are merged
    into one cluster (single-link agglomerative). Processing happens in sorted
    ``id`` order so, for a fixed model, the result is deterministic.

    Args:
        entities: list of ``{"id", "name", "workspace"?}`` dicts. Non-dict rows,
            rows lacking a usable id/name, and blank names are skipped.
        threshold: cosine-similarity cut-off in ``[0, 1]`` for joining a cluster.
            Out-of-range / non-numeric values fall back to ``0.85``.

    Returns:
        ``{"schema_version", "clusters": [{"canonical", "members":
        [{"id", "name", "workspace"}], "size", "workspaces": [sorted]}],
        "singletons": int, "note"?}``.

        ``canonical`` is the lexicographically smallest member name. ``clusters``
        is sorted by (-size, canonical) so the largest / most-merged concepts
        surface first. ``singletons`` counts size-1 clusters. ``note`` is present
        only on a fail-safe degradation (empty input or model unavailable).

        ALWAYS a well-formed dict; never raises on malformed input or a missing
        model / dep.
    """
    # Validate threshold defensively.
    try:
        thr = float(threshold)
    except (TypeError, ValueError):
        thr = 0.85
    if not math.isfinite(thr) or thr < 0.0 or thr > 1.0:
        thr = 0.85

    cleaned = _clean_entities(entities)

    if not cleaned:
        return {
            "schema_version": SCHEMA,
            "clusters": [],
            "singletons": 0,
            "note": "no usable entities",
        }

    names = [e["name"] for e in cleaned]
    vectors = _embed(names)

    if vectors is None:
        # Fail-safe: model/dep unavailable -> every entity is its own singleton.
        clusters = [
            {
                "canonical": e["name"],
                "members": [
                    {"id": e["id"], "name": e["name"], "workspace": e["workspace"]}
                ],
                "size": 1,
                "workspaces": sorted(
                    {e["workspace"]} - {None}
                ),  # drop the None placeholder
            }
            for e in cleaned
        ]
        clusters.sort(key=lambda c: (-c["size"], c["canonical"]))
        return {
            "schema_version": SCHEMA,
            "clusters": clusters,
            "singletons": len(clusters),
            "note": (
                "embedding model unavailable; "
                "each entity treated as a singleton"
            ),
        }

    n = len(cleaned)
    similar: "set[Tuple[int, int]]" = set()
    for i in range(n):
        for j in range(i + 1, n):
            if _cosine(vectors[i], vectors[j]) >= thr:
                similar.add((i, j))

    index_clusters = _cluster_single_link(n, similar)

    clusters: List[Dict[str, Any]] = []
    singletons = 0
    for member_indices in index_clusters:
        members = [cleaned[idx] for idx in member_indices]
        member_out = [
            {"id": m["id"], "name": m["name"], "workspace": m["workspace"]}
            for m in members
        ]
        # Canonical = lexicographically smallest member NAME (stable, order-free).
        canonical = min(m["name"] for m in members)
        workspaces = sorted({m["workspace"] for m in members} - {None})
        size = len(member_out)
        if size == 1:
            singletons += 1
        clusters.append(
            {
                "canonical": canonical,
                "members": member_out,
                "size": size,
                "workspaces": workspaces,
            }
        )

    # Largest / most-merged concepts first; lexical canonical breaks ties.
    clusters.sort(key=lambda c: (-c["size"], c["canonical"]))

    return {
        "schema_version": SCHEMA,
        "clusters": clusters,
        "singletons": singletons,
    }
