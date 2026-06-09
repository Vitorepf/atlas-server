"""Hybrid relevance ranker for context assembly (AP-815 E-6, python_ai_data).

The MUSCLE that ranks a handed-in list of candidate context items against a
query, combining several precomputed signals into one auditable score. This is
the heavy/data-shaped work that, per atlas-ai-runtime-language-boundaries.md,
belongs in the python_ai_data runtime rather than the Laravel Kernel — the
Kernel hands over the candidates (text + precomputed centrality/recency) and
this op returns the weighted ranking; it NEVER decides provider/model/domain/
policy and is not promoted to production until human review
(runtime_promotion_policy.v1).

Pure stdlib, fully deterministic (sorted traversal + id tie-break). No external
embedding model is required: a lexical BM25-ish / token-overlap signal is the
mandatory backbone. A semantic signal is added ONLY if `fastembed` is already
importable in the runtime — otherwise it is omitted gracefully and the test
suite never depends on it (sovereignty: new deps need human approval).

Input candidates: [{"id": str, "text": str,
                    "centrality"?: float (0..1), "recency"?: float (0..1)}, ...]
Output: the candidates sorted by combined score DESC (id tie-break ASC), each
annotated with {"score": float, "signals": {"lexical", "centrality", "recency"
[, "semantic"]}}.
"""

from __future__ import annotations

import math
import re
from typing import Any, Dict, List, Mapping, Optional, Sequence

SCHEMA = "atlas.code_graph.hybrid_ranker.v1"

# Default blend. Kept explicit and additive so the ranking is auditable: every
# contributing signal is reported per candidate under `signals`.
_DEFAULT_WEIGHTS: Dict[str, float] = {
    "lexical": 0.5,
    "centrality": 0.3,
    "recency": 0.2,
}

# BM25 free parameters (Robertson/Sparck-Jones). k1 controls term-frequency
# saturation, b controls document-length normalization. Standard defaults.
_BM25_K1 = 1.5
_BM25_B = 0.75

_TOKEN_RE = re.compile(r"[A-Za-z0-9_]+")

# Resolved lazily, once, on first rank() call: True/False whether fastembed is
# importable. We never import it at module top level so importing this module on
# the system python (no fastembed) stays cheap and safe.
_FASTEMBED_AVAILABLE: Optional[bool] = None


def _tokenize(text: Any) -> List[str]:
    """Lowercased alphanumeric/underscore tokens. Fail-safe on non-str."""
    if not isinstance(text, str):
        return []
    return _TOKEN_RE.findall(text.lower())


def _clamp01(value: Any) -> float:
    """Coerce to a finite float clamped to [0, 1]; non-numeric -> 0.0."""
    try:
        f = float(value)
    except (TypeError, ValueError):
        return 0.0
    if math.isnan(f) or math.isinf(f):
        return 0.0
    if f < 0.0:
        return 0.0
    if f > 1.0:
        return 1.0
    return f


def _candidate_id(value: Any, fallback: str) -> str:
    """Best-effort string id; falls back to a positional id when missing."""
    if isinstance(value, str):
        trimmed = value.strip()
        if trimmed:
            return trimmed
    if value is not None and not isinstance(value, (dict, list)):
        return str(value)
    return fallback


def _fastembed_available() -> bool:
    """Whether fastembed can be imported in this runtime (memoized).

    Optional semantic signal only — never installed here. If unavailable the
    ranker degrades to lexical+centrality+recency with no error.
    """
    global _FASTEMBED_AVAILABLE
    if _FASTEMBED_AVAILABLE is None:
        try:  # pragma: no cover - exercised only where fastembed is installed
            import fastembed  # noqa: F401

            _FASTEMBED_AVAILABLE = True
        except Exception:  # noqa: BLE001 - any import failure -> omit signal
            _FASTEMBED_AVAILABLE = False
    return _FASTEMBED_AVAILABLE


