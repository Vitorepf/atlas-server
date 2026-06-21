#!/bin/bash
# BABYSIT watchdog (propose-only, NO auto-merge). 24h self-heal:
#   - keepalive  → respawn the campaign supervisor if its process died (resume, honors kill_switch)
#   - reclaim-orphaned-grinds → free tasks held by a DEAD grind worker in seconds (not the 90min lease),
#     so a crashed/OOM'd worker never holds a slot hostage and stalls throughput (fail-closed if pgrep absent)
#   - backlog-feed → top up the work queue from real signals (no merge, no provider spend)
# Deliberately does NOT call atlas:loop:automerge — propose-only is preserved during the babysit test.
# Usage: bin/atlas-loop-babysit-watchdog.sh <campaign-id>
set -u
CID="${1:?campaign-id required}"
PHP="${ATLAS_PHP_BIN:-/opt/homebrew/bin/php}"
INTERVAL="${BABYSIT_INTERVAL:-60}"
LOG="storage/logs/babysit-watchdog-${CID}.log"
echo "[$(date '+%Y-%m-%d %H:%M:%S')] babysit watchdog START campaign=$CID interval=${INTERVAL}s (NO automerge)" >> "$LOG"
while true; do
  # respawn ONLY when no campaign process is alive (avoid the double-supervisor race)
  if ! pgrep -f 'artisan atlas:loop:campaign' >/dev/null 2>&1; then
    echo "[$(date '+%H:%M:%S')] no live campaign — keepalive respawn" >> "$LOG"
    $PHP -d memory_limit=2048M artisan atlas:loop:keepalive --stale-minutes=3 --json >> "$LOG" 2>&1
  fi
  # free tasks orphaned by a dead grind worker (process-liveness reclaim; fail-closed if pgrep is absent)
  $PHP -d memory_limit=2048M artisan atlas:loop:reclaim-orphaned-grinds --campaign-id="$CID" --json >> "$LOG" 2>&1
  # keep the queue fed from real signals (never merges)
  $PHP -d memory_limit=2048M artisan atlas:loop:backlog-feed --json >> "$LOG" 2>&1
  sleep "$INTERVAL"
done
