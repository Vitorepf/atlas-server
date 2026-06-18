#!/bin/bash
# Atlas Loop — CONTROLLED self-heal watchdog (loop-only; NO finance/workers/missions GLM-callers).
#
# Replaces the launchd `com.atlas.scheduler` for the 24h soak: that scheduler also fired
# queue:work missions / operator-comprehend / hermes-probe / venture (GLM burners). This runs
# ONLY the three deterministic loop maintenance commands + respawn, so the only GLM spend is the
# campaign's own grinds.
#
#   automerge   → drain certified+reproven proposals to main (governed, no GLM)
#   keepalive   → respawn the campaign if its process died (resume; honors kill_switch)
#   backlog-feed→ top up the work queue from real signals (no GLM)
#
# Usage: bin/atlas-loop-watchdog.sh <campaign-id>
# Stop:  touch storage/atlas-loop/WATCHDOG_STOP   (or kill the process)
set -u
cd /Users/vitorepf/develop/Atlas/atlas-server || exit 1

CID="${1:-}"
PHP="/opt/homebrew/bin/php"
LOG="/tmp/atlas-loop-watchdog.log"
STOP="storage/atlas-loop/WATCHDOG_STOP"
INTERVAL="${ATLAS_LOOP_WATCHDOG_INTERVAL:-60}"

echo "[$(date '+%H:%M:%S')] watchdog START campaign=$CID interval=${INTERVAL}s" >> "$LOG"
while true; do
  [ -f "$STOP" ] && { echo "[$(date '+%H:%M:%S')] STOP file present — exiting" >> "$LOG"; rm -f "$STOP"; break; }

  # 0. ENFORCE per-grind wall-clock ceiling. The configured atlas.loop.campaign.attempt_hard_seconds
  # (900s) is NOT enforced on the hermes_cli grind path (it bounds by --max-turns 90, not wall-clock),
  # so one hard target can run far past budget burning GLM. Kill any grind (run-scenario + its hermes)
  # older than the ceiling so a single attempt can never become "hours". The supervisor then records
  # the attempt as failed and moves on (max 2 attempts/target).
  CEIL="${ATLAS_LOOP_GRIND_MAX_SECONDS:-1800}"
  for pid in $(pgrep -f 'atlas:loop:run-scenario'; pgrep -f 'hermes chat --quiet'); do
    age=$(ps -o etimes= -p "$pid" 2>/dev/null | tr -d ' ')
    if [ -n "$age" ] && [ "$age" -gt "$CEIL" ]; then
      echo "[$(date '+%H:%M:%S')] KILL over-budget grind pid=$pid age=${age}s (ceil=${CEIL}s)" >> "$LOG"
      kill "$pid" 2>/dev/null
    fi
  done

  # 1. Drain certified → main (deterministic; re-proves + canary; never -A, scoped add).
  $PHP -d memory_limit=3072M artisan atlas:loop:automerge --limit=10 --json >> "$LOG" 2>&1

  # 2. Respawn a dead supervisor (resume; kill_switch-aware so a stopped campaign stays stopped).
  $PHP -d memory_limit=2048M artisan atlas:loop:keepalive --stale-minutes=3 --json >> "$LOG" 2>&1

  # 3. Top up the queue (anti-starvation supply).
  $PHP -d memory_limit=2048M artisan atlas:loop:backlog-feed --json >> "$LOG" 2>&1

  sleep "$INTERVAL"
done
echo "[$(date '+%H:%M:%S')] watchdog EXIT" >> "$LOG"
