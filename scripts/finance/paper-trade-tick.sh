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

# Pista 1 — baseline fixo (controle): trend-breakout conservador, params imutáveis.
PARAMS='{"regime_period":100,"entry_lookback":20,"exit_lookback":10,"atr_period":14,"atr_mult":3.0,"risk_pct":0.25,"fee_bps":10,"slippage_bps":5,"min_hold_bars":0}'

for SYMBOL in BTCUSDT ETHUSDT; do
  for INTERVAL in 4h 1d; do
    "$PHP" artisan atlas:finance:paper-trade \
      --symbol="$SYMBOL" --interval="$INTERVAL" \
      --family=trend-breakout-v1 --params="$PARAMS" --json \
      >> "$APP_ROOT/storage/atlas/finance/paper/tick.log" 2>&1
  done
done

# Pista 2 — ELITES do loop (research-only, não certificados): o --elite puxa o melhor
# candidato atual do registry por cenário; cenário sem elite falha de leve e segue.
# Conforme o loop encontra candidatos melhores, o paper os adota automaticamente.
for FAMILY in trend-breakout-v1 mean-reversion-v1 momentum-v1 volume-breakout-v1 pullback-trend-v1 regime-adaptive-v1 trend-breakout-v2 pullback-trend-v2; do
  for SYMBOL in BTCUSDT ETHUSDT; do
    for INTERVAL in 4h 1d; do
      ID="$(echo "$SYMBOL-$INTERVAL-$FAMILY-elite" | tr '[:upper:]' '[:lower:]')"
      "$PHP" artisan atlas:finance:paper-trade \
        --symbol="$SYMBOL" --interval="$INTERVAL" \
        --family="$FAMILY" --elite --id="$ID" --json \
        >> "$APP_ROOT/storage/atlas/finance/paper/tick.log" 2>&1
    done
  done
done

# Pista 3 — censo contínuo de arbitragem triangular (determinística; abre só em stress).
"$PHP" scripts/finance/triangular-probe.php
