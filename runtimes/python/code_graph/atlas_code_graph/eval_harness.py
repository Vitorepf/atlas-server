"""Graph precision/recall/F1 evaluation harness (AP-815 Q-2, python_ai_data).

THE block that turns "precise" from a claim into a measured number: given a
`predicted` edge set and a `gold` (ground-truth) edge set, compute set-based
precision / recall / F1 plus the raw tp / fp / fn counts. This is the quality
keystone for the code-graph extractor — every time CodeGraphEdgeResolver (or any
future extractor) is changed, this op produces an auditable score against a
frozen gold set instead of a hand-wavy "it looks more precise now".

Per atlas-ai-runtime-language-boundaries.md, this set arithmetic over edge
collections lives in the python_ai_data runtime (the MUSCLE). It is advisory /
read-model only: it measures, it never decides provider/model/domain/policy, and
is not promoted to production until human review (runtime_promotion_policy.v1).

Edge shape — both forms are accepted and normalised to the same identity:
  - dict:  {"from": str, "to": str, "type"?: str}
           the runtime-native {"from_node_id", "to_node_id", "edge_type"} keys
           are ALSO accepted (so this op composes with the rest of the runtime),
           "type"/"edge_type" defaulting to "".
  - tuple/list: [from, to] or [from, to, type] (type defaults to "").

Edge identity = the normalised, whitespace-trimmed (from, to, type) triple.
Duplicate edges within a set collapse to one (set semantics) so a duplicated
prediction can neither inflate nor deflate the score. Comparison is exact and
case-sensitive — a type mismatch makes two otherwise-equal edges distinct and is
therefore counted as a miss.

Pure stdlib, fully deterministic, fail-safe: malformed edges are skipped (never
raise), and every division is guarded (0.0 when the denominator is 0).
"""

from __future__ import annotations

from typing import Any, Iterable, Optional

SCHEMA = "atlas.code_graph.eval_harness.v1"

# Accepted key aliases, in priority order, so the harness reads both the literal
# {from,to,type} contract and the runtime-native {from_node_id,...} edges.
_FROM_KEYS = ("from", "from_node_id", "source", "src")
_TO_KEYS = ("to", "to_node_id", "target", "dst")
_TYPE_KEYS = ("type", "edge_type", "rel", "relation")


def _coerce_str(value: Any) -> str:
    """Return a whitespace-trimmed string for str/number inputs, else ``""``."""
    if isinstance(value, str):
        return value.strip()
    if isinstance(value, bool):
        # bool is an int subclass; never a valid node id — treat as empty.
        return ""
    if isinstance(value, (int, float)):
        return str(value).strip()
    return ""


def _first_key(edge: dict, keys: tuple[str, ...]) -> Any:
    for key in keys:
        if key in edge:
            return edge[key]
    return None


def _normalize_edge(edge: Any) -> Optional[tuple[str, str, str]]:
    """Normalise one edge to a ``(from, to, type)`` identity tuple.

    Returns ``None`` for any malformed edge (missing/blank endpoints, wrong
    shape) so the caller can skip it without raising. ``type`` defaults to ``""``
    and all three components are whitespace-trimmed.
    """
    if isinstance(edge, dict):
        a = _coerce_str(_first_key(edge, _FROM_KEYS))
        b = _coerce_str(_first_key(edge, _TO_KEYS))
        t = _coerce_str(_first_key(edge, _TYPE_KEYS))
    elif isinstance(edge, (tuple, list)):
        # [from, to] or [from, to, type]; extra elements are ignored.
        a = _coerce_str(edge[0]) if len(edge) >= 1 else ""
        b = _coerce_str(edge[1]) if len(edge) >= 2 else ""
        t = _coerce_str(edge[2]) if len(edge) >= 3 else ""
    else:
        return None

    if not a or not b:
        return None
    return (a, b, t)


def _normalize_set(edges: Any) -> set[tuple[str, str, str]]:
    """Normalise an iterable of edges into a set of identity tuples.

    Non-iterable / ``None`` input yields an empty set (fail-safe). Malformed
    individual edges are silently skipped.
    """
    out: set[tuple[str, str, str]] = set()
    if edges is None or isinstance(edges, (str, bytes, dict)):
        # A bare dict/str is not a *collection* of edges — treat as empty.
        return out
    if not isinstance(edges, Iterable):
        return out
    for edge in edges:
        norm = _normalize_edge(edge)
        if norm is not None:
            out.add(norm)
    return out


