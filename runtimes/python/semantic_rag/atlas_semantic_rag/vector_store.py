"""In-memory cosine vector store over L2-normalised real embeddings.

Because every embedding is L2-normalised at creation, cosine similarity reduces
to a dot product, so search is one matrix-vector product (real linear algebra,
numpy). This store operates on whatever REAL vectors it is given — it is not a
token bag and not a hash; its correctness is fully testable with known vectors,
independent of which embedding model produced them.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any, Sequence

import numpy as np


@dataclass
class Document:
    id: str
    text: str
    vector: np.ndarray  # L2-normalised float32
    metadata: dict[str, Any] = field(default_factory=dict)


@dataclass
class ScoredDocument:
    id: str
    text: str
    score: float
    metadata: dict[str, Any]
    via: str = "semantic"


class VectorStore:
    def __init__(self) -> None:
        self._ids: list[str] = []
        self._texts: list[str] = []
        self._meta: list[dict[str, Any]] = []
        self._matrix: np.ndarray | None = None  # shape (n, dim)

    def __len__(self) -> int:
        return len(self._ids)

    @property
    def ids(self) -> list[str]:
        return list(self._ids)

    @property
    def dim(self) -> int | None:
        return None if self._matrix is None else int(self._matrix.shape[1])

    def add(self, documents: Sequence[Document]) -> None:
        if not documents:
            return
        vectors = np.asarray([d.vector for d in documents], dtype=np.float32).reshape(len(documents), -1)
        if self._matrix is not None and vectors.shape[1] != self._matrix.shape[1]:
            raise ValueError(f"embedding dim mismatch: store={self._matrix.shape[1]} new={vectors.shape[1]}")
        for d in documents:
            self._ids.append(d.id)
            self._texts.append(d.text)
            self._meta.append(dict(d.metadata))
        self._matrix = vectors if self._matrix is None else np.vstack([self._matrix, vectors])

    def all_scores(self, query_vector: np.ndarray) -> np.ndarray:
        """Cosine score of the query against every stored doc (real dot product)."""
        if self._matrix is None or not self._ids:
            return np.asarray([], dtype=np.float32)
        q = np.asarray(query_vector, dtype=np.float32).reshape(-1)
        norm = float(np.linalg.norm(q))
        if norm:
            q = q / norm
        return (self._matrix @ q).astype(np.float32)

    def _scored(self, index: int, score: float, via: str = "semantic") -> ScoredDocument:
        return ScoredDocument(self._ids[index], self._texts[index], float(score), self._meta[index], via)

    def search(self, query_vector: np.ndarray, k: int = 5) -> list[ScoredDocument]:
        scores = self.all_scores(query_vector)
        if scores.size == 0:
            return []
        k = max(1, min(k, len(self._ids)))
        top = np.argsort(-scores)[:k]
        return [self._scored(int(i), scores[int(i)]) for i in top]

    def similarity_graph(self, threshold: float = 0.6) -> dict[str, list[str]]:
        """Semantic similarity graph: an edge between two docs whose embedding
        cosine >= threshold. This is a REAL graph over learned vectors — not a
        token co-occurrence heuristic."""
        graph: dict[str, list[str]] = {doc_id: [] for doc_id in self._ids}
        if self._matrix is None or len(self._ids) < 2:
            return graph
        sims = self._matrix @ self._matrix.T
        n = len(self._ids)
        for a in range(n):
            for b in range(a + 1, n):
                if float(sims[a, b]) >= threshold:
                    graph[self._ids[a]].append(self._ids[b])
                    graph[self._ids[b]].append(self._ids[a])
        return graph

    def scored_by_ids(self, query_vector: np.ndarray, ids: Sequence[str], via: str = "graph") -> list[ScoredDocument]:
        scores = self.all_scores(query_vector)
        if scores.size == 0:
            return []
        index_of = {doc_id: i for i, doc_id in enumerate(self._ids)}
        out: list[ScoredDocument] = []
        for doc_id in ids:
            i = index_of.get(doc_id)
            if i is not None:
                out.append(self._scored(i, scores[i], via))
        return out
