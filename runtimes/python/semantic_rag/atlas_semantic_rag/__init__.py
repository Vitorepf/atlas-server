"""Atlas semantic + graph RAG Python data runtime.

Real learned embeddings + cosine vector search + graph RAG, run in Python per
the runtime_language_boundary canon (never in the PHP kernel, never faked).
"""

from __future__ import annotations

from .contract import RECEIPT_SCHEMA, REQUEST_SCHEMA, probe_provider, run_manifest
from .embeddings import Embedder, NoEmbeddingProviderError, embed_texts, resolve_embedder
from .rag import RagResult, SemanticRag
from .vector_store import Document, ScoredDocument, VectorStore

__all__ = [
    "run_manifest",
    "probe_provider",
    "REQUEST_SCHEMA",
    "RECEIPT_SCHEMA",
    "SemanticRag",
    "RagResult",
    "VectorStore",
    "Document",
    "ScoredDocument",
    "embed_texts",
    "resolve_embedder",
    "Embedder",
    "NoEmbeddingProviderError",
]
