#!/usr/bin/env bash
#
# S3 (Obra #19) — session-state across a compaction. The LIVE session's ephemeral
# state (staged paths + a timestamp) is snapshotted on `PreCompact` and re-injected
# on the NEXT `UserPromptSubmit` — but ONLY once, and ONLY after a compaction, so the
# model recovers "what I proved / what is staged" without re-deriving, and a normal
# turn is never spammed (anti-eco by a one-shot marker).
#
# The durable obra-state file (F2, #17) stays the owner of durable truth; this is the
# SESSION layer — a throwaway snapshot that exists only to survive one compaction.
#
# Two roles via $1:
#   write     — PreCompact: snapshot state + arm the re-inject marker.
#   reinject  — UserPromptSubmit: if armed, emit the snapshot as additionalContext + disarm.
#
# Fail-open by contract: any fault exits 0 silently (never blocks a prompt/compaction).
set -u

ACTION="${1:-reinject}"

PROJECT_DIR="${CLAUDE_PROJECT_DIR:-}"
if [ -z "$PROJECT_DIR" ]; then
    PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." 2>/dev/null && pwd)"
fi
DIR="${ATLAS_SESSION_STATE_DIR:-$PROJECT_DIR/storage/atlas/session-state}"
STATE="$DIR/session-state.json"
MARKER="$DIR/.needs-reinject"

if [ "$ACTION" = "write" ]; then
    mkdir -p "$DIR" 2>/dev/null || exit 0
    staged='[]'
    if command -v git >/dev/null 2>&1 && command -v jq >/dev/null 2>&1; then
        staged="$(cd "$PROJECT_DIR" 2>/dev/null && git diff --cached --name-only 2>/dev/null | head -50 | jq -R . | jq -s . 2>/dev/null || echo '[]')"
    fi
    printf '{"captured":"pre-compact","staged_paths":%s}\n' "$staged" > "$STATE" 2>/dev/null || exit 0
    : > "$MARKER" 2>/dev/null || true
    exit 0
fi

# reinject: only after a compaction (marker armed). Otherwise a normal turn — stay silent.
[ -f "$MARKER" ] || exit 0
rm -f "$MARKER" 2>/dev/null || true   # anti-eco: re-inject exactly ONCE per compaction
[ -f "$STATE" ] || exit 0
command -v jq >/dev/null 2>&1 || exit 0

STATE_JSON="$(cat "$STATE" 2>/dev/null || true)"
[ -n "$STATE_JSON" ] || exit 0

printf '%s' "$STATE_JSON" | jq -c '{
    hookSpecificOutput: {
        hookEventName: "UserPromptSubmit",
        additionalContext: ("## Estado da sessão (re-injetado pós-compactação)\n"
            + "staged_paths=" + ((.staged_paths // []) | join(", "))
            + "\n(camada de sessão efêmera; a obra-state F2 é a verdade durável)")
    }
}' 2>/dev/null || exit 0

exit 0
