"""Atlas semantic + graph RAG over REAL learned embeddings.

- ``index`` embeds documents with a real model and stores L2-normalised vectors.
- ``retrieve`` embeds the query, cosine-ranks (semantic retrieval), and can
  expand via the embedding-similarity graph (graph RAG): neighbours of the top
  hits that the raw query under-ranked. Everything operates on real vectors —
  there is no token bag, hash, or fabricated score anywhere in the path.

The embedder is injectable, so the retrieval/graph logic is unit-tested with
known vectors (no model needed); the realness of the embeddings is proven by a
separate provider-gated test.
"""

from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Sequence

from .embeddings import Embedder, resolve_embedder
from .vector_store import Document, VectorStore


@dataclass
class RagResult:
    query: str
    matches: list[dict[str, Any]]
    provider: str
    model: str
    dim: int
    semantic_count: int
    graph_count: int


class SemanticRag:
    def __init__(self, embedder: Embedder | None = None, prefer_local: bool = True) -> None:
        self._embedder = embedder or resolve_embedder(prefer_local=prefer_local)
        self._store = VectorStore()

    @property
    def provider(self) -> str:
        return self._embedder.name

    @property
    def model(self) -> str:
        return self._embedder.model

    @property
    def dim(self) -> int:
        return int(self._embedder.dim)

    def __len__(self) -> int:
        return len(self._store)

    def index(self, documents: Sequence[dict[str, Any]]) -> int:
        if not documents:
            return 0
        texts = [str(d.get("text", "")) for d in documents]
        vectors = self._embedder.embed(texts)
        docs = [
            Document(
                id=str(d.get("id", i)),
                text=texts[i],
                vector=vectors[i],
                metadata=dict(d.get("metadata", {}) or {}),
            )
            for i, d in enumerate(documents)
        ]
        self._store.add(docs)
        return len(docs)

    def retrieve(
        self,
        query: str,
        k: int = 5,
        graph_expand: bool = False,
        graph_threshold: float = 0.6,
    ) -> RagResult:
        query_vector = self._embedder.embed([query])[0]
        hits = self._store.search(query_vector, k=k)
        matches = [
            {"id": h.id, "text": h.text, "score": h.score, "metadata": h.metadata, "via": "semantic"}
            for h in hits
        ]
        graph_count = 0
        if graph_expand and hits:
            graph = self._store.similarity_graph(threshold=graph_threshold)
            seen = {h.id for h in hits}
            neighbour_ids: list[str] = []
            for h in hits:
                for nb in graph.get(h.id, []):
                    if nb not in seen:
                        seen.add(nb)
                        neighbour_ids.append(nb)
            for nb in self._store.scored_by_ids(query_vector, neighbour_ids, via="graph"):
                matches.append(
                    {"id": nb.id, "text": nb.text, "score": nb.score, "metadata": nb.metadata, "via": "graph"}
                )
                graph_count += 1
        return RagResult(
            query=query,
            matches=matches,
            provider=self.provider,
            model=self.model,
            dim=self.dim,
            semantic_count=len(hits),
            graph_count=graph_count,
        )