def _prf(tp: int, fp: int, fn: int) -> tuple[float, float, float]:
    """Precision, recall and harmonic-mean F1, each guarded against /0."""
    precision = tp / (tp + fp) if (tp + fp) > 0 else 0.0
    recall = tp / (tp + fn) if (tp + fn) > 0 else 0.0
    f1 = (
        2.0 * precision * recall / (precision + recall)
        if (precision + recall) > 0
        else 0.0
    )
    return precision, recall, f1


def evaluate(predicted: Any, gold: Any) -> dict:
    """Score a predicted edge set against a gold (ground-truth) edge set.

    Args:
        predicted: iterable of edges (dict or tuple/list form) the extractor
            produced.
        gold: iterable of edges representing ground truth.

    Returns a dict::

        {
          "schema_version": str,
          "precision": float,   # tp / (tp + fp), 0.0 if denom 0
          "recall":    float,   # tp / (tp + fn), 0.0 if denom 0
          "f1":        float,   # harmonic mean, 0.0 if denom 0
          "tp": int, "fp": int, "fn": int,
          "predicted": int,     # distinct normalised predicted edges
          "gold": int,          # distinct normalised gold edges
        }

    Fail-safe: malformed / non-iterable inputs degrade to empty sets, every
    division is guarded, and the function never raises on bad input.
    """
    pred_set = _normalize_set(predicted)
    gold_set = _normalize_set(gold)

    tp_set = pred_set & gold_set
    tp = len(tp_set)
    fp = len(pred_set - gold_set)
    fn = len(gold_set - pred_set)

    precision, recall, f1 = _prf(tp, fp, fn)

    return {
        "schema_version": SCHEMA,
        "precision": round(precision, 6),
        "recall": round(recall, 6),
        "f1": round(f1, 6),
        "tp": tp,
        "fp": fp,
        "fn": fn,
        "predicted": len(pred_set),
        "gold": len(gold_set),
    }


def evaluate_by_type(predicted: Any, gold: Any) -> dict:
    """Per-edge-type precision/recall/F1, plus the overall (micro) score.

    Buckets both edge sets by their normalised ``type`` component and runs the
    same set arithmetic within each bucket, so you can see *which* relation
    types the extractor is precise/lossy on. The ``"overall"`` entry is the
    micro-averaged score across all types (identical to :func:`evaluate`).

    Returns::

        {
          "schema_version": str,
          "overall": {<same shape as evaluate(...)>},
          "by_type": {
            "<type>": {"precision","recall","f1","tp","fp","fn",
                       "predicted","gold"},
            ...
          },
        }

    Edges whose type is ``""`` are bucketed under ``""``. Deterministic: the
    ``by_type`` map is built from a sorted key set. Fail-safe like
    :func:`evaluate`.
    """
    pred_set = _normalize_set(predicted)
    gold_set = _normalize_set(gold)

    pred_by_type: dict[str, set[tuple[str, str, str]]] = {}
    gold_by_type: dict[str, set[tuple[str, str, str]]] = {}
    for edge in pred_set:
        pred_by_type.setdefault(edge[2], set()).add(edge)
    for edge in gold_set:
        gold_by_type.setdefault(edge[2], set()).add(edge)

    by_type: dict[str, dict] = {}
    for etype in sorted(set(pred_by_type) | set(gold_by_type)):
        p = pred_by_type.get(etype, set())
        g = gold_by_type.get(etype, set())
        tp = len(p & g)
        fp = len(p - g)
        fn = len(g - p)
        precision, recall, f1 = _prf(tp, fp, fn)
        by_type[etype] = {
            "precision": round(precision, 6),
            "recall": round(recall, 6),
            "f1": round(f1, 6),
            "tp": tp,
            "fp": fp,
            "fn": fn,
            "predicted": len(p),
            "gold": len(g),
        }

    return {
        "schema_version": SCHEMA,
        "overall": evaluate(predicted, gold),
        "by_type": by_type,
    }
