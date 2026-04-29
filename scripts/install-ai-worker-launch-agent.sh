#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
UID_VALUE="$(id -u)"

mkdir -p "$HOME/Library/LaunchAgents" "$ROOT_DIR/storage/logs"

install_worker() {
  local label="$1"
  local provider="$2"
  local plist="$HOME/Library/LaunchAgents/$label.plist"
  local stdout="$ROOT_DIR/storage/logs/$label.log"
  local stderr="$ROOT_DIR/storage/logs/$label.err.log"

  cat > "$plist" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>$label</string>
    <key>ProgramArguments</key>
    <array>
        <string>$ROOT_DIR/scripts/run-ai-worker.sh</string>
        <string>$provider</string>
    </array>
    <key>WorkingDirectory</key>
    <string>$ROOT_DIR</string>
    <key>RunAtLoad</key>
    <true/>
    <key>KeepAlive</key>
    <true/>
    <key>StandardOutPath</key>
    <string>$stdout</string>
    <key>StandardErrorPath</key>
    <string>$stderr</string>
</dict>
</plist>
PLIST

  plutil -lint "$plist" >/dev/null

  launchctl bootout "gui/$UID_VALUE" "$plist" >/dev/null 2>&1 || true
  launchctl bootstrap "gui/$UID_VALUE" "$plist"
  launchctl kickstart -k "gui/$UID_VALUE/$label"

  echo "Atlas AI launch agent installed: $plist"
  echo "Status: launchctl print gui/$UID_VALUE/$label"
}

OLD_LABEL="com.atlas.ai-worker"
OLD_PLIST="$HOME/Library/LaunchAgents/$OLD_LABEL.plist"
launchctl bootout "gui/$UID_VALUE" "$OLD_PLIST" >/dev/null 2>&1 || true
rm -f "$OLD_PLIST"

install_worker "com.atlas.ai-worker.claude" "claude_cli"
install_worker "com.atlas.ai-worker.codex" "codex_cli"
