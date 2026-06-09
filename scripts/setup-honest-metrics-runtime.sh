#!/usr/bin/env bash
# Set up the honest_metrics Python data runtime (real numpy finance metrics).
# Idempotent: creates the venv and installs the package (numpy). This is the
# runtime the runtime_language_boundary canon + operator thesis mandate — the PHP
# kernel invokes it instead of hand-rolling moments / Deflated Sharpe (with the
# Lo-2002 variance floor) / PBO via CSCV.
set -euo pipefail

RUNTIME="$(cd "$(dirname "$0")/.." && pwd)/runtimes/python/honest_metrics"
cd "$RUNTIME"

echo "[honest_metrics] creating venv at $RUNTIME/.venv"
python3 -m venv .venv

echo "[honest_metrics] installing package + numpy + test deps"
.venv/bin/pip install -q --disable-pip-version-check --upgrade pip >/dev/null 2>&1 || true
.venv/bin/pip install -q --disable-pip-version-check -e '.[test]'

echo "[honest_metrics] verifying numpy is importable"
.venv/bin/python -c "import numpy; print('  numpy', numpy.__version__)"

echo "[honest_metrics] running tests"
.venv/bin/python -m pytest -q

echo "[honest_metrics] ready."
