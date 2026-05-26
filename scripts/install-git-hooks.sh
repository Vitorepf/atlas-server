#!/usr/bin/env bash
set -euo pipefail

repo_root="$(git rev-parse --show-toplevel)"
server_dir="$repo_root"

if [[ ! -f "$server_dir/artisan" && -f "$repo_root/atlas-server/artisan" ]]; then
  server_dir="$repo_root/atlas-server"
fi

git -C "$server_dir" config core.hooksPath .githooks
chmod +x "$server_dir/.githooks/pre-commit"

echo "Atlas git hooks installed: core.hooksPath=.githooks"

