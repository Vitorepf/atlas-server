"""Canonical manifest-in / JSON-out entrypoint helper for Python runtimes."""

from __future__ import annotations

import importlib
import json
from pathlib import Path
from typing import Any, Callable, Sequence

ManifestRunner = Callable[[dict[str, Any]], Any]


def run_package_manifest_entrypoint(
    argv: Sequence[str],
    package_name: str,
    *,
    runner_attr: str = "run_manifest",
    include_exception_type: bool = True,
) -> int:
    """Resolve ``<package>.<runner_attr>`` and run the canonical manifest entrypoint.

    Shared body of every runtime ``main.py``. The import is eager (before the
    argv check) so a broken runtime package still fails loudly, same as before.
    """
    runner = getattr(importlib.import_module(package_name), runner_attr)
    return run_json_manifest_entrypoint(
        argv, runner, include_exception_type=include_exception_type
    )


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
