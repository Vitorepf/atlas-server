#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PID_FILE="$ROOT_DIR/storage/app/atlas-ai-worker.pid"
LOG_FILE="$ROOT_DIR/storage/logs/atlas-ai-worker.log"

cd "$ROOT_DIR"
mkdir -p "$(dirname "$PID_FILE")" "$(dirname "$LOG_FILE")"

if [[ -f "$PID_FILE" ]]; then
  EXISTING_PID="$(cat "$PID_FILE")"
  if [[ -n "$EXISTING_PID" ]] && kill -0 "$EXISTING_PID" 2>/dev/null; then
    echo "Atlas AI worker already running: pid=$EXISTING_PID"
    exit 0
  fi
fi

php artisan atlas:ai:health >/dev/null

nohup php artisan atlas:ai:work --sleep=2 --worker-id="$(hostname)" >> "$LOG_FILE" 2>&1 &
echo "$!" > "$PID_FILE"

echo "Atlas AI worker started: pid=$(cat "$PID_FILE")"
echo "Log: $LOG_FILE"
