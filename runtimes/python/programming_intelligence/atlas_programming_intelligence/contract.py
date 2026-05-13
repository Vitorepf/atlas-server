from __future__ import annotations

import hashlib
import json
from pathlib import Path
from typing import Any

from .language_analyzer import analyze_file

REQUEST_SCHEMA = "atlas.programming.python_runtime.request.v1"
ANALYSIS_SCHEMA = "atlas.programming.python_runtime.analysis.v1"

FORBIDDEN_KEYS = {
    "api_key",
    "authorization",
    "token",
    "secret",
    "password",
    "provider",
    "model",
    "tool",
    "memory_write",
}


def analyze_manifest(manifest: dict[str, Any]) -> dict[str, Any]:
    validate_manifest(manifest)

    workspace = Path(str(manifest["workspace"])).resolve()
    limits = manifest.get("limits") if isinstance(manifest.get("limits"), dict) else {}
    max_files = int(limits.get("max_files", 40))
    max_bytes_per_file = int(limits.get("max_bytes_per_file", 250_000))
    files = [str(item) for item in manifest.get("files", [])][:max_files]

    analyses = []
    skipped = []
    for relative in files:
        path = (workspace / relative).resolve()
        if not _inside(path, workspace):
            skipped.append({"path": relative, "reason": "outside_workspace"})
            continue
        if not path.is_file():
            skipped.append({"path": relative, "reason": "missing"})
            continue
        if path.stat().st_size > max_bytes_per_file:
            skipped.append({"path": relative, "reason": "file_too_large"})
            continue

        analyses.append(analyze_file(workspace, path))

    payload = {
        "schema_version": ANALYSIS_SCHEMA,
        "status": "ready" if analyses else "empty",
        "runtime": {
            "family": "python_ai_data",
            "capability": "programming_ast_embeddings",
            "standard_library_only": True,
            "provider_calls": False,
            "shell_calls": False,
            "network_calls": False,
            "memory_writes": False,
        },
        "workspace_hash": _sha256(str(workspace)),
        "file_count": len(analyses),
        "files": analyses,
        "skipped": skipped,
    }
    payload["receipt_hash"] = _sha256(json.dumps(payload, sort_keys=True, separators=(",", ":")))

    return payload


def validate_manifest(manifest: dict[str, Any]) -> None:
    if manifest.get("schema_version") != REQUEST_SCHEMA:
        raise ValueError("invalid_schema_version")
    _reject_forbidden_keys(manifest)
    workspace = manifest.get("workspace")
    if not isinstance(workspace, str) or not workspace:
        raise ValueError("workspace_required")
    if not Path(workspace).resolve().is_dir():
        raise ValueError("workspace_missing")
    files = manifest.get("files")
    if not isinstance(files, list) or not all(isinstance(item, str) for item in files):
        raise ValueError("files_must_be_string_list")


def _reject_forbidden_keys(value: Any, path: str = "") -> None:
    if isinstance(value, dict):
        for key, nested in value.items():
            normalized = str(key).lower()
            if normalized in FORBIDDEN_KEYS:
                raise ValueError(f"forbidden_key:{path + normalized}")
            _reject_forbidden_keys(nested, path + normalized + ".")
    elif isinstance(value, list):
        for index, nested in enumerate(value):
            _reject_forbidden_keys(nested, path + str(index) + ".")


def _inside(path: Path, workspace: Path) -> bool:
    try:
        path.relative_to(workspace)
        return True
    except ValueError:
        return False


def _sha256(value: str) -> str:
    return hashlib.sha256(value.encode("utf-8")).hexdigest()
