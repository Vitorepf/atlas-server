"""Boundary contract for the Atlas near-duplicate-memory Python data runtime.

The PHP kernel decides/gates/governs and invokes this runtime via
`python3 main.py <manifest.json>` (the canonical ProgrammingPythonRuntimeExecutor
protocol, identical to semantic_rag / stats_engine / honest_metrics). This module
validates the manifest, runs the requested near-duplicate detection on REAL numpy,
and returns a receipt the kernel verifies (near_duplicate_in_python=true,
fabricated=false) — the same anti-fake boundary the other runtimes use, so a PHP
hand-rolled stand-in can never pass back through.

Operations:
  - detect: {rows:[{id, tokens:[...], memory_type, scope, priority, importance,
             recency}, ...], threshold?} -> the full near-duplicate result
             (schema_version, evaluated, clusters[...], cluster_count,
             duplicate_count). Each cluster carries member_ids, canonical_id,
             merge_candidate_ids, the qualifying pairs with 4-dp similarities, and
             max_similarity — exactly the shape the removed PHP returned.

No secret/provider/model override is accepted (FORBIDDEN_KEYS) — the kernel
governs those; this runtime only computes the similarity clustering.
"""

from __future__ import annotations

from typing import Any

from . import near_duplicate

REQUEST_SCHEMA = "atlas.near_duplicate.python_runtime.request.v1"
RECEIPT_SCHEMA = "atlas.near_duplicate.python_runtime.receipt.v1"

VALID_OPERATIONS = {"detect"}

FORBIDDEN_KEYS = {"api_key", "authorization", "token", "secret", "password", "provider", "model"}


class ManifestError(ValueError):
    pass


def _as_rows(value: Any) -> list[dict[str, Any]]:
    if not isinstance(value, list):
        raise ManifestError("rows must be a list of row objects")
    out: list[dict[str, Any]] = []
    for row in value:
        if not isinstance(row, dict):
            raise ManifestError("each row must be an object")
        out.append(row)
    return out


def _as_threshold(value: Any) -> float:
    if value is None:
        return 0.82
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        raise ManifestError("threshold must be a number")
    return float(value)


def validate_manifest(manifest: dict[str, Any]) -> None:
    if not isinstance(manifest, dict):
        raise ManifestError("manifest must be an object")
    leaked = FORBIDDEN_KEYS & set(manifest.keys())
    if leaked:
        raise ManifestError(f"forbidden keys in manifest (kernel governs these): {sorted(leaked)}")
    operation = manifest.get("operation")
    if operation not in VALID_OPERATIONS:
        raise ManifestError(f"operation must be one of {sorted(VALID_OPERATIONS)}, got {operation!r}")
    if "rows" not in manifest:
        raise ManifestError("detect requires rows: [{id, tokens, memory_type, scope, ...}, ...]")


def _boundary() -> dict[str, Any]:
    return near_duplicate._boundary()


def run_manifest(manifest: dict[str, Any]) -> dict[str, Any]:
    validate_manifest(manifest)
    rows = _as_rows(manifest.get("rows", []))
    threshold = _as_threshold(manifest.get("threshold"))

    # near_duplicate.detect() already embeds the boundary receipt in its result, so
    # the whole result IS the receipt the kernel verifies.
    return near_duplicate.detect(rows, threshold)


def probe() -> dict[str, Any]:
    """Report that the real numpy engine is importable, honestly."""
    return near_duplicate.probe()
