"""Boundary contract for the Atlas world-model graph-ranking Python data runtime.

The PHP kernel (WorldModelGraphRanker) decides/gates/governs and invokes this
runtime via `python3 main.py <manifest.json>` (the canonical
ProgrammingPythonRuntimeExecutor protocol). This module validates the manifest,
runs the node ranking on REAL networkx + numpy, and returns a receipt the kernel
verifies (graph_rank_in_python=true, fabricated=false, real_graph_math=true) —
the same anti-fake boundary the near_duplicate / stats_engine runtimes use, so a
PHP hand-rolled stand-in can never pass back through.

Operation:
  rank: {nodes:[...], edges:[...], query:{...}} -> {scored, ranked_order, ...}

No secret/provider/model override is accepted (FORBIDDEN_KEYS) — the kernel
governs those; this runtime only computes the graph math.
"""

from __future__ import annotations

from typing import Any, Dict

from . import scoring

REQUEST_SCHEMA = "atlas.graph_rank.python_runtime.request.v1"
RECEIPT_SCHEMA = "atlas.graph_rank.python_runtime.receipt.v1"

VALID_OPERATIONS = {"rank"}

# The manifest must NOT carry secrets or runtime overrides — the kernel governs
# those; this runtime only computes numbers over a handed-in graph.
FORBIDDEN_KEYS = {"api_key", "authorization", "token", "secret", "password", "provider", "model"}


class ManifestError(ValueError):
    pass


def validate_manifest(manifest: Dict[str, Any]) -> None:
    if not isinstance(manifest, dict):
        raise ManifestError("manifest must be an object")
    leaked = FORBIDDEN_KEYS & set(manifest.keys())
    if leaked:
        raise ManifestError(f"forbidden keys in manifest (kernel governs these): {sorted(leaked)}")
    operation = manifest.get("operation")
    if operation not in VALID_OPERATIONS:
        raise ManifestError(f"operation must be one of {sorted(VALID_OPERATIONS)}, got {operation!r}")
    if not isinstance(manifest.get("nodes"), list):
        raise ManifestError("rank requires nodes: [...]")
    if not isinstance(manifest.get("edges"), list):
        raise ManifestError("rank requires edges: [...]")
    if not isinstance(manifest.get("query"), dict):
        raise ManifestError("rank requires query: {...}")


def _boundary() -> Dict[str, Any]:
    # The self-declared boundary proof the kernel verifies: this IS the real
    # in-Python networkx+numpy graph engine, not a PHP hand-rolled stand-in.
    import networkx
    import numpy

    return {
        "graph_rank_in_python": True,
        "php_adapter_only": True,
        "real_graph_math": True,
        "fabricated": False,
        "library": "networkx+numpy",
        "networkx_version": getattr(networkx, "__version__", "unknown"),
        "numpy_version": getattr(numpy, "__version__", "unknown"),
    }


def run_manifest(manifest: Dict[str, Any]) -> Dict[str, Any]:
    validate_manifest(manifest)
    result = scoring.rank_nodes(
        manifest["nodes"],
        manifest["edges"],
        manifest["query"],
    )
    return {
        "schema_version": RECEIPT_SCHEMA,
        "operation": "rank",
        **result,
        "boundary": _boundary(),
    }


def probe() -> Dict[str, Any]:
    """Report that the real networkx+numpy graph engine is importable, honestly."""
    try:
        import networkx
        import numpy

        return {
            "available": True,
            "library": "networkx+numpy",
            "networkx_version": networkx.__version__,
            "numpy_version": numpy.__version__,
        }
    except Exception as exc:  # pragma: no cover - environment failure surface
        return {"available": False, "reason": f"{type(exc).__name__}: {exc}"}
