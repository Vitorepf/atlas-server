#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PID_FILE="$ROOT_DIR/storage/app/atlas-ai-worker.pid"

if [[ ! -f "$PID_FILE" ]]; then
  echo "Atlas AI worker is not running."
  exit 0
fi

PID="$(cat "$PID_FILE")"
if [[ -z "$PID" ]] || ! kill -0 "$PID" 2>/dev/null; then
  rm -f "$PID_FILE"
  echo "Atlas AI worker pid file was stale."
  exit 0
fi

kill "$PID"
rm -f "$PID_FILE"
echo "Atlas AI worker stopped: pid=$PID"
