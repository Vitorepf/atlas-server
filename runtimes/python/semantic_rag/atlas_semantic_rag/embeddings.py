"""Real embedding providers for the Atlas semantic/RAG data runtime.

Canon (atlas-ai-runtime-language-boundaries.md + SemanticNotesPythonContract):
embeddings run in PYTHON, never in the PHP kernel. This module produces REAL
learned vector embeddings from a real model — NEVER a hash signature or a
TF-IDF token bag. If no real provider is available it raises
``NoEmbeddingProviderError`` (an honest hard failure), so a caller can degrade
*explicitly* — it never silently substitutes a fake vector.

Providers, in sovereignty order (local first):
  1. FastEmbedEmbedder  — local ONNX model (BAAI/bge-small-en-v1.5), offline,
     no data leaves the machine. Preferred for sensitive/secret classes.
  2. OpenAIEmbedder     — OpenAI `text-embedding-3-small` (1536-d), real model
     over the API. Used when a key is present and the local model is absent.

MAXA-09 additionally exposes a local FastEmbed late-interaction reranker
(ColBERT-family token embeddings) for already-shortlisted top-K windows.

Both return L2-normalised float32 vectors so cosine == dot product downstream.
"""

from __future__ import annotations

import os
from dataclasses import dataclass
from typing import Protocol, Sequence

import numpy as np


class NoEmbeddingProviderError(RuntimeError):
    """Raised when NO real embedding model is available.

    This is deliberate: the runtime refuses to fabricate vectors (no hash, no
    TF-IDF fallback). The boundary contract requires a real embeddings engine;
    if none can be resolved the caller must handle the explicit failure.
    """


@dataclass(frozen=True)
class EmbeddingResult:
    vectors: np.ndarray  # shape (n, dim), float32, L2-normalised
    model: str
    provider: str
    dim: int


@dataclass(frozen=True)
class LateInteractionRerankResult:
    matches: list[dict[str, float | str]]
    model: str
    provider: str
    dim: int


class Embedder(Protocol):
    name: str
    model: str
    dim: int

    def embed(self, texts: Sequence[str]) -> np.ndarray: ...


_EMBEDDER_CACHE: dict[tuple[str, str | None], Embedder] = {}

_LATE_INTERACTION_CACHE: dict[str, "FastEmbedLateInteractionReranker"] = {}


def _l2_normalise(matrix: np.ndarray) -> np.ndarray:
    matrix = np.asarray(matrix, dtype=np.float32)
    if matrix.ndim == 1:
        matrix = matrix.reshape(1, -1)
    norms = np.linalg.norm(matrix, axis=1, keepdims=True)
    norms[norms == 0.0] = 1.0
    return (matrix / norms).astype(np.float32)


class FastEmbedEmbedder:
    """Local, offline real embeddings via fastembed (ONNX). Sovereign default."""

    name = "fastembed_local"

    def __init__(self, model: str = "sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2") -> None:
        # Multilingual by default (~50 languages incl. Portuguese — the Atlas corpus is
        # PT + mixed). 384-d like bge-small, so the vector store / pgvector column dim is
        # unchanged. fastembed-supported; no query/passage prefix required.
        try:
            from fastembed import TextEmbedding  # type: ignore
        except Exception as exc:  # pragma: no cover - import guard
            raise NoEmbeddingProviderError(f"fastembed unavailable: {exc}") from exc
        self.model = model
        self._engine = TextEmbedding(model_name=model)
        # bge-small is 384-d; probe once so dim is real, not assumed.
        probe = next(iter(self._engine.embed(["dim_probe"])))
        self.dim = int(np.asarray(probe).reshape(-1).shape[0])

    def embed(self, texts: Sequence[str]) -> np.ndarray:
        vectors = list(self._engine.embed(list(texts)))
        return _l2_normalise(np.asarray(vectors, dtype=np.float32))


