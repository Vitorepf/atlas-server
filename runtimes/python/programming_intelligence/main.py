from __future__ import annotations

import sys
from pathlib import Path

_RUNTIME_ROOT = Path(__file__).resolve().parents[1]
if str(_RUNTIME_ROOT) not in sys.path:
    sys.path.insert(0, str(_RUNTIME_ROOT))

from atlas_programming_intelligence import analyze_manifest
from atlas_runtime_contract import run_json_manifest_entrypoint


def main(argv: list[str]) -> int:
    return run_json_manifest_entrypoint(
        argv,
        analyze_manifest,
        include_exception_type=False,
    )


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
