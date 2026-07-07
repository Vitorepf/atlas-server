#!/usr/bin/env bash
#
# S3 (Obra #19) — runnable check for the session-state hook: (1) `write` snapshots
# state + arms the marker, (2) `reinject` emits the state ONCE and disarms, (3) a
# second `reinject` is silent (anti-eco — a normal turn is never spammed).
#
#   bash tests/hooks/atlas-session-state.test.sh   # exit 0 on pass, 1 on any failure
set -u

HOOK="$(cd "$(dirname "$0")/../.." && pwd)/.claude/hooks/atlas-session-state.sh"
WORK="$(mktemp -d)"
export ATLAS_SESSION_STATE_DIR="$WORK/state"
export CLAUDE_PROJECT_DIR="$WORK"

fail=0

# 1) write ⇒ state file + marker armed.
bash "$HOOK" write
if [ -f "$ATLAS_SESSION_STATE_DIR/session-state.json" ] && [ -f "$ATLAS_SESSION_STATE_DIR/.needs-reinject" ]; then
    echo "PASS write-arms"
else
    echo "FAIL write-arms"; fail=1
fi

# 2) reinject ⇒ emits the state, and disarms the marker.
out="$(bash "$HOOK" reinject)"
if printf '%s' "$out" | grep -q "pós-compactação"; then echo "PASS reinject-emits"; else echo "FAIL reinject-emits (got [$out])"; fail=1; fi
if [ ! -f "$ATLAS_SESSION_STATE_DIR/.needs-reinject" ]; then echo "PASS reinject-disarms"; else echo "FAIL reinject-disarms"; fail=1; fi

# 3) reinject again ⇒ silent (anti-eco: marker gone).
out="$(bash "$HOOK" reinject)"
if [ -z "$out" ]; then echo "PASS anti-eco"; else echo "FAIL anti-eco (got [$out])"; fail=1; fi

rm -rf "$WORK"
if [ "$fail" = 0 ]; then echo "ALL PASS"; else echo "FAILURES"; fi
exit "$fail"
