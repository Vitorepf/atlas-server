#!/bin/bash
# Atlas Loop — watchdog SUPERVISOR (loop-only; OS-less crash-resilience for a watched soak).
#
# The watchdog (bin/atlas-loop-watchdog.sh) is what keeps a soak honest: it kills over-wall-clock grinds
# (the hermes-hang the certify-run hit), drains certified→main, and respawns a dead campaign. But the
# watchdog is itself a SINGLE process — if it is OOM-killed or crashes mid-soak, hung grinds are never
# reaped again and the machine thrashes silently (exactly what soak-readiness must prevent). This
# supervisor RESPAWNS the watchdog when it dies UNEXPECTEDLY, so the soak's self-heal survives a watchdog
# crash. It is the OS-less complement to a launchd KeepAlive: no system install, no root, nothing left
# resident — runnable for a WATCHED soak today (the launchd variant is the later unattended-days upgrade).
#
# §0 MASTER SWITCH — honored EXACTLY like the watchdog: when the loop is globally OFF, it supervises
# NOTHING and exits; it NEVER respawns the watchdog while master is off. The discriminator after the
# watchdog returns is a fresh master read: still-ON ⇒ the watchdog crashed ⇒ respawn; now-OFF ⇒ the
# watchdog's clean master-off exit ⇒ intended stop, exit. The loop can never re-enable itself (operator-only).
#
# Usage: bin/atlas-loop-watchdog-supervised.sh <campaign-id>
# Stop:  flip the master switch off (`atlas:loop:off`, the canonical kill) OR touch the supervisor STOP
#        file. (The watchdog's OWN STOP file is for un-supervised manual runs — under supervision a still-
#        armed watchdog is respawned, so use the supervisor STOP / master-off to stop a supervised run.)
set -u
REPO_DIR="${ATLAS_LOOP_REPO_DIR:-/Users/vitorepf/develop/Atlas/atlas-server}"
cd "$REPO_DIR" || exit 1

CID="${1:-}"
ENV_FILE="${ATLAS_LOOP_ENV_FILE:-.env}"
WATCHDOG="${ATLAS_LOOP_WATCHDOG_BIN:-bin/atlas-loop-watchdog.sh}"
STOP="${ATLAS_LOOP_SUPERVISOR_STOP:-storage/atlas-loop/WATCHDOG_SUPERVISOR_STOP}"
LOG="${ATLAS_LOOP_SUPERVISOR_LOG:-/tmp/atlas-loop-watchdog-supervisor.log}"
BACKOFF="${ATLAS_LOOP_SUPERVISOR_BACKOFF:-5}"

# Fail-closed master read. Mirrors AtlasLoopMasterSwitch::enabled() / the watchdog (direct .env read, never config).
master_enabled() {
  local v
  v=$(grep -E "^ATLAS_LOOP_MASTER_ENABLED=" "$ENV_FILE" 2>/dev/null | tail -1 | cut -d'=' -f2- | tr -d " \"'" | tr '[:upper:]' '[:lower:]')
  case "$v" in 1|true|on|yes|enabled) return 0 ;; *) return 1 ;; esac
}

echo "[$(date '+%H:%M:%S')] supervisor START campaign=$CID watchdog=$WATCHDOG backoff=${BACKOFF}s" >> "$LOG"
while true; do
  [ -f "$STOP" ] && { echo "[$(date '+%H:%M:%S')] supervisor STOP present — exiting" >> "$LOG"; rm -f "$STOP"; break; }
  master_enabled || { echo "[$(date '+%H:%M:%S')] MASTER SWITCH off — supervisor exiting (respawns nothing)" >> "$LOG"; break; }

  echo "[$(date '+%H:%M:%S')] (re)starting watchdog campaign=$CID" >> "$LOG"
  "$WATCHDOG" "$CID"
  rc=$?

  # Discriminate intended-stop from crash by RE-READING the master switch (the watchdog exits clean on
  # master-off / its own STOP; it returns non-zero or dies on a crash while still armed).
  if ! master_enabled; then
    echo "[$(date '+%H:%M:%S')] watchdog returned rc=$rc; master now OFF — supervisor exiting (intended stop)" >> "$LOG"
    break
  fi
  echo "[$(date '+%H:%M:%S')] watchdog DIED rc=$rc while master ON — respawning in ${BACKOFF}s" >> "$LOG"
  sleep "$BACKOFF"
done
echo "[$(date '+%H:%M:%S')] supervisor EXIT" >> "$LOG"
