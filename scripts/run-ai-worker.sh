#!/usr/bin/env zsh
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${0}")/.." && pwd)"

export PATH="/Users/vitorepf/.nvm/versions/node/v24.9.0/bin:/Users/vitorepf/.local/bin:/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin:${PATH:-}"

cd "$ROOT_DIR"

if [[ -n "${1:-}" ]]; then
  exec /opt/homebrew/bin/php artisan atlas:ai:work --sleep=2 --provider="$1" --worker-id="$(hostname)-$1"
fi

exec /opt/homebrew/bin/php artisan atlas:ai:work --sleep=2 --worker-id="$(hostname)"