class FastEmbedLateInteractionReranker:
    """Local, offline ColBERT-family reranker via fastembed late interaction."""

    name = "fastembed_late_interaction"

    def __init__(self, model: str | None = None) -> None:
        try:
            from fastembed import LateInteractionTextEmbedding  # type: ignore
        except Exception as exc:  # pragma: no cover - import guard
            raise NoEmbeddingProviderError(f"fastembed late-interaction unavailable: {exc}") from exc

        self.model = model or os.environ.get(
            "ATLAS_SEMANTIC_RAG_LATE_INTERACTION_MODEL",
            "answerdotai/answerai-colbert-small-v1",
        )
        self._engine = LateInteractionTextEmbedding(model_name=self.model)
        self.dim = int(getattr(self._engine, "embedding_size", 0) or self._engine.get_embedding_size(self.model))

    def rerank(self, query: str, documents: Sequence[dict[str, str]], k: int = 5) -> LateInteractionRerankResult:
        query_tokens = np.asarray(next(iter(self._engine.query_embed([query]))), dtype=np.float32)
        passage_vectors = list(self._engine.passage_embed([str(doc.get("text", "")) for doc in documents]))

        raw: list[tuple[str, float]] = []
        for doc, passage_tokens in zip(documents, passage_vectors, strict=False):
            doc_id = str(doc.get("id", ""))
            if not doc_id:
                continue
            passage = np.asarray(passage_tokens, dtype=np.float32)
            if query_tokens.size == 0 or passage.size == 0:
                score = 0.0
            else:
                # ColBERT MaxSim: each query token keeps its strongest passage-token match.
                score = float(np.max(query_tokens @ passage.T, axis=1).mean())
            raw.append((doc_id, score))

        raw.sort(key=lambda row: (-row[1], row[0]))
        selected = raw[: max(1, int(k))]
        values = [score for _, score in selected]
        min_score = min(values) if values else 0.0
        max_score = max(values) if values else 0.0

        matches: list[dict[str, float | str]] = []
        for doc_id, score in selected:
            if max_score > min_score:
                normalized = (score - min_score) / (max_score - min_score)
            else:
                normalized = 1.0 if values else 0.0
            matches.append({
                "id": doc_id,
                "score": float(round(max(0.0, min(1.0, normalized)), 6)),
                "via": "late_interaction",
            })

        return LateInteractionRerankResult(
            matches=matches,
            model=self.model,
            provider=self.name,
            dim=self.dim,
        )


class OpenAIEmbedder:
    """Real embeddings via OpenAI text-embedding-3-small (API, not local)."""

    name = "openai_api"

    def __init__(self, model: str = "text-embedding-3-small") -> None:
        key = os.environ.get("OPENAI_API_KEY")
        if not key:
            raise NoEmbeddingProviderError("OPENAI_API_KEY not set")
        try:
            from openai import OpenAI  # type: ignore
        except Exception as exc:  # pragma: no cover - import guard
            raise NoEmbeddingProviderError(f"openai sdk unavailable: {exc}") from exc
        self.model = model
        self._client = OpenAI(api_key=key)
        self.dim = 1536  # text-embedding-3-small native dim

    def embed(self, texts: Sequence[str]) -> np.ndarray:
        resp = self._client.embeddings.create(model=self.model, input=list(texts))
        vectors = [item.embedding for item in resp.data]
        return _l2_normalise(np.asarray(vectors, dtype=np.float32))


def resolve_embedder(prefer_local: bool = True, model: str | None = None) -> Embedder:
    """Return the first available REAL embedder, or raise NoEmbeddingProviderError.

    Order honours sovereignty: local (fastembed) before the API (openai). NEVER
    returns a fake/hash embedder — absence is an explicit failure.
    """
    cache_key = ("local_first" if prefer_local else "api_first", model)
    if cache_key in _EMBEDDER_CACHE:
        return _EMBEDDER_CACHE[cache_key]

    order = [FastEmbedEmbedder, OpenAIEmbedder] if prefer_local else [OpenAIEmbedder, FastEmbedEmbedder]
    errors: list[str] = []
    for cls in order:
        try:
            embedder = cls(model) if model else cls()  # type: ignore[call-arg]
            _EMBEDDER_CACHE[cache_key] = embedder

            return embedder
        except NoEmbeddingProviderError as exc:
            errors.append(f"{cls.__name__}: {exc}")
    raise NoEmbeddingProviderError(
        "no real embedding provider available (refusing to fabricate vectors). Tried: "
        + " | ".join(errors)
    )


def embed_texts(texts: Sequence[str], prefer_local: bool = True) -> EmbeddingResult:
    embedder = resolve_embedder(prefer_local=prefer_local)
    vectors = embedder.embed(texts)
    return EmbeddingResult(
        vectors=vectors,
        model=embedder.model,
        provider=embedder.name,
        dim=embedder.dim,
    )


def resolve_late_interaction_reranker(model: str | None = None) -> FastEmbedLateInteractionReranker:
    model_key = model or os.environ.get(
        "ATLAS_SEMANTIC_RAG_LATE_INTERACTION_MODEL",
        "answerdotai/answerai-colbert-small-v1",
    )
    if model_key not in _LATE_INTERACTION_CACHE:
        _LATE_INTERACTION_CACHE[model_key] = FastEmbedLateInteractionReranker(model_key)

    return _LATE_INTERACTION_CACHE[model_key]


def late_interaction_rerank(
    query: str,
    documents: Sequence[dict[str, str]],
    k: int = 5,
) -> LateInteractionRerankResult:
    reranker = resolve_late_interaction_reranker()
    return reranker.rerank(query, documents, k)
