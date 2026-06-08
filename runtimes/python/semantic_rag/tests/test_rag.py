"""RAG + graph-expansion LOGIC, proven deterministically with a test-double
embedder (known vectors). Production never uses a fake embedder — this double
exists ONLY to make the retrieval/graph logic model-independent under test."""

from __future__ import annotations

import numpy as np

from atlas_semantic_rag.rag import SemanticRag


class KnownVectorEmbedder:
    name = "test_double"
    model = "known_vectors"
    dim = 3

    def __init__(self, table: dict[str, list[float]]) -> None:
        self._table = {k: np.asarray(v, dtype=np.float32) for k, v in table.items()}

    def embed(self, texts):
        out = []
        for t in texts:
            v = self._table[t]
            n = float(np.linalg.norm(v))
            out.append(v / n if n else v)
        return np.asarray(out, dtype=np.float32)


def test_retrieve_ranks_semantically_close_docs_first():
    table = {"dog": [1, 0, 0], "puppy": [0.95, 0.05, 0], "car": [0, 1, 0], "q": [1, 0, 0]}
    rag = SemanticRag(embedder=KnownVectorEmbedder(table))
    assert rag.index([{"id": "d_dog", "text": "dog"}, {"id": "d_pup", "text": "puppy"}, {"id": "d_car", "text": "car"}]) == 3
    res = rag.retrieve("q", k=2)
    ids = [m["id"] for m in res.matches]
    assert "d_car" not in ids
    assert res.semantic_count == 2
    assert all(m["via"] == "semantic" for m in res.matches)


def test_graph_expansion_pulls_real_neighbours():
    table = {"dog": [1, 0, 0], "puppy": [0.97, 0.05, 0], "wolf": [0.9, 0.12, 0], "car": [0, 1, 0], "q": [1, 0, 0]}
    rag = SemanticRag(embedder=KnownVectorEmbedder(table))
    rag.index([{"id": i, "text": i} for i in ["dog", "puppy", "wolf", "car"]])
    res = rag.retrieve("q", k=1, graph_expand=True, graph_threshold=0.85)
    via = {m["id"]: m["via"] for m in res.matches}
    assert via.get("dog") == "semantic"
    assert res.graph_count >= 1            # puppy/wolf reached via the similarity graph
    assert "car" not in via                # unrelated doc never pulled in


def test_retrieve_on_empty_corpus_is_safe():
    table = {"q": [1, 0, 0]}
    rag = SemanticRag(embedder=KnownVectorEmbedder(table))
    res = rag.retrieve("q", k=5)
    assert res.matches == [] and res.semantic_count == 0
