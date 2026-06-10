#!/usr/bin/env bash
#
# setup-aobg.sh — register the Atlas Open Brain Gateway (AOBG) MCP server with the
# external AIs that plug into it: Codex (~/.codex/config.toml) and Cursor (~/.cursor/mcp.json).
#
# AOBG N1.F4 — "make the gateway pluggable by Claude Code, Codex, Cursor". Claude Code is
# already wired in-repo via .mcp.json (no global edit needed). This script handles the two
# providers whose registration lives in the operator's GLOBAL config:
#
#   * Codex  — TOML table  [mcp_servers.atlas-open-brain]  in ~/.codex/config.toml
#   * Cursor — JSON entry  mcpServers."atlas-open-brain"   in ~/.cursor/mcp.json
#
# Both point at the SAME local invocation Claude Code uses — `bin/atlas open-brain mcp` —
# which auto-scopes the workspace from the caller's CWD (multi-project; never cross-leaks)
# and serves the read-only, provider-bound, cost-free Open Brain tools.
#
# SAFETY: by default this script only PRINTS the exact stanzas + shows what it would change
# (a dry diff). It does NOT touch the operator's external configs. Pass --install to apply,
# and even then it is IDEMPOTENT (skips when an atlas-open-brain registration already exists)
# and writes a timestamped .bak before any change. The in-repo .mcp.json + hook are the only
# files the gateway build writes; external global configs are the operator's to apply.
#
# Usage:
#   scripts/setup-aobg.sh              # PRINT the Codex + Cursor stanzas + dry status (default)
#   scripts/setup-aobg.sh --install    # idempotently append to ~/.codex/config.toml + ~/.cursor/mcp.json
#   scripts/setup-aobg.sh --print      # explicit print-only (same as no args)
#
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ATLAS_BIN="$REPO_ROOT/bin/atlas"
SERVER_NAME="atlas-open-brain"

CODEX_CONFIG="${ATLAS_AOBG_CODEX_CONFIG:-$HOME/.codex/config.toml}"
CURSOR_CONFIG="${ATLAS_AOBG_CURSOR_CONFIG:-$HOME/.cursor/mcp.json}"

MODE="print"
case "${1:-}" in
  --install) MODE="install" ;;
  --print|"") MODE="print" ;;
  -h|--help)
    grep '^#' "$0" | sed 's/^# \{0,1\}//'
    exit 0
    ;;
  *)
    echo "unknown arg: ${1:-}  (use --print | --install | --help)" >&2
    exit 2
    ;;
esac

if [ ! -x "$ATLAS_BIN" ]; then
  echo "[aobg] WARNING: $ATLAS_BIN is not executable; the MCP server launcher may fail." >&2
fi

# --- the exact stanzas ---------------------------------------------------------------

codex_stanza() {
  cat <<EOF
[mcp_servers.$SERVER_NAME]
command = "$ATLAS_BIN"
args = ["open-brain", "mcp"]
EOF
}

cursor_stanza() {
  cat <<EOF
{
  "mcpServers": {
    "$SERVER_NAME": {
      "command": "$ATLAS_BIN",
      "args": ["open-brain", "mcp"]
    }
  }
}
EOF
}

print_stanzas() {
  echo "==================================================================="
  echo " Atlas Open Brain Gateway (AOBG) — MCP registration stanzas"
  echo " server: $SERVER_NAME   command: $ATLAS_BIN open-brain mcp"
  echo "==================================================================="
  echo
  echo "----- Codex  (~/.codex/config.toml) -------------------------------"
  echo " add this TOML table:"
  echo
  codex_stanza
  echo
  echo "----- Cursor (~/.cursor/mcp.json) ---------------------------------"
  echo " merge this mcpServers entry (keep your existing servers):"
  echo
  cursor_stanza
  echo
  echo "----- Claude Code -------------------------------------------------"
  echo " already wired in-repo via $REPO_ROOT/.mcp.json (no global edit)."
  echo
}

