"""Canonical manifest-in / JSON-out entrypoint helper for Python runtimes."""

from __future__ import annotations

import json
from pathlib import Path
from typing import Any, Callable, Sequence

ManifestRunner = Callable[[dict[str, Any]], Any]


def run_json_manifest_entrypoint(
    argv: Sequence[str],
    runner: ManifestRunner,
    *,
    include_exception_type: bool = True,
) -> int:
    """Run a governed manifest runtime while preserving Atlas' stdout contract."""
    if len(argv) != 2:
        print(json.dumps({"ok": False, "error": "manifest_path_required"}))
        return 2

    try:
        manifest = json.loads(Path(argv[1]).read_text(encoding="utf-8"))
        result = runner(manifest)
    except Exception as exc:
        error = f"{type(exc).__name__}: {exc}" if include_exception_type else str(exc)
        print(json.dumps({"ok": False, "error": error}))
        return 1

    print(json.dumps({"ok": True, "result": result}, sort_keys=True))
    return 0
