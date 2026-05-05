#!/usr/bin/env zsh
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${0}")/.." && pwd)"

# Resolve dinamicamente a versao mais recente do node do nvm
# (hardcode de versao quebra apos nvm install de uma nova).
NVM_NODE_BIN="$(ls -d "$HOME"/.nvm/versions/node/v*/bin 2>/dev/null | sort -V | tail -1)"
export PATH="${NVM_NODE_BIN:-/usr/local/bin}:/Users/vitorepf/.local/bin:/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin:${PATH:-}"

cd "$ROOT_DIR"

if [[ -n "${1:-}" ]]; then
  exec /opt/homebrew/bin/php artisan atlas:ai:work --sleep=2 --provider="$1" --worker-id="$(hostname)-$1"
fi

exec /opt/homebrew/bin/php artisan atlas:ai:work --sleep=2 --worker-id="$(hostname)"
