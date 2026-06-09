#!/usr/bin/env bash
# Set up the graph_rank Python data runtime (real networkx+numpy world-model node ranking).
# Idempotent: creates the venv and installs the package (networkx + numpy). This is the
# runtime the runtime_language_boundary canon + operator thesis mandate — the PHP kernel
# (WorldModelGraphRanker) invokes it instead of hand-rolling node centrality / edge-weight
# propagation / incoming-outgoing scoring.
set -euo pipefail

RUNTIME="$(cd "$(dirname "$0")/.." && pwd)/runtimes/python/graph_rank"
cd "$RUNTIME"

echo "[graph_rank] creating venv at $RUNTIME/.venv"
python3 -m venv .venv

echo "[graph_rank] installing package + networkx + numpy + test deps"
.venv/bin/pip install -q --disable-pip-version-check --upgrade pip >/dev/null 2>&1 || true
.venv/bin/pip install -q --disable-pip-version-check -e '.[test]'

echo "[graph_rank] verifying networkx + numpy are importable"
.venv/bin/python -c "import networkx, numpy; print('  networkx', networkx.__version__, '| numpy', numpy.__version__)"

echo "[graph_rank] running tests"
.venv/bin/python -m pytest -q

echo "[graph_rank] ready."
