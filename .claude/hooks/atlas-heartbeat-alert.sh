#!/usr/bin/env bash
#
# Atlas heartbeat alert — WO-17-T0.3 (anti-morte-silenciosa).
#
# A Claude Code `UserPromptSubmit` hook: injects ONE line into the session when the
# scheduler heartbeat is stale (>24h), so the autonomous loop can never die 8 days in
# silence again. Staleness = mtime of storage/atlas/scheduler/heartbeat.jsonl (matches
# AtlasAcosEvolutionScoreService::heartbeatFresh). FAIL-OPEN always: any fault, missing
# artifact, or fresh heartbeat emits NOTHING and exits 0 (never degrades a session).
# Read-only, local, zero provider spend.
#
# Dedup: UserPromptSubmit is registered twice in settings.json (both run their hooks),
# so a 5s marker collapses the near-instant double-fire into ONE injected line.

set -u

PROJECT_DIR="${CLAUDE_PROJECT_DIR:-}"
if [ -z "$PROJECT_DIR" ]; then
    PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." 2>/dev/null && pwd)" || exit 0
fi
HB="$PROJECT_DIR/storage/atlas/scheduler/heartbeat.jsonl"
[ -f "$HB" ] || exit 0   # no artifact yet -> nothing to warn about (fail-open)

NOW="$(date +%s 2>/dev/null)" || exit 0
MT="$(stat -f %m "$HB" 2>/dev/null || stat -c %Y "$HB" 2>/dev/null || echo "$NOW")"
AGE=$(( NOW - MT ))
[ "$AGE" -le 86400 ] && exit 0   # fresh (<=24h) -> emit nothing

# Dedup the double-wired fire: skip if we already emitted in the last 5 seconds.
MARK="$(dirname "$HB")/.hb-alert-mark"
if [ -f "$MARK" ]; then
    MMT="$(stat -f %m "$MARK" 2>/dev/null || stat -c %Y "$MARK" 2>/dev/null || echo 0)"
    [ $(( NOW - MMT )) -lt 5 ] && exit 0
fi
touch "$MARK" 2>/dev/null || true

DAYS=$(( AGE / 86400 ))
HOURS=$(( (AGE % 86400) / 3600 ))
MSG="⚠️ Atlas scheduler heartbeat STALE há ~${DAYS}d${HOURS}h (storage/atlas/scheduler/heartbeat.jsonl). Autônomos pode estar parado — cheque launchd (print-disabled) e o master (atlas:agents:on|off autonomos; alias loop)."

if command -v jq >/dev/null 2>&1; then
    jq -cn --arg m "$MSG" '{hookSpecificOutput:{hookEventName:"UserPromptSubmit",additionalContext:$m}}' 2>/dev/null || exit 0
else
    # jq absent -> hand-encode the single known-safe line (no untrusted interpolation).
    printf '{"hookSpecificOutput":{"hookEventName":"UserPromptSubmit","additionalContext":"%s"}}' "$MSG"
fi
exit 0
