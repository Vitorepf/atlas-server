#!/usr/bin/env bash
# Set up the semantic_rag Python data runtime (real local embeddings).
# Idempotent: creates the venv, installs the package + the local embedding model
# deps, and warms the model so the first kernel call is offline.
set -euo pipefail

RUNTIME="$(cd "$(dirname "$0")/.." && pwd)/runtimes/python/semantic_rag"
cd "$RUNTIME"

echo "[semantic_rag] creating venv at $RUNTIME/.venv"
python3 -m venv .venv

echo "[semantic_rag] installing package + local embedding model deps"
.venv/bin/pip install -q --disable-pip-version-check --upgrade pip >/dev/null 2>&1 || true
.venv/bin/pip install -q --disable-pip-version-check -e '.[local]'

echo "[semantic_rag] warming the local model (downloads BAAI/bge-small once, then offline)"
.venv/bin/python -c "from atlas_semantic_rag.embeddings import resolve_embedder; e=resolve_embedder(); e.embed(['warmup']); print('  provider:', e.name, 'model:', e.model, 'dim:', e.dim)"

echo "[semantic_rag] running tests"
.venv/bin/python -m pytest -q

echo "[semantic_rag] ready."
