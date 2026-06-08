"""Real cosine-search logic, proven with KNOWN vectors (no model needed)."""

from __future__ import annotations

import numpy as np
import pytest

from atlas_semantic_rag.vector_store import Document, VectorStore


def _doc(doc_id: str, vec: list[float]) -> Document:
    v = np.asarray(vec, dtype=np.float32)
    v = v / np.linalg.norm(v)
    return Document(id=doc_id, text=doc_id, vector=v)


def test_search_ranks_by_real_cosine():
    store = VectorStore()
    store.add([_doc("a", [1, 0, 0]), _doc("b", [0, 1, 0]), _doc("c", [0.9, 0.1, 0])])
    hits = store.search(np.asarray([1, 0, 0], dtype=np.float32), k=3)
    assert [h.id for h in hits] == ["a", "c", "b"]
    assert hits[0].score > hits[1].score > hits[2].score
    assert hits[0].score == pytest.approx(1.0, abs=1e-5)


def test_similarity_graph_real_edges():
    store = VectorStore()
    store.add([_doc("a", [1, 0]), _doc("b", [0.99, 0.14]), _doc("c", [0, 1])])
    graph = store.similarity_graph(threshold=0.9)
    assert "b" in graph["a"] and "a" in graph["b"]
    assert "c" not in graph["a"]


def test_dim_mismatch_is_rejected():
    store = VectorStore()
    store.add([_doc("a", [1, 0])])
    with pytest.raises(ValueError):
        store.add([_doc("b", [1, 0, 0])])


def test_empty_store_returns_no_hits():
    assert VectorStore().search(np.asarray([1.0, 0.0], dtype=np.float32)) == []
