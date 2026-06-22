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

# §0 MASTER SWITCH — fail-closed. Mirrors AtlasLoopMasterSwitch::enabled() (direct .env read, never config).
# Absent/unreadable/not-truthy ATLAS_LOOP_MASTER_ENABLED ⇒ the loop is globally OFF: respawn nothing, exit.
# The loop can never re-enable itself — the flag is operator-only (pétreo in the constitution).
master_enabled() {
  local v
  v=$(grep -E "^ATLAS_LOOP_MASTER_ENABLED=" .env 2>/dev/null | tail -1 | cut -d'=' -f2- | tr -d " \"'" | tr '[:upper:]' '[:lower:]')
  case "$v" in 1|true|on|yes|enabled) return 0 ;; *) return 1 ;; esac
}

echo "[$(date '+%H:%M:%S')] watchdog START campaign=$CID interval=${INTERVAL}s" >> "$LOG"
while true; do
  [ -f "$STOP" ] && { echo "[$(date '+%H:%M:%S')] STOP file present — exiting" >> "$LOG"; rm -f "$STOP"; break; }
  master_enabled || { echo "[$(date '+%H:%M:%S')] MASTER SWITCH off — watchdog exiting (respawns nothing)" >> "$LOG"; break; }

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

  # 1b. POST-MERGE HEALTH NET (LOOP-OS Slice 1.5). The per-merge canary only proves the changed file's own
  # sibling; this re-runs the IMPACTED suite over the window's freshly-landed loop commits against post-merge
  # main and git-reverts (NEVER reset) a commit that is green-in-isolation but RED-in-combination. External by
  # design: a process must not health-check-then-revert inside its own edit surface. Holds the single
  # main-merge lock so it never reverts mid-crossing.
  $PHP -d memory_limit=3072M artisan atlas:loop:main-health --window=10 --json >> "$LOG" 2>&1

  # 2. Respawn a dead supervisor — ONLY if no campaign process is actually alive. Guard against the
  # observed DUPLICATE: a long grind makes the heartbeat look stale, and the keepalive's own liveness
  # check raced into spawning a SECOND campaign for the same id (two supervisors => double grinds =>
  # double GLM spend). A live `artisan atlas:loop:campaign` process means nothing to respawn.
  if ! pgrep -f 'artisan atlas:loop:campaign' >/dev/null 2>&1; then
    echo "[$(date '+%H:%M:%S')] no live campaign — running keepalive" >> "$LOG"
    $PHP -d memory_limit=2048M artisan atlas:loop:keepalive --stale-minutes=3 --json >> "$LOG" 2>&1
  fi

  # 3. Top up the queue (anti-starvation supply).
  $PHP -d memory_limit=2048M artisan atlas:loop:backlog-feed --json >> "$LOG" 2>&1

  sleep "$INTERVAL"
done
echo "[$(date '+%H:%M:%S')] watchdog EXIT" >> "$LOG"
