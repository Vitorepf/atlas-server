#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
UID_VALUE="$(id -u)"
LABEL="com.atlas.mac-agent"
PLIST="$HOME/Library/LaunchAgents/$LABEL.plist"
STDOUT="$ROOT_DIR/storage/logs/$LABEL.log"
STDERR="$ROOT_DIR/storage/logs/$LABEL.err.log"

mkdir -p "$HOME/Library/LaunchAgents" "$ROOT_DIR/storage/logs"

cat > "$PLIST" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>$LABEL</string>
    <key>ProgramArguments</key>
    <array>
        <string>$ROOT_DIR/scripts/run-mac-agent.sh</string>
    </array>
    <key>WorkingDirectory</key>
    <string>$ROOT_DIR</string>
    <key>RunAtLoad</key>
    <true/>
    <key>KeepAlive</key>
    <true/>
    <key>StandardOutPath</key>
    <string>$STDOUT</string>
    <key>StandardErrorPath</key>
    <string>$STDERR</string>
</dict>
</plist>
PLIST

chmod +x "$ROOT_DIR/scripts/run-mac-agent.sh"
plutil -lint "$PLIST" >/dev/null

launchctl bootout "gui/$UID_VALUE" "$PLIST" >/dev/null 2>&1 || true
launchctl bootstrap "gui/$UID_VALUE" "$PLIST"
launchctl kickstart -k "gui/$UID_VALUE/$LABEL"

echo "Atlas Mac Agent installed: $PLIST"
echo "Status: launchctl print gui/$UID_VALUE/$LABEL"
