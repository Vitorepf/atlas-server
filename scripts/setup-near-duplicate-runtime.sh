#!/usr/bin/env bash
# Set up the near_duplicate Python data runtime (real numpy near-duplicate detection).
# Idempotent: creates the venv and installs the package (numpy). This is the runtime
# the runtime_language_boundary canon + operator thesis mandate — the PHP kernel
# invokes it instead of hand-rolling the O(n^2) pairwise shingle-Jaccard double loop.
set -euo pipefail

RUNTIME="$(cd "$(dirname "$0")/.." && pwd)/runtimes/python/near_duplicate"
cd "$RUNTIME"

echo "[near_duplicate] creating venv at $RUNTIME/.venv"
python3 -m venv .venv

echo "[near_duplicate] installing package + numpy + test deps"
.venv/bin/pip install -q --disable-pip-version-check --upgrade pip >/dev/null 2>&1 || true
.venv/bin/pip install -q --disable-pip-version-check -e '.[test]'

echo "[near_duplicate] verifying numpy is importable"
.venv/bin/python -c "import numpy; print('  numpy', numpy.__version__)"

echo "[near_duplicate] running tests"
.venv/bin/python -m pytest -q

echo "[near_duplicate] ready."
