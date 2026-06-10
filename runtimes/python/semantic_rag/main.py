"""Entrypoint for the Atlas semantic/RAG Python data runtime.

Invoked by the PHP kernel (ProgrammingPythonRuntimeExecutor) as
``python3 main.py <manifest.json>`` and emits one JSON line on stdout.
Mirrors the canonical programming_intelligence/main.py protocol.
"""

from __future__ import annotations

import sys
from pathlib import Path

_RUNTIME_ROOT = Path(__file__).resolve().parents[1]
if str(_RUNTIME_ROOT) not in sys.path:
    sys.path.insert(0, str(_RUNTIME_ROOT))

from atlas_semantic_rag import run_manifest
from atlas_runtime_contract import run_json_manifest_entrypoint


def main(argv: list[str]) -> int:
    return run_json_manifest_entrypoint(argv, run_manifest)


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
