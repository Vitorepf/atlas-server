#!/usr/bin/env bash
set -euo pipefail

export PATH="/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin"

SOURCE="${ATLAS_VAULT_SOURCE:-$HOME/Library/Mobile Documents/iCloud~md~obsidian/Documents/AtlasVault}"
MIRROR="${ATLAS_VAULT_MIRROR:-/Users/vitorepf/Develop/atlas/atlas-vault-backup}"
BRANCH="${ATLAS_VAULT_BRANCH:-main}"

if [ ! -d "$SOURCE" ]; then
  echo "AtlasVault source does not exist: $SOURCE" >&2
  exit 1
fi

mkdir -p "$MIRROR"

case "$MIRROR" in
  "$SOURCE"|"$SOURCE"/*)
    echo "Refusing to place the git mirror inside the live iCloud vault." >&2
    exit 1
    ;;
esac

# Materializa stubs do iCloud antes do rsync (evita "Resource deadlock" no mmap).
# Tolerante: arquivos ainda nao baixados podem falhar e nao devem abortar o script.
{ find "$SOURCE" -type f \! -path '*/.git/*' \! -name '.DS_Store' \! -name '._*' -print0 2>/dev/null \
  | xargs -0 -P 8 -I {} sh -c 'dd if="$1" of=/dev/null bs=1 count=1 2>/dev/null || true' _ {} ; } || true

rsync -aW --delete \
  --exclude='.git/' \
  --exclude='.gitignore' \
  --exclude='.DS_Store' \
  --exclude='._*' \
  --exclude='.Trash/' \
  --exclude='.icloud' \
  "$SOURCE/" "$MIRROR/"

if [ ! -d "$MIRROR/.git" ]; then
  git -C "$MIRROR" init -b "$BRANCH" >/dev/null 2>&1 || git -C "$MIRROR" init >/dev/null
fi

current_branch="$(git -C "$MIRROR" branch --show-current 2>/dev/null || true)"
if [ "$current_branch" != "$BRANCH" ]; then
  git -C "$MIRROR" branch -M "$BRANCH"
fi

if [ ! -f "$MIRROR/.gitignore" ]; then
  {
    echo ".DS_Store"
    echo "._*"
    echo ".Trash/"
    echo "*.tmp"
    echo "*.swp"
    echo "*.swo"
    echo ".icloud"
  } > "$MIRROR/.gitignore"
fi

git -C "$MIRROR" add -A

if git -C "$MIRROR" diff --cached --quiet; then
  echo "AtlasVault snapshot: no changes."
  exit 0
fi

timestamp="$(date '+%Y-%m-%d %H:%M:%S %z')"
git -C "$MIRROR" commit -m "Vault snapshot $timestamp"

if git -C "$MIRROR" remote get-url origin >/dev/null 2>&1; then
  git -C "$MIRROR" push -u origin "$BRANCH"
else
  echo "AtlasVault snapshot committed locally. No origin remote configured yet."
fi
