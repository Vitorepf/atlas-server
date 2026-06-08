"""Boundary contract for the Atlas semantic/RAG Python data runtime.

Satisfies the PHP-side SemanticNotesPythonContract / OpenBrainGraphRagContract:
embeddings + RAG run in PYTHON (embeddings_engine_in_python=true, php_adapter
_only). The PHP kernel decides/gates/governs and invokes this runtime via
`python3 main.py <manifest.json>` (the canonical ProgrammingPythonRuntimeExecutor
protocol); this module validates the manifest, runs the requested operation on
REAL embeddings, and returns a receipt the kernel can verify.

Operations:
  - embed:    {texts:[...]}                       -> real embedding vectors (for pgvector)
  - retrieve: {documents:[{id,text,metadata}], query, k?, graph_expand?, graph_threshold?}
  - graph:    {documents:[...], graph_threshold?} -> the embedding-similarity graph

No secret/provider/model override is accepted from the manifest (FORBIDDEN_KEYS);
the embedding provider is resolved from the local environment (sovereignty order).
"""

from __future__ import annotations

from typing import Any

from .embeddings import NoEmbeddingProviderError, embed_texts, resolve_embedder
from .rag import SemanticRag

REQUEST_SCHEMA = "atlas.semantic_rag.python_runtime.request.v1"
RECEIPT_SCHEMA = "atlas.semantic_rag.python_runtime.receipt.v1"

VALID_OPERATIONS = {"embed", "retrieve", "graph"}

# The manifest must NOT carry secrets or runtime overrides — the kernel governs
# those; the runtime resolves its own real provider from the environment.
FORBIDDEN_KEYS = {"api_key", "authorization", "token", "secret", "password", "provider", "model"}


class ManifestError(ValueError):
    pass


def validate_manifest(manifest: dict[str, Any]) -> None:
    if not isinstance(manifest, dict):
        raise ManifestError("manifest must be an object")
    leaked = FORBIDDEN_KEYS & set(manifest.keys())
    if leaked:
        raise ManifestError(f"forbidden keys in manifest (kernel governs these): {sorted(leaked)}")
    operation = manifest.get("operation")
    if operation not in VALID_OPERATIONS:
        raise ManifestError(f"operation must be one of {sorted(VALID_OPERATIONS)}, got {operation!r}")
    if operation == "embed" and not isinstance(manifest.get("texts"), list):
        raise ManifestError("embed requires texts: [string, ...]")
    if operation in {"retrieve", "graph"} and not isinstance(manifest.get("documents"), list):
        raise ManifestError(f"{operation} requires documents: [{{id,text}}, ...]")
    if operation == "retrieve" and not isinstance(manifest.get("query"), str):
        raise ManifestError("retrieve requires query: string")


def _boundary(provider: str, model: str, dim: int) -> dict[str, Any]:
    # The self-declared boundary proof the kernel verifies: this IS a real
    # in-Python embeddings engine, not the PHP hash/token fallback.
    return {
        "embeddings_engine_in_python": True,
        "php_adapter_only": True,
        "real_embeddings": True,
        "fabricated_vectors": False,
        "provider": provider,
        "model": model,
        "dim": int(dim),
    }


def run_manifest(manifest: dict[str, Any]) -> dict[str, Any]:
    validate_manifest(manifest)
    operation = str(manifest["operation"])
    prefer_local = bool(manifest.get("prefer_local", True))

    if operation == "embed":
        result = embed_texts([str(t) for t in manifest["texts"]], prefer_local=prefer_local)
        return {
            "schema_version": RECEIPT_SCHEMA,
            "operation": "embed",
            "count": int(result.vectors.shape[0]),
            "vectors": result.vectors.tolist(),
            "boundary": _boundary(result.provider, result.model, result.dim),
        }

    rag = SemanticRag(prefer_local=prefer_local)
    indexed = rag.index(manifest["documents"])

    if operation == "graph":
        # Build the engine's store, then expose the embedding-similarity graph.
        threshold = float(manifest.get("graph_threshold", 0.6))
        graph = rag._store.similarity_graph(threshold=threshold)  # noqa: SLF001 (intra-package)
        return {
            "schema_version": RECEIPT_SCHEMA,
            "operation": "graph",
            "indexed": indexed,
            "graph_threshold": threshold,
            "graph": graph,
            "edge_count": sum(len(v) for v in graph.values()) // 2,
            "boundary": _boundary(rag.provider, rag.model, rag.dim),
        }

    # retrieve
    res = rag.retrieve(
        str(manifest["query"]),
        k=int(manifest.get("k", 5)),
        graph_expand=bool(manifest.get("graph_expand", False)),
        graph_threshold=float(manifest.get("graph_threshold", 0.6)),
    )
    return {
        "schema_version": RECEIPT_SCHEMA,
        "operation": "retrieve",
        "indexed": indexed,
        "query": res.query,
        "matches": res.matches,
        "semantic_count": res.semantic_count,
        "graph_count": res.graph_count,
        "boundary": _boundary(res.provider, res.model, res.dim),
    }


def probe_provider() -> dict[str, Any]:
    """Report whether a REAL embedding provider is available, honestly."""
    try:
        embedder = resolve_embedder()
        return {"available": True, "provider": embedder.name, "model": embedder.model, "dim": int(embedder.dim)}
    except NoEmbeddingProviderError as exc:
        return {"available": False, "reason": str(exc)}
