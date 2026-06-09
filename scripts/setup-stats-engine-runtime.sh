#!/usr/bin/env bash
# Set up the stats_engine Python data runtime (real numpy telemetry statistics).
# Idempotent: creates the venv and installs the package (numpy). This is the
# runtime the runtime_language_boundary canon mandates — the PHP kernel invokes
# it instead of hand-rolling KS/Mann-Kendall/CUSUM/EWMA/Wilson.
set -euo pipefail

RUNTIME="$(cd "$(dirname "$0")/.." && pwd)/runtimes/python/stats_engine"
cd "$RUNTIME"

echo "[stats_engine] creating venv at $RUNTIME/.venv"
python3 -m venv .venv

echo "[stats_engine] installing package + numpy + test deps"
.venv/bin/pip install -q --disable-pip-version-check --upgrade pip >/dev/null 2>&1 || true
.venv/bin/pip install -q --disable-pip-version-check -e '.[test]'

echo "[stats_engine] verifying numpy is importable"
.venv/bin/python -c "import numpy; print('  numpy', numpy.__version__)"

echo "[stats_engine] running tests"
.venv/bin/python -m pytest -q

echo "[stats_engine] ready."
