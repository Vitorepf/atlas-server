"""Near-duplicate memory detection, computed in numpy.

This is the REAL engine the runtime_language_boundary canon + the operator thesis
("data math belongs in Python; never hand-roll a data engine in the PHP kernel; the
kernel scanner forbids reimplementing engines in PHP") mandate. It is a FAITHFUL
numpy port of the (now-removed) hand-rolled PHP reference in
app/Services/Ai/MemoryGovernance/MemoryNearDuplicateDetector.php — same shingling,
same contiguous-bigram Jaccard, same >=threshold link rule, same transitive-closure
clustering, same deterministic canonical selection (priority -> importance ->
recency -> lexicographically-smallest id), same ordering and 4-dp rounding — so the
swap is behaviour-preserving. The equivalence tests pin old-PHP-vs-new-Python output
EXACTLY (the metric is a rational p/q on small integer counts, so equality is exact
after the shared 4-dp round, not merely within a tolerance).

The single piece of real numeric work the PHP hand-rolled is the O(n^2) pairwise
Jaccard double loop. Here it is VECTORISED: each row's bigram-shingle set becomes a
boolean row of a (n x V) membership matrix M over the group's shingle vocabulary V;
then

    intersection = M @ M.T          # integer co-occurrence counts, one matmul
    sizes        = M.sum(axis=1)
    union        = sizes[:,None] + sizes[None,:] - intersection
    jaccard      = intersection / union   (0 where union == 0)

replaces the hand-rolled double loop. Connected components of the boolean adjacency
(jaccard >= threshold) are found with an explicit BFS over a numpy boolean matrix —
exactly the transitive closure the PHP union-find produced. numpy ONLY: no
datasketch / sklearn / scipy / networkx is needed or taken (the BFS is a few lines
over the boolean matrix, and the metric is exact integer arithmetic).
"""

from __future__ import annotations

import functools
from typing import Any

import numpy as np

# Mirror the PHP class consts EXACTLY.
SCHEMA_VERSION = "atlas.memory_governance.near_duplicate.v1"
SIMILARITY_PRECISION = 4


def _round(value: float) -> float:
    """Round half away from zero to SIMILARITY_PRECISION, matching PHP round().

    PHP's round() is round-half-away-from-zero; numpy/Python's banker's rounding
    differs on exact .5 ties. Jaccard values here are p/q on small non-negative
    integers, so we replicate PHP's mode explicitly to stay bit-identical."""
    factor = 10**SIMILARITY_PRECISION
    scaled = value * factor
    # round half away from zero (value >= 0 for a Jaccard, but keep it general)
    rounded = np.floor(scaled + 0.5) if scaled >= 0 else np.ceil(scaled - 0.5)
    return float(rounded) / factor


def _clean_tokens(tokens: Any) -> list[str]:
    """Trim each token, drop empties — the PHP cleanTokens() contract."""
    if not isinstance(tokens, list):
        return []
    clean: list[str] = []
    for token in tokens:
        trimmed = str(token).strip()
        if trimmed == "":
            continue
        clean.append(trimmed)
    return clean


def _shingles(tokens: list[str]) -> set[str]:
    """Contiguous bigram-shingle set; a lone token degrades to a single 1-shingle so
    any non-skipped row owns a non-empty set — the PHP shingles() contract."""
    count = len(tokens)
    if count == 1:
        return {tokens[0]}
    shingles: set[str] = set()
    for i in range(count - 1):
        shingles.add(tokens[i] + "|" + tokens[i + 1])
    return shingles


def _id_of(row: dict[str, Any]) -> int | str:
    """Preserve PHP idOf(): ints stay ints, everything else stringifies."""
    raw = row.get("id", "")
    if isinstance(raw, bool):
        return str(raw)
    if isinstance(raw, int):
        return raw
    return str(raw)


def _group_key(memory_type: str, scope: str) -> str:
    return memory_type + "::" + scope


