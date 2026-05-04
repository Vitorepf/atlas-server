#!/usr/bin/env zsh
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${0}")/.." && pwd)"

export PATH="/Users/vitorepf/.nvm/versions/node/v24.9.0/bin:/Users/vitorepf/.local/bin:/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin:${PATH:-}"

cd "$ROOT_DIR"

exec /opt/homebrew/bin/php artisan atlas:host-agent:work --sleep="${ATLAS_HOST_AGENT_SLEEP:-30}"
