#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PID_FILE="$ROOT_DIR/storage/app/atlas-ai-worker.pid"
UID_VALUE="$(id -u)"

STATUS=1

for LABEL in "com.atlas.ai-worker.claude" "com.atlas.ai-worker.codex" "com.atlas.ai-worker"; do
  if launchctl print "gui/$UID_VALUE/$LABEL" >/dev/null 2>&1; then
    PID="$(launchctl print "gui/$UID_VALUE/$LABEL" 2>/dev/null | awk -F'= ' '/pid = / {print $2; exit}')"
    if [[ -n "${PID:-}" ]]; then
      echo "Atlas AI worker [$LABEL]: running via launchd pid=$PID"
    else
      echo "Atlas AI worker [$LABEL]: loaded via launchd"
    fi
    STATUS=0
  fi
done

if [[ "$STATUS" -eq 0 ]]; then
  exit 0
fi

if [[ ! -f "$PID_FILE" ]]; then
  echo "Atlas AI worker: stopped"
  exit 1
fi

PID="$(cat "$PID_FILE")"
if [[ -z "$PID" ]] || ! kill -0 "$PID" 2>/dev/null; then
  echo "Atlas AI worker: stopped (stale pid file)"
  exit 1
fi

echo "Atlas AI worker: running pid=$PID"
