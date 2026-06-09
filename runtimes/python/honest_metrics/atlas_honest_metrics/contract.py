"""Boundary contract for the Atlas honest-finance-metrics Python data runtime.

The PHP kernel decides/gates/governs and invokes this runtime via
`python3 main.py <manifest.json>` (the canonical ProgrammingPythonRuntimeExecutor
protocol, identical to semantic_rag / stats_engine). This module validates the
manifest, runs the requested finance metrics on REAL numpy, and returns a receipt
the kernel verifies (honest_metrics_in_python=true, fabricated=false) — the same
anti-fake boundary the other runtimes use, so a PHP hand-rolled stand-in can never
pass back through.

Operations:
  - deflated_sharpe:        {sr, n_obs, skew, kurt, trials, var_sharpe}          -> DSR prob
  - deflated_sharpe_returns:{returns:[...], trials, var_sharpe}                  -> DSR prob from a series
  - pbo:                    {matrix:[[...],...], blocks?}                        -> PBO prob
  - moments:                {x:[...]}                                           -> {mean,std,skewness,kurtosis}
  - normal_cdf:             {x}                                                 -> Φ(x)
  - inverse_normal_cdf:     {p}                                                 -> probit(p)
  - honesty_gate:           {winner_daily_returns, sibling_sharpes, sibling_windows,
                             scenarios_explored, periods_per_year?, pbo_blocks?} -> the WHOLE
                            post-selection honesty computation (var_sharpe + DSR + PBO +
                            scoring sharpe) in ONE subprocess — the perf contract for the
                            TradingHonestyGate (off the HTTP hot path; one boundary cost per
                            campaign verdict).
  - batch:                  {jobs:[{id, op, ...op-args}, ...]}                  -> {results:{id: result}}

No secret/provider/model override is accepted (FORBIDDEN_KEYS) — the kernel
governs those; this runtime only computes numbers.
"""

from __future__ import annotations

from typing import Any

from . import metrics

REQUEST_SCHEMA = "atlas.honest_metrics.python_runtime.request.v1"
RECEIPT_SCHEMA = "atlas.honest_metrics.python_runtime.receipt.v1"

SINGLE_OPERATIONS = {
    "deflated_sharpe",
    "deflated_sharpe_returns",
    "pbo",
    "moments",
    "normal_cdf",
    "inverse_normal_cdf",
    "honesty_gate",
}
VALID_OPERATIONS = SINGLE_OPERATIONS | {"batch"}

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


def _as_matrix(value: Any, field: str) -> list[list[float]]:
    if not isinstance(value, list):
        raise ManifestError(f"{field} must be a list of rows")
    return [_as_float_list(row, f"{field}[]") for row in value]


def _as_number(value: Any, field: str) -> float:
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        raise ManifestError(f"{field} must be a number")
    return float(value)


def _as_int(value: Any, field: str, default: int | None = None) -> int:
    if value is None and default is not None:
        return default
    if isinstance(value, bool) or not isinstance(value, int):
        raise ManifestError(f"{field} must be an integer")
    return int(value)


def _run_single(op: str, spec: dict[str, Any]) -> dict[str, Any]:
    if op == "deflated_sharpe":
        value = metrics.deflated_sharpe(
            _as_number(spec.get("sr"), "sr"),
            _as_int(spec.get("n_obs"), "n_obs"),
            _as_number(spec.get("skew"), "skew"),
            _as_number(spec.get("kurt"), "kurt"),
            _as_int(spec.get("trials"), "trials"),
            _as_number(spec.get("var_sharpe"), "var_sharpe"),
        )
        return {"deflated_sharpe": value}
    if op == "deflated_sharpe_returns":
        value = metrics.deflated_sharpe_ratio(
            _as_float_list(spec.get("returns"), "returns"),
            _as_int(spec.get("trials"), "trials"),
            _as_number(spec.get("var_sharpe"), "var_sharpe"),
        )
        return {"deflated_sharpe": value}
    if op == "pbo":
        value = metrics.pbo(
            _as_matrix(spec.get("matrix"), "matrix"),
            blocks=_as_int(spec.get("blocks"), "blocks", default=8),
        )
        return {"pbo": value}
    if op == "moments":
        x = _as_float_list(spec.get("x"), "x")
        return {
            "mean": metrics.mean(x),
            "std": metrics.std(x),
            "skewness": metrics.skewness(x),
            "kurtosis": metrics.kurtosis(x),
        }
    if op == "normal_cdf":
        return {"normal_cdf": metrics.normal_cdf(_as_number(spec.get("x"), "x"))}
    if op == "inverse_normal_cdf":
        return {"inverse_normal_cdf": metrics.inverse_normal_cdf(_as_number(spec.get("p"), "p"))}
    if op == "honesty_gate":
        return _honesty_gate(spec)
    raise ManifestError(f"unknown operation {op!r}")


def _honesty_gate(spec: dict[str, Any]) -> dict[str, Any]:
    """The complete post-selection honesty computation in one call: derive the
    cross-trial Sharpe variance from the siblings (std² — exactly the PHP
    TradingHonestyGate did), then DSR (N-deflated, with the Lo-2002 floor), PBO
    over the sibling OOS windows, and the annualized scoring Sharpe. The PHP gate
    keeps the thresholds + reason assembly; this returns only the NUMBERS so the
    governance/decisioning stays in the kernel."""
    returns = _as_float_list(spec.get("winner_daily_returns", []), "winner_daily_returns")
    sibling_sharpes = _as_float_list(spec.get("sibling_sharpes", []), "sibling_sharpes")
    sibling_windows = _as_matrix(spec.get("sibling_windows", []), "sibling_windows")
    trials = max(1, _as_int(spec.get("scenarios_explored"), "scenarios_explored", default=1))
    periods_per_year = _as_number(spec.get("periods_per_year", 365), "periods_per_year")
    pbo_blocks = _as_int(spec.get("pbo_blocks"), "pbo_blocks", default=8)

    # variance of the Sharpe ESTIMATES across the N trials = the luck the search drew from.
    var_sharpe = (metrics.std(sibling_sharpes) ** 2) if sibling_sharpes else 0.0

    dsr = metrics.deflated_sharpe_ratio(returns, trials, var_sharpe) if len(returns) >= 2 else 0.0
    pbo_value = metrics.pbo(sibling_windows, pbo_blocks) if len(sibling_windows) >= 2 else 1.0
    scoring_sharpe = metrics.sharpe(returns, periods_per_year) if len(returns) >= 2 else 0.0

    return {
        "n_trials": trials,
        "var_sharpe_across_trials": var_sharpe,
        "deflated_sharpe": dsr,
        "pbo": pbo_value,
        "scoring_sharpe": scoring_sharpe,
        "n_returns": len(returns),
        "n_siblings": len(sibling_sharpes),
        "n_sibling_windows": len(sibling_windows),
    }


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
    # in-Python numpy finance-metrics engine, not a PHP hand-rolled stand-in.
    return {
        "honest_metrics_in_python": True,
        "php_adapter_only": True,
        "real_metrics": True,
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
    """Report that the real numpy metrics engine is importable, honestly."""
    try:
        import numpy  # noqa: F401

        return {"available": True, "library": "numpy", "numpy_version": numpy.__version__}
    except Exception as exc:  # pragma: no cover - environment failure surface
        return {"available": False, "reason": f"{type(exc).__name__}: {exc}"}
