"""Governed SEMANTIC edges via local embeddings (AP-815 P-4, python_ai_data).

Reveals coupling that NEITHER static analysis (imports/AST) NOR temporal
coupling (co_change) can see: two artifacts whose *meaning* is close even when
they never reference each other and never changed together. Two functions both
about "user authentication", a doc and the code it describes, a test and the
behaviour it pins — semantically adjacent, structurally invisible.

This is exactly the heavy, local-model analytic that, per
atlas-ai-runtime-language-boundaries.md, belongs in the python_ai_data runtime
(the muscle): the Kernel hands us ``{id, text}`` nodes and we embed + compare.
Embeddings are LOCAL (fastembed / BAAI/bge-small-en-v1.5 ONNX) — no network
provider, sovereignty preserved. Read-model only: emits structure for the graph,
never decides provider/model/domain/policy, never executes what it reads.

Governed + fail-safe by construction:

* Lazy heavy-dep import INSIDE the function, so module import stays cheap
  (matches pdf_ingest.py / treesitter_extract.py).
* If fastembed import OR model load fails at runtime, returns a well-formed
  EMPTY result with a ``note`` key — it NEVER raises. The graph simply gains no
  semantic edges that run instead of crashing the pipeline.
* Nodes with empty/whitespace text are skipped (no meaningful embedding).
* Deterministic ordering: canonical ``from`` < ``to`` (deduped unordered
  pairs), sorted by (-score, from, to), bounded by ``max_edges``.

NOT promoted to production until human review (runtime_promotion_policy.v1).
"""

from __future__ import annotations

import math
from typing import Any, Dict, List, Tuple

SCHEMA = "atlas.code_graph.semantic.v1"

# Local ONNX embedding model. Small + fast + good enough for code/doc semantics;
# downloaded once (~130MB) on first use, then cached by fastembed.
_MODEL_NAME = "BAAI/bge-small-en-v1.5"

# Edge type for the graph layer. Distinct from imports/co_change so consumers can
# treat semantic adjacency with its own (lower) trust than a static reference.
_EDGE_TYPE = "semantic"


def _clean(value: Any) -> str:
    """Return a trimmed string, or "" for anything non-string/empty."""
    if not isinstance(value, str):
        return ""
    return value.strip()


def _coerce_threshold(threshold: Any) -> float:
    """Clamp ``threshold`` into [-1.0, 1.0]; default 0.78 on bad input."""
    try:
        value = float(threshold)
    except (TypeError, ValueError):
        return 0.78
    if math.isnan(value):
        return 0.78
    if value < -1.0:
        return -1.0
    if value > 1.0:
        return 1.0
    return value


def _coerce_max_edges(max_edges: Any) -> int:
    """Coerce ``max_edges`` to a non-negative int; default 5000 on bad input."""
    try:
        value = int(max_edges)
    except (TypeError, ValueError):
        return 5000
    return max(value, 0)


def _clean_nodes(nodes: Any) -> Tuple[List[str], List[str]]:
    """Extract parallel (id, text) lists from caller nodes, skipping junk.

    A node contributes only when it is a dict with a non-empty string ``id`` and
    a non-empty (post-strip) string ``text``. Duplicate ids keep their first
    occurrence so the pair space stays well-defined. Returns ``([], [])`` for
    non-iterable input — never raises.
    """
    ids: List[str] = []
    texts: List[str] = []
    if not isinstance(nodes, (list, tuple)):
        return ids, texts

    seen = set()
    for node in nodes:
        if not isinstance(node, dict):
            continue
        node_id = _clean(node.get("id"))
        text = _clean(node.get("text"))
        if not node_id or not text or node_id in seen:
            continue
        seen.add(node_id)
        ids.append(node_id)
        texts.append(text)
    return ids, texts


def _cosine(a: List[float], b: List[float]) -> float:
    """Cosine similarity of two equal-length vectors; 0.0 if either is zero."""
    dot = 0.0
    norm_a = 0.0
    norm_b = 0.0
    for x, y in zip(a, b):
        dot += x * y
        norm_a += x * x
        norm_b += y * y
    if norm_a <= 0.0 or norm_b <= 0.0:
        return 0.0
    return dot / (math.sqrt(norm_a) * math.sqrt(norm_b))