def _bm25_lexical_scores(
    query_tokens: Sequence[str],
    docs_tokens: Sequence[List[str]],
) -> List[float]:
    """BM25-ish relevance of each doc vs the query, normalized to [0, 1].

    Standard Okapi BM25 with IDF over the candidate set as the corpus. The raw
    per-doc score is divided by the maximum achievable score for the query
    (every query term saturated, average-length doc), giving a stable 0..1
    signal that does not depend on corpus size. Deterministic, pure stdlib.
    """
    n_docs = len(docs_tokens)
    if n_docs == 0:
        return []

    query_terms = list(dict.fromkeys(t for t in query_tokens if t))
    if not query_terms:
        return [0.0] * n_docs

    doc_lengths = [len(d) for d in docs_tokens]
    total_len = sum(doc_lengths)
    avgdl = (total_len / n_docs) if n_docs else 0.0

    # Document frequency per query term (how many docs contain it at least once).
    doc_freq: Dict[str, int] = {}
    doc_term_freqs: List[Dict[str, int]] = []
    for tokens in docs_tokens:
        tf: Dict[str, int] = {}
        for tok in tokens:
            tf[tok] = tf.get(tok, 0) + 1
        doc_term_freqs.append(tf)
        for term in query_terms:
            if term in tf:
                doc_freq[term] = doc_freq.get(term, 0) + 1

    # BM25 IDF with the +1 inside the log so it is always >= 0 (no negative IDF
    # for terms appearing in more than half the corpus).
    idf: Dict[str, float] = {}
    for term in query_terms:
        df = doc_freq.get(term, 0)
        idf[term] = math.log(1.0 + (n_docs - df + 0.5) / (df + 0.5))

    def _score(tf: Mapping[str, int], dl: int) -> float:
        if dl <= 0:
            return 0.0
        denom_len = _BM25_B * (dl / avgdl) if avgdl > 0 else _BM25_B
        score = 0.0
        for term in query_terms:
            freq = tf.get(term, 0)
            if freq <= 0:
                continue
            numer = freq * (_BM25_K1 + 1.0)
            denom = freq + _BM25_K1 * (1.0 - _BM25_B + denom_len)
            if denom > 0:
                score += idf[term] * (numer / denom)
        return score

    # Per-query saturation ceiling: every query term present once in an
    # average-length doc, fully IDF-weighted. Used only to normalize to [0, 1].
    ceiling = 0.0
    if avgdl > 0:
        sat = (1.0 * (_BM25_K1 + 1.0)) / (
            1.0 + _BM25_K1 * (1.0 - _BM25_B + _BM25_B * 1.0)
        )
        ceiling = sum(idf[term] for term in query_terms) * sat
    if ceiling <= 0:
        return [0.0] * n_docs

    raw = [_score(doc_term_freqs[i], doc_lengths[i]) for i in range(n_docs)]
    return [min(1.0, r / ceiling) for r in raw]


def _resolve_weights(weights: Optional[Mapping[str, Any]]) -> Dict[str, float]:
    """Merge caller weights over defaults; coerce to finite non-negative floats.

    Unknown keys are kept (so a future signal weight passes through) but only
    signals actually computed contribute to the score.
    """
    resolved = dict(_DEFAULT_WEIGHTS)
    if isinstance(weights, Mapping):
        for key, value in weights.items():
            if not isinstance(key, str):
                continue
            try:
                f = float(value)
            except (TypeError, ValueError):
                continue
            if math.isnan(f) or math.isinf(f) or f < 0.0:
                continue
            resolved[key] = f
    return resolved


