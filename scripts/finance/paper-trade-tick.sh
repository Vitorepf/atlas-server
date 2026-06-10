#!/bin/sh
# Tick periódico do paper-trading (Fase A — zero dinheiro, fills simulados).
# Idempotente por barra: rodar com frequência só processa barras novas.
# Kill-switch: storage/atlas/finance/paper/STOP
set -u

APP_ROOT="/Users/vitorepf/develop/Atlas/atlas-server"
PHP="/opt/homebrew/bin/php"
STOP_FILE="$APP_ROOT/storage/atlas/finance/paper/STOP"

if [ -f "$STOP_FILE" ]; then
  exit 0
fi

cd "$APP_ROOT"

# Params explícitos (baseline trend-breakout conservador). Quando o loop certificar
# uma estratégia, ela substitui estes — o cano é o mesmo.
PARAMS='{"regime_period":100,"entry_lookback":20,"exit_lookback":10,"atr_period":14,"atr_mult":3.0,"risk_pct":0.25,"fee_bps":10,"slippage_bps":5,"min_hold_bars":0}'

for SYMBOL in BTCUSDT ETHUSDT; do
  for INTERVAL in 4h 1d; do
    "$PHP" artisan atlas:finance:paper-trade \
      --symbol="$SYMBOL" --interval="$INTERVAL" \
      --family=trend-breakout-v1 --params="$PARAMS" --json \
      >> "$APP_ROOT/storage/atlas/finance/paper/tick.log" 2>&1
  done
done
