"""Entrypoint for the Atlas semantic/RAG Python data runtime.

Invoked by the PHP kernel (ProgrammingPythonRuntimeExecutor) as
``python3 main.py <manifest.json>`` and emits one JSON line on stdout.
Mirrors the canonical programming_intelligence/main.py protocol.
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

from atlas_semantic_rag import run_manifest


def main(argv: list[str]) -> int:
    if len(argv) != 2:
        print(json.dumps({"ok": False, "error": "manifest_path_required"}))
        return 2

    try:
        manifest = json.loads(Path(argv[1]).read_text(encoding="utf-8"))
        result = run_manifest(manifest)
    except Exception as exc:  # honest failure surface — never a fabricated result
        print(json.dumps({"ok": False, "error": f"{type(exc).__name__}: {exc}"}))
        return 1

    print(json.dumps({"ok": True, "result": result}, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