def _normalize_rows(rows: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """Mirror PHP normalizeRows(): clean tokens, skip empty, build the shingle set,
    carry the ranking fields. Order is preserved (the PHP appends in input order)."""
    valid: list[dict[str, Any]] = []
    for row in rows:
        tokens = _clean_tokens(row.get("tokens", []))
        if not tokens:
            continue
        valid.append(
            {
                "id": _id_of(row),
                "shingles": _shingles(tokens),
                "memory_type": str(row.get("memory_type", "")),
                "scope": str(row.get("scope", "")),
                "priority": int(row.get("priority", 0) or 0),
                "importance": int(row.get("importance", 0) or 0),
                "recency": int(row.get("recency", 0) or 0),
            }
        )
    return valid


def _php_strcmp(a: int | str, b: int | str) -> int:
    """Byte-wise comparison of the STRING forms, matching PHP strcmp((string)$id).

    PHP sorts ids and breaks canonical/member ties by strcmp on the string cast, so
    '10' < '11' < '2' (lexicographic, NOT numeric). We must reproduce that to be
    behaviour-identical for integer ids too."""
    sa, sb = str(a), str(b)
    if sa < sb:
        return -1
    if sa > sb:
        return 1
    return 0


def _outranks(candidate: dict[str, Any], incumbent: dict[str, Any]) -> bool:
    """PHP outranks(): priority -> importance -> recency -> smallest id (strcmp)."""
    if candidate["priority"] != incumbent["priority"]:
        return candidate["priority"] > incumbent["priority"]
    if candidate["importance"] != incumbent["importance"]:
        return candidate["importance"] > incumbent["importance"]
    if candidate["recency"] != incumbent["recency"]:
        return candidate["recency"] > incumbent["recency"]
    return _php_strcmp(candidate["id"], incumbent["id"]) < 0


def _canonical_index(group: list[dict[str, Any]], indices: list[int]) -> int:
    """PHP canonicalIndex(): the highest-ranked member of the cluster."""
    best = indices[0]
    for index in indices:
        if _outranks(group[index], group[best]):
            best = index
    return best


def _jaccard_matrix(group: list[dict[str, Any]]) -> np.ndarray:
    """Vectorised pairwise contiguous-bigram Jaccard over a (memory_type, scope)
    group. Returns an (n x n) float matrix; entry [i,j] is the Jaccard of rows i and
    j (0.0 when both shingle sets are empty / union is 0 — the PHP jaccard() guard).

    This is the numpy replacement for the removed PHP O(n^2) double loop:
    one membership matmul instead of n*(n-1)/2 hand-rolled set intersections."""
    n = len(group)
    if n == 0:
        return np.zeros((0, 0), dtype=float)

    # Build the group's shingle vocabulary -> column index.
    vocab: dict[str, int] = {}
    for member in group:
        for shingle in member["shingles"]:
            if shingle not in vocab:
                vocab[shingle] = len(vocab)

    v = len(vocab)
    if v == 0:
        # No shingles at all in the group -> every pair has union 0 -> Jaccard 0.
        return np.zeros((n, n), dtype=float)

    # Boolean membership matrix M (n x v): M[i, c] == 1 iff row i contains shingle c.
    membership = np.zeros((n, v), dtype=np.float64)
    for i, member in enumerate(group):
        for shingle in member["shingles"]:
            membership[i, vocab[shingle]] = 1.0

    # intersection[i,j] = |S_i ∩ S_j| via one matmul of the boolean rows.
    intersection = membership @ membership.T
    sizes = membership.sum(axis=1)
    union = sizes[:, None] + sizes[None, :] - intersection

    with np.errstate(divide="ignore", invalid="ignore"):
        jaccard = np.where(union > 0, intersection / union, 0.0)

    return jaccard


def _connected_components(adjacency: np.ndarray) -> list[list[int]]:
    """Connected components of a symmetric boolean adjacency matrix via BFS — the
    transitive closure the PHP union-find produced. Returns each component as a
    sorted list of node indices. Singletons (no qualifying link) are included; the
    caller drops components of size < 2, exactly like the PHP."""
    n = adjacency.shape[0]
    seen = np.zeros(n, dtype=bool)
    components: list[list[int]] = []
    for start in range(n):
        if seen[start]:
            continue
        stack = [start]
        seen[start] = True
        component = [start]
        while stack:
            node = stack.pop()
            # neighbours linked to `node` and not yet visited
            neighbours = np.nonzero(adjacency[node] & ~seen)[0]
            for nb in neighbours:
                seen[nb] = True
                component.append(int(nb))
                stack.append(int(nb))
        components.append(sorted(component))
    return components


def _build_cluster(
    group: list[dict[str, Any]],
    indices: list[int],
    jaccard: np.ndarray,
    threshold: float,
) -> dict[str, Any]:
    """Mirror PHP buildCluster(): sorted member ids (strcmp), canonical by rank,
    qualifying pairs (i<j, sim>=threshold) sorted by (a,b) strcmp, max similarity,
    merge candidates = members minus canonical. All similarities 4-dp rounded."""
    index_set = set(indices)

    member_ids = [group[i]["id"] for i in indices]
    member_ids = sorted(member_ids, key=_StrcmpKey)

    canonical_index = _canonical_index(group, indices)
    canonical_id = group[canonical_index]["id"]

    pairs: list[dict[str, Any]] = []
    max_similarity = 0.0
    # Enumerate qualifying pairs within this component, lower-triangular i<j to match
    # the PHP loop's (i,j) emission with i<j.
    sorted_idx = sorted(index_set)
    for a_pos in range(len(sorted_idx)):
        for b_pos in range(a_pos + 1, len(sorted_idx)):
            i = sorted_idx[a_pos]
            j = sorted_idx[b_pos]
            sim = float(jaccard[i, j])
            if sim >= threshold:
                rounded = _round(sim)
                pairs.append(
                    {
                        "a": group[i]["id"],
                        "b": group[j]["id"],
                        "similarity": rounded,
                    }
                )
                if rounded > max_similarity:
                    max_similarity = rounded

    pairs.sort(key=lambda p: (str(p["a"]), str(p["b"])))

    merge_candidate_ids = [
        m for m in member_ids if str(m) != str(canonical_id)
    ]

    return {
        "key": _group_key(
            group[canonical_index]["memory_type"], group[canonical_index]["scope"]
        ),
        "member_ids": member_ids,
        "canonical_id": canonical_id,
        "merge_candidate_ids": merge_candidate_ids,
        "pairs": pairs,
        "max_similarity": _round(max_similarity),
    }


class _StrcmpKey:
    """Sort key that orders by the PHP strcmp((string)$x) byte comparison."""

    __slots__ = ("value",)

    def __init__(self, value: int | str) -> None:
        self.value = str(value)

    def __lt__(self, other: "_StrcmpKey") -> bool:
        return self.value < other.value


def _clusters_for_group(
    group: list[dict[str, Any]], threshold: float
) -> list[dict[str, Any]]:
    """PHP clustersForGroup(): score every pair (vectorised), link >=threshold,
    transitive-closure into components of size >= 2, build each cluster."""
    size = len(group)
    if size < 2:
        return []

    jaccard = _jaccard_matrix(group)

    # boolean adjacency for the qualifying pairs (symmetric, diagonal irrelevant)
    adjacency = jaccard >= threshold
    np.fill_diagonal(adjacency, False)

    components = _connected_components(adjacency)

    clusters: list[dict[str, Any]] = []
    for indices in components:
        if len(indices) < 2:
            continue
        clusters.append(_build_cluster(group, indices, jaccard, threshold))
    return clusters


def detect(rows: list[dict[str, Any]], threshold: float = 0.82) -> dict[str, Any]:
    """Detect near-duplicate memory rows via vectorised token-shingle Jaccard.

    Behaviour-identical to the removed PHP MemoryNearDuplicateDetector::detect():
    group by (memory_type, scope); within a group score every pair with contiguous
    bigram-shingle Jaccard; link pairs >= threshold into transitive-closure clusters
    of size >= 2; pick a deterministic canonical (priority -> importance -> recency
    -> smallest id); order clusters by max_similarity desc, then key asc, then
    canonical_id asc. Rows with empty/whitespace-only tokens are skipped.
    """
    threshold = float(threshold)
    valid = _normalize_rows(rows)

    # Group by (memory_type, scope), preserving first-seen group order.
    groups: dict[str, list[dict[str, Any]]] = {}
    for row in valid:
        key = _group_key(row["memory_type"], row["scope"])
        groups.setdefault(key, []).append(row)

    clusters: list[dict[str, Any]] = []
    for group in groups.values():
        clusters.extend(_clusters_for_group(group, threshold))

    # Order: max_similarity DESC, key ASC, canonical_id ASC (strcmp). Mirrors the
    # PHP usort comparator [right.max, left.key, left.canon] <=> [left.max, right.key, right.canon].
    clusters.sort(
        key=lambda c: (
            -c["max_similarity"],
            c["key"],
            str(c["canonical_id"]),
        )
    )

    duplicate_count = sum(len(c["merge_candidate_ids"]) for c in clusters)

    return {
        "schema_version": SCHEMA_VERSION,
        "threshold": threshold,
        "evaluated": len(valid),
        "clusters": clusters,
        "cluster_count": len(clusters),
        "duplicate_count": duplicate_count,
        "boundary": _boundary(),
    }


def _boundary() -> dict[str, Any]:
    # The self-declared boundary proof the kernel verifies: this IS the real
    # in-Python numpy near-duplicate engine, not a PHP hand-rolled stand-in.
    return {
        "near_duplicate_in_python": True,
        "php_adapter_only": True,
        "real_jaccard": True,
        "fabricated": False,
        "library": "numpy",
        "numpy_version": getattr(np, "__version__", "unknown"),
    }


def probe() -> dict[str, Any]:
    """Report that the real numpy engine is importable, honestly."""
    try:
        import numpy  # noqa: F401

        return {"available": True, "library": "numpy", "numpy_version": numpy.__version__}
    except Exception as exc:  # pragma: no cover - environment failure surface
        return {"available": False, "reason": f"{type(exc).__name__}: {exc}"}