def _empty(threshold: float, nodes_count: int, note: str) -> Dict[str, Any]:
    """Well-formed empty result carrying a ``note`` (fail-safe path)."""
    return {
        "schema_version": SCHEMA,
        "edges": [],
        "nodes": nodes_count,
        "threshold": threshold,
        "note": note,
    }


def semantic_edges(
    nodes: Any,
    threshold: float = 0.78,
    max_edges: int = 5000,
) -> Dict[str, Any]:
    """Emit semantic edges between nodes whose embeddings are close enough.

    Each node's ``text`` is embedded with a LOCAL model (BAAI/bge-small-en-v1.5
    via fastembed). For every unordered pair of distinct nodes, cosine
    similarity is computed; a pair with ``cosine >= threshold`` becomes an edge
    in canonical ``from`` < ``to`` direction (deduped), sorted by (-score, from,
    to) and bounded by ``max_edges``.

    Args:
        nodes: list of dicts, each with a string ``id`` and a string ``text``.
            Entries missing either, or with empty text, are skipped.
        threshold: minimum cosine similarity to emit an edge (clamped to
            [-1.0, 1.0]; defaults to 0.78 on bad input).
        max_edges: hard cap on the number of emitted edges (>= 0).

    Returns:
        ``{"schema_version", "edges": [{"from", "to", "score", "edge_type"}],
        "nodes": int, "threshold": float}``. On the fail-safe path (heavy dep
        missing, model load failure, embedding failure) the same shape is
        returned with empty ``edges`` plus a ``note`` key. Never raises.
    """
    threshold = _coerce_threshold(threshold)
    max_edges = _coerce_max_edges(max_edges)

    ids, texts = _clean_nodes(nodes)
    node_count = len(ids)

    # Fewer than two usable nodes => no pair can exist. Succeed quietly (empty).
    if node_count < 2:
        return {
            "schema_version": SCHEMA,
            "edges": [],
            "nodes": node_count,
            "threshold": threshold,
        }

    # Lazy heavy-dep import: keeps module import cheap and lets us degrade to a
    # safe empty result (with a note) if fastembed is absent at runtime.
    try:
        from fastembed import TextEmbedding  # venv-only heavy dep
    except Exception as exc:  # noqa: BLE001 - any import failure must be fail-safe
        return _empty(threshold, node_count, f"fastembed unavailable: {exc!r}")

    try:
        model = TextEmbedding(model_name=_MODEL_NAME)
        # fastembed yields one numpy vector per input, in input order.
        vectors = [list(map(float, vec)) for vec in model.embed(texts)]
    except Exception as exc:  # noqa: BLE001 - model load / embed must be fail-safe
        return _empty(threshold, node_count, f"embedding failed: {exc!r}")

    # Defensive: if the backend returned the wrong count, don't index past it.
    if len(vectors) != node_count:
        return _empty(
            threshold,
            node_count,
            f"embedding count mismatch: got {len(vectors)} for {node_count} nodes",
        )

    edges: List[Dict[str, Any]] = []
    for i in range(node_count):
        for j in range(i + 1, node_count):
            score = _cosine(vectors[i], vectors[j])
            if score < threshold:
                continue
            a, b = ids[i], ids[j]
            if a > b:  # enforce canonical from < to direction
                a, b = b, a
            edges.append(
                {
                    "from": a,
                    "to": b,
                    "score": round(score, 4),
                    "edge_type": _EDGE_TYPE,
                }
            )

    # Deterministic order: strongest semantic adjacency first, lexical for ties.
    edges.sort(key=lambda e: (-e["score"], e["from"], e["to"]))

    if len(edges) > max_edges:
        edges = edges[:max_edges]

    return {
        "schema_version": SCHEMA,
        "edges": edges,
        "nodes": node_count,
        "threshold": threshold,
    }
