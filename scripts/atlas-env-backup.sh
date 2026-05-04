#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKUP_DIR="${ATLAS_ENV_BACKUP_DIR:-$HOME/Library/Mobile Documents/com~apple~CloudDocs/AtlasPrivateBackups}"
TIMESTAMP="$(date '+%Y%m%d-%H%M%S')"
FILES=(
  "atlas-server/.env"
  "atlas-app/.env"
)

usage() {
  echo "Usage:"
  echo "  $0 backup"
  echo "  $0 restore [archive.tar.gz.enc] [--force]"
}

latest_backup() {
  find "$BACKUP_DIR" -maxdepth 1 -type f -name 'atlas-env-*.tar.gz.enc' 2>/dev/null | sort | tail -n 1
}

backup_env() {
  mkdir -p "$BACKUP_DIR"
  chmod 700 "$BACKUP_DIR"

  tmp="$(mktemp -d)"
  trap 'rm -rf "$tmp"' EXIT

  {
    echo "Atlas env backup"
    echo "created_at=$TIMESTAMP"
    echo "root=$ROOT_DIR"
    echo
  } > "$tmp/MANIFEST.txt"

  found=0
  for relative in "${FILES[@]}"; do
    source_file="$ROOT_DIR/$relative"
    if [[ -f "$source_file" ]]; then
      mkdir -p "$tmp/$(dirname "$relative")"
      cp "$source_file" "$tmp/$relative"
      chmod 600 "$tmp/$relative"
      shasum -a 256 "$source_file" >> "$tmp/MANIFEST.txt"
      found=1
    fi
  done

  if [[ "$found" -ne 1 ]]; then
    echo "No .env files found under $ROOT_DIR." >&2
    exit 1
  fi

  archive="$BACKUP_DIR/atlas-env-$TIMESTAMP.tar.gz.enc"
  tar -czf - -C "$tmp" . | openssl enc -aes-256-cbc -salt -pbkdf2 -iter 200000 -out "$archive"
  chmod 600 "$archive"

  echo "Encrypted env backup created:"
  echo "$archive"
}

restore_env() {
  archive="${1:-}"
  force="${2:-}"

  if [[ -z "$archive" ]]; then
    archive="$(latest_backup)"
  fi

  if [[ -z "$archive" || ! -f "$archive" ]]; then
    echo "No env backup archive found. Pass archive path explicitly." >&2
    exit 1
  fi

  if [[ "$force" != "--force" ]]; then
    echo "Restore requires --force to overwrite local .env files." >&2
    echo "Archive: $archive" >&2
    exit 1
  fi

  tmp="$(mktemp -d)"
  trap 'rm -rf "$tmp"' EXIT

  openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -in "$archive" | tar -xzf - -C "$tmp"

  for relative in "${FILES[@]}"; do
    restored_file="$tmp/$relative"
    target_file="$ROOT_DIR/$relative"
    if [[ -f "$restored_file" ]]; then
      mkdir -p "$(dirname "$target_file")"
      if [[ -f "$target_file" ]]; then
        cp "$target_file" "$target_file.before-env-restore-$TIMESTAMP"
      fi
      cp "$restored_file" "$target_file"
      chmod 600 "$target_file"
      echo "Restored $target_file"
    fi
  done
}

command="${1:-}"
case "$command" in
  backup)
    backup_env
    ;;
  restore)
    restore_env "${2:-}" "${3:-}"
    ;;
  *)
    usage
    exit 1
    ;;
esac
