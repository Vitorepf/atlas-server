"""Entrypoint for the Atlas telemetry-statistics Python data runtime.

Invoked by the PHP kernel (StatsEngineRuntimeClient) as
``python3 main.py <manifest.json>`` and emits one JSON line on stdout.
"""

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))  # locate atlas_runtime_contract

from atlas_runtime_contract import run_package_manifest_entrypoint

if __name__ == "__main__":
    raise SystemExit(run_package_manifest_entrypoint(sys.argv, "atlas_stats_engine"))
