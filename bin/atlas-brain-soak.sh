#!/usr/bin/env bash
# atlas-brain-soak.sh — drive the Atlas EXTERNAL BRAIN cycle-after-cycle for a deadline (default 4h).
#
# WHY THIS EXISTS: a single pasted model session cannot run for hours — it ends its turn ("does one
# cycle and stops"). The loop must live in CODE, not in the model. This driver IS that loop: each
# iteration invokes the provider CLI to run ONE brain cycle (comprehend→originate→author→gate→seed),
# then repeats until the deadline, a STOP file, or the brain master switch going OFF.
#
# Usage:   bin/atlas-brain-soak.sh [HOURS] [real|dry]
#   HOURS  total run time in hours (default 4)
#   mode   real = invoke the provider per cycle (seeds real tasks); dry = prove the loop only, no spend
# Stop:    touch storage/app/atlas/brain/SOAK_STOP    (or flip ATLAS_BRAIN_MASTER_ENABLED=false)
# Detach:  nohup bin/atlas-brain-soak.sh 4 real >/dev/null 2>&1 &   (survives terminal close)

set -uo pipefail
cd /Users/vitorepf/develop/Atlas/atlas-server || exit 1

HOURS="${1:-4}"
MODE="${2:-real}"
SCOPE="${BRAIN_SCOPE:-autonomous}"
PROVIDER_CMD="${BRAIN_PROVIDER_CMD:-codex exec}"   # override if your one-shot model CLI differs
CYCLE_SLEEP="${BRAIN_CYCLE_SLEEP:-15}"             # pause between cycles (seconds)
MAX_CYCLE_SECONDS="${BRAIN_MAX_CYCLE_SECONDS:-600}" # hard cap per cycle so one hang can't eat the run

START=$(date +%s)
DEADLINE=$(( START + ${HOURS%.*}*3600 ))
STOP_FILE="storage/app/atlas/brain/SOAK_STOP"
PROMPT_FILE="storage/app/atlas/brain/soak-cycle-prompt.txt"
mkdir -p storage/logs storage/app/atlas/brain
LOG="storage/logs/atlas-brain-soak-$(date +%Y%m%d-%H%M%S).log"
rm -f "$STOP_FILE"

log(){ printf '[%s] %s\n' "$(date '+%H:%M:%S')" "$*" | tee -a "$LOG"; }

if [ ! -f "$PROMPT_FILE" ]; then log "FATAL: missing $PROMPT_FILE"; exit 1; fi

log "SOAK start — ${HOURS}h, scope=$SCOPE, mode=$MODE, provider='$PROVIDER_CMD'"
log "deadline=$(date -r "$DEADLINE" '+%Y-%m-%d %H:%M:%S')  log=$LOG"
log "stop anytime: touch $STOP_FILE   (or set ATLAS_BRAIN_MASTER_ENABLED=false)"

cycle=0
while [ "$(date +%s)" -lt "$DEADLINE" ]; do
  if [ -f "$STOP_FILE" ]; then log "STOP file present — halting cleanly."; break; fi
  if ! grep -q '^ATLAS_BRAIN_MASTER_ENABLED=true' .env 2>/dev/null; then
    log "brain master switch is OFF — halting (operator-only switch)."; break
  fi

  cycle=$(( cycle + 1 ))
  remain=$(( (DEADLINE - $(date +%s)) / 60 ))
  log "=== cycle $cycle  (~${remain}min left) ==="

  if [ "$MODE" = "dry" ]; then
    log "(dry) would run: $PROVIDER_CMD \"\$(cat $PROMPT_FILE)\""
    sleep 2
  else
    # one bounded brain cycle via the provider; a per-cycle timeout keeps a hang from eating the soak
    if command -v timeout >/dev/null 2>&1; then
      timeout "$MAX_CYCLE_SECONDS" $PROVIDER_CMD "$(cat "$PROMPT_FILE")" >>"$LOG" 2>&1 \
        || log "cycle $cycle: provider exited non-zero / timed out"
    else
      $PROVIDER_CMD "$(cat "$PROMPT_FILE")" >>"$LOG" 2>&1 \
        || log "cycle $cycle: provider exited non-zero"
    fi
  fi

  sleep "$CYCLE_SLEEP"
done

log "SOAK end — ran $cycle cycles over $(( ($(date +%s) - START) / 60 )) min."
