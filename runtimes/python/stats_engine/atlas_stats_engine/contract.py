"""Boundary contract for the Atlas telemetry-statistics Python data runtime.

The PHP kernel decides/gates/governs and invokes this runtime via
`python3 main.py <manifest.json>` (the canonical ProgrammingPythonRuntimeExecutor
protocol). This module validates the manifest, runs the requested statistics on
REAL numpy, and returns a receipt the kernel verifies (stats_engine_in_python=
true, fabricated=false) — the same anti-fake boundary the semantic_rag runtime
uses, so a PHP hand-rolled stand-in can never pass back through.

Operations:
  - ks:           {sample1:[...], sample2:[...]}                       -> KS / Mann-Whitney result
  - mann_kendall: {series:[...]}                                       -> trend + Sen's slope
  - cusum:        {series:[...]}                                       -> change-point
  - ewma:         {series:[...], alpha?, k_sigma?, warmup_days?}       -> single-day anomaly
  - wilson:       {k:int, n:int, z?}                                   -> proportion CI
  - batch:        {jobs:[{id, op, ...op-args}, ...]}                   -> {results:{id: result}}

`batch` is the perf contract: one subprocess computes many stats for the report
engine instead of one-subprocess-per-stat. No secret/provider/model override is
accepted (FORBIDDEN_KEYS).
"""

from __future__ import annotations

from typing import Any

from . import stats

REQUEST_SCHEMA = "atlas.stats_engine.python_runtime.request.v1"
RECEIPT_SCHEMA = "atlas.stats_engine.python_runtime.receipt.v1"

SINGLE_OPERATIONS = {"ks", "mann_kendall", "cusum", "ewma", "wilson"}
VALID_OPERATIONS = SINGLE_OPERATIONS | {"batch"}

# The manifest must NOT carry secrets or runtime overrides — the kernel governs
# those; this runtime only computes numbers.
FORBIDDEN_KEYS = {"api_key", "authorization", "token", "secret", "password", "provider", "model"}


class ManifestError(ValueError):
    pass


def _as_float_list(value: Any, field: str) -> list[float]:
    if not isinstance(value, list):
        raise ManifestError(f"{field} must be a list of numbers")
    out: list[float] = []
    for v in value:
        if isinstance(v, bool) or not isinstance(v, (int, float)):
            raise ManifestError(f"{field} must contain only numbers")
        out.append(float(v))
    return out


def _run_single(op: str, spec: dict[str, Any]) -> dict[str, Any]:
    if op == "ks":
        return stats.kolmogorov_smirnov(
            _as_float_list(spec.get("sample1"), "sample1"),
            _as_float_list(spec.get("sample2"), "sample2"),
        )
    if op == "mann_kendall":
        return stats.mann_kendall(_as_float_list(spec.get("series"), "series"))
    if op == "cusum":
        return stats.cusum(_as_float_list(spec.get("series"), "series"))
    if op == "ewma":
        return stats.ewma(
            _as_float_list(spec.get("series"), "series"),
            alpha=float(spec.get("alpha", stats.EWMA_DEFAULT_ALPHA)),
            k_sigma=float(spec.get("k_sigma", stats.EWMA_DEFAULT_K_SIGMA)),
            warmup_days=int(spec.get("warmup_days", stats.EWMA_DEFAULT_WARMUP_DAYS)),
        )
    if op == "wilson":
        if not isinstance(spec.get("k"), int) or isinstance(spec.get("k"), bool):
            raise ManifestError("wilson requires k: int")
        if not isinstance(spec.get("n"), int) or isinstance(spec.get("n"), bool):
            raise ManifestError("wilson requires n: int")
        return stats.wilson(
            int(spec["k"]),
            int(spec["n"]),
            z=float(spec.get("z", stats.WILSON_Z_95)),
        )
    raise ManifestError(f"unknown operation {op!r}")


def validate_manifest(manifest: dict[str, Any]) -> None:
    if not isinstance(manifest, dict):
        raise ManifestError("manifest must be an object")
    leaked = FORBIDDEN_KEYS & set(manifest.keys())
    if leaked:
        raise ManifestError(f"forbidden keys in manifest (kernel governs these): {sorted(leaked)}")
    operation = manifest.get("operation")
    if operation not in VALID_OPERATIONS:
        raise ManifestError(f"operation must be one of {sorted(VALID_OPERATIONS)}, got {operation!r}")
    if operation == "batch":
        if not isinstance(manifest.get("jobs"), list):
            raise ManifestError("batch requires jobs: [{id, op, ...}, ...]")
        for job in manifest["jobs"]:
            if not isinstance(job, dict) or "id" not in job or job.get("op") not in SINGLE_OPERATIONS:
                raise ManifestError("each batch job needs an id and an op in " + str(sorted(SINGLE_OPERATIONS)))


def _boundary() -> dict[str, Any]:
    # The self-declared boundary proof the kernel verifies: this IS the real
    # in-Python numpy stats engine, not a PHP hand-rolled stand-in.
    return {
        "stats_engine_in_python": True,
        "php_adapter_only": True,
        "real_stats": True,
        "fabricated": False,
        "library": "numpy",
        "numpy_version": getattr(__import__("numpy"), "__version__", "unknown"),
    }


def run_manifest(manifest: dict[str, Any]) -> dict[str, Any]:
    validate_manifest(manifest)
    operation = str(manifest["operation"])

    if operation == "batch":
        results: dict[str, Any] = {}
        for job in manifest["jobs"]:
            job_id = str(job["id"])
            results[job_id] = _run_single(str(job["op"]), job)
        return {
            "schema_version": RECEIPT_SCHEMA,
            "operation": "batch",
            "count": len(results),
            "results": results,
            "boundary": _boundary(),
        }

    result = _run_single(operation, manifest)
    return {
        "schema_version": RECEIPT_SCHEMA,
        "operation": operation,
        "result": result,
        "boundary": _boundary(),
    }


def probe() -> dict[str, Any]:
    """Report that the real numpy stats engine is importable, honestly."""
    try:
        import numpy  # noqa: F401

        return {"available": True, "library": "numpy", "numpy_version": numpy.__version__}
    except Exception as exc:  # pragma: no cover - environment failure surface
        return {"available": False, "reason": f"{type(exc).__name__}: {exc}"}
