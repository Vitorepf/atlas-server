#!/usr/bin/env zsh
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${0}")/.." && pwd)"

# Mesmo PATH do run-ai-worker.sh: o runner nativo do Rivals precisa de
# docker/tb/uv fora do PATH mínimo do launchd.
NVM_NODE_BIN="$(ls -d "$HOME"/.nvm/versions/node/v*/bin 2>/dev/null | sort -V | tail -1)"
export PATH="${NVM_NODE_BIN:-/usr/local/bin}:/Users/vitorepf/.local/bin:/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin:${PATH:-}"

cd "$ROOT_DIR"

# KeepAlive + loop (padrão dos ai-workers desta máquina; StartInterval não
# dispara aqui — provado 2026-07-18: runs=1 em 10min). launchd garante
# instância única do Label; o sleep dá o ritmo. Fila vazia custa um exit 0
# do artisan e 60s de sono.
while true; do
  /opt/homebrew/bin/php artisan atlas:arena:drain --approve-provider-spend || true
  sleep 60
done