# --- idempotency probe (true => already registered) ----------------------------------

codex_has_server() {
  [ -f "$CODEX_CONFIG" ] && grep -q "\[mcp_servers\.$SERVER_NAME\]" "$CODEX_CONFIG" 2>/dev/null
}

cursor_has_server() {
  [ -f "$CURSOR_CONFIG" ] && grep -q "\"$SERVER_NAME\"" "$CURSOR_CONFIG" 2>/dev/null
}

# --- install (idempotent, backed up) -------------------------------------------------

install_codex() {
  if codex_has_server; then
    echo "[aobg] Codex: '$SERVER_NAME' already in $CODEX_CONFIG — skipping (idempotent)."
    return 0
  fi
  mkdir -p "$(dirname "$CODEX_CONFIG")"
  if [ -f "$CODEX_CONFIG" ]; then
    cp "$CODEX_CONFIG" "$CODEX_CONFIG.aobg.bak.$(date +%Y%m%d%H%M%S)"
    printf '\n' >>"$CODEX_CONFIG"
  fi
  codex_stanza >>"$CODEX_CONFIG"
  echo "[aobg] Codex: appended '$SERVER_NAME' to $CODEX_CONFIG."
}

install_cursor() {
  if cursor_has_server; then
    echo "[aobg] Cursor: '$SERVER_NAME' already in $CURSOR_CONFIG — skipping (idempotent)."
    return 0
  fi
  mkdir -p "$(dirname "$CURSOR_CONFIG")"
  if [ -f "$CURSOR_CONFIG" ] && [ -s "$CURSOR_CONFIG" ]; then
    cp "$CURSOR_CONFIG" "$CURSOR_CONFIG.aobg.bak.$(date +%Y%m%d%H%M%S)"
    if command -v jq >/dev/null 2>&1; then
      # Safe structural merge: add/overwrite only the atlas-open-brain server, keep the rest.
      tmp="$(mktemp)"
      jq --arg name "$SERVER_NAME" --arg cmd "$ATLAS_BIN" \
        '.mcpServers = ((.mcpServers // {}) + {($name): {command: $cmd, args: ["open-brain","mcp"]}})' \
        "$CURSOR_CONFIG" >"$tmp" && mv "$tmp" "$CURSOR_CONFIG"
      echo "[aobg] Cursor: merged '$SERVER_NAME' into $CURSOR_CONFIG (jq merge)."
      return 0
    fi
    echo "[aobg] Cursor: $CURSOR_CONFIG exists but jq is unavailable for a safe merge." >&2
    echo "[aobg] Cursor: NOT modified — add the entry below by hand:" >&2
    cursor_stanza >&2
    return 0
  fi
  cursor_stanza >"$CURSOR_CONFIG"
  echo "[aobg] Cursor: wrote fresh $CURSOR_CONFIG with '$SERVER_NAME'."
}

# --- run -----------------------------------------------------------------------------

print_stanzas

echo "----- current status ----------------------------------------------"
if codex_has_server; then echo "[aobg] Codex:  registered  ($CODEX_CONFIG)"; else echo "[aobg] Codex:  NOT registered  ($CODEX_CONFIG)"; fi
if cursor_has_server; then echo "[aobg] Cursor: registered  ($CURSOR_CONFIG)"; else echo "[aobg] Cursor: NOT registered  ($CURSOR_CONFIG)"; fi
echo

if [ "$MODE" = "print" ]; then
  echo "[aobg] print-only (default). Re-run with --install to apply the stanzas above."
  echo "[aobg] verify the server independently:  $ATLAS_BIN open-brain mcp --describe --json"
  exit 0
fi

echo "[aobg] --install: applying (idempotent, backups written before any change)..."
install_codex
install_cursor
echo "[aobg] done. Restart Codex / Cursor to pick up the new MCP server."
echo "[aobg] verify:  $ATLAS_BIN open-brain mcp --describe --json"
