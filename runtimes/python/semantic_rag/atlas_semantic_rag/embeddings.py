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


class Embedder(Protocol):
    name: str
    model: str
    dim: int

    def embed(self, texts: Sequence[str]) -> np.ndarray: ...


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

    def __init__(self, model: str = "BAAI/bge-small-en-v1.5") -> None:
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
    order = [FastEmbedEmbedder, OpenAIEmbedder] if prefer_local else [OpenAIEmbedder, FastEmbedEmbedder]
    errors: list[str] = []
    for cls in order:
        try:
            return cls(model) if model else cls()  # type: ignore[call-arg]
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
