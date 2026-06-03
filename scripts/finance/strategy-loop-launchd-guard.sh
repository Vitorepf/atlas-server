#!/bin/sh
set -eu

APP_ROOT="/Users/vitorepf/develop/Atlas/atlas-server"
STOP_FILE="$APP_ROOT/storage/atlas/finance/STOP"
RUNNER_PATTERN="artisan atlas:finance:strategy-campaign-runner --family=roadmap --symbol=BTCUSDT --interval=1d"

if [ -f "$STOP_FILE" ]; then
  exit 0
fi

if /usr/bin/pgrep -f "$RUNNER_PATTERN" >/dev/null 2>&1; then
  exit 0
fi

cd "$APP_ROOT"
exec /opt/homebrew/bin/php artisan atlas:finance:strategy-campaign-runner \
  --family=roadmap \
  --symbol=BTCUSDT \
  --interval=1d \
  --candidates=600 \
  --sleep=2 \
  --continuous \
  --max-campaigns=0 \
  --idle-sleep=60
