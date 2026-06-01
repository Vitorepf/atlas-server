#!/usr/bin/env bash
#
# Idempotently install the Atlas git hooks into this checkout's .git/hooks.
# Invoked by `composer atlas:install-hooks`. Safe to re-run; it just overwrites
# the managed hook with the tracked source and makes it executable.
#
# This script does NOT auto-run on its own — the operator (or composer) runs it.
#
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"

SRC="$ROOT/scripts/hooks/pre-commit"
HOOKS_DIR="$ROOT/.git/hooks"
DEST="$HOOKS_DIR/pre-commit"

if [ ! -f "$SRC" ]; then
    echo "[install-git-hooks] source hook not found: $SRC" >&2
    exit 1
fi

if [ ! -d "$HOOKS_DIR" ]; then
    echo "[install-git-hooks] hooks dir not found (is this a git checkout?): $HOOKS_DIR" >&2
    exit 1
fi

cp "$SRC" "$DEST"
chmod +x "$DEST"

echo "[install-git-hooks] installed pre-commit -> $DEST (ADRS write-bound gate active)."
echo "[install-git-hooks] override a single commit with: ATLAS_SKIP_ADRS_GATE=1 git commit ..."
