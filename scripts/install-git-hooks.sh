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

HOOKS_DIR="$ROOT/.git/hooks"

if [ ! -d "$HOOKS_DIR" ]; then
    echo "[install-git-hooks] hooks dir not found (is this a git checkout?): $HOOKS_DIR" >&2
    exit 1
fi

# Every tracked hook in scripts/hooks/ is managed: overwrite + chmod.
for SRC in "$ROOT"/scripts/hooks/*; do
    NAME="$(basename "$SRC")"
    cp "$SRC" "$HOOKS_DIR/$NAME"
    chmod +x "$HOOKS_DIR/$NAME"
    echo "[install-git-hooks] installed $NAME -> $HOOKS_DIR/$NAME"
done

echo "[install-git-hooks] pre-commit: NON-BLOCKING doc auto-heal (blocking ADRS variant: scripts/hooks-optional/)"
echo "[install-git-hooks] pre-push:   .env secret-leak gate (operator bypass: git push --no-verify)"