def rank(
    query: Any,
    candidates: Any,
    weights: Optional[Mapping[str, Any]] = None,
) -> List[Dict[str, Any]]:
    """Rank `candidates` against `query` by a weighted blend of signals.

    Args:
        query: free-text query string (non-str is treated as empty).
        candidates: list of dicts, each
            {"id": str, "text": str,
             "centrality"?: float (0..1), "recency"?: float (0..1)}.
        weights: optional override of the signal weights; defaults to
            {"lexical": 0.5, "centrality": 0.3, "recency": 0.2}. A "semantic"
            weight is honoured only when the semantic signal is available.

    Returns:
        The candidates (as new dicts) sorted by combined score DESC, ties broken
        by id ASC. Each is annotated with:
            {"score": float, "signals": {"lexical", "centrality", "recency"
             [, "semantic"]}}.
        Malformed input never raises — it yields safe defaults (e.g. empty
        candidates -> []).
    """
    if not isinstance(candidates, Sequence) or isinstance(candidates, (str, bytes)):
        return []

    items: List[Dict[str, Any]] = [c for c in candidates if isinstance(c, dict)]
    if not items:
        return []

    query_tokens = _tokenize(query)
    docs_tokens = [_tokenize(item.get("text")) for item in items]
    lexical_scores = _bm25_lexical_scores(query_tokens, docs_tokens)

    resolved_weights = _resolve_weights(weights)

    # Optional semantic signal. Only attempt when fastembed is importable AND a
    # caller weight asks for it; computation itself is also fail-safe.
    semantic_scores: Optional[List[float]] = None
    if resolved_weights.get("semantic", 0.0) > 0.0 and _fastembed_available():
        semantic_scores = _semantic_scores(query, items)  # pragma: no cover

    ranked: List[Dict[str, Any]] = []
    for idx, item in enumerate(items):
        cid = _candidate_id(item.get("id"), f"__candidate_{idx}")
        signals: Dict[str, float] = {
            "lexical": round(lexical_scores[idx], 6),
            "centrality": round(_clamp01(item.get("centrality")), 6),
            "recency": round(_clamp01(item.get("recency")), 6),
        }
        if semantic_scores is not None:
            signals["semantic"] = round(_clamp01(semantic_scores[idx]), 6)

        score = 0.0
        for name, value in signals.items():
            score += resolved_weights.get(name, 0.0) * value

        annotated = dict(item)
        annotated["id"] = cid
        annotated["score"] = round(score, 6)
        annotated["signals"] = signals
        ranked.append(annotated)

    ranked.sort(key=lambda r: (-r["score"], r["id"]))
    return ranked


def _semantic_scores(query: Any, items: List[Dict[str, Any]]) -> List[float]:
    """Cosine similarity of query vs candidate texts via fastembed (optional).

    Only reached when fastembed is importable. Fail-safe: any error yields a
    zero vector so the ranker still returns lexical+centrality+recency.
    """
    try:  # pragma: no cover - requires fastembed, omitted in the default runtime
        from fastembed import TextEmbedding  # type: ignore

        model = TextEmbedding()
        q_text = query if isinstance(query, str) else ""
        texts = [q_text] + [
            it.get("text") if isinstance(it.get("text"), str) else "" for it in items
        ]
        vectors = list(model.embed(texts))
        if not vectors:
            return [0.0] * len(items)
        q_vec = vectors[0]
        out: List[float] = []
        for vec in vectors[1:]:
            out.append(_cosine(q_vec, vec))
        return out
    except Exception:  # noqa: BLE001 - semantic is best-effort only
        return [0.0] * len(items)


def _cosine(a: Sequence[float], b: Sequence[float]) -> float:
    """Cosine similarity mapped to [0, 1]; pure stdlib, fail-safe."""
    try:  # pragma: no cover - only used on the fastembed path
        dot = sum(float(x) * float(y) for x, y in zip(a, b))
        na = math.sqrt(sum(float(x) * float(x) for x in a))
        nb = math.sqrt(sum(float(y) * float(y) for y in b))
        if na <= 0.0 or nb <= 0.0:
            return 0.0
        cos = dot / (na * nb)
        return max(0.0, min(1.0, (cos + 1.0) / 2.0))
    except Exception:  # noqa: BLE001
        return 0.0
